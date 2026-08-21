# Upgrade Guide

## 1.4.0 升级到 1.5.0

v1.5.0 将当前查询路径与最终索引定义收敛为结果表 7 项、回调表 5 项。现有 v1.4 表仍需执行：

```text
database/sql/upgrades/1.3.0-to-1.4.0.sql
```

该文件名是历史命名，内容已经覆盖 v1.5 最终索引。生产执行前由 DBA 评估在线 DDL、锁和临时磁盘影响；执行后核对：

```sql
SHOW INDEX FROM `workflow_approval_results`;
SHOW INDEX FROM `workflow_callback_deliveries`;
```

结果表应保留主键、两项唯一约束及四项精确 route 到期/租约索引，共 7 项。回调表应保留主键、回调幂等唯一索引，以及 `idx_wcd_result(approval_result_id)`、`idx_wcd_instance(instance_uuid)`、`idx_wcd_received(received_at)`，共 5 项。这三项运营索引分别支持按结果记录查看投递历史、按 Workflow 实例排障、按接收时间归档/清理/排障；回调表不承担业务回写驱动。

生产命令行为需同步检查：`workflow:retry-start` 必须同时传入非空 `--owner` 和 `--process`，缺失任一参数会错误退出 1，不再支持 owner-only 扫描；`--limit` 同时限制候选处理与每轮过期租约恢复。安装 v1.5 后先手工执行：

```bash
php artisan workflow:apply-results --owner=ic --process=skc_approval --dry-run --limit=100
php artisan workflow:retry-start --owner=ic --process=skc_approval --limit=100
```

确认精确 route、索引和结果候选符合预期后，再启用带 `flock`、日志重定向及明确 owner/process 的系统 crontab。

## 1.3.0 升级到 1.4.0

1.4.0 的最终索引覆盖四条精确路由主路径：

- 结果到期：`process_code, owner_system, local_apply_status, workflow_status, apply_next_retry_at`
- 结果租约：`process_code, owner_system, local_apply_status, apply_processing_at`
- 发起到期：`process_code, owner_system, start_status, start_next_retry_at`
- 发起租约：`process_code, owner_system, start_status, start_processing_at`

已有 1.3 表执行 `database/sql/upgrades/1.3.0-to-1.4.0.sql`。脚本通过 `information_schema.statistics` 校验四项 route 索引及三项 callback 运营索引的完整列顺序：索引缺失时新增，定义不一致时安全 drop/recreate；并仅在普通冗余索引实际存在时动态删除。脚本可重复执行，且不使用旧 MySQL 兼容性不统一的 `ADD/DROP INDEX IF EXISTS`。删除清单包含 `idx_war_start_due`、`idx_war_apply_due` 及原有三项冗余结果索引；回调表只安全删除 `idx_wcd_biz`。结果表最终共 7 项索引，回调表共 5 项。

route due 末尾不显式包含 `id`，因为 InnoDB 二级索引已隐含主键；查询保留 `ORDER BY id`，不承诺避免 filesort。回调表保留 `idx_wcd_result(approval_result_id)` 查询结果投递历史、`idx_wcd_instance(instance_uuid)` 按 Workflow 实例排障、`idx_wcd_received(received_at)` 支持归档/清理/排障。业务回写仍由 `workflow_approval_results` 驱动，callback 表仅承担投递审计、排障和归档；`idx_wcd_biz` 与结果表 `uk_war_biz` 重复，升级时安全删除。

大表执行 `ALTER TABLE` 前由 DBA 评估 MySQL 版本的在线 DDL 能力、执行窗口、锁影响和临时磁盘空间。完成后用 `SHOW INDEX FROM workflow_approval_results` 核对上述列顺序，再部署依赖新索引的代码。

全新安装仅执行最终建表 SQL：`database/sql/workflow_approval_results.sql`。

从 1.4 起，`workflow:retry-start` 必须同时传入非空 `--owner + --process`，缺失或空值时错误退出 1；`StartWorkflowProcessor::processDue()` 同样拒绝空路由，不再提供 owner-only 扫描。生产 cron 还应设置单批 `limit`（最大 1000），同一 limit 也限制每轮过期租约恢复数量。`workflow:apply-results` 无参数运行仍通过 registry 生成精确路由 OR。

## 上线后规则

- 已发布版本的建表 SQL 和 migration 不再修改语义。
- 新字段、新索引或状态迁移必须提供独立、可回滚评估的增量 SQL。
- 先完成数据库变更和校验，再部署依赖新索引查询路径的包代码。
- Composer 库版本由 Git tag 决定，不在 `composer.json` 写 `version`。
