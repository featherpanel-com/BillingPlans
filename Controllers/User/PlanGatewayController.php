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

namespace App\Addons\billingplans\Controllers\User;

use App\Helpers\ApiResponse;
use App\Addons\billingplans\Chat\Plan;
use Symfony\Component\HttpFoundation\Request;
use App\Addons\billingplans\Chat\Subscription;
use Symfony\Component\HttpFoundation\Response;
use App\Addons\billingplans\Chat\GatewayCheckout;
use App\Addons\billingplans\Helpers\SettingsHelper;
use App\Addons\billingplans\Helpers\PlanGatewayPaymentHelper;

class PlanGatewayController
{
    public function listGateways(Request $request): Response
    {
        return ApiResponse::success([
            'gateways' => PlanGatewayPaymentHelper::listEnabledGateways(),
            'oxapay_recurring_supported' => false,
        ], 'Payment gateways retrieved', 200);
    }

    /**
     * Start recurring (gateway) subscription checkout for a plan.
     * Body: gateway (stripe|paypal|razorpay), plus same fields as credit subscribe.
     */
    public function startRecurringCheckout(Request $request, int $planId): Response
    {
        $user = $request->get('user');
        $userId = (int) ($user['id'] ?? 0);
        $input = json_decode($request->getContent(), true) ?: [];

        $gateway = strtolower(trim((string) ($input['gateway'] ?? '')));
        if ($gateway === 'oxapay') {
            return ApiResponse::error(
                'Automatic recurring billing is not available with OxaPay. Use credits or another payment method.',
                'OXAPAY_RECURRING_UNSUPPORTED',
                400
            );
        }

        if (!in_array($gateway, PlanGatewayPaymentHelper::SUPPORTED_GATEWAYS, true)) {
            return ApiResponse::error('Unsupported payment gateway', 'GATEWAY_UNSUPPORTED', 400);
        }

        if (!PlanGatewayPaymentHelper::isGatewayEnabled($gateway)) {
            return ApiResponse::error(ucfirst($gateway) . ' payments are disabled', 'GATEWAY_DISABLED', 400);
        }

        $plan = Plan::getById($planId);
        if ($plan === null || (int) ($plan['is_active'] ?? 0) !== 1) {
            return ApiResponse::error('Plan not found', 'PLAN_NOT_FOUND', 404);
        }

        $maxPlansPerUser = SettingsHelper::getMaxPlansPerUser();
        if ($maxPlansPerUser > 0) {
            $userActivePlanCount = count(Subscription::getActiveByUserId($userId));
            if ($userActivePlanCount >= $maxPlansPerUser) {
                return ApiResponse::error(
                    'You have reached the maximum number of active plan subscriptions (' .
                        $maxPlansPerUser .
                        ').',
                    'MAX_PLANS_REACHED',
                    400,
                );
            }
        }

        try {
            $amounts = PlanGatewayPaymentHelper::resolvePlanCheckoutAmount(
                $planId,
                isset($input['coupon_code']) ? (string) $input['coupon_code'] : null,
                $userId
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'PLAN_NOT_FOUND', 404);
        }

        if ($amounts['amount_cents'] < 50) {
            return ApiResponse::error('Plan amount is too low for card payments', 'AMOUNT_TOO_LOW', 400);
        }

        unset($input['gateway']);
        $checkoutId = GatewayCheckout::create([
            'user_id' => $userId,
            'plan_id' => $planId,
            'gateway' => $gateway,
            'amount_cents' => $amounts['amount_cents'],
            'currency_code' => $amounts['currency_code'],
            'charge_credits' => $amounts['charge_credits'],
            'subscribe_payload' => $input,
        ]);

        if ($checkoutId === null) {
            return ApiResponse::error('Failed to create checkout', 'CHECKOUT_CREATE_FAILED', 500);
        }

        $handlerClass = match ($gateway) {
            'stripe' => \App\Addons\billingstripe\Services\StripeRecurringService::class,
            'paypal' => \App\Addons\billingpaypal\Services\PayPalRecurringService::class,
            'razorpay' => \App\Addons\billingrazorpay\Services\RazorpayRecurringService::class,
            default => null,
        };

        if ($handlerClass === null || !class_exists($handlerClass)) {
            return ApiResponse::error('Gateway handler not installed', 'GATEWAY_NOT_INSTALLED', 503);
        }

        $result = $handlerClass::createPlanCheckout(
            $checkoutId,
            $user,
            $plan,
            $amounts['amount_cents'],
            $amounts['currency_code']
        );

        if (isset($result['error'])) {
            GatewayCheckout::update($checkoutId, ['status' => 'failed']);

            return ApiResponse::error(
                $result['detail'] ?? 'Could not start payment',
                $result['error'],
                502
            );
        }

        if (!empty($result['gateway_checkout_id'])) {
            GatewayCheckout::update($checkoutId, [
                'gateway_checkout_id' => $result['gateway_checkout_id'],
            ]);
        }

        return ApiResponse::success([
            'checkout_id' => $checkoutId,
            'gateway' => $gateway,
            'checkout_url' => $result['checkout_url'] ?? $result['approve_url'] ?? null,
            'session_id' => $result['session_id'] ?? null,
            'order_id' => $result['order_id'] ?? null,
            'subscription_id' => $result['subscription_id'] ?? null,
            'amount_cents' => $amounts['amount_cents'],
            'currency_code' => $amounts['currency_code'],
        ], 'Recurring checkout started', 200);
    }
}
