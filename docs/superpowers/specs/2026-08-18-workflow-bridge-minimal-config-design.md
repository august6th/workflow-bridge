# Workflow Bridge Minimal Configuration Design

## Goal

Reduce ERP-side Workflow Bridge configuration to four environment variables without adding a Redis dependency or weakening callback and SSO security.

## Environment Contract

ERP projects configure only:

- `WORKFLOW_BASE_URL`: deployed Workflow API base URL.
- `WORKFLOW_SSO_SECRET`: SSO HMAC secret shared with Workflow.
- `WORKFLOW_CALLBACK_SECRET`: callback HMAC secret shared with the bound Workflow business callback asset.
- `WORKFLOW_OWNER_SYSTEM`: stable ERP system code.

Retry intervals, leases, clock-skew windows, HTTP timeout, and token lifetime remain explicit package configuration values but use fixed package defaults rather than environment variables.

## Client Identity

The SSO machine identity is derived from `owner_system`:

- `external_user_id`: `<owner_system>_workflow_bridge`
- `user_name`: `<owner_system>_workflow_bridge`
- `name`: `<UPPERCASE_OWNER_SYSTEM> Workflow Bridge`
- `source_system`: the configured `owner_system`

The SSO assertion continues to request `workflow:external:start` and `workflow:external:view`. Including `external_user_id` satisfies Workflow's trusted SSO identity contract.

## Process Codes

Process codes remain method arguments such as `dispatchProcess('skc_approval', $businessKey)`. The unused `WORKFLOW_SKC_APPROVAL_PROCESS_CODE` mapping is removed.

## Redis And Queues

Workflow Bridge does not access Redis directly. `dispatchProcess()` uses the host Laravel queue connection, which may be `sync`, `database`, or `redis`. Database-backed retry and result-application commands remain unchanged.

## Verification

Tests must decode the outbound SSO assertion and verify the derived identity. Configuration tests must prove operational settings are fixed defaults and the obsolete process mapping is absent. Documentation must show only the four required environment variables.
