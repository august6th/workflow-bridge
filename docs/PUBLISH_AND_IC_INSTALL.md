# 发布与 ERP 安装

## 发布前检查

在 `workflow-bridge` 目录执行：

```bash
composer validate --strict --no-plugins --no-check-lock
vendor/bin/phpunit
find src config database/migrations tests -name '*.php' -exec php -l {} \;
git diff --check
git ls-files | rg '^(vendor/|composer\.lock$|\.env$)'
```

最后一条命令应无输出。真实 `WORKFLOW_SSO_SECRET`、`WORKFLOW_CALLBACK_SECRET` 和数据库凭据不得进入仓库。

## 版本

Composer 库版本由 Git tag 决定，不在 `composer.json` 写 `version`。本次发布目标为 `v1.5.0`；发布前确认 CHANGELOG、README、升级说明、最终 DDL/升级 SQL 与 v1.5 行为一致。registry 精确路由 API 从 `v1.4.0` 起提供。

准备完成并经人工审核后，发布负责人可另行创建并推送 `v1.5.0` tag。本次发布准备不执行 `git tag` 或 `git push`。

## ERP 安装

公开 Packagist 安装不需要配置 `repositories`；仅私有 Git VCS 包需要在宿主项目 `composer.json` 配置仓库地址并确保部署身份有读取权限。进入 ERP 项目后安装 v1.5.x：

```bash
cd /absolute/path/to/erp
composer require august6th/workflow-bridge:^1.5 \
  --no-plugins \
  --no-scripts \
  --ignore-platform-reqs \
  --no-interaction
composer show august6th/workflow-bridge
composer dump-autoload --no-plugins --no-scripts
php artisan vendor:publish --provider="August6th\\WorkflowBridge\\WorkflowBridgeServiceProvider" --tag=workflow-bridge-config
```

`^1.5` 允许 `>=1.5.0 <2.0.0` 的兼容更新；`^1.x` 不是有效 Composer 约束，不要使用。`--no-plugins`、`--no-scripts` 和 `--ignore-platform-reqs` 仅用于暂时绕开遗留 ERP 的插件、脚本和本机平台差异；它们可能跳过必要插件/包发现并掩盖 PHP 或扩展不兼容，上线前必须在目标环境重新校验平台要求并完成宿主项目测试。

Laravel 5.5 未自动发现时，在 `config/app.php` 注册 `WorkflowBridgeServiceProvider`。

## 数据库

全新安装执行最终 DDL：

```bash
mysql -h<host> -u<user> -p<db> < vendor/august6th/workflow-bridge/database/sql/workflow_approval_results.sql
```

该 DDL 同时创建结果投影表和回调投递表；尚未发生的流程、重试、抢占和应用时间为 `NULL`。

已有 v1.3 或 v1.4 表升级到 v1.5 时，`CREATE TABLE IF NOT EXISTS` 不会新增索引。先执行：

```sql
SHOW INDEX FROM `workflow_approval_results`;
```

执行 `vendor/august6th/workflow-bridge/database/sql/upgrades/1.3.0-to-1.4.0.sql`，完成后再次 `SHOW INDEX` 验证。该脚本可重复执行：会校验/rebuild 四项精确 route 索引和三项 callback 运营索引，并安全删除已存在且无包内独立查询路径的 `idx_war_instance`、`idx_war_status_updated`、`idx_war_apply_created`、`idx_war_start_due`、`idx_war_apply_due`；callback 删除清单仅含 `idx_wcd_biz`，不会删除主键或唯一约束。旧 MySQL 不依赖 `ADD/DROP INDEX IF EXISTS`。

最终结果表仅保留 PRIMARY、`uk_war_idem`、`uk_war_biz` 和四项 route due/lease 索引（共 7 项）；回调表保留 PRIMARY、`uk_wcd_idempotency`、按结果记录查询投递历史的 `idx_wcd_result(approval_result_id)`、按 Workflow 实例排障的 `idx_wcd_instance(instance_uuid)`、按接收时间归档/清理/排障的 `idx_wcd_received(received_at)`（共 5 项）。业务回写仍由 `workflow_approval_results` 驱动，callback 表只用于投递审计、排障和归档。route due 索引不显式追加 InnoDB 已隐含的主键 `id`；业务查询继续 `ORDER BY id`，不承诺消除 filesort。

## 配置与路由

至少配置：

```env
WORKFLOW_BASE_URL=https://<workflow-host>/api
WORKFLOW_SSO_SECRET=<shared-secret>
WORKFLOW_CALLBACK_SECRET=<callback-secret>
WORKFLOW_OWNER_SYSTEM=ic
```

发起 Job 实际队列名为 `{WORKFLOW_OWNER_SYSTEM}:{WORKFLOW_START_QUEUE}`，后缀默认 `workflow-bridge`（IC 即 `ic:workflow-bridge`）。其余租约、重试、验签时间窗口、HTTP 超时和 token 缓存都支持 `env()` 覆盖，但常规项目直接使用包内默认值，不写入 `.env` 或 `.env.example`。客户端身份根据 `WORKFLOW_OWNER_SYSTEM` 自动生成，`ic` 对应 `ic_workflow_bridge`；流程编码由调用方法直接传入。

执行 `php artisan config:clear`。回调路由需排除登录鉴权、内部 signature 中间件和 CSRF 校验，包内负责 Workflow HMAC 验签。

Bridge 不直接依赖 Redis。`dispatchProcess()` 使用 ERP 项目现有的 Laravel 队列连接；可以选择 `sync`、`database` 或 `redis`。使用 `sync` 或 `database` 时无需为 Bridge 增加 Redis。worker 需 `--queue=<owner_system>:<start_queue_suffix>`，例如 `ic:workflow-bridge`。

## 调度与验证

v1.5 生产运行中，`workflow:retry-start` 必须同时传入非空 `--owner` 和 `--process`，否则错误退出 1；不再支持 owner-only 扫描。应设置 `--limit`（最大 1000），该 limit 同时限制候选处理和每轮租约恢复。`workflow:apply-results` 无参数运行仍按 registry 已注册路由扫描。



```cron
* * * * * cd /absolute/path/to/ic && /usr/bin/flock -n /tmp/ic-workflow-retry-start.lock /usr/bin/php artisan workflow:retry-start --owner=ic --process=REPLACE_WITH_SKC_PROCESS_CODE >> /absolute/path/to/ic/storage/logs/workflow-retry-start.log 2>&1
* * * * * cd /absolute/path/to/ic && /usr/bin/flock -n /tmp/ic-workflow-apply-results.lock /usr/bin/php artisan workflow:apply-results --owner=ic --process=REPLACE_WITH_SKC_PROCESS_CODE >> /absolute/path/to/ic/storage/logs/workflow-apply-results.log 2>&1
```

验证顺序：

1. 发起业务单后出现唯一 `workflow_approval_results` 记录。
2. `start_status` 从 `pending/processing` 进入 `succeeded`，失败则进入 `failed`。
3. Workflow 终态回调新增 `workflow_callback_deliveries`，并更新 `workflow_status`。
4. `ResultApplier` 幂等执行后，`local_apply_status` 进入 `applied` 或 `skipped`。
