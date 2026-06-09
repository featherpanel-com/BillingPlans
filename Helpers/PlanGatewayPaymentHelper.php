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

namespace App\Addons\billingplans\Helpers;

use App\Plugins\PluginSettings;
use App\Addons\billingplans\Chat\Plan;
use App\Addons\billingcore\Helpers\CurrencyHelper;

class PlanGatewayPaymentHelper
{
    /** @var list<string> */
    public const SUPPORTED_GATEWAYS = ['stripe', 'paypal', 'razorpay'];

    public static function isGatewayEnabled(string $gateway): bool
    {
        $gateway = strtolower(trim($gateway));
        if (!in_array($gateway, self::SUPPORTED_GATEWAYS, true)) {
            return false;
        }

        $enabled = PluginSettings::getSetting('billing' . $gateway, 'enabled');

        return $enabled === 'true' || $enabled === '1';
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function listEnabledGateways(): array
    {
        $out = [];
        foreach (self::SUPPORTED_GATEWAYS as $gw) {
            if (self::isGatewayEnabled($gw)) {
                $out[] = [
                    'id' => $gw,
                    'label' => ucfirst($gw),
                ];
            }
        }

        return $out;
    }

    /**
     * Convert plan charge (credits) to currency minor units (cents).
     *
     * @return array{amount_cents: int, currency_code: string, charge_credits: int}
     */
    public static function planChargeToMoney(int $planId, int $chargeCredits): array
    {
        $currency = CurrencyHelper::getDefaultCurrency();
        $currencyCode = $currency['code'] ?? 'USD';
        $creditsPerUnit = self::getCreditsPerCurrencyUnit('billingstripe');
        if ($chargeCredits <= 0 || $creditsPerUnit <= 0) {
            return ['amount_cents' => 0, 'currency_code' => $currencyCode, 'charge_credits' => $chargeCredits];
        }
        $amount = $chargeCredits / $creditsPerUnit;
        $amountCents = (int) max(50, round($amount * 100));

        return [
            'amount_cents' => $amountCents,
            'currency_code' => $currencyCode,
            'charge_credits' => $chargeCredits,
        ];
    }

    public static function getCreditsPerCurrencyUnit(string $gatewayPluginId = 'billingstripe'): int
    {
        $override = PluginSettings::getSetting($gatewayPluginId, 'credits_per_currency');
        if ($override !== null && $override !== '' && (float) $override > 0) {
            return (int) round((float) $override);
        }

        $mode = CurrencyHelper::getCreditsMode();
        if ($mode === 'currency') {
            return 100;
        }

        $tokensPerCurrency = (float) (PluginSettings::getSetting('billingcore', 'tokens_per_currency') ?? 1);

        return (int) round(max(1.0, $tokensPerCurrency));
    }

    /**
     * Map billing_period_days to Stripe-style recurring interval.
     *
     * @return array{interval: string, interval_count: int}
     */
    public static function billingPeriodToInterval(int $billingPeriodDays): array
    {
        $days = max(1, $billingPeriodDays);
        if ($days >= 365) {
            return ['interval' => 'year', 'interval_count' => max(1, (int) round($days / 365))];
        }
        if ($days >= 28 && $days <= 31) {
            return ['interval' => 'month', 'interval_count' => 1];
        }
        if ($days === 7) {
            return ['interval' => 'week', 'interval_count' => 1];
        }
        if ($days % 7 === 0 && $days < 28) {
            return ['interval' => 'week', 'interval_count' => (int) ($days / 7)];
        }

        return ['interval' => 'day', 'interval_count' => $days];
    }

    /**
     * @return array{amount_cents: int, currency_code: string, charge_credits: int, plan: array<string, mixed>}
     */
    public static function resolvePlanCheckoutAmount(int $planId, ?string $couponCode = null, int $userId = 0): array
    {
        $plan = Plan::getById($planId);
        if ($plan === null) {
            throw new \InvalidArgumentException('Plan not found');
        }
        $breakdown = Plan::calculateChargeBreakdown($plan);
        $chargeCredits = (int) $breakdown['total_credits'];
        if ($couponCode !== null && $couponCode !== '' && $userId > 0) {
            // Coupon discounts for gateway checkout use same initial charge as credits path (simplified: full breakdown)
        }
        $money = self::planChargeToMoney($planId, $chargeCredits);

        return [
            'amount_cents' => $money['amount_cents'],
            'currency_code' => $money['currency_code'],
            'charge_credits' => $money['charge_credits'],
            'plan' => $plan,
        ];
    }
}
