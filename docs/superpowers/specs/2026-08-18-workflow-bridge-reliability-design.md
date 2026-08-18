# Workflow Bridge Reliability Design

## 目标

将 `workflow-bridge` 收敛为 ERP 业务系统接入 Workflow 的可靠单实例桥接包，解决发布版本、发起补偿、回调安全、回调审计和业务结果应用的可靠性问题。

业务唯一性固定为：

```text
owner_system + process_code + business_key
```

同一业务单在同一流程下只允许一个 Workflow 实例。重复调用必须返回已有记录，不提供覆盖终态或重复发起能力。

## 范围

本次包括：

- 可靠发起状态与补偿机制
- 单实例幂等约束
- 回调投递记录独立存储
- 发起、Workflow、业务应用三段状态
- 安全的回调契约校验
- 并发安全的结果应用与失败重试
- 从 `1.0.0` 升级的增量 SQL
- Laravel 5.5 兼容测试和新版本发布准备

本次不包括：

- 同业务同流程多实例
- 自动修改各 ERP 业务表
- Workflow 流程定义或运行引擎调整
- 跨 ERP 的统一业务结果映射实现

## 数据模型

### workflow_approval_results

保留现有表作为单个业务流程实例的本地投影，并明确三段状态：

```text
start_status: pending / processing / succeeded / failed
workflow_status: not_started / running / waiting / approved / rejected / failed / cancelled
local_apply_status: pending / processing / applied / skipped / failed
```

新增或调整字段：

- `start_idempotency_key`：发起阶段稳定幂等键，使用固定长度哈希
- `requested_process_version`：调用方显式指定的流程版本；补偿时原样复用
- `process_version`：Workflow 实际创建的流程版本
- `start_attempts`：发起尝试次数
- `start_next_retry_at`：下次允许重试时间
- `start_processing_at`：发起任务抢占时间
- `apply_attempts`：业务结果应用次数
- `apply_next_retry_at`：下次允许应用时间
- `apply_processing_at`：应用任务抢占时间

保留业务三元组唯一索引。`instance_uuid` 在非空值场景下保持唯一语义，但 MySQL 空字符串无法使用普通唯一索引，因此由业务三元组和写入逻辑共同约束。

旧 `idempotency_key`、`delivery_id`、`callback_payload_json` 不再承担回调历史职责。为兼容旧调用可暂时保留字段，但新代码不再依赖它们进行回调幂等。

### workflow_callback_deliveries

每个 Workflow 回调投递保存一行：

- `idempotency_key` 唯一，作为回调幂等依据
- `delivery_id`
- `approval_result_id`，关联本地实例投影
- Workflow 实例、业务键、流程编码和来源系统快照
- 回调结果与原始 payload
- `received_at`

重复回调命中同一 `idempotency_key` 时直接返回已处理结果，不重复修改主记录。不同幂等键的迟到回调只有在不造成终态回退时才能更新主记录。

## 发起流程

1. ERP 调用 `startProcess()` 时先在本地创建 `start_status=pending` 的唯一业务记录。
2. 同步调用可立即尝试抢占该记录；异步 Job 只携带业务三元组或记录 ID。
3. 抢占成功后设置 `start_status=processing` 并增加尝试次数。
4. Workflow 返回成功后写入实例 UUID、流程版本和运行状态，并设置 `start_status=succeeded`。
5. 网络超时等不确定错误先按业务三元组查询 Workflow；查到实例视为成功。
6. 确认失败后设置 `start_status=failed`、错误信息和下次重试时间。
7. 已经 `succeeded` 或已进入 Workflow 终态的记录禁止重新发起。

调用方未指定版本时，首次请求和补偿请求都遵循 Workflow 的“当前已发布版本”语义；调用方指定版本时，补偿必须复用 `requested_process_version`。

`StartWorkflowProcessJob` 不再依赖 Laravel 队列自动重试完成业务补偿。业务失败由持久化状态和 `workflow:retry-start` 管理；代码或数据库异常继续抛出，使队列系统能够记录真实任务失败。

## 回调流程

回调必须满足：

- `WORKFLOW_CALLBACK_SECRET` 非空，否则拒绝请求
- HMAC 签名、时间窗口和必需请求头有效
- `event=workflow.finished`
- `idempotency_key`、`instance_uuid`、业务三元组完整
- `result` 只能是 `approved` 或 `rejected`
- 字段长度符合数据库约束

处理顺序：验签、校验 payload、写入回调投递记录、锁定实例投影、验证实例及业务三元组一致、更新 Workflow 终态。事务提交后向 Workflow 返回成功。

终态不可回退或互相覆盖。重复投递返回第一次处理结果。

## 业务结果应用

`workflow:apply-results` 使用数据库事务和状态抢占：

1. 选择到期的 `pending` 或可重试 `failed` 记录。
2. 原子更新为 `processing`，避免多个命令重复领取。
3. 调用宿主项目绑定的 `ResultApplier`。
4. 成功写入 `applied` 或 `skipped`；失败写入 `failed`、错误、次数和下次重试时间。

`ResultApplier` 必须按本地结果记录 ID 或业务三元组实现业务幂等。包只能避免并发领取，无法替代宿主业务事务内的最终幂等。

## 查询语义

- `findResult()` 返回业务三元组唯一记录。
- `mapResultsByBusinessKeys()` 必须指定 `process_code`，避免同一业务键在不同流程之间互相覆盖。
- 状态展示分别读取发起状态、Workflow 状态和应用状态，不再把 `start_failed` 混入 Workflow 状态。

## 升级策略

提供：

- 新安装使用的完整建表 SQL
- `database/sql/upgrades/1.0.0-to-1.1.0.sql`
- 对应 Laravel migration

升级脚本先新增字段和回调投递表，再回填旧状态：

- `workflow_status=start_failed` 转为 `start_status=failed`、`workflow_status=not_started`
- 已有实例或运行/终态记录转为 `start_status=succeeded`
- 旧回调字段迁移为一条历史投递记录，仅在幂等键不是 `start:` 前缀且 payload 存在时执行

脚本必须可重复执行或在执行前明确检测结构，且不删除旧字段，避免回滚和旧代码读取失败。

## 发布策略

现有 `v1.0.0` tag 不修改。新实现发布为 `v1.1.0`，因为包含新增表、字段和状态语义。发布前确认 tag 指向包含通用 Job、升级 SQL和测试的提交，并确认发布树不包含 `vendor`、`composer.lock` 或本地密钥。

## 测试

至少覆盖：

- Laravel 5.5 / PHP 7.x 兼容安装
- SSO 登录、发起和超时反查
- 同业务重复发起不会产生第二实例
- 空回调密钥、错误签名、过期时间和非法 payload 被拒绝
- 重复回调幂等、迟到回调不覆盖终态
- 两个应用进程只能抢占一次
- 应用失败按退避时间重试
- `1.0.0` 数据升级后的状态和历史回调正确

## 验收标准

- 任一业务三元组最多存在一条实例投影
- 队列消息丢失或发起失败均可从本地状态发现并补偿
- 未配置回调密钥时无法写入审批结果
- 每次回调投递均有独立审计记录
- 并发执行命令不会同时应用同一审批结果
- 已安装 `1.0.0` 的 ERP 可以通过增量 SQL 无损升级
