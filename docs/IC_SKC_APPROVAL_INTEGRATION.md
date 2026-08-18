# IC 审版双轨接入 Workflow

## 业务目标

审版单创建成功、进入「可开始审版」后，自动向 Workflow 发起流程实例；IC 原审版保持不变。Workflow 结果写入 `workflow_approval_results`，前期不回写 `ic_product_skc_approval`。

## 建表（ERP 不用 migrate）

在 IC 业务库执行：

`database/sql/workflow_approval_results.sql`

## Workflow 平台配置

1. 发布流程，`process_code` 与 IC `WORKFLOW_SKC_APPROVAL_PROCESS_CODE` 一致（默认 `skc_approval`）。
2. 创建业务回调资产：
   - URL：`https://<ic-host>/workflow/callback`
   - `secret`：与 IC `WORKFLOW_CALLBACK_SECRET` 相同
3. 绑定到流程终态交付。
4. 创建接入身份，授予 `workflow:external:start`、`workflow:external:view`。
5. `WORKFLOW_SSO_SECRET` 与 Workflow `web/.env` 一致。
6. 生产环境运行 `workflow-callbacks-0..3` 队列 worker。

## IC 安装

见 [PUBLISH_AND_IC_INSTALL.md](PUBLISH_AND_IC_INSTALL.md)。

## 同步状态

```text
workflow_approval_results.business_key = approval_no
  无行 / start_failed → 可 workflow:retry-start
  waiting → 审批中
  approved / rejected → 终态（映射脚本启用前不写回业务表）
```
