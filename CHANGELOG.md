# Changelog

## 1.1.0 - Unreleased

### Added

- 持久化发起请求、原子抢占、租约恢复和指数退避
- 独立 `workflow_callback_deliveries` 回调投递审计表
- 回调 fail-closed 验签、严格终态校验和安全控制器错误响应
- 可抢占、可重试的本地 `ResultApplier` 应用状态机
- `WorkflowRequestException` 与 HTTP 401 单次刷新重试
- `WorkflowBridge::requestProcess()`、`dispatchProcess()`
- 发起和应用任务的租约、退避默认策略

### Changed

- 业务唯一键固定为 `owner_system + process_code + business_key`
- 发起、Workflow、本地应用状态拆分保存
- 批量结果映射强制传入 `process_code`，未命中项返回 `null`
- 异步 Job 只接收已持久化的结果记录 ID
- 项目未上线，数据库结构合并为一份最终 DDL 和一份建表 migration
- 未发生、未调度和未抢占的业务时间使用 `NULL`，不再使用哨兵日期
- ERP 环境变量收敛为 Workflow 地址、两个共享密钥和来源系统编码
- SSO 客户端身份根据 `owner_system` 自动生成，并补充稳定 `external_user_id`
- 移除未使用的流程编码环境变量；流程编码继续由 Bridge 方法参数传入

## 1.0.0 - 2026-08-18

- 初始开发版本，仅用于本地验证，未作为生产数据库升级基线。
