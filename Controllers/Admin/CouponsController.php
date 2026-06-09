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

namespace App\Addons\billingplans\Controllers\Admin;

use App\Chat\Activity;
use App\Helpers\ApiResponse;
use OpenApi\Attributes as OA;
use App\CloudFlare\CloudFlareRealIP;
use App\Addons\billingplans\Chat\Plan;
use App\Addons\billingredeem\Chat\RedeemCode;
use Symfony\Component\HttpFoundation\Request;
use App\Addons\billingredeem\Chat\RedeemUsage;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Admin - Billing Plan Coupons', description: 'Checkout coupon codes for billing plans')]
class CouponsController
{
    private const REWARD_TYPE = 'billing_plan_coupon';

    #[OA\Get(
        path: '/api/admin/billingplans/coupons',
        summary: 'List plan checkout coupons',
        tags: ['Admin - Billing Plan Coupons'],
        responses: [new OA\Response(response: 200, description: 'Coupons retrieved successfully')]
    )]
    public function list(Request $request): Response
    {
        $unavailable = $this->ensureRedeemAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $search = trim((string) $request->query->get('search', ''));

        $result = RedeemCode::getPaginatedByRewardType(self::REWARD_TYPE, $page, $limit, $search);
        $rows = array_map(fn (array $row) => $this->enrichCouponRow($row), $result['data']);

        return ApiResponse::success([
            'data' => $rows,
            'meta' => [
                'pagination' => [
                    'total' => $result['total'],
                    'count' => count($rows),
                    'per_page' => $limit,
                    'current_page' => $page,
                    'total_pages' => (int) ceil($result['total'] / max(1, $limit)),
                ],
            ],
        ], 'Coupons retrieved successfully', 200);
    }

    #[OA\Get(
        path: '/api/admin/billingplans/coupons/{couponId}',
        summary: 'Get a plan checkout coupon',
        tags: ['Admin - Billing Plan Coupons'],
        responses: [
            new OA\Response(response: 200, description: 'Coupon retrieved successfully'),
            new OA\Response(response: 404, description: 'Coupon not found'),
        ]
    )]
    public function get(Request $request, int $couponId): Response
    {
        $unavailable = $this->ensureRedeemAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $coupon = $this->getCouponOrNull($couponId);
        if ($coupon === null) {
            return ApiResponse::error('Coupon not found', 'COUPON_NOT_FOUND', 404);
        }

        return ApiResponse::success($this->enrichCouponRow($coupon), 'Coupon retrieved successfully', 200);
    }

    #[OA\Post(
        path: '/api/admin/billingplans/coupons',
        summary: 'Create a plan checkout coupon',
        tags: ['Admin - Billing Plan Coupons'],
        responses: [
            new OA\Response(response: 200, description: 'Coupon created successfully'),
            new OA\Response(response: 400, description: 'Invalid request data'),
        ]
    )]
    public function create(Request $request): Response
    {
        $unavailable = $this->ensureRedeemAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $admin = $request->get('user');
        $data = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($data)) {
            return ApiResponse::error('Invalid JSON', 'INVALID_JSON', 400);
        }

        $validation = $this->validateCouponPayload($data);
        if ($validation !== null) {
            return $validation;
        }

        $code = strtoupper(trim((string) $data['code']));
        if (RedeemCode::getByCode($code)) {
            return ApiResponse::error('Coupon code already exists', 'COUPON_EXISTS', 400);
        }

        $created = RedeemCode::create([
            'code' => $code,
            'amount' => 0,
            'max_uses' => max(0, (int) ($data['max_uses'] ?? 0)),
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : null,
            'reward_type' => self::REWARD_TYPE,
            'plan_id' => isset($data['plan_id']) && (int) $data['plan_id'] > 0 ? (int) $data['plan_id'] : null,
            'discount_percent' => (float) ($data['discount_percent'] ?? 0),
            'discount_credits' => (int) ($data['discount_credits'] ?? 0),
            'coupon_scope' => (string) ($data['coupon_scope'] ?? 'initial'),
        ]);

        if (!$created) {
            return ApiResponse::error('Failed to create coupon', 'CREATE_COUPON_FAILED', 500);
        }

        Activity::createActivity([
            'user_uuid' => $admin['uuid'] ?? null,
            'name' => 'billingplans_create_coupon',
            'context' => "Created billing plan coupon {$created['code']}",
            'ip_address' => CloudFlareRealIP::getRealIP(),
        ]);

        return ApiResponse::success($this->enrichCouponRow($created), 'Coupon created successfully', 200);
    }

    #[OA\Patch(
        path: '/api/admin/billingplans/coupons/{couponId}',
        summary: 'Update a plan checkout coupon',
        tags: ['Admin - Billing Plan Coupons'],
        responses: [
            new OA\Response(response: 200, description: 'Coupon updated successfully'),
            new OA\Response(response: 404, description: 'Coupon not found'),
        ]
    )]
    public function update(Request $request, int $couponId): Response
    {
        $unavailable = $this->ensureRedeemAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $admin = $request->get('user');
        $existing = $this->getCouponOrNull($couponId);
        if ($existing === null) {
            return ApiResponse::error('Coupon not found', 'COUPON_NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($data)) {
            return ApiResponse::error('Invalid JSON', 'INVALID_JSON', 400);
        }

        $merged = array_merge($existing, $data);
        $validation = $this->validateCouponPayload($merged, true);
        if ($validation !== null) {
            return $validation;
        }

        if (isset($data['code'])) {
            $newCode = strtoupper(trim((string) $data['code']));
            $other = RedeemCode::getByCode($newCode);
            if ($other && (int) $other['id'] !== $couponId) {
                return ApiResponse::error('Coupon code already exists', 'COUPON_EXISTS', 400);
            }
            $data['code'] = $newCode;
        }

        $updateData = [];
        foreach (['code', 'max_uses', 'expires_at', 'plan_id', 'discount_percent', 'discount_credits', 'coupon_scope'] as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }
        if (array_key_exists('plan_id', $updateData)) {
            $updateData['plan_id'] = (int) ($updateData['plan_id'] ?? 0) > 0
                ? (int) $updateData['plan_id']
                : null;
        }

        if (!RedeemCode::update($couponId, $updateData)) {
            return ApiResponse::error('Failed to update coupon', 'UPDATE_COUPON_FAILED', 500);
        }

        $updated = $this->getCouponOrNull($couponId);
        if ($updated === null) {
            return ApiResponse::error('Coupon not found after update', 'COUPON_NOT_FOUND', 404);
        }

        Activity::createActivity([
            'user_uuid' => $admin['uuid'] ?? null,
            'name' => 'billingplans_update_coupon',
            'context' => "Updated billing plan coupon {$updated['code']} (#$couponId)",
            'ip_address' => CloudFlareRealIP::getRealIP(),
        ]);

        return ApiResponse::success($this->enrichCouponRow($updated), 'Coupon updated successfully', 200);
    }

    #[OA\Delete(
        path: '/api/admin/billingplans/coupons/{couponId}',
        summary: 'Delete a plan checkout coupon',
        tags: ['Admin - Billing Plan Coupons'],
        responses: [
            new OA\Response(response: 200, description: 'Coupon deleted successfully'),
            new OA\Response(response: 404, description: 'Coupon not found'),
        ]
    )]
    public function delete(Request $request, int $couponId): Response
    {
        $unavailable = $this->ensureRedeemAvailable();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $admin = $request->get('user');
        $existing = $this->getCouponOrNull($couponId);
        if ($existing === null) {
            return ApiResponse::error('Coupon not found', 'COUPON_NOT_FOUND', 404);
        }

        if (!RedeemCode::delete($couponId)) {
            return ApiResponse::error('Failed to delete coupon', 'DELETE_COUPON_FAILED', 500);
        }

        Activity::createActivity([
            'user_uuid' => $admin['uuid'] ?? null,
            'name' => 'billingplans_delete_coupon',
            'context' => "Deleted billing plan coupon {$existing['code']} (#$couponId)",
            'ip_address' => CloudFlareRealIP::getRealIP(),
        ]);

        return ApiResponse::success([], 'Coupon deleted successfully', 200);
    }

    private function ensureRedeemAvailable(): ?Response
    {
        if (!class_exists(RedeemCode::class)) {
            return ApiResponse::error(
                'Billing Redeem addon is required for coupon codes. Install and enable billingredeem.',
                'REDEEM_UNAVAILABLE',
                503,
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateCouponPayload(array $data, bool $isUpdate = false): ?Response
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if (!$isUpdate && $code === '') {
            return ApiResponse::error('Coupon code is required', 'CODE_REQUIRED', 400);
        }

        $discountPercent = (float) ($data['discount_percent'] ?? 0);
        $discountCredits = (int) ($data['discount_credits'] ?? 0);
        if ($discountPercent < 0 || $discountPercent > 100) {
            return ApiResponse::error('discount_percent must be between 0 and 100', 'INVALID_DISCOUNT_PERCENT', 400);
        }
        if ($discountCredits < 0) {
            return ApiResponse::error('discount_credits must be >= 0', 'INVALID_DISCOUNT_CREDITS', 400);
        }
        if ($discountPercent <= 0 && $discountCredits <= 0) {
            return ApiResponse::error('Set a percent and/or fixed credit discount', 'MISSING_DISCOUNT', 400);
        }

        $scope = (string) ($data['coupon_scope'] ?? 'initial');
        if (!in_array($scope, ['initial', 'renewal', 'both'], true)) {
            return ApiResponse::error('coupon_scope must be initial, renewal, or both', 'INVALID_COUPON_SCOPE', 400);
        }

        $planId = isset($data['plan_id']) ? (int) $data['plan_id'] : 0;
        if ($planId > 0 && Plan::getById($planId) === null) {
            return ApiResponse::error('Selected plan does not exist', 'INVALID_PLAN_ID', 400);
        }

        if (isset($data['max_uses']) && (int) $data['max_uses'] < 0) {
            return ApiResponse::error('max_uses must be >= 0 (0 = unlimited)', 'INVALID_MAX_USES', 400);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCouponOrNull(int $couponId): ?array
    {
        $coupon = RedeemCode::getById($couponId);
        if (!$coupon || ($coupon['reward_type'] ?? '') !== self::REWARD_TYPE) {
            return null;
        }

        return $coupon;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function enrichCouponRow(array $row): array
    {
        $row['usage_count'] = class_exists(RedeemUsage::class)
            ? RedeemUsage::getCodeUsageCount((int) $row['id'])
            : (int) ($row['uses'] ?? 0);
        $row['is_valid'] = RedeemCode::isValid($row);
        $row['plan_name'] = null;
        if (!empty($row['plan_id'])) {
            $plan = Plan::getById((int) $row['plan_id']);
            $row['plan_name'] = $plan['name'] ?? null;
        }

        return $row;
    }
}
