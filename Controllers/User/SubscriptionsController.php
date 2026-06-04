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

use App\App;
use App\Chat\Server;
use App\Chat\Activity;
use App\Helpers\ApiResponse;
use OpenApi\Attributes as OA;
use App\CloudFlare\CloudFlareRealIP;
use App\Addons\billingplans\Chat\Plan;
use Symfony\Component\HttpFoundation\Request;
use App\Addons\billingplans\Chat\Subscription;
use Symfony\Component\HttpFoundation\Response;
use App\Addons\billingcore\Helpers\CreditsHelper;
use App\Addons\billingplans\Helpers\SettingsHelper;

#[
    OA\Tag(
        name: "User - Billing Plans Subscriptions",
        description: "Manage your subscriptions",
    ),
]
class SubscriptionsController
{
    #[
        OA\Get(
            path: "/api/user/billingplans/subscriptions",
            summary: "Get my subscriptions",
            tags: ["User - Billing Plans Subscriptions"],
            responses: [
                new OA\Response(
                    response: 200,
                    description: "Subscriptions retrieved successfully",
                ),
            ],
        ),
    ]
    public function list(Request $request): Response
    {
        $user = $request->get("user");
        $userId = (int) $user["id"];
        $subscriptions = Subscription::getByUserId($userId);

        foreach ($subscriptions as &$sub) {
            $sub["billing_period_label"] = Plan::getBillingPeriodLabel(
                (int) ($sub["billing_period_days"] ?? 30),
            );
            if (
                isset($sub["slider_config"]) &&
                is_string($sub["slider_config"])
            ) {
                $sub["slider_config"] = json_decode(
                    $sub["slider_config"],
                    true,
                );
            }
            if (
                isset($sub["custom_resources"]) &&
                is_string($sub["custom_resources"])
            ) {
                $sub["custom_resources"] = json_decode(
                    $sub["custom_resources"],
                    true,
                );
            }
            $breakdown = Plan::calculateChargeBreakdown($sub);
            $sub["base_credits"] = (int) $breakdown["base_credits"];
            $sub["tax_credits"] = (int) $breakdown["tax_credits"];
            $sub["extra_charge_credits"] =
                (int) $breakdown["extra_charge_credits"];
            $sub["total_credits"] = (int) $breakdown["total_credits"];
            $sub["allowed_upgrade_plan_ids"] = Plan::decodeIds(
                $sub["allowed_upgrade_plan_ids"] ?? null,
            );
            $sub["allowed_downgrade_plan_ids"] = Plan::decodeIds(
                $sub["allowed_downgrade_plan_ids"] ?? null,
            );
        }

        return ApiResponse::success(
            [
                "data" => $subscriptions,
                "user_credits" => CreditsHelper::getUserCredits($userId),
            ],
            "Subscriptions retrieved successfully",
            200,
        );
    }

    #[
        OA\Get(
            path: "/api/user/billingplans/subscriptions/{subscriptionId}",
            summary: "Get a subscription",
            tags: ["User - Billing Plans Subscriptions"],
            parameters: [
                new OA\Parameter(
                    name: "subscriptionId",
                    in: "path",
                    required: true,
                    schema: new OA\Schema(type: "integer"),
                ),
            ],
            responses: [
                new OA\Response(
                    response: 200,
                    description: "Subscription retrieved successfully",
                ),
                new OA\Response(
                    response: 404,
                    description: "Subscription not found",
                ),
            ],
        ),
    ]
    public function get(Request $request, int $subscriptionId): Response
    {
        $user = $request->get("user");
        $subscription = Subscription::getById($subscriptionId);

        if (
            $subscription === null ||
            (int) $subscription["user_id"] !== (int) $user["id"]
        ) {
            return ApiResponse::error(
                "Subscription not found",
                "SUBSCRIPTION_NOT_FOUND",
                404,
            );
        }

        $subscription["billing_period_label"] = Plan::getBillingPeriodLabel(
            (int) ($subscription["billing_period_days"] ?? 30),
        );
        if (
            isset($subscription["slider_config"]) &&
            is_string($subscription["slider_config"])
        ) {
            $subscription["slider_config"] = json_decode(
                $subscription["slider_config"],
                true,
            );
        }
        if (
            isset($subscription["custom_resources"]) &&
            is_string($subscription["custom_resources"])
        ) {
            $subscription["custom_resources"] = json_decode(
                $subscription["custom_resources"],
                true,
            );
        }
        $breakdown = Plan::calculateChargeBreakdown($subscription);
        $subscription["base_credits"] = (int) $breakdown["base_credits"];
        $subscription["tax_credits"] = (int) $breakdown["tax_credits"];
        $subscription["extra_charge_credits"] =
            (int) $breakdown["extra_charge_credits"];
        $subscription["total_credits"] = (int) $breakdown["total_credits"];
        $subscription["allowed_upgrade_plan_ids"] = Plan::decodeIds(
            $subscription["allowed_upgrade_plan_ids"] ?? null,
        );
        $subscription["allowed_downgrade_plan_ids"] = Plan::decodeIds(
            $subscription["allowed_downgrade_plan_ids"] ?? null,
        );

        return ApiResponse::success(
            $subscription,
            "Subscription retrieved successfully",
            200,
        );
    }

    #[
        OA\Delete(
            path: "/api/user/billingplans/subscriptions/{subscriptionId}",
            summary: "Cancel a subscription",
            description: "Marks the subscription as cancelled. No refund is issued. The subscription remains active until the current period ends.",
            tags: ["User - Billing Plans Subscriptions"],
            parameters: [
                new OA\Parameter(
                    name: "subscriptionId",
                    in: "path",
                    required: true,
                    schema: new OA\Schema(type: "integer"),
                ),
            ],
            responses: [
                new OA\Response(
                    response: 200,
                    description: "Subscription cancelled successfully",
                ),
                new OA\Response(
                    response: 400,
                    description: "Subscription cannot be cancelled",
                ),
                new OA\Response(
                    response: 404,
                    description: "Subscription not found",
                ),
            ],
        ),
    ]
    public function cancel(Request $request, int $subscriptionId): Response
    {
        if (!SettingsHelper::getAllowUserCancellation()) {
            return ApiResponse::error(
                "User cancellation is disabled by the administrator",
                "CANCELLATION_DISABLED",
                403,
            );
        }

        $user = $request->get("user");
        $userId = (int) $user["id"];
        $subscription = Subscription::getById($subscriptionId);

        if (
            $subscription === null ||
            (int) $subscription["user_id"] !== $userId
        ) {
            return ApiResponse::error(
                "Subscription not found",
                "SUBSCRIPTION_NOT_FOUND",
                404,
            );
        }

        if (in_array($subscription["status"], ["cancelled", "expired"], true)) {
            return ApiResponse::error(
                "Subscription is already cancelled or expired",
                "ALREADY_CANCELLED",
                400,
            );
        }

        if (!Subscription::cancel($subscriptionId, $userId)) {
            return ApiResponse::error(
                "Failed to cancel subscription",
                "CANCEL_SUBSCRIPTION_FAILED",
                500,
            );
        }

        $serverUuid = $subscription["server_uuid"] ?? null;
        if ($serverUuid && SettingsHelper::getSuspendServers()) {
            try {
                $server = Server::getServerByUuid($serverUuid);
                if ($server) {
                    Server::updateServerById((int) $server["id"], [
                        "suspended" => 1,
                    ]);
                }
            } catch (\Exception $e) {
                $app = App::getInstance(false, true);
                $app->getLogger()->error(
                    "BillingPlans: Failed to suspend server $serverUuid on user cancel of subscription #$subscriptionId: " .
                        $e->getMessage(),
                );
            }
        }

        Activity::createActivity([
            "user_uuid" => $user["uuid"] ?? null,
            "name" => "billingplans_cancel_subscription",
            "context" =>
                "User cancelled subscription #$subscriptionId (plan: {$subscription["plan_name"]})" .
                ($serverUuid ? " — server $serverUuid suspended" : ""),
            "ip_address" => CloudFlareRealIP::getRealIP(),
        ]);

        return ApiResponse::success(
            [],
            "Subscription cancelled successfully",
            200,
        );
    }

    public function changePlan(Request $request, int $subscriptionId): Response
    {
        $user = $request->get("user");
        $userId = (int) $user["id"];
        $subscription = Subscription::getById($subscriptionId);
        if (
            $subscription === null ||
            (int) $subscription["user_id"] !== $userId
        ) {
            return ApiResponse::error(
                "Subscription not found",
                "SUBSCRIPTION_NOT_FOUND",
                404,
            );
        }
        if (
            !in_array(
                (string) $subscription["status"],
                ["active", "suspended"],
                true,
            )
        ) {
            return ApiResponse::error(
                "Only active or suspended subscriptions can be changed",
                "INVALID_SUBSCRIPTION_STATUS",
                400,
            );
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return ApiResponse::error("Invalid JSON", "INVALID_JSON", 400);
        }

        $newPlanId = (int) ($data["plan_id"] ?? 0);
        if ($newPlanId <= 0) {
            return ApiResponse::error(
                "Invalid plan ID",
                "INVALID_PLAN_ID",
                400,
            );
        }
        $currentPlanId = (int) ($subscription["plan_id"] ?? 0);
        if ($newPlanId === $currentPlanId) {
            return ApiResponse::error(
                "Subscription is already on this plan",
                "PLAN_UNCHANGED",
                400,
            );
        }

        $newPlan = Plan::getById($newPlanId);
        if ($newPlan === null || (int) ($newPlan["is_active"] ?? 0) !== 1) {
            return ApiResponse::error(
                "Target plan not found or inactive",
                "PLAN_NOT_FOUND",
                404,
            );
        }

        $oldPlan = Plan::getById($currentPlanId);
        if ($oldPlan === null) {
            return ApiResponse::error(
                "Current plan not found",
                "CURRENT_PLAN_NOT_FOUND",
                404,
            );
        }

        $maxSubscriptions =
            isset($newPlan["max_subscriptions"]) &&
            $newPlan["max_subscriptions"] !== null
                ? (int) $newPlan["max_subscriptions"]
                : null;
        if ($maxSubscriptions !== null && $maxSubscriptions > 0) {
            $activeCount = Plan::getActiveSubscriptionCount($newPlanId);
            if ($activeCount >= $maxSubscriptions) {
                return ApiResponse::error(
                    "The selected plan is sold out right now.",
                    "PLAN_SOLD_OUT",
                    400,
                );
            }
        }

        $oldBreakdown = Plan::calculateChargeBreakdown($oldPlan);
        $newBreakdown = Plan::calculateChargeBreakdown($newPlan);
        $oldTotal = (int) ($oldBreakdown["total_credits"] ?? 0);
        $newTotal = (int) ($newBreakdown["total_credits"] ?? 0);
        $delta = $newTotal - $oldTotal;

        $allowedUpgradeIds = Plan::decodeIds(
            $oldPlan["allowed_upgrade_plan_ids"] ?? null,
        );
        $allowedDowngradeIds = Plan::decodeIds(
            $oldPlan["allowed_downgrade_plan_ids"] ?? null,
        );
        $hasExplicitRules =
            !empty($allowedUpgradeIds) || !empty($allowedDowngradeIds);
        if ($hasExplicitRules) {
            if ($delta > 0 && !in_array($newPlanId, $allowedUpgradeIds, true)) {
                return ApiResponse::error(
                    "This plan cannot be upgraded to the selected target.",
                    "UPGRADE_NOT_ALLOWED",
                    403,
                );
            }
            if (
                $delta < 0 &&
                !in_array($newPlanId, $allowedDowngradeIds, true)
            ) {
                return ApiResponse::error(
                    "This plan cannot be downgraded to the selected target.",
                    "DOWNGRADE_NOT_ALLOWED",
                    403,
                );
            }
            if (
                $delta === 0 &&
                !in_array(
                    $newPlanId,
                    array_values(
                        array_unique(
                            array_merge(
                                $allowedUpgradeIds,
                                $allowedDowngradeIds,
                            ),
                        ),
                    ),
                    true,
                )
            ) {
                return ApiResponse::error(
                    "Switching to the selected plan is not allowed.",
                    "PLAN_SWITCH_NOT_ALLOWED",
                    403,
                );
            }
        }

        if ($delta > 0) {
            $credits = CreditsHelper::getUserCredits($userId);
            if ($credits < $delta) {
                return ApiResponse::error(
                    "Insufficient credits. You need {$delta} credits to upgrade.",
                    "INSUFFICIENT_CREDITS",
                    400,
                );
            }
            if (!CreditsHelper::removeUserCredits($userId, $delta)) {
                return ApiResponse::error(
                    "Failed to deduct credits.",
                    "CREDITS_DEDUCTION_FAILED",
                    500,
                );
            }
        } elseif ($delta < 0) {
            $refund = abs($delta);
            if (!CreditsHelper::addUserCredits($userId, $refund)) {
                return ApiResponse::error(
                    "Failed to refund credits.",
                    "CREDITS_REFUND_FAILED",
                    500,
                );
            }
        }

        $nextRenewal = date(
            "Y-m-d H:i:s",
            strtotime(
                "+" .
                    max(1, (int) ($newPlan["billing_period_days"] ?? 30)) .
                    " days",
            ),
        );
        if (
            !Subscription::update($subscriptionId, [
                "plan_id" => $newPlanId,
                "next_renewal_at" => $nextRenewal,
            ])
        ) {
            // Best effort rollback for credit movement
            if ($delta > 0) {
                CreditsHelper::addUserCredits($userId, $delta);
            } elseif ($delta < 0) {
                CreditsHelper::removeUserCredits($userId, abs($delta));
            }

            return ApiResponse::error(
                "Failed to change subscription plan",
                "PLAN_CHANGE_FAILED",
                500,
            );
        }

        Activity::createActivity([
            "user_uuid" => $user["uuid"] ?? null,
            "name" => "billingplans_change_subscription_plan",
            "context" => "User changed subscription #{$subscriptionId} from plan #{$currentPlanId} ({$oldPlan["name"]}) to #{$newPlanId} ({$newPlan["name"]}); delta {$delta} credits.",
            "ip_address" => CloudFlareRealIP::getRealIP(),
        ]);

        $updated = Subscription::getById($subscriptionId);

        return ApiResponse::success(
            [
                "subscription" => $updated,
                "credits_delta" => $delta,
                "new_credits_balance" => CreditsHelper::getUserCredits($userId),
                "next_renewal_at" => $nextRenewal,
            ],
            "Subscription plan changed successfully",
            200,
        );
    }
}
