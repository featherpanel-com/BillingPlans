ALTER TABLE `featherpanel_billingplans_plans`
    ADD COLUMN `allowed_upgrade_plan_ids` JSON NULL DEFAULT NULL
    COMMENT 'Whitelist of plan IDs this plan can upgrade to'
    AFTER `card_background_image`,
    ADD COLUMN `allowed_downgrade_plan_ids` JSON NULL DEFAULT NULL
    COMMENT 'Whitelist of plan IDs this plan can downgrade to'
    AFTER `allowed_upgrade_plan_ids`;
