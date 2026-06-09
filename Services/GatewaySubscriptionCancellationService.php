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

/**
 * Cancels auto-renewal at the payment gateway when a panel subscription is cancelled.
 */
class GatewaySubscriptionCancellationService
{
    /**
     * @param array<string, mixed> $subscription
     */
    public static function cancelAtGateway(array $subscription): void
    {
        if (empty($subscription['auto_renew_via_gateway']) || empty($subscription['gateway_subscription_id'])) {
            return;
        }

        $gateway = (string) ($subscription['payment_gateway'] ?? '');
        $gatewaySubId = (string) $subscription['gateway_subscription_id'];

        try {
            $ok = match ($gateway) {
                'stripe' => class_exists(\App\Addons\billingstripe\Services\StripeRecurringService::class)
                    && \App\Addons\billingstripe\Services\StripeRecurringService::cancelSubscription($gatewaySubId),
                'paypal' => class_exists(\App\Addons\billingpaypal\Services\PayPalRecurringService::class)
                    && \App\Addons\billingpaypal\Services\PayPalRecurringService::cancelSubscription($gatewaySubId),
                'razorpay' => class_exists(\App\Addons\billingrazorpay\Services\RazorpayRecurringService::class)
                    && \App\Addons\billingrazorpay\Services\RazorpayRecurringService::cancelSubscription($gatewaySubId),
                default => false,
            };

            if (!$ok) {
                App::getInstance(true)->getLogger()->warning(
                    "BillingPlans: Gateway cancel may have failed for {$gateway} subscription {$gatewaySubId}"
                );
            }
        } catch (\Throwable $e) {
            App::getInstance(true)->getLogger()->error(
                'BillingPlans: Gateway subscription cancel error: ' . $e->getMessage()
            );
        }
    }
}
