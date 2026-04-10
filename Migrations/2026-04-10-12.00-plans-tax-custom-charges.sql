ALTER TABLE `featherpanel_billingplans_plans`
    ADD COLUMN `tax_rate_percent` DECIMAL(7,2) NOT NULL DEFAULT 0.00 AFTER `billing_period_days`,
    ADD COLUMN `extra_charge_percent` DECIMAL(7,2) NOT NULL DEFAULT 0.00 AFTER `tax_rate_percent`,
    ADD COLUMN `extra_charge_name` VARCHAR(120) NULL DEFAULT NULL AFTER `extra_charge_percent`;
