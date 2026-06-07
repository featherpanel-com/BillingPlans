ALTER TABLE `featherpanel_billingplans_plans`
    ADD COLUMN `slider_config` JSON NULL DEFAULT NULL COMMENT 'JSON configuration for dynamic resource sliders';

ALTER TABLE `featherpanel_billingplans_subscriptions`
    ADD COLUMN `custom_resources` JSON NULL DEFAULT NULL COMMENT 'JSON values of selected dynamic resource values';
