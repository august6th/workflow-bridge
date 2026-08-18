-- workflow_approval_results
-- ERP 项目通常不走 Laravel migrate，请由 DBA 在业务库手工执行本脚本。
-- 包内路径：vendor/august6th/workflow-bridge/database/sql/workflow_approval_results.sql
-- 或源码：erp/workflow-bridge/database/sql/workflow_approval_results.sql

CREATE TABLE IF NOT EXISTS `workflow_approval_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `business_key` varchar(160) NOT NULL DEFAULT '' COMMENT '业务单号，IC 审版=approval_no',
  `owner_system` varchar(60) NOT NULL DEFAULT '' COMMENT '来源系统，如 ic',
  `process_code` varchar(120) NOT NULL DEFAULT '' COMMENT 'Workflow 流程 code',
  `instance_uuid` varchar(64) NOT NULL DEFAULT '' COMMENT 'Workflow 实例 UUID',
  `workflow_status` varchar(30) NOT NULL DEFAULT '' COMMENT 'waiting/approved/rejected/start_failed',
  `start_error` varchar(1000) NOT NULL DEFAULT '' COMMENT '发起失败原因',
  `result` varchar(30) NOT NULL DEFAULT '' COMMENT '终态 approved/rejected',
  `result_value` varchar(60) NOT NULL DEFAULT '' COMMENT '流程 result_mapping 映射值',
  `idempotency_key` varchar(190) NOT NULL DEFAULT '' COMMENT '回调幂等键',
  `delivery_id` varchar(80) NOT NULL DEFAULT '' COMMENT 'Workflow 投递 ID',
  `callback_payload_json` mediumtext NULL COMMENT '原始回调 JSON',
  `business_payload_json` mediumtext NULL COMMENT '发起时 business_payload',
  `input_json` mediumtext NULL COMMENT '发起时 input',
  `local_apply_status` varchar(40) NOT NULL DEFAULT 'pending' COMMENT 'pending/applied/skipped/failed',
  `local_apply_error` varchar(1000) NOT NULL DEFAULT '' COMMENT '映射失败原因',
  `started_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `finished_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `applied_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `created_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `updated_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_approval_idempotency` (`idempotency_key`),
  UNIQUE KEY `uk_workflow_approval_biz` (`business_key`,`owner_system`,`process_code`),
  KEY `idx_workflow_approval_instance` (`instance_uuid`),
  KEY `idx_workflow_approval_status_updated` (`workflow_status`,`updated_at`),
  KEY `idx_workflow_approval_apply` (`local_apply_status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Workflow 审核桥接结果表';
