# Workflow Bridge Minimal Configuration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce ERP-side Workflow Bridge configuration to four environment variables and derive a valid Workflow SSO machine identity from `owner_system`.

**Architecture:** Keep operational tuning as ordinary package config with fixed defaults, so advanced consumers may still publish and override the PHP config without expanding `.env`. Build the SSO claims inside `WorkflowClient` from `owner_system`, including the stable external identity required by Workflow.

**Tech Stack:** PHP 7.0+, Laravel 5.5-10, Guzzle, PHPUnit 6-9.

---

### Task 1: Lock The Minimal Configuration Contract

**Files:**
- Modify: `tests/ConfigurationTest.php`
- Modify: `tests/WorkflowClientTest.php`

- [ ] Add a configuration test asserting fixed operational defaults and removal of `client` and `processes` config sections.
- [ ] Add an HTTP history test that decodes the SSO assertion and expects `external_user_id`, `user_name`, `name`, and `source_system` derived from `owner_system`.
- [ ] Run `vendor/bin/phpunit tests/ConfigurationTest.php tests/WorkflowClientTest.php` and confirm the new assertions fail for the missing behavior.

### Task 2: Implement Derived Identity And Fixed Defaults

**Files:**
- Modify: `config/workflow-bridge.php`
- Modify: `src/Client/WorkflowClient.php`

- [ ] Replace operational `env()` calls with their existing numeric defaults, remove unused process mapping, and keep only the four environment-backed values.
- [ ] Derive the client identity from normalized `owner_system`, include `external_user_id`, and retain the two external API permissions.
- [ ] Run the focused PHPUnit tests and confirm they pass.

### Task 3: Update Consumer Documentation

**Files:**
- Modify: `README.md`
- Modify: `docs/PUBLISH_AND_IC_INSTALL.md`
- Modify: `docs/IC_SKC_APPROVAL_INTEGRATION.md`
- Modify: `CHANGELOG.md`

- [ ] Replace expanded `.env` samples with the four-variable contract.
- [ ] Explain that Bridge has no direct Redis dependency and that the host queue driver determines whether Redis is used.
- [ ] Document automatic client identity derivation and removal of the unused process-code environment variable.

### Task 4: Verify And Commit

**Files:**
- Verify all changed source, tests, configuration, and documentation files.

- [ ] Run `composer validate --strict`.
- [ ] Run `vendor/bin/phpunit` and require zero failures.
- [ ] Run PHP syntax checks for `src`, `config`, `database/migrations`, and `tests`.
- [ ] Run `git diff --check` and inspect `git status --short`.
- [ ] Commit the complete change to `main` with `feat: simplify workflow bridge configuration`.
