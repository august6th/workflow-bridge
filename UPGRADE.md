# Upgrade Guide

## 当前阶段

项目尚未上线，`workflow-bridge` 使用一份最终建表定义，不提供历史增量 SQL：

```text
database/sql/workflow_approval_results.sql
database/migrations/2026_08_18_000001_create_workflow_approval_results_table.php
```

如果开发库曾执行过早期试验版 DDL，且确认没有需要保留的数据，可以删除以下两张表后重新执行最终 SQL：

```sql
DROP TABLE IF EXISTS `workflow_callback_deliveries`;
DROP TABLE IF EXISTS `workflow_approval_results`;
```

存在需要保留的数据时不要直接删除，由 DBA 根据最终 DDL 单独制定迁移脚本。

## 上线后规则

- 已发布版本的建表 SQL 和 migration 不再修改语义。
- 新字段、新索引或状态迁移必须提供独立、可回滚评估的增量 SQL。
- 先完成数据库变更和校验，再部署依赖新字段的包代码。
- Composer 库版本由 Git tag 决定，不在 `composer.json` 写 `version`。
