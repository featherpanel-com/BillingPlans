ALTER TABLE `featherpanel_billingplans_plans`
    ADD COLUMN `card_background_image` VARCHAR(1024) NULL DEFAULT NULL
    COMMENT 'Optional background image URL for client-side plan cards'
    AFTER `image_override`;
