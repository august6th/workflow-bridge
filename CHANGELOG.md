# Changelog

## 1.5.0 - 2026-08-21

### Changed

- 数据库索引按真实查询路径最小化：结果表最终保留 7 项索引，回调投递表最终保留 5 项索引
- 发起重试、结果应用及租约恢复使用 `owner_system + process_code` 精确 route；`workflow:retry-start` 必须同时提供非空 `--owner` 和 `--process`
- `--limit` 同时限制本轮候选处理和过期租约恢复，避免无界更新造成大事务与长锁
- 回调表保留按结果记录、Workflow 实例和接收时间查询的运营索引，用于投递审计、排障及归档
- 最终 DDL 与历史命名的 `1.3.0-to-1.4.0.sql` 升级脚本同步 v1.5 索引定义和性能校验逻辑

## 1.4.0 - 2026-08-20

### Added

- 新增 `ResultApplierRegistry`，按 `owner_system + process_code` 精确注册和解析本地结果应用器
- 通用应用命令仅扫描已注册路由，并要求 `--owner` 与 `--process` 成对传入；定向业务键候选同样遵循终态、应用状态和重试到期范围
- 新增结果/发起到期与租约精确路由索引，并提供可校验旧索引列顺序的 1.3.0 到 1.4.0 增量 SQL
- 数据库索引按真实源码查询最小化：route due 去除 InnoDB 隐含的显式 `id`，删除结果表五个无独立查询路径的普通索引（含 `idx_war_start_due`、`idx_war_apply_due`）；回调表保留按结果记录、Workflow 实例和接收时间查询的 `idx_wcd_result`、`idx_wcd_instance`、`idx_wcd_received`，仅删除与结果表业务唯一键重复的 `idx_wcd_biz`。业务回写继续由结果表驱动，callback 表用于投递审计、排障和归档

### Fixed

- 未注册任何路由时不再扫描或处理结果
- 候选 ID 被其他 worker 抢占时不再错误计入 `processDue()` 的 processed 统计

## 1.3.0 - 2026-08-20

### Changed

- 发起 Job 队列名由 `{WORKFLOW_OWNER_SYSTEM}:{WORKFLOW_START_QUEUE}` 自动拼接；`WORKFLOW_START_QUEUE` 仅表示后缀，默认 `workflow-bridge`（例如 IC 得到 `ic:workflow-bridge`），避免多模块共用 Redis 时互相消费

## 1.2.0 - 2026-08-19

### Fixed

- 回调控制器改为基于原始 JSON body 验签，避免 Laravel 全局 `ConvertEmptyStringsToNull` / `TrimStrings` 将 payload 中的空字符串改写后导致 `Invalid workflow callback signature`

## 1.1.0 - 2026-08-19

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
