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

use App\App;
use App\Addons\billingcore\Helpers\BillingHelper;
use App\Addons\billingcore\Helpers\CurrencyHelper;

/**
 * Thin wrapper around billingcore's BillingHelper to generate
 * invoices for billingplans purchase and renewal events.
 */
class InvoiceHelper
{
    /**
     * Create a "paid" invoice for an initial plan purchase.
     *
     * @param int $userId The purchasing user's ID
     * @param int $planId The plan ID
     * @param string $planName Human-readable plan name
     * @param int $subscriptionId The new subscription ID
     * @param array<string,mixed> $chargeBreakdown Charge breakdown from Plan::calculateChargeBreakdown()
     * @param int $periodDays Billing period length
     */
    public static function createPurchaseInvoice(
        int $userId,
        int $planId,
        string $planName,
        int $subscriptionId,
        array $chargeBreakdown,
        int $periodDays,
    ): ?array {
        if (!SettingsHelper::getGenerateInvoices()) {
            return null;
        }

        try {
            $currency = CurrencyHelper::getDefaultCurrency();
            $currencyCode = $currency['code'] ?? 'EUR';

            $periodLabel = self::periodLabel($periodDays);
            $baseCredits = (int) ($chargeBreakdown['base_credits'] ?? 0);
            $taxCredits = (int) ($chargeBreakdown['tax_credits'] ?? 0);
            $taxRatePercent = (float) ($chargeBreakdown['tax_rate_percent'] ?? 0);
            $extraChargeCredits = (int) ($chargeBreakdown['extra_charge_credits'] ?? 0);
            $extraChargePercent = (float) ($chargeBreakdown['extra_charge_percent'] ?? 0);
            $extraChargeName = (string) ($chargeBreakdown['extra_charge_name'] ?? 'Additional charge');
            $totalCredits = (int) ($chargeBreakdown['total_credits'] ?? $baseCredits);

            $invoiceData = [
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
                'due_date' => date('Y-m-d'),
                'currency_code' => $currencyCode,
                'notes' => "Plan purchase for subscription #$subscriptionId. Amount is denominated in credits, not real currency.",
            ];

            $itemsData = [
                [
                    'description' => "Plan: $planName — Initial Purchase ($periodLabel)",
                    'quantity' => 1.00,
                    'unit_price' => (float) $baseCredits,
                    'total' => (float) $baseCredits,
                ],
            ];
            if ($taxCredits > 0) {
                $itemsData[] = [
                    'description' => "Tax ({$taxRatePercent}%)",
                    'quantity' => 1.00,
                    'unit_price' => (float) $taxCredits,
                    'total' => (float) $taxCredits,
                ];
            }
            if ($extraChargeCredits > 0) {
                $itemsData[] = [
                    'description' => "{$extraChargeName} ({$extraChargePercent}%)",
                    'quantity' => 1.00,
                    'unit_price' => (float) $extraChargeCredits,
                    'total' => (float) $extraChargeCredits,
                ];
            }
            $invoiceData['notes'] = "Plan purchase for subscription #$subscriptionId. Amount is denominated in credits, not real currency. Total charged: {$totalCredits} credits.";

            return BillingHelper::createInvoiceWithItems($userId, $invoiceData, $itemsData);
        } catch (\Throwable $e) {
            App::getInstance(false, true)->getLogger()->error(
                "BillingPlans InvoiceHelper: Failed to create purchase invoice for user #$userId plan #$planId: " . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Create a "paid" invoice for an automatic subscription renewal.
     *
     * @param int $userId The user's ID
     * @param int $subscriptionId The subscription ID
     * @param string $planName Human-readable plan name
     * @param array<string,mixed> $chargeBreakdown Charge breakdown from Plan::calculateChargeBreakdown()
     * @param int $periodDays Billing period length
     * @param string $nextRenewal Next renewal date string
     */
    public static function createRenewalInvoice(
        int $userId,
        int $subscriptionId,
        string $planName,
        array $chargeBreakdown,
        int $periodDays,
        string $nextRenewal,
    ): ?array {
        if (!SettingsHelper::getGenerateInvoices()) {
            return null;
        }

        try {
            $currency = CurrencyHelper::getDefaultCurrency();
            $currencyCode = $currency['code'] ?? 'EUR';

            $periodLabel = self::periodLabel($periodDays);
            $baseCredits = (int) ($chargeBreakdown['base_credits'] ?? 0);
            $taxCredits = (int) ($chargeBreakdown['tax_credits'] ?? 0);
            $taxRatePercent = (float) ($chargeBreakdown['tax_rate_percent'] ?? 0);
            $extraChargeCredits = (int) ($chargeBreakdown['extra_charge_credits'] ?? 0);
            $extraChargePercent = (float) ($chargeBreakdown['extra_charge_percent'] ?? 0);
            $extraChargeName = (string) ($chargeBreakdown['extra_charge_name'] ?? 'Additional charge');
            $totalCredits = (int) ($chargeBreakdown['total_credits'] ?? $baseCredits);

            $invoiceData = [
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
                'due_date' => date('Y-m-d'),
                'currency_code' => $currencyCode,
                'notes' => "Automatic renewal for subscription #$subscriptionId. Next renewal: $nextRenewal. Amount is denominated in credits, not real currency.",
            ];

            $itemsData = [
                [
                    'description' => "Plan: $planName — Renewal ($periodLabel)",
                    'quantity' => 1.00,
                    'unit_price' => (float) $baseCredits,
                    'total' => (float) $baseCredits,
                ],
            ];
            if ($taxCredits > 0) {
                $itemsData[] = [
                    'description' => "Tax ({$taxRatePercent}%)",
                    'quantity' => 1.00,
                    'unit_price' => (float) $taxCredits,
                    'total' => (float) $taxCredits,
                ];
            }
            if ($extraChargeCredits > 0) {
                $itemsData[] = [
                    'description' => "{$extraChargeName} ({$extraChargePercent}%)",
                    'quantity' => 1.00,
                    'unit_price' => (float) $extraChargeCredits,
                    'total' => (float) $extraChargeCredits,
                ];
            }
            $invoiceData['notes'] = "Automatic renewal for subscription #$subscriptionId. Next renewal: $nextRenewal. Amount is denominated in credits, not real currency. Total charged: {$totalCredits} credits.";

            return BillingHelper::createInvoiceWithItems($userId, $invoiceData, $itemsData);
        } catch (\Throwable $e) {
            App::getInstance(false, true)->getLogger()->error(
                "BillingPlans InvoiceHelper: Failed to create renewal invoice for user #$userId subscription #$subscriptionId: " . $e->getMessage()
            );

            return null;
        }
    }

    private static function periodLabel(int $days): string
    {
        $map = [
            1 => 'Daily', 7 => 'Weekly', 14 => 'Bi-Weekly',
            30 => 'Monthly', 90 => 'Quarterly', 180 => 'Semi-Annual', 365 => 'Annual',
        ];

        return $map[$days] ?? "Every {$days} days";
    }
}
