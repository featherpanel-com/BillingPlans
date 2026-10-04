ALTER TABLE `featherpanel_billingplans_plans`
    ADD COLUMN `product_type` VARCHAR(16) NOT NULL DEFAULT 'server' AFTER `name`,
    ADD COLUMN `vds_config` JSON NULL DEFAULT NULL AFTER `server_config`;

ALTER TABLE `featherpanel_billingplans_subscriptions`
    ADD COLUMN `vm_instance_id` INT(11) NULL DEFAULT NULL AFTER `server_uuid`,
    ADD COLUMN `vm_creation_task_id` VARCHAR(64) NULL DEFAULT NULL AFTER `vm_instance_id`,
    ADD KEY `idx_billingplans_sub_vm_instance` (`vm_instance_id`),
    ADD KEY `idx_billingplans_sub_vm_creation_task` (`vm_creation_task_id`);
