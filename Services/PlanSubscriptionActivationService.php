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

namespace App\Addons\billingplans\Services;

use App\App;
use App\Chat\User;
use Symfony\Component\HttpFoundation\Request;
use App\Addons\billingplans\Chat\Subscription;
use App\Addons\billingplans\Chat\GatewayCheckout;
use App\Addons\billingplans\Helpers\InvoiceHelper;
use App\Addons\billingplans\Controllers\User\PlansController;

/**
 * Completes a pending gateway checkout and activates the billing plan subscription.
 */
class PlanSubscriptionActivationService
{
    /**
     * @return array{success: bool, subscription_id?: int, error?: string, error_code?: string}
     */
    public static function completeCheckout(
        int $checkoutId,
        ?string $gatewaySubscriptionId = null,
        ?string $gatewayCustomerId = null,
    ): array {
        $checkout = GatewayCheckout::getById($checkoutId);
        if ($checkout === null) {
            return ['success' => false, 'error' => 'Checkout not found', 'error_code' => 'CHECKOUT_NOT_FOUND'];
        }

        if ($checkout['status'] === 'completed' && !empty($checkout['subscription_id'])) {
            return [
                'success' => true,
                'subscription_id' => (int) $checkout['subscription_id'],
            ];
        }

        if ($checkout['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Checkout is not pending', 'error_code' => 'CHECKOUT_INVALID_STATE'];
        }

        $userId = (int) $checkout['user_id'];
        $planId = (int) $checkout['plan_id'];
        $gateway = (string) $checkout['gateway'];
        $user = User::getUserById($userId);
        if ($user === null) {
            GatewayCheckout::update($checkoutId, ['status' => 'failed']);

            return ['success' => false, 'error' => 'User not found', 'error_code' => 'USER_NOT_FOUND'];
        }

        $payload = GatewayCheckout::decodePayload($checkout) ?? [];

        $request = Request::create(
            '/api/user/billingplans/plans/' . $planId . '/subscribe',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload) ?: '{}'
        );
        $request->attributes->set('user', $user);
        $request->attributes->set('_billingplans_skip_initial_charge', true);
        $request->attributes->set('_billingplans_subscription_source', 'gateway:' . $gateway);

        $response = (new PlansController())->subscribe($request, $planId);
        $content = json_decode($response->getContent(), true);
        if (!is_array($content) || empty($content['success'])) {
            $message = is_array($content) ? ($content['message'] ?? $content['error_message'] ?? 'Activation failed') : 'Activation failed';
            GatewayCheckout::update($checkoutId, ['status' => 'failed']);
            App::getInstance(true)->getLogger()->error('Plan gateway activation failed for checkout #' . $checkoutId . ': ' . $message);

            return ['success' => false, 'error' => (string) $message, 'error_code' => 'ACTIVATION_FAILED'];
        }

        $subscription = $content['data']['subscription'] ?? $content['subscription'] ?? null;
        $subscriptionId = is_array($subscription) ? (int) ($subscription['id'] ?? 0) : 0;
        if ($subscriptionId <= 0) {
            GatewayCheckout::update($checkoutId, ['status' => 'failed']);

            return ['success' => false, 'error' => 'Subscription id missing after activation', 'error_code' => 'SUBSCRIPTION_MISSING'];
        }

        Subscription::update($subscriptionId, [
            'payment_gateway' => $gateway,
            'gateway_subscription_id' => $gatewaySubscriptionId,
            'gateway_customer_id' => $gatewayCustomerId,
            'auto_renew_via_gateway' => 1,
        ]);

        $subRow = Subscription::getById($subscriptionId);
        if ($subRow !== null) {
            $breakdown = \App\Addons\billingplans\Chat\Plan::calculateChargeBreakdown($subRow);
            InvoiceHelper::createPurchaseInvoice(
                $userId,
                $planId,
                (string) ($subRow['plan_name'] ?? 'Plan'),
                $subscriptionId,
                $breakdown,
                (int) ($subRow['billing_period_days'] ?? 30)
            );
        }

        GatewayCheckout::update($checkoutId, [
            'status' => 'completed',
            'subscription_id' => $subscriptionId,
            'gateway_subscription_id' => $gatewaySubscriptionId,
            'gateway_customer_id' => $gatewayCustomerId,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        App::getInstance(true)->getLogger()->info(
            "BillingPlans: Gateway checkout #$checkoutId completed → subscription #$subscriptionId ($gateway)"
        );

        return ['success' => true, 'subscription_id' => $subscriptionId];
    }

    /**
     * Extend renewal date after a successful gateway renewal invoice.
     */
    public static function recordGatewayRenewal(
        string $gateway,
        string $gatewaySubscriptionId,
    ): bool {
        $pdo = \App\Chat\Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, p.billing_period_days FROM featherpanel_billingplans_subscriptions s
             LEFT JOIN featherpanel_billingplans_plans p ON s.plan_id = p.id
             WHERE s.payment_gateway = :gateway AND s.gateway_subscription_id = :gid AND s.status = \'active\' LIMIT 1'
        );
        $stmt->execute(['gateway' => $gateway, 'gid' => $gatewaySubscriptionId]);
        $sub = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$sub) {
            return false;
        }

        $periodDays = max(1, (int) ($sub['billing_period_days'] ?? 30));
        $next = date('Y-m-d H:i:s', strtotime('+' . $periodDays . ' days'));
        Subscription::update((int) $sub['id'], [
            'next_renewal_at' => $next,
            'grace_started_at' => null,
            'suspended_at' => null,
            'status' => 'active',
        ]);

        return true;
    }
}
