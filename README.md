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

## 其他 Laravel 项目安装

以下步骤安装 v1.5.x。Composer 约束 `^1.5` 表示允许安装 `>=1.5.0 <2.0.0` 的兼容更新，包括后续 1.5.x；它不等同于 `^1.x`。`^1.x` 不是有效的 Composer 版本约束，不要写入命令或 `composer.json`。

### 1. 进入宿主项目并确认包来源

```bash
cd /absolute/path/to/laravel-project
```

先检查宿主项目的 `composer.json`。包已发布到公开 Packagist 时不需要配置 `repositories`；包仅存在于私有 Git 仓库时，需先加入 VCS 仓库并确保当前机器有读取权限，例如：

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:august6th/workflow-bridge.git"
        }
    ]
}
```

### 2. 安装 v1.5.x

```bash
composer require august6th/workflow-bridge:^1.5 \
  --no-plugins \
  --no-scripts \
  --ignore-platform-reqs \
  --no-interaction
```

这些参数用于在旧 ERP 项目中暂时隔离 Composer 插件、宿主脚本和本机平台差异，降低安装过程被遗留配置中断的概率，但不是长期豁免：

- `--no-plugins` 会跳过 Composer 插件，依赖插件完成安装的宿主项目需在评估后恢复执行。
- `--no-scripts` 会跳过 `post-autoload-dump` 等脚本，因此 Laravel 包发现、缓存刷新等动作可能尚未运行。
- `--ignore-platform-reqs` 会忽略 PHP 与扩展要求，可能安装当前运行环境实际无法执行的依赖。上线前必须在目标 PHP 环境重新检查平台要求并完成测试。

安装后确认实际版本并重新生成自动加载：

```bash
composer show august6th/workflow-bridge
composer dump-autoload --no-plugins --no-scripts
```

### 3. 注册 Provider 并发布配置

Laravel 5.5 未自动发现时，在 `config/app.php` 的 `providers` 中手工注册：

```php
August6th\WorkflowBridge\WorkflowBridgeServiceProvider::class,
```

发布配置：

```bash
php artisan vendor:publish --provider="August6th\\WorkflowBridge\\WorkflowBridgeServiceProvider" --tag=workflow-bridge-config
```

在 `.env` 配置 `WORKFLOW_BASE_URL`、`WORKFLOW_SSO_SECRET`、`WORKFLOW_CALLBACK_SECRET` 和 `WORKFLOW_OWNER_SYSTEM`，然后执行 `php artisan config:clear`。真实 secret 不得写入仓库或 crontab。

### 4. 建表或升级到 v1.5 最终索引

全新项目执行最终 DDL：

```bash
mysql -h<host> -u<user> -p<db> < vendor/august6th/workflow-bridge/database/sql/workflow_approval_results.sql
```

已有 v1.3 或 v1.4 表执行 1.3→1.5 升级 SQL。文件名因历史原因仍为 `1.3.0-to-1.4.0.sql`，内容已覆盖 v1.5 最终索引：

```bash
mysql -h<host> -u<user> -p<db> < vendor/august6th/workflow-bridge/database/sql/upgrades/1.3.0-to-1.4.0.sql
```

执行前由 DBA 评估锁和磁盘影响，执行后用 `SHOW INDEX FROM workflow_approval_results` 与 `SHOW INDEX FROM workflow_callback_deliveries` 核对最终索引。

### 5. 接入回调与业务结果

在宿主项目注册 `POST /workflow/callback` 路由，并排除登录鉴权、内部 signature 中间件和 CSRF；回调仍由包内 HMAC 验签。随后按 `owner_system + process_code` 在 `ResultApplierRegistry` 注册宿主项目的幂等 `ResultApplier`，具体代码见下文“回调”和“应用业务结果”。

### 6. dry-run 后配置系统 crontab

先手工验证精确路由，不要直接上线周期任务：

```bash
php artisan workflow:apply-results --owner=ic --process=skc_approval --dry-run --limit=100
php artisan workflow:retry-start --owner=ic --process=skc_approval --limit=100
```

确认结果后，再按下文“定时任务”配置系统 crontab。`workflow:retry-start` 必须同时提供非空 `--owner` 和 `--process`；`workflow:apply-results` 的生产条目也应显式指定 owner/process。每个条目都应包含宿主项目绝对路径、PHP 可执行文件、日志重定向和 `flock`，避免任务重叠；不要在 Laravel scheduler 中注册这些业务周期任务。

## 建表

项目尚未上线，数据库定义已汇总为一份最终 DDL。ERP 项目直接执行：

```bash
mysql -h<host> -u<user> -p<db> < vendor/august6th/workflow-bridge/database/sql/workflow_approval_results.sql
```

该 SQL 一次创建：

- `workflow_approval_results`：业务单对应的唯一流程实例与状态投影
- `workflow_callback_deliveries`：不可变的回调投递审计记录

索引按包内真实查询最小化：结果表仅保留主键、两项幂等/业务唯一约束和四项精确路由到期/租约索引（共 7 项）。精确 route due 索引不显式追加 `id`（InnoDB 二级索引已隐含主键）；查询仍按 `id` 排序，但不承诺消除 filesort。回调投递表共 5 项索引：主键、回调幂等唯一索引，以及按结果记录查询投递历史的 `idx_wcd_result`、按 Workflow 实例排障的 `idx_wcd_instance`、按接收时间归档/清理/排障的 `idx_wcd_received`。业务回写仍由 `workflow_approval_results` 驱动；回调表仅用于投递审计、排障与归档，不恢复重复业务三元组唯一约束的 `idx_wcd_biz`。

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

从 1.4 起，`workflow:retry-start` 必须同时传入非空 `--owner` 和 `--process`，否则命令错误退出 1；发起重试和租约恢复只走精确路由索引，不再支持 owner-only 扫描。`--limit` 同时限制本轮候选处理和过期租约恢复，最大 1000，避免无限更新造成大事务和长锁。结果应用命令不传路由时仍只扫描 registry 中已注册的精确路由。

```cron
* * * * * cd /absolute/path/to/erp && /usr/bin/flock -n /tmp/workflow-retry-start.lock /usr/bin/php artisan workflow:retry-start --owner=ic --process=skc_approval --limit=100 >> /absolute/path/to/erp/storage/logs/workflow-retry-start.log 2>&1
* * * * * cd /absolute/path/to/erp && /usr/bin/flock -n /tmp/workflow-apply-results.lock /usr/bin/php artisan workflow:apply-results --owner=ic --process=skc_approval --limit=100 >> /absolute/path/to/erp/storage/logs/workflow-apply-results.log 2>&1
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
