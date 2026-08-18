# Changelog

## 1.0.0 - 2026-08-18

### Added

- Workflow SSO client：login / start / query
- `WorkflowBridge::startProcess` 与失败落库 `start_failed`
- `workflow_approval_results` migration
- 回调验签与幂等 `CallbackHandler`
- 命令 `workflow:retry-start`、`workflow:apply-results`
- Laravel 5.5+ ServiceProvider 与 config publish
