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

namespace App\Addons\billingplans\Controllers\Public;

use App\Chat\Realm;
use App\Chat\Spell;
use App\Helpers\ApiResponse;
use App\Addons\billingplans\Chat\Plan;
use App\Addons\billingplans\Chat\Category;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Addons\billingplans\Helpers\SettingsHelper;
use App\Addons\billingplans\Controllers\User\PlansController as UserPlansController;

class PublicPlansController
{
    public function config(Request $request): Response
    {
        return ApiResponse::success(
            [
                'plans_public_enabled' => SettingsHelper::getPlansPublicEnabled(),
            ],
            'Public billing plans config retrieved successfully',
            200,
        );
    }

    public function listPlans(Request $request): Response
    {
        if (!SettingsHelper::getPlansPublicEnabled()) {
            return ApiResponse::error('Public plans page is disabled', 'PLANS_PUBLIC_DISABLED', 403);
        }

        $plans = Plan::getAll(true);
        $planIds = array_map(
            static fn (array $p): int => (int) ($p['id'] ?? 0),
            $plans,
        );

        $userController = new UserPlansController();
        $preloaded = [
            'activeCounts' => $userController->getActiveSubscriptionCountsPublic($planIds),
            'allRealms' => Realm::getAll(null, 500, 0) ?: [],
            'allSpells' => Spell::getAllSpells() ?: [],
            'realmById' => [],
            'spellById' => [],
        ];
        $categoryCache = [];

        foreach ($plans as &$plan) {
            $plan = $userController->hydratePlanPublic(
                $plan,
                0,
                $categoryCache,
                $preloaded,
            );
        }

        return ApiResponse::success(
            [
                'data' => array_values($plans),
                'user_credits' => 0,
                'max_plans_per_user' => SettingsHelper::getMaxPlansPerUser(),
                'user_active_plan_count' => 0,
                'can_subscribe_more' => true,
            ],
            'Plans retrieved successfully',
            200,
        );
    }

    public function listCategories(Request $request): Response
    {
        if (!SettingsHelper::getPlansPublicEnabled()) {
            return ApiResponse::error('Public plans page is disabled', 'PLANS_PUBLIC_DISABLED', 403);
        }

        $categories = Category::getAll(true);

        foreach ($categories as &$cat) {
            $cat['plan_count'] = Category::getPlanCount((int) $cat['id']);
            $cat['is_active'] = (bool) $cat['is_active'];
        }

        $categories = array_values(array_filter($categories, fn ($c) => $c['plan_count'] > 0));

        return ApiResponse::success($categories, 'Categories retrieved successfully', 200);
    }
}
