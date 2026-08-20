-- workflow-bridge 1.3.0 -> 1.4.0
-- 执行前先确认索引不存在：
-- SHOW INDEX FROM `workflow_approval_results` WHERE Key_name = 'idx_war_route_apply_due';
-- 本脚本通过 information_schema 判断后再执行 ADD INDEX，兼容不支持索引条件式新增语法的 MySQL。

SET @war_route_index_exists = (
  SELECT COUNT(*)
  FROM `information_schema`.`statistics`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'workflow_approval_results'
    AND `index_name` = 'idx_war_route_apply_due'
);
SET @war_route_index_sql = IF(
  @war_route_index_exists = 0,
  'ALTER TABLE `workflow_approval_results` ADD INDEX `idx_war_route_apply_due` (`process_code`, `owner_system`, `apply_next_retry_at`, `local_apply_status`)',
  'SELECT ''idx_war_route_apply_due already exists'' AS message'
);
PREPARE war_route_index_statement FROM @war_route_index_sql;
EXECUTE war_route_index_statement;
DEALLOCATE PREPARE war_route_index_statement;
