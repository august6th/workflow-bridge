# Upgrade Guide

## 1.3.0 升级到 1.4.0

1.4.0 为按 `owner_system + process_code` 路由的到期结果查询新增索引：

```text
database/sql/upgrades/1.3.0-to-1.4.0.sql
```

旧表执行新版 `CREATE TABLE IF NOT EXISTS` 不会补索引。升级前先检查：

```sql
SHOW INDEX FROM `workflow_approval_results` WHERE Key_name = 'idx_war_route_apply_due';
```

若无结果，执行增量 SQL；脚本通过 `information_schema.statistics` 判断索引名，支持重复执行，不依赖兼容性不统一的 `ADD INDEX IF NOT EXISTS`。执行后再次 `SHOW INDEX`，确认列顺序为 `process_code, owner_system, apply_next_retry_at, local_apply_status`。

全新安装直接执行最终建表 SQL，不要再执行该增量脚本：

```text
database/sql/workflow_approval_results.sql
```

## 上线后规则

- 已发布版本的建表 SQL 和 migration 不再修改语义。
- 新字段、新索引或状态迁移必须提供独立、可回滚评估的增量 SQL。
- 先完成数据库变更和校验，再部署依赖新索引查询路径的包代码。
- Composer 库版本由 Git tag 决定，不在 `composer.json` 写 `version`。
