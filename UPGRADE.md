# Upgrade Guide

## 从开发副本升级到 1.0.x

1. 业务项目将依赖改为 `"august6th/workflow-bridge": "^1.0"`。
2. `composer update august6th/workflow-bridge --with-dependencies`。
3. 若有新增 migration，执行 `php artisan migrate`（包不会自动跑迁移）。
4. 对比 `config/workflow-bridge.php` 与 publish 后的本地配置，补齐新增环境变量。

## 兼容承诺

- `1.0.x`：不改公共方法签名与表语义。
- `1.x`：可新增字段（必须有默认值）与向后兼容 API。
- `2.x`：不兼容变更，本文件会补充迁移步骤。
