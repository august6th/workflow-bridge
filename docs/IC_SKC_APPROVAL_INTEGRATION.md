# IC 审版双轨接入 Workflow

## 业务目标

审版单创建成功、进入「可开始审版」后，自动向 Workflow 发起流程实例；IC 原「开始审版 → 提交」保持不变。Workflow 结果只写入 `workflow_approval_results`，前期不回写 `ic_product_skc_approval`。

## Workflow 平台配置

1. 发布流程，`process_code` 与 IC 环境变量 `WORKFLOW_SKC_APPROVAL_PROCESS_CODE` 一致（默认 `skc_approval`）。
2. 创建业务回调资产：
   - URL：`https://<ic-host>/workflow/callback`（本地按实际域名）
   - `secret`：与 IC `WORKFLOW_CALLBACK_SECRET` 相同
3. 将回调资产绑定到流程终态交付。
4. 创建接入身份（如 `ic_workflow_client`），授予：
   - `workflow:external:start`
   - `workflow:external:view`
5. 确保 `WORKFLOW_SSO_SECRET` 与 IC 一致。
6. 生产环境常驻 `workflow-callbacks-0..3` 队列 worker。

## IC 侧

- 依赖 `august6th/workflow-bridge:^1.0`
- 创建钩子：`SkcApprovalService::batchCreateApproval` / `innerCreateApproval`
- `business_key` = `approval_no`
- `owner_system` = `ic`
- 列表通过左连结果表展示 `workflow_status_zh`
- 重试：`php artisan workflow:retry-start --process=skc_approval --owner=ic`

## 同步状态查询

```text
workflow_approval_results.business_key = approval_no
  无行 / start_failed → 可补偿
  waiting → 审批中
  approved / rejected → Workflow 终态（映射脚本启用前不写回业务表）
```
