# IC 审版接入 Workflow

## 业务约束

审版单以以下三元组唯一对应一个 Workflow 实例：

```text
ic + skc_approval + approval_no
```

重复触发不会创建第二个流程实例。Workflow 结果先写入桥接表，再由 IC 的 `ResultApplier` 幂等回填业务表。

## 安装 v1.5

在 IC 项目目录安装 v1.5.x：

```bash
composer require august6th/workflow-bridge:^1.5 \
  --no-plugins \
  --no-scripts \
  --ignore-platform-reqs \
  --no-interaction
composer show august6th/workflow-bridge
composer dump-autoload --no-plugins --no-scripts
```

`^1.5` 允许 1.5.x 兼容更新；不要写无效的 `^1.x`。安装参数会暂时跳过插件、脚本和平台要求，上线前必须在 IC 目标环境核对 PHP/扩展兼容性并完成测试。

## 建表

项目尚未上线，IC 业务库只执行最终 DDL：

```text
database/sql/workflow_approval_results.sql
```

它会同时创建 `workflow_approval_results` 和 `workflow_callback_deliveries`。

## Workflow 配置

1. 发布 `process_code=skc_approval` 的流程。
2. 创建业务回调资产，URL 为 `https://<ic-host>/workflow/callback`。
3. 回调 secret 与 IC 的 `WORKFLOW_CALLBACK_SECRET` 完全一致。
4. 将回调绑定到流程终态交付。
5. 首次 SSO 登录会创建 `ic_workflow_bridge` 接入身份，为其授予 `workflow:external:start`、`workflow:external:view`。
6. `WORKFLOW_SSO_SECRET` 与 Workflow 服务端配置一致。

IC 的 `.env` 和 `.env.example` 需保留 `WORKFLOW_BASE_URL`、`WORKFLOW_SSO_SECRET`、`WORKFLOW_CALLBACK_SECRET`、`WORKFLOW_OWNER_SYSTEM=ic`。发起 Job 队列自动为 `ic:workflow-bridge`（`{owner_system}:{start_queue_suffix}`，后缀默认 `workflow-bridge`）。流程编码直接作为方法参数传入；租约与重试参数虽支持 `env()` 覆盖，但默认无需列出。

## IC 发起

```php
use August6th\WorkflowBridge\Bridge\WorkflowBridge;

app(WorkflowBridge::class)->dispatchProcess($processCode, $approvalNo, [
    'owner_system' => 'ic',
    'business_payload' => $payload,
    'input' => $payload,
]);
```

不要直接构造 `StartWorkflowProcessJob`；公开入口会先持久化记录，再派发只携带记录 ID 的 Job。

## 状态判断

```text
start_status=pending/processing  -> 等待发起或正在发起
start_status=failed              -> 可由 workflow:retry-start 重试
workflow_status=waiting          -> 审批中
workflow_status=approved/rejected -> Workflow 终态
local_apply_status=pending/failed -> 等待或重试回填 IC
local_apply_status=applied/skipped -> 本地处理完成
```

`started_at`、`finished_at`、`applied_at` 等尚未发生时为 `NULL`。

## 调度

v1.5 中，`workflow:retry-start` 必须同时传入非空 `--owner` 和 `--process`，否则错误退出 1；不再支持 owner-only 扫描。应设置 `--limit`（最大 1000），该 limit 同时限制候选处理和每轮租约恢复，避免大事务与长锁。

```cron
* * * * * cd /absolute/path/to/ic && /usr/bin/flock -n /tmp/ic-workflow-retry-start.lock /usr/bin/php artisan workflow:retry-start --owner=ic --process=skc_approval >> /absolute/path/to/ic/storage/logs/workflow-retry-start.log 2>&1
* * * * * cd /absolute/path/to/ic && /usr/bin/flock -n /tmp/ic-workflow-apply-results.lock /usr/bin/php artisan workflow:apply-results --owner=ic --process=skc_approval >> /absolute/path/to/ic/storage/logs/workflow-apply-results.log 2>&1
```

回调路由必须排除 IC 登录鉴权、内部 signature 中间件和 CSRF 校验。

Bridge 本身不依赖 Redis。IC 使用 `sync` 或数据库队列时无需新增 Redis；只有将 Laravel 队列连接设置为 `redis` 时才需要相应基础设施。
