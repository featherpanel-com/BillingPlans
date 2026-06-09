ALTER TABLE `featherpanel_billingplans_subscriptions`
    ADD COLUMN `payment_gateway` VARCHAR(32) NULL DEFAULT NULL COMMENT 'stripe, paypal, razorpay, credits' AFTER `server_uuid`,
    ADD COLUMN `gateway_subscription_id` VARCHAR(255) NULL DEFAULT NULL AFTER `payment_gateway`,
    ADD COLUMN `gateway_customer_id` VARCHAR(255) NULL DEFAULT NULL AFTER `gateway_subscription_id`,
    ADD COLUMN `auto_renew_via_gateway` TINYINT(1) NOT NULL DEFAULT 0 AFTER `gateway_customer_id`,
    ADD KEY `idx_billingplans_sub_gateway` (`payment_gateway`, `gateway_subscription_id`);

CREATE TABLE IF NOT EXISTS `featherpanel_billingplans_gateway_checkouts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `plan_id` INT(11) NOT NULL,
    `gateway` VARCHAR(32) NOT NULL,
    `status` ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    `amount_cents` INT(11) NOT NULL DEFAULT 0,
    `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
    `charge_credits` INT(11) NOT NULL DEFAULT 0,
    `subscribe_payload` JSON NULL,
    `gateway_checkout_id` VARCHAR(255) NULL,
    `gateway_subscription_id` VARCHAR(255) NULL,
    `gateway_customer_id` VARCHAR(255) NULL,
    `subscription_id` INT(11) NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bp_gw_checkout_user` (`user_id`),
    KEY `idx_bp_gw_checkout_status` (`status`),
    KEY `idx_bp_gw_checkout_gateway_ref` (`gateway`, `gateway_checkout_id`),
    CONSTRAINT `fk_bp_gw_checkout_user`
        FOREIGN KEY (`user_id`) REFERENCES `featherpanel_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bp_gw_checkout_plan`
        FOREIGN KEY (`plan_id`) REFERENCES `featherpanel_billingplans_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
