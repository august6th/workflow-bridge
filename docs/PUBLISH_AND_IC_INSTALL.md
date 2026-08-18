# 发布 august6th/workflow-bridge（Composer 包）

本文说明如何把 `erp/workflow-bridge` 发布成可 `composer require` 的独立包，并说明**发布前安全检查**。

---

## 一、发布前安全检查（必做）

在 `workflow-bridge` 目录执行：

```bash
cd /Users/aug/Code/erp/workflow-bridge

# 1. 确认不会把 vendor / lock / 本地密钥提交进 Git
git status
git ls-files | rg -i 'vendor/|\.env$|composer\.lock|\.pem|id_rsa|secret'

# 2. 扫描源码里是否误写了真实密钥（应只有 env 占位符）
rg -n '877823|password\s*=\s*[^"\']+|WORKFLOW_SSO_SECRET=\S+' --glob '!vendor/**' .

# 3. 确认 .gitignore 至少包含
cat .gitignore
# 应有：/vendor/  /composer.lock  .env
```

### 当前包内安全结论（发布前已核对）

| 项 | 状态 | 说明 |
|----|------|------|
| `vendor/` | 已忽略 | 不会进 Git |
| `composer.lock` | 已忽略 | 库项目一般不提交 lock |
| `.env` | 无此文件 | 密钥只写在业务项目 `.env` |
| `config/workflow-bridge.php` | 安全 | 仅 `env('WORKFLOW_*')` 占位，无硬编码密钥 |
| 测试用 secret | 安全 | 仅 `'secret'` / `'callback-secret'` 假值 |
| 内网域名 `workflow.aug.test` | 低危 | 开发域名，可公开；生产 URL 由业务 `.env` 配置 |
| IC 业务 `.env` | **勿提交** | 含 `WORKFLOW_SSO_SECRET` 等，必须在 IC 仓库 `.gitignore` 内 |

**高危：不要把 Workflow / IC 的 `WORKFLOW_SSO_SECRET`、`WORKFLOW_CALLBACK_SECRET`、数据库密码写进包源码或 README 示例。**

---

## 二、本地仓库准备

```bash
cd /Users/aug/Code/erp/workflow-bridge

# 跑测试（可选但推荐）
composer install --no-interaction
vendor/bin/phpunit

# 确认版本号（composer.json 里 "version": "1.0.0"）
# 打 tag（SemVer）
git add -A
git commit -m "release: v1.0.0"
git tag -a v1.0.0 -m "v1.0.0"
```

---

## 三、创建 GitHub 远程仓库

1. 打开 https://github.com/new  
2. Repository name：`workflow-bridge`  
3. Owner：`august6th`（与你的 `amzn-sp-api` 一致）  
4. **Private**（推荐，内部 ERP 包）或 Public  
5. 不要勾选 “Add a README”（本地已有）  
6. Create repository  

绑定远程并推送：

```bash
cd /Users/aug/Code/erp/workflow-bridge

git remote add origin git@github.com:august6th/workflow-bridge.git
# 若已存在 origin：git remote set-url origin git@github.com:august6th/workflow-bridge.git

git push -u origin main
git push origin v1.0.0
```

验证：浏览器打开 `https://github.com/august6th/workflow-bridge/tags` 应能看到 `v1.0.0`。

---

## 四、IC 侧 composer require（由你亲手执行）

> IC 业务代码（Job、Service 钩子、回调路由）已写好，但**依赖包需你自行安装**。

### 4.1 添加 VCS 源

编辑 `ic/composer.json`，在根级增加 `repositories`（与 `require` 同级）：

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/august6th/workflow-bridge.git"
  }
]
```

私有仓库需本机已配置 GitHub SSH 或 HTTPS token；Composer 会通过 Git 拉取 tag。

### 4.2 安装包（在 Laradock workspace 或 PHP 7.x 环境）

```bash
cd /var/www/erp/ic
# 或本机：cd /Users/aug/Code/erp/ic

composer require august6th/workflow-bridge:^1.0 --no-plugins
```

若本机 PHP 8.x 与 Laravel 5.5 冲突，可加：

```bash
composer require august6th/workflow-bridge:^1.0 --no-plugins --ignore-platform-reqs
```

**本地开发尚未推 GitHub 时**，可临时用 path（仅本机）：

```json
"repositories": [
  {
    "type": "path",
    "url": "../workflow-bridge",
    "options": { "symlink": true }
  }
]
```

```bash
composer require august6th/workflow-bridge:1.0.0 --no-plugins
```

推远程后改回 VCS + `^1.0`。

### 4.3 注册 ServiceProvider（Laravel 5.5）

`config/app.php` 的 `providers` 中增加：

```php
August6th\WorkflowBridge\WorkflowBridgeServiceProvider::class,
```

### 4.4 发布配置（不要 migrate）

```bash
php artisan vendor:publish --provider="August6th\WorkflowBridge\WorkflowBridgeServiceProvider" --tag=workflow-bridge-config
```

会生成 `config/workflow-bridge.php`。  
**ERP 不用 migrate**：建表见下一节。

### 4.5 建表（手工 SQL，推荐）

在 IC 业务库执行包内 SQL：

```bash
# 路径示例（composer 安装后）
cat vendor/august6th/workflow-bridge/database/sql/workflow_approval_results.sql
```

或源码：

```bash
cat /Users/aug/Code/erp/workflow-bridge/database/sql/workflow_approval_results.sql
```

交给 DBA / 自己在 MySQL 客户端执行即可。

### 4.6 配置 IC `.env`

在 `ic/.env` 追加（**值从 Workflow 管理端 / 运维获取，勿提交 Git**）：

```env
WORKFLOW_BASE_URL=http://workflow.aug.test/api
WORKFLOW_SSO_SECRET=
WORKFLOW_CALLBACK_SECRET=
WORKFLOW_OWNER_SYSTEM=ic
WORKFLOW_CLIENT_USER_NAME=ic_workflow_client
WORKFLOW_CLIENT_NAME="IC Workflow Client"
WORKFLOW_CLIENT_SOURCE_SYSTEM=ic
WORKFLOW_SKC_APPROVAL_PROCESS_CODE=skc_approval
```

注意：`WORKFLOW_CLIENT_NAME` 含空格，**.env 里必须加引号**。

`WORKFLOW_SSO_SECRET` 与 Workflow 的 `web/.env` 中 `WORKFLOW_SSO_SECRET` 一致。  
`WORKFLOW_CALLBACK_SECRET` 与 Workflow 业务回调资产里配置的 secret 一致。

```bash
php artisan config:clear
```

### 4.7 验证

1. 新建审版单 → 查 `workflow_approval_results` 是否出现 `waiting`  
2. 失败时：`php artisan workflow:retry-start --process=skc_approval --owner=ic`  
3. Workflow 审完 → IC `POST /workflow/callback` 写入 `approved/rejected`  
4. 审版列表「Workflow状态」列有值  

详细 Workflow 平台配置见 [IC_SKC_APPROVAL_INTEGRATION.md](IC_SKC_APPROVAL_INTEGRATION.md)。

---

## 五、后续发版流程

1. 在 `workflow-bridge` 改代码 + 测试  
2. 更新 `CHANGELOG.md`，改 `composer.json` 的 `version`（如 `1.0.1`）  
3. `git tag -a v1.0.1 -m "v1.0.1"` && `git push origin v1.0.1`  
4. IC 项目：`composer update august6th/workflow-bridge --no-plugins`  
5. 若有**新 SQL**（仅追加字段），DBA 执行增量脚本；**不要改历史 migration/SQL 语义**

---

## 六、Packagist（可选）

内部包通常 **不需要** Packagist，VCS + GitHub tag 即可。  
若以后要公开索引：注册 packagist.org → Submit → 填 `august6th/workflow-bridge` → 配置 GitHub webhook 自动同步 tag。

---

## 七、常见问题

| 问题 | 处理 |
|------|------|
| `Repository not found` | GitHub 仓库未创建或无权限；检查 SSH key |
| `package:discover` 在 PHP 8 报错 | 使用 `--no-plugins` 或进 PHP 7 容器执行 |
| Dotenv 含空格报错 | `.env` 中带空格的值加双引号 |
| 表不存在 | 执行 `database/sql/workflow_approval_results.sql`，不要用 migrate |
| 发起 start_failed | 查 `start_error` 字段；补 SSO secret / process_code / Workflow 接入身份权限 |
