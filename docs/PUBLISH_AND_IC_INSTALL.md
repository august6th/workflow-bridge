# 发布与 ERP 安装

## 发布前检查

在 `workflow-bridge` 目录执行：

```bash
composer validate --strict
vendor/bin/phpunit
find src config database/migrations tests -name '*.php' -exec php -l {} \;
git diff --check
git ls-files | rg '^(vendor/|composer\.lock$|\.env$)'
```

最后一条命令应无输出。真实 `WORKFLOW_SSO_SECRET`、`WORKFLOW_CALLBACK_SECRET` 和数据库凭据不得进入仓库。

## 版本

Composer 库版本由 Git tag 决定，不在 `composer.json` 写 `version`。registry 精确路由 API 从 `v1.4.0` 起提供。

发布时：

```bash
git tag -a v1.4.0 -m "v1.4.0"
git push origin main
git push origin v1.4.0
```

## ERP 安装

私有 VCS 仓库配置：

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:august6th/workflow-bridge.git"
    }
  ],
  "require": {
    "august6th/workflow-bridge": "^1.4"
  }
}
```

```bash
composer update august6th/workflow-bridge --with-dependencies --no-interaction
php artisan vendor:publish --provider="August6th\\WorkflowBridge\\WorkflowBridgeServiceProvider" --tag=workflow-bridge-config
```

Laravel 5.5 未自动发现时，在 `config/app.php` 注册 `WorkflowBridgeServiceProvider`。

## 数据库

全新安装执行最终 DDL：

```bash
mysql -h<host> -u<user> -p<db> < vendor/august6th/workflow-bridge/database/sql/workflow_approval_results.sql
```

该 DDL 同时创建结果投影表和回调投递表；尚未发生的流程、重试、抢占和应用时间为 `NULL`。

已有 1.3 表升级到 1.4 时，`CREATE TABLE IF NOT EXISTS` 不会新增索引。先执行：

```sql
SHOW INDEX FROM `workflow_approval_results` WHERE Key_name = 'idx_war_route_apply_due';
```

无结果时执行 `vendor/august6th/workflow-bridge/database/sql/upgrades/1.3.0-to-1.4.0.sql`，完成后再次 `SHOW INDEX` 验证。该脚本可重复执行，不使用兼容性不统一的 `ADD INDEX IF NOT EXISTS`。

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

```cron
* * * * * cd /absolute/path/to/ic && /usr/bin/flock -n /tmp/ic-workflow-retry-start.lock /usr/bin/php artisan workflow:retry-start --owner=ic --process=REPLACE_WITH_SKC_PROCESS_CODE >> /absolute/path/to/ic/storage/logs/workflow-retry-start.log 2>&1
* * * * * cd /absolute/path/to/ic && /usr/bin/flock -n /tmp/ic-workflow-apply-results.lock /usr/bin/php artisan workflow:apply-results --owner=ic --process=REPLACE_WITH_SKC_PROCESS_CODE >> /absolute/path/to/ic/storage/logs/workflow-apply-results.log 2>&1
```

验证顺序：

1. 发起业务单后出现唯一 `workflow_approval_results` 记录。
2. `start_status` 从 `pending/processing` 进入 `succeeded`，失败则进入 `failed`。
3. Workflow 终态回调新增 `workflow_callback_deliveries`，并更新 `workflow_status`。
4. `ResultApplier` 幂等执行后，`local_apply_status` 进入 `applied` 或 `skipped`。
