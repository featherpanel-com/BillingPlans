ALTER TABLE `featherpanel_billingplans_subscriptions`
    ADD COLUMN IF NOT EXISTS `coupon_code_id` INT(11) NULL DEFAULT NULL AFTER `plan_id`,
    ADD COLUMN IF NOT EXISTS `coupon_code` VARCHAR(255) NULL DEFAULT NULL AFTER `coupon_code_id`,
    ADD COLUMN IF NOT EXISTS `coupon_scope` ENUM('initial','renewal','both') NULL DEFAULT NULL AFTER `coupon_code`,
    ADD COLUMN IF NOT EXISTS `renewal_discount_percent` DECIMAL(7,2) NULL DEFAULT NULL AFTER `coupon_scope`,
    ADD COLUMN IF NOT EXISTS `renewal_discount_credits` INT(11) NULL DEFAULT NULL AFTER `renewal_discount_percent`,
    ADD KEY `idx_billingplans_sub_coupon_code_id` (`coupon_code_id`);
