<?php

/*
 * This file is part of FeatherPanel.
 *
 * Copyright (C) 2025 MythicalSystems Studios
 * Copyright (C) 2025 FeatherPanel Contributors
 * Copyright (C) 2025 Cassian Gherman (aka NaysKutzu)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See the LICENSE file or <https://www.gnu.org/licenses/>.
 */

namespace App\Addons\billingplans\Chat;

use App\Chat\Database;

class Subscription
{
    private static string $table = 'featherpanel_billingplans_subscriptions';

    public static function getById(int $id): ?array
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, p.name as plan_name, p.price_credits, p.billing_period_days, p.tax_rate_percent, p.extra_charge_percent, p.extra_charge_name,
                    p.allowed_upgrade_plan_ids, p.allowed_downgrade_plan_ids, p.slider_config
             FROM ' .
                self::$table .
                ' s
             LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
             WHERE s.id = :id LIMIT 1',
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function getByServerUuid(string $serverUuid): ?array
    {
        $all = self::getAllByServerUuid($serverUuid);

        return $all[0] ?? null;
    }

    /**
     * All subscriptions linked to a server UUID (normally one; supports duplicate rows from data issues).
     *
     * @return list<array<string, mixed>>
     */
    public static function getAllByServerUuid(string $serverUuid): array
    {
        $serverUuid = trim($serverUuid);
        if ($serverUuid === '') {
            return [];
        }
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM ' .
                self::$table .
                ' WHERE server_uuid = :uuid ORDER BY id ASC',
        );
        $stmt->execute(['uuid' => $serverUuid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_values($rows);
    }

    /**
     * Aggregate admin refund counters (requires admin_credits_refunded_total column — returns zeros if missing).
     *
     * @return array{total_credits_refunded: int, subscriptions_with_refunds: int}
     */
    public static function getAdminRefundAggregateStats(): array
    {
        $defaults = [
            'total_credits_refunded' => 0,
            'subscriptions_with_refunds' => 0,
        ];
        try {
            $pdo = Database::getPdoConnection();
            $stmt = $pdo->query(
                'SELECT COALESCE(SUM(admin_credits_refunded_total), 0) AS total_credits_refunded,
                        COALESCE(SUM(CASE WHEN COALESCE(admin_credits_refunded_total, 0) > 0 THEN 1 ELSE 0 END), 0) AS subscriptions_with_refunds
                 FROM ' . self::$table,
            );
            if ($stmt === false) {
                return $defaults;
            }
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return $defaults;
            }

            return [
                'total_credits_refunded' => (int) $row['total_credits_refunded'],
                'subscriptions_with_refunds' => (int) $row['subscriptions_with_refunds'],
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    public static function getByUserId(int $userId): array
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, p.name as plan_name, p.price_credits, p.billing_period_days, p.tax_rate_percent, p.extra_charge_percent, p.extra_charge_name, p.description as plan_description,
                    p.allowed_upgrade_plan_ids, p.allowed_downgrade_plan_ids, p.slider_config
             FROM ' .
                self::$table .
                ' s
             LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
             WHERE s.user_id = :user_id
             ORDER BY s.created_at DESC',
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getActiveByUserId(int $userId): array
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, p.name as plan_name, p.price_credits, p.billing_period_days, p.tax_rate_percent, p.extra_charge_percent, p.extra_charge_name, p.description as plan_description,
                    p.allowed_upgrade_plan_ids, p.allowed_downgrade_plan_ids, p.slider_config
             FROM ' .
                self::$table .
                ' s
             LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
             WHERE s.user_id = :user_id AND s.status IN (\'active\', \'suspended\')
             ORDER BY s.created_at DESC',
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get subscriptions due for renewal (next_renewal_at <= NOW and status = 'active').
     */
    public static function getDueForRenewal(): array
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, p.name as plan_name, p.price_credits, p.billing_period_days, p.tax_rate_percent, p.extra_charge_percent, p.extra_charge_name, p.slider_config
             FROM ' .
                self::$table .
                ' s
             LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
             WHERE s.status = \'active\'
               AND s.next_renewal_at IS NOT NULL
               AND s.next_renewal_at <= NOW()
             ORDER BY s.next_renewal_at ASC',
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get paginated list of all subscriptions for admin.
     */
    public static function getPaginated(
        int $page,
        int $limit,
        string $status = '',
        string $search = '',
    ): array {
        $pdo = Database::getPdoConnection();
        $offset = ($page - 1) * $limit;
        $where = ['1=1'];
        $params = [];

        if (!empty($status)) {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }

        if (!empty($search)) {
            $where[] =
                '(u.username LIKE :search OR u.email LIKE :search OR p.name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countSql =
            'SELECT COUNT(*) as count
                     FROM ' .
            self::$table .
            ' s
                     LEFT JOIN featherpanel_users u ON s.user_id = u.id
                     LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
                     ' .
            $whereClause;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch(\PDO::FETCH_ASSOC)['count'];

        $sql =
            'SELECT s.*, p.name as plan_name, p.price_credits, p.billing_period_days, p.tax_rate_percent, p.extra_charge_percent, p.extra_charge_name,
                       u.username, u.email, u.uuid as user_uuid
                FROM ' .
            self::$table .
            ' s
                LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
                LEFT JOIN featherpanel_users u ON s.user_id = u.id
                ' .
            $whereClause .
            '
                ORDER BY s.created_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return ['data' => $rows, 'total' => $total];
    }

    public static function create(array $data): ?int
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO ' .
                self::$table .
                ' (user_id, plan_id, coupon_code_id, coupon_code, coupon_scope, renewal_discount_percent, renewal_discount_credits, server_uuid, status, next_renewal_at, custom_resources)
             VALUES (:user_id, :plan_id, :coupon_code_id, :coupon_code, :coupon_scope, :renewal_discount_percent, :renewal_discount_credits, :server_uuid, :status, :next_renewal_at, :custom_resources)',
        );
        $stmt->execute([
            'user_id' => (int) $data['user_id'],
            'plan_id' => (int) $data['plan_id'],
            'coupon_code_id' => isset($data['coupon_code_id'])
                ? (int) $data['coupon_code_id']
                : null,
            'coupon_code' => $data['coupon_code'] ?? null,
            'coupon_scope' => $data['coupon_scope'] ?? null,
            'renewal_discount_percent' => isset(
                $data['renewal_discount_percent'],
            )
                ? (float) $data['renewal_discount_percent']
                : null,
            'renewal_discount_credits' => isset(
                $data['renewal_discount_credits'],
            )
                ? (int) $data['renewal_discount_credits']
                : null,
            'server_uuid' => $data['server_uuid'] ?? null,
            'status' => $data['status'] ?? 'active',
            'next_renewal_at' => $data['next_renewal_at'] ?? null,
            'custom_resources' => $data['custom_resources'] ?? null,
        ]);

        $insertId = (int) $pdo->lastInsertId();

        return $insertId > 0 ? $insertId : null;
    }

    /**
     * Cancelled subscriptions still within their paid billing period (server kept running).
     */
    public static function isPendingCancellation(array $subscription): bool
    {
        if (($subscription['status'] ?? '') !== 'cancelled') {
            return false;
        }

        $nextRenewal = $subscription['next_renewal_at'] ?? null;
        if ($nextRenewal === null || $nextRenewal === '') {
            return false;
        }

        return strtotime((string) $nextRenewal) > time();
    }

    /**
     * Get cancelled subscriptions whose next_renewal_at has passed and still have a server_uuid.
     * These are subscriptions the user cancelled — server was kept running until expiry.
     */
    public static function getDueCancellation(): array
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, p.name as plan_name, p.billing_period_days, p.price_credits, p.tax_rate_percent, p.extra_charge_percent, p.extra_charge_name, p.slider_config
             FROM ' .
                self::$table .
                ' s
             LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
             WHERE s.status = \'cancelled\'
               AND s.server_uuid IS NOT NULL
               AND s.server_uuid != \'\'
               AND s.suspended_at IS NULL
               AND (s.next_renewal_at IS NULL OR s.next_renewal_at <= NOW())
             ORDER BY s.next_renewal_at ASC',
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all subscriptions with a given status (for cron termination logic).
     */
    public static function getByStatus(string $status): array
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, p.name as plan_name, p.price_credits, p.billing_period_days, p.tax_rate_percent, p.extra_charge_percent, p.extra_charge_name, p.slider_config
             FROM ' .
                self::$table .
                ' s
             LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
             WHERE s.status = :status
             ORDER BY s.suspended_at ASC',
        );
        $stmt->execute(['status' => $status]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function update(int $id, array $data): bool
    {
        $pdo = Database::getPdoConnection();
        $allowed = [
            'status',
            'plan_id',
            'coupon_code_id',
            'coupon_code',
            'coupon_scope',
            'renewal_discount_percent',
            'renewal_discount_credits',
            'server_uuid',
            'next_renewal_at',
            'suspended_at',
            'grace_started_at',
            'cancelled_at',
            'server_suspend_sync',
            'custom_resources',
        ];
        $sets = [];
        $params = ['id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "`{$field}` = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($sets)) {
            return true;
        }

        $stmt = $pdo->prepare(
            'UPDATE ' .
                self::$table .
                ' SET ' .
                implode(', ', $sets) .
                ' WHERE id = :id',
        );

        return $stmt->execute($params);
    }

    /**
     * Record credits returned to the subscriber by an admin refund (increments total, bumps last-refund time).
     */
    public static function recordAdminRefund(int $id, int $amount): bool
    {
        if ($amount < 1) {
            return false;
        }
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'UPDATE ' .
                self::$table .
                '
             SET admin_credits_refunded_total = admin_credits_refunded_total + :amount,
                 admin_refunded_at = NOW()
             WHERE id = :id',
        );

        return $stmt->execute(['id' => $id, 'amount' => $amount])
            && $stmt->rowCount() > 0;
    }

    public static function cancel(int $id, int $userId, bool $atPeriodEnd): bool
    {
        return self::applyCancellation($id, $atPeriodEnd, $userId);
    }

    /**
     * Mark a subscription as cancelled. End-of-term keeps status cancelled until renewal date;
     * immediate cancel moves straight to suspended for the termination lifecycle.
     */
    public static function applyCancellation(
        int $id,
        bool $atPeriodEnd,
        ?int $userId = null,
    ): bool {
        $pdo = Database::getPdoConnection();
        $userClause = $userId !== null ? ' AND user_id = :user_id' : '';
        if ($atPeriodEnd) {
            $sql =
                'UPDATE ' .
                self::$table .
                " SET status = 'cancelled', cancelled_at = NOW()
             WHERE id = :id{$userClause} AND status NOT IN ('cancelled','expired')";
        } else {
            $sql =
                'UPDATE ' .
                self::$table .
                " SET status = 'suspended', cancelled_at = NOW(), suspended_at = NOW(), server_suspend_sync = 0
             WHERE id = :id{$userClause} AND status NOT IN ('cancelled','expired')";
        }

        $stmt = $pdo->prepare($sql);
        $params = ['id' => $id];
        if ($userId !== null) {
            $params['user_id'] = $userId;
        }

        return $stmt->execute($params) && $stmt->rowCount() > 0;
    }

    public static function countByStatus(): array
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->query(
            'SELECT status, COUNT(*) as count FROM ' .
                self::$table .
                ' GROUP BY status',
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['count'];
        }

        return $result;
    }
}
