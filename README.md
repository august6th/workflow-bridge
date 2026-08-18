# Workflow Bridge

ERP 业务系统接入 Workflow 审核流的通用 Composer 包。

包名：`august6th/workflow-bridge`  
兼容：PHP `>=7.0`，Laravel `5.5+`

## 能力

- SSO 换 token 后发起 / 查询流程
- 本地表 `workflow_approval_results` 记录同步与终态
- 回调验签 + 幂等落库
- `workflow:retry-start` 补偿发起失败
- `workflow:apply-results` 映射骨架（需业务侧绑定 `ResultApplier`）

## 安装

```bash
composer require august6th/workflow-bridge:^1.0
```

私有仓库时在业务项目 `composer.json` 增加：

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/august6th/workflow-bridge.git"
    }
  ]
}
```

本地联调（仅开发机）可用 path：

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../workflow-bridge",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "august6th/workflow-bridge": "1.0.0"
  }
}
```

发布配置与迁移：

```bash
php artisan vendor:publish --provider="August6th\\WorkflowBridge\\WorkflowBridgeServiceProvider" --tag=workflow-bridge-config
php artisan vendor:publish --provider="August6th\\WorkflowBridge\\WorkflowBridgeServiceProvider" --tag=workflow-bridge-migrations
php artisan migrate
```

Laravel 5.5 若未自动发现，在 `config/app.php` 的 `providers` 中注册：

```php
August6th\WorkflowBridge\WorkflowBridgeServiceProvider::class,
```

## 环境变量

```env
WORKFLOW_BASE_URL=http://workflow.aug.test/api
WORKFLOW_SSO_SECRET=
WORKFLOW_CALLBACK_SECRET=
WORKFLOW_OWNER_SYSTEM=ic
WORKFLOW_CLIENT_USER_NAME=ic_workflow_client
WORKFLOW_CLIENT_NAME=IC Workflow Client
WORKFLOW_CLIENT_SOURCE_SYSTEM=ic
WORKFLOW_SKC_APPROVAL_PROCESS_CODE=skc_approval
```

## 发起

```php
use August6th\WorkflowBridge\Bridge\WorkflowBridge;

$bridge = app(WorkflowBridge::class);
$bridge->startProcess('skc_approval', $approvalNo, [
    'owner_system' => 'ic',
    'business_payload' => ['approval_no' => $approvalNo],
    'input' => ['approval_no' => $approvalNo],
]);
```

发起失败写入 `workflow_status=start_failed`，不抛异常打断业务事务。

## 回调路由

```php
Route::post('workflow/callback', '\\August6th\\WorkflowBridge\\Http\\Controllers\\CallbackController');
```

该路由不要走 ERP 内网 signature 中间件；包内会校验 Workflow 回调签名。

## 命令

```bash
php artisan workflow:retry-start --process=skc_approval --owner=ic
php artisan workflow:apply-results --dry-run
```

## 版本

见 [CHANGELOG.md](CHANGELOG.md)。升级说明见 [UPGRADE.md](UPGRADE.md)。

IC 审版接入说明见 [docs/IC_SKC_APPROVAL_INTEGRATION.md](docs/IC_SKC_APPROVAL_INTEGRATION.md)。
