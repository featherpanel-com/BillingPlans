<?php

namespace App\Addons\billingplans\Services;

use App\Chat\VmInstance;
use App\Chat\VmIp;
use App\Chat\VmNode;
use App\Chat\VmTask;
use App\Chat\VmTemplate;
use App\Controllers\Admin\VmInstancesController;
use App\Services\Vm\VmInstanceUtil;
use App\Addons\billingplans\Chat\Subscription;
use Symfony\Component\HttpFoundation\Request;

class VdsProvisioningService
{
    /**
     * Start asynchronous VM creation for a billing plan.
     *
     * @return array{success: bool, creation_task_id?: string, error?: string, code?: string}
     */
    public static function provision(array $plan, array $user, ?string $customName = null): array
    {
        $config = self::decodeConfig($plan['vds_config'] ?? null);
        $templateId = (int) ($config['template_id'] ?? 0);
        $nodeIds = self::intList($config['vm_node_ids'] ?? $config['vm_node_id'] ?? []);

        if ($templateId <= 0) {
            return self::failure('VDS plan has no VM template configured.', 'VDS_TEMPLATE_REQUIRED');
        }

        $template = VmTemplate::getById($templateId);
        if (!$template || ($template['is_active'] ?? 'true') === 'false') {
            return self::failure('The VM template configured for this plan is unavailable.', 'VDS_TEMPLATE_NOT_FOUND');
        }

        if (empty($nodeIds) && !empty($template['vm_node_id'])) {
            $nodeIds = [(int) $template['vm_node_id']];
        }
        if (empty($nodeIds)) {
            return self::failure('VDS plan has no VM node configured.', 'VDS_NODE_REQUIRED');
        }

        $node = self::selectNode($nodeIds);
        if (!$node) {
            return self::failure('No configured VM node has a free IP address.', 'VDS_NO_FREE_IP');
        }

        if (!empty($template['vm_node_id']) && (int) $template['vm_node_id'] !== (int) $node['id']) {
            return self::failure('The selected VM node does not match the VM template.', 'VDS_TEMPLATE_NODE_MISMATCH');
        }

        if (($template['guest_type'] ?? 'qemu') === 'qemu'
            && (trim((string) ($config['ci_user'] ?? '')) === '' || trim((string) ($config['ci_password'] ?? '')) === '')) {
            return self::failure('QEMU VDS plans must include cloud-init credentials.', 'VDS_CLOUD_INIT_REQUIRED');
        }

        $hostname = self::hostname($customName, $plan['name'] ?? 'vds', $user['username'] ?? 'user');
        $payload = [
            'vm_node_id' => (int) $node['id'],
            'template_id' => $templateId,
            'memory' => max(128, (int) ($config['memory'] ?? 512)),
            'cpus' => max(1, (int) ($config['cpus'] ?? 1)),
            'cores' => max(1, (int) ($config['cores'] ?? 1)),
            'disk' => max(1, (int) ($config['disk'] ?? 10)),
            'storage' => trim((string) ($config['storage'] ?? $template['storage'] ?? 'local')),
            'bridge' => trim((string) ($config['bridge'] ?? 'vmbr0')),
            'on_boot' => array_key_exists('on_boot', $config) ? (!empty($config['on_boot']) ? 1 : 0) : 1,
            'hostname' => $hostname,
            'user_uuid' => $user['uuid'] ?? null,
            'notes' => 'BillingPlans VDS plan #' . (int) ($plan['id'] ?? 0),
            'ci_user' => $config['ci_user'] ?? null,
            'ci_password' => $config['ci_password'] ?? null,
            'backup_limit' => max(0, (int) ($config['backup_limit'] ?? 0)),
            'backup_retention_mode' => $config['backup_retention_mode'] ?? null,
        ];

        $request = Request::create(
            '/api/admin/vm-instances',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $request->attributes->set('user', $user);

        try {
            $response = (new VmInstancesController())->create($request);
            $body = json_decode($response->getContent() ?: '{}', true) ?: [];
            $taskId = (string) ($body['data']['creation_id'] ?? $body['creation_id'] ?? '');
            if ($response->getStatusCode() !== 202 || $taskId === '') {
                return self::failure((string) ($body['message'] ?? 'VM creation could not be queued.'), 'VDS_CREATE_FAILED');
            }

            return ['success' => true, 'creation_task_id' => $taskId];
        } catch (\Throwable $exception) {
            return self::failure($exception->getMessage(), 'VDS_CREATE_FAILED');
        }
    }

    public static function syncSubscription(array $subscription): bool
    {
        $taskId = trim((string) ($subscription['vm_creation_task_id'] ?? ''));
        if ($taskId === '' || !empty($subscription['vm_instance_id'])) {
            return !empty($subscription['vm_instance_id']);
        }

        $task = VmTask::getByTaskId($taskId);
        if (!$task) {
            return false;
        }
        $status = strtolower((string) ($task['status'] ?? ''));
        if ($status === 'failed') {
            Subscription::update((int) $subscription['id'], [
                'status' => 'suspended',
                'suspended_at' => date('Y-m-d H:i:s'),
                'vm_creation_task_id' => null,
            ]);
            return false;
        }
        if (!in_array($status, ['completed', 'complete', 'success', 'successful'], true)) {
            return false;
        }

        $vmid = (int) ($task['vmid'] ?? 0);
        $nodeId = (int) ($task['vm_node_id'] ?? 0);
        $instance = $vmid > 0 && $nodeId > 0 ? VmInstance::getByVmidAndNode($vmid, $nodeId) : null;
        if (!$instance) {
            return false;
        }

        return Subscription::update((int) $subscription['id'], [
            'vm_instance_id' => (int) $instance['id'],
            'vm_creation_task_id' => null,
        ]);
    }

    public static function queuePowerAction(array $subscription, string $action): ?string
    {
        $instance = self::instanceFor($subscription);
        if (!$instance || !in_array($action, ['start', 'stop', 'reboot'], true)) {
            return null;
        }

        return VmInstanceUtil::createVmTask(
            $instance,
            'power',
            '',
            ['instance_id' => (int) $instance['id'], 'vm_type' => $instance['vm_type'] ?? 'qemu', 'action' => $action],
            (int) $instance['vmid'],
            (string) ($instance['pve_node'] ?? ''),
        );
    }

    public static function queueDelete(array $subscription): ?string
    {
        $instance = self::instanceFor($subscription);
        if (!$instance) {
            return null;
        }

        return VmInstanceUtil::createVmTask(
            $instance,
            'delete',
            '',
            ['instance_id' => (int) $instance['id'], 'vm_type' => $instance['vm_type'] ?? 'qemu'],
            (int) $instance['vmid'],
            (string) ($instance['pve_node'] ?? ''),
        );
    }

    private static function instanceFor(array $subscription): ?array
    {
        $instanceId = (int) ($subscription['vm_instance_id'] ?? 0);
        return $instanceId > 0 ? VmInstance::getById($instanceId) : null;
    }

    private static function selectNode(array $nodeIds): ?array
    {
        foreach ($nodeIds as $nodeId) {
            $node = VmNode::getVmNodeById((int) $nodeId);
            if ($node && !empty(VmIp::getFreeIpsForNode((int) $nodeId))) {
                return $node;
            }
        }
        return null;
    }

    private static function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function intList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return array_values(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0));
    }

    private static function hostname(?string $customName, string $planName, string $username): string
    {
        $value = trim((string) ($customName ?: $planName . '-' . $username));
        $value = strtolower((string) preg_replace('/[^a-z0-9.-]+/i', '-', $value));
        $value = trim($value, '-.');
        return substr($value !== '' ? $value : 'vds', 0, 63);
    }

    /** @return array{success: false, error: string, code: string} */
    private static function failure(string $error, string $code): array
    {
        return ['success' => false, 'error' => $error !== '' ? $error : 'VDS provisioning failed.', 'code' => $code];
    }
}
