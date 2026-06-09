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

class GatewayCheckout
{
    private static string $table = 'featherpanel_billingplans_gateway_checkouts';

    public static function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare('SELECT * FROM ' . self::$table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function getByGatewayCheckoutId(string $gateway, string $gatewayCheckoutId): ?array
    {
        $gatewayCheckoutId = trim($gatewayCheckoutId);
        if ($gatewayCheckoutId === '') {
            return null;
        }
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::$table . ' WHERE gateway = :gateway AND gateway_checkout_id = :ref LIMIT 1'
        );
        $stmt->execute(['gateway' => $gateway, 'ref' => $gatewayCheckoutId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function create(array $data): ?int
    {
        $pdo = Database::getPdoConnection();
        $payload = $data['subscribe_payload'] ?? null;
        if (is_array($payload)) {
            $payload = json_encode($payload);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::$table . '
             (user_id, plan_id, gateway, status, amount_cents, currency_code, charge_credits, subscribe_payload, gateway_checkout_id)
             VALUES
             (:user_id, :plan_id, :gateway, :status, :amount_cents, :currency_code, :charge_credits, :subscribe_payload, :gateway_checkout_id)'
        );
        $stmt->execute([
            'user_id' => (int) $data['user_id'],
            'plan_id' => (int) $data['plan_id'],
            'gateway' => (string) $data['gateway'],
            'status' => $data['status'] ?? 'pending',
            'amount_cents' => (int) ($data['amount_cents'] ?? 0),
            'currency_code' => strtoupper((string) ($data['currency_code'] ?? 'USD')),
            'charge_credits' => (int) ($data['charge_credits'] ?? 0),
            'subscribe_payload' => $payload,
            'gateway_checkout_id' => $data['gateway_checkout_id'] ?? null,
        ]);

        $id = (int) $pdo->lastInsertId();

        return $id > 0 ? $id : null;
    }

    public static function update(int $id, array $data): bool
    {
        $pdo = Database::getPdoConnection();
        $allowed = [
            'status',
            'gateway_checkout_id',
            'gateway_subscription_id',
            'gateway_customer_id',
            'subscription_id',
            'completed_at',
        ];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "`{$field}` = :{$field}";
                $params[$field] = $data[$field];
            }
        }
        if ($sets === []) {
            return true;
        }
        $stmt = $pdo->prepare('UPDATE ' . self::$table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id');

        return $stmt->execute($params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decodePayload(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $raw = $row['subscribe_payload'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
