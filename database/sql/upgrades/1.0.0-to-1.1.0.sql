-- workflow-bridge 1.0.0 -> 1.1.0
-- 执行顺序：先执行本脚本，再部署 1.1.0 包代码。

ALTER TABLE `workflow_approval_results`
  ADD COLUMN `start_status` varchar(30) NOT NULL DEFAULT 'pending' COMMENT '发起状态：pending/processing/succeeded/failed' AFTER `process_code`,
  ADD COLUMN `start_idempotency_key` varchar(64) NOT NULL DEFAULT '' COMMENT '发起幂等键 SHA-256' AFTER `idempotency_key`,
  ADD COLUMN `requested_process_version` int unsigned NOT NULL DEFAULT 0 COMMENT '调用方指定的流程版本，0 表示未指定' AFTER `process_code`,
  ADD COLUMN `process_version` int unsigned NOT NULL DEFAULT 0 COMMENT 'Workflow 实际流程版本' AFTER `requested_process_version`,
  ADD COLUMN `start_attempts` int unsigned NOT NULL DEFAULT 0 COMMENT '流程发起尝试次数' AFTER `start_error`,
  ADD COLUMN `start_next_retry_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '流程发起下次重试时间' AFTER `start_attempts`,
  ADD COLUMN `start_processing_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '流程发起任务抢占时间' AFTER `start_next_retry_at`,
  ADD COLUMN `apply_attempts` int unsigned NOT NULL DEFAULT 0 COMMENT '业务结果应用尝试次数' AFTER `local_apply_error`,
  ADD COLUMN `apply_next_retry_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '业务结果应用下次重试时间' AFTER `apply_attempts`,
  ADD COLUMN `apply_processing_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '业务结果应用任务抢占时间' AFTER `apply_next_retry_at`,
  ADD KEY `idx_war_start_due` (`start_next_retry_at`,`start_status`),
  ADD KEY `idx_war_apply_due` (`apply_next_retry_at`,`local_apply_status`);

UPDATE `workflow_approval_results`
SET `start_status` = 'failed',
    `workflow_status` = 'not_started'
WHERE `workflow_status` = 'start_failed';

UPDATE `workflow_approval_results`
SET `start_status` = 'succeeded'
WHERE `workflow_status` IN ('running','waiting','approved','rejected','failed','cancelled')
   OR `instance_uuid` <> '';

UPDATE `workflow_approval_results`
SET `workflow_status` = 'not_started'
WHERE `workflow_status` = '';

UPDATE `workflow_approval_results`
SET `start_idempotency_key` = SHA2(CONCAT(`owner_system`, '\n', `process_code`, '\n', `business_key`), 256);

CREATE TABLE IF NOT EXISTS `workflow_callback_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '回调投递记录 ID',
  `approval_result_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '本地流程结果记录 ID',
  `business_key` varchar(160) NOT NULL DEFAULT '' COMMENT '业务单号快照',
  `owner_system` varchar(60) NOT NULL DEFAULT '' COMMENT '来源系统快照',
  `process_code` varchar(120) NOT NULL DEFAULT '' COMMENT 'Workflow 流程 code 快照',
  `instance_uuid` varchar(64) NOT NULL DEFAULT '' COMMENT 'Workflow 实例 UUID 快照',
  `event` varchar(60) NOT NULL DEFAULT '' COMMENT '回调事件名称',
  `result` varchar(30) NOT NULL DEFAULT '' COMMENT '回调审批结果',
  `result_value` varchar(60) NOT NULL DEFAULT '' COMMENT '回调结果映射值',
  `idempotency_key` varchar(190) NOT NULL DEFAULT '' COMMENT 'Workflow 回调幂等键',
  `delivery_id` varchar(80) NOT NULL DEFAULT '' COMMENT 'Workflow 投递 ID',
  `payload_json` mediumtext NULL COMMENT '原始回调 JSON，应用层默认空对象',
  `received_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '回调接收时间',
  `created_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wcd_idempotency` (`idempotency_key`),
  KEY `idx_wcd_result` (`approval_result_id`),
  KEY `idx_wcd_instance` (`instance_uuid`),
  KEY `idx_wcd_biz` (`business_key`,`process_code`,`owner_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Workflow 回调投递记录';
