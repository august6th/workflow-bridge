# Workflow Bridge

ERP Laravel 项目接入 Workflow 的通用 Composer 包。业务唯一键固定为：

```text
owner_system + process_code + business_key
```

同一业务单在同一流程中只能发起一次；重复请求返回同一条本地记录。

## 能力

- 先持久化发起请求，再同步处理或投递队列
- 发起任务原子抢占、租约恢复和指数退避
- Workflow 状态与本地业务应用状态分离
- 回调验签、严格 payload 校验和独立投递审计
- 本地结果应用原子抢占、失败重试和租约恢复
- HTTP 401 自动刷新 token 并重试一次

兼容 PHP `>=7.0`、Laravel `5.5-10`。

## 安装

```bash
composer require august6th/workflow-bridge:^1.4
```

Laravel 5.5 未自动发现时，在 `config/app.php` 注册：

```php
August6th\WorkflowBridge\WorkflowBridgeServiceProvider::class,
```

发布配置：

```bash
php artisan vendor:publish --provider="August6th\\WorkflowBridge\\WorkflowBridgeServiceProvider" --tag=workflow-bridge-config
```

## 建表

项目尚未上线，数据库定义已汇总为一份最终 DDL。ERP 项目直接执行：

```bash
mysql -h<host> -u<user> -p<db> < vendor/august6th/workflow-bridge/database/sql/workflow_approval_results.sql
```

该 SQL 一次创建：

- `workflow_approval_results`：业务单对应的唯一流程实例与状态投影
- `workflow_callback_deliveries`：不可变的回调投递审计记录

没有 `1.0 -> 1.1` 增量 SQL。使用 migration 的项目也只有一份建表 migration。

## 环境变量

```env
WORKFLOW_BASE_URL=http://workflow.aug.test/api
WORKFLOW_SSO_SECRET=
WORKFLOW_CALLBACK_SECRET=
WORKFLOW_OWNER_SYSTEM=ic
```

`WORKFLOW_SSO_SECRET`、`WORKFLOW_CALLBACK_SECRET` 不能为空。回调 secret 为空时验签会直接失败。发起 Job 实际队列名为 `{WORKFLOW_OWNER_SYSTEM}:{WORKFLOW_START_QUEUE}`，后缀默认 `workflow-bridge`（IC 即 `ic:workflow-bridge`）；一般无需单独配置 `WORKFLOW_START_QUEUE`。

客户端身份由 `WORKFLOW_OWNER_SYSTEM` 自动生成。例如 `ic` 对应 `external_user_id` 和 `user_name` 均为 `ic_workflow_bridge`，展示名为 `IC Workflow Bridge`。流程编码由 `dispatchProcess()` 等方法直接传入，不需要环境变量。

租约、重试、验签时间窗口、HTTP 超时和 token 缓存均提供 `env()` 覆盖入口和包内默认值。常规项目不需要把这些可选项写入 `.env` 或 `.env.example`；只有确需调优时再单独配置。

## Redis 与队列

该包不直接访问 Redis。`requestProcess()`、`startProcess()`、失败重试和结果应用均可只使用业务数据库；`dispatchProcess()` 使用宿主 Laravel 项目的队列配置：

- `sync`：同步执行，不需要 Redis
- `database`：使用数据库队列，不需要 Redis
- `redis`：由宿主项目提供 Redis 和队列 worker

发起 Job 实际队列名为 `{WORKFLOW_OWNER_SYSTEM}:{WORKFLOW_START_QUEUE}`（后缀默认 `workflow-bridge`）。worker 需监听对应队列：

```bash
php artisan queue:work redis --queue=ic:workflow-bridge
```

Workflow 服务端可继续使用 Redis 处理回调队列和共享缓存，这不要求每个 ERP 项目为 Bridge 单独部署 Redis。

## 发起流程

异步发起推荐使用持久化入口：

```php
use August6th\WorkflowBridge\Bridge\WorkflowBridge;

app(WorkflowBridge::class)->dispatchProcess('skc_approval', $approvalNo, [
    'owner_system' => 'ic',
    'business_payload' => $payload,
    'input' => $payload,
]);
```

同步场景使用 `startProcess()`；只创建待处理记录使用 `requestProcess()`。

发起失败时：

```text
start_status=failed
workflow_status=not_started
```

失败不会伪装成 Workflow 流程终态。

## 回调

```php
Route::post(
    'workflow/callback',
    '\\August6th\\WorkflowBridge\\Http\\Controllers\\CallbackController'
);
```

该路由应排除 ERP 登录鉴权、内部 signature 中间件和 CSRF 校验。包内会校验 Workflow 时间戳、nonce、幂等键和 HMAC 签名，并且只接受 `workflow.finished` 的 `approved/rejected` 终态。

## 应用业务结果

每种业务结果按 `owner_system + process_code` 精确注册独立的 `ResultApplier`。未注册路由不会被查询、抢占或修改状态；重复注册会抛出 `InvalidArgumentException`，避免后注册者静默覆盖已有规则。

```php
use App\Workflow\SkcApprovalResultApplier;
use App\Workflow\ListingPublishResultApplier;
use August6th\WorkflowBridge\Application\ResultApplierRegistry;

public function boot(ResultApplierRegistry $registry)
{
    $registry->register('ic', 'skc_approval', SkcApprovalResultApplier::class);
    $registry->register('listing', 'publish_approval', ListingPublishResultApplier::class);
}
```

也可将已构造且实现 `August6th\WorkflowBridge\Contracts\ResultApplier` 的对象作为第三个参数。类字符串会在实际应用结果时通过 Laravel 容器解析。

`apply()` 返回：

- `true`：已应用，记录进入 `applied`
- `false`：业务明确跳过，记录进入 `skipped`
- 抛出异常或 `Error`：记录进入 `failed` 并按退避时间重试

实现必须按结果 ID 或 `owner_system + process_code + business_key` 保证幂等，因为 worker 在执行成功但落状态前退出时会重试。

不带路由过滤时，命令只扫描 registry 中已注册的路由。显式过滤必须同时提供 `--owner` 和 `--process`，并且该精确路由必须已注册：

```bash
php artisan workflow:apply-results --dry-run --limit=100
php artisan workflow:apply-results --owner=ic --process=skc_approval --include-failed=1
```

## 定时任务

```cron
* * * * * cd /absolute/path/to/erp && /usr/bin/flock -n /tmp/workflow-retry-start.lock /usr/bin/php artisan workflow:retry-start --owner=ic --process=skc_approval >> /absolute/path/to/erp/storage/logs/workflow-retry-start.log 2>&1
* * * * * cd /absolute/path/to/erp && /usr/bin/flock -n /tmp/workflow-apply-results.lock /usr/bin/php artisan workflow:apply-results --owner=ic --process=skc_approval >> /absolute/path/to/erp/storage/logs/workflow-apply-results.log 2>&1
```

手工检查：

```bash
php artisan workflow:retry-start --process=skc_approval --owner=ic
php artisan workflow:apply-results --owner=ic --process=skc_approval --dry-run
php artisan workflow:apply-results --owner=ic --process=skc_approval --include-failed=1
```

## 查询

批量映射必须明确指定 `process_code`：

```php
$results = app(WorkflowBridge::class)->mapResultsByBusinessKeys(
    $businessKeys,
    'ic',
    'skc_approval'
);
```

返回集合保留去重后的请求顺序，未命中的业务号对应 `null`。

版本记录见 [CHANGELOG.md](CHANGELOG.md)，接入说明见 [docs/IC_SKC_APPROVAL_INTEGRATION.md](docs/IC_SKC_APPROVAL_INTEGRATION.md)。
