# Workflow Bridge Reliability Implementation Plan

> **Scope update (2026-08-18):** The project has not gone live. Database work is consolidated into one final install SQL and one create migration. References below to an additive `1.0.0 -> 1.1.0` upgrade are superseded by this decision.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare `workflow-bridge` as a production-ready single-instance ERP bridge with durable start requests, secure callback receipts, explicit state transitions, retryable result application, and one final pre-launch database definition.

**Architecture:** Keep `WorkflowBridge` as the public facade, move remote-start state transitions into a focused processor, store every callback in an inbox table, and claim start/apply work through persisted processing states. Preserve the existing business triple unique constraint and retain legacy columns for backward-compatible reads during the `1.1.x` line.

**Tech Stack:** PHP 7.0-compatible syntax, Laravel/Illuminate 5.5-10, Eloquent, Guzzle 6/7, MySQL, PHPUnit 6-9, Illuminate Database Capsule.

---

## File Responsibilities

- `src/Bridge/WorkflowBridge.php`: public request, synchronous start, dispatch, and query API.
- `src/Start/StartWorkflowProcessor.php`: claim and execute pending/failed start requests.
- `src/Jobs/StartWorkflowProcessJob.php`: process one persisted result row by ID.
- `src/Callback/CallbackHandler.php`: validate and persist a callback receipt, then advance the instance projection.
- `src/Callback/CallbackPayloadValidator.php`: callback contract validation independent of HTTP transport.
- `src/Models/WorkflowApprovalResult.php`: instance projection and state constants.
- `src/Models/WorkflowCallbackDelivery.php`: immutable callback inbox rows.
- `src/Application/ResultApplicationService.php`: claim and apply terminal results with retry metadata.
- `src/Console/RetryFailedStartsCommand.php`: process due start requests.
- `src/Console/ApplyResultsCommand.php`: process due business applications.
- `database/sql/workflow_approval_results.sql`: complete schema for new installations.
- `database/migrations/2026_08_18_000001_create_workflow_approval_results_table.php`: complete create migration equivalent to the install SQL.
- `tests/TestCase.php`: Capsule database container and package schema setup.

### Task 1: Build the Illuminate Integration Test Harness

**Files:**
- Modify: `composer.json`
- Modify: `phpunit.xml`
- Create: `tests/TestCase.php`
- Create: `tests/DatabaseSchemaTest.php`

- [ ] **Step 1: Add a failing schema integration test**

```php
public function testSchemaSupportsStartApplyAndCallbackStates()
{
    $columns = Schema::getColumnListing('workflow_approval_results');

    $this->assertContains('start_status', $columns);
    $this->assertContains('start_idempotency_key', $columns);
    $this->assertContains('requested_process_version', $columns);
    $this->assertContains('start_attempts', $columns);
    $this->assertContains('apply_attempts', $columns);
    $this->assertTrue(Schema::hasTable('workflow_callback_deliveries'));
}
```

- [ ] **Step 2: Run the test and verify the missing schema failure**

Run: `vendor/bin/phpunit tests/DatabaseSchemaTest.php`

Expected: FAIL because the new columns and callback table do not exist.

- [ ] **Step 3: Add the Capsule package test base**

`tests/TestCase.php` must configure `Illuminate\Database\Capsule\Manager` with an in-memory SQLite connection, bind the facade container, boot Eloquent, and load the single package create migration in `setUp()`.

- [ ] **Step 4: Confirm the test now reaches the schema assertion**

Run: `vendor/bin/phpunit tests/DatabaseSchemaTest.php`

Expected: FAIL only on the new schema assertions, not on application bootstrapping.

- [ ] **Step 5: Commit the test harness**

```bash
git add composer.json phpunit.xml tests/TestCase.php tests/DatabaseSchemaTest.php
git commit -m "test: add workflow bridge integration harness"
```

### Task 2: Add the Final Schema and Explicit State Model

**Files:**
- Modify: `src/Models/WorkflowApprovalResult.php`
- Create: `src/Models/WorkflowCallbackDelivery.php`
- Modify: `database/sql/workflow_approval_results.sql`
- Modify: `database/migrations/2026_08_18_000001_create_workflow_approval_results_table.php`
- Modify: `tests/DatabaseSchemaTest.php`

- [ ] **Step 1: Add failing model-state tests**

```php
public function testStartIdempotencyKeyHasFixedLength()
{
    $key = WorkflowApprovalResult::startIdempotencyKey(
        str_repeat('o', 60),
        str_repeat('p', 120),
        str_repeat('b', 160)
    );

    $this->assertSame(64, strlen($key));
}

public function testStateConstantsAreSeparated()
{
    $this->assertSame('failed', WorkflowApprovalResult::START_FAILED);
    $this->assertSame('not_started', WorkflowApprovalResult::STATUS_NOT_STARTED);
    $this->assertSame('processing', WorkflowApprovalResult::APPLY_PROCESSING);
}
```

- [ ] **Step 2: Verify the state tests fail**

Run: `vendor/bin/phpunit tests/DatabaseSchemaTest.php`

Expected: FAIL because the fixed hash and constants are absent.

- [ ] **Step 3: Implement the explicit state constants and fixed idempotency hash**

```php
const START_PENDING = 'pending';
const START_PROCESSING = 'processing';
const START_SUCCEEDED = 'succeeded';
const START_FAILED = 'failed';

const STATUS_NOT_STARTED = 'not_started';
const STATUS_RUNNING = 'running';
const STATUS_WAITING = 'waiting';
const STATUS_APPROVED = 'approved';
const STATUS_REJECTED = 'rejected';
const STATUS_FAILED = 'failed';
const STATUS_CANCELLED = 'cancelled';

const APPLY_PENDING = 'pending';
const APPLY_PROCESSING = 'processing';
const APPLY_APPLIED = 'applied';
const APPLY_SKIPPED = 'skipped';
const APPLY_FAILED = 'failed';

public static function startIdempotencyKey($ownerSystem, $processCode, $businessKey)
{
    return hash('sha256', implode("\n", [$ownerSystem, $processCode, $businessKey]));
}
```

- [ ] **Step 4: Consolidate the final pre-launch schema**

Create both tables in the single install SQL and create migration. Add Chinese comments, named indexes, and no foreign key. Lifecycle, retry, and processing timestamps are nullable until they occur; audit creation timestamps remain non-null.

Backfill rules in both SQL and migration:

```sql
UPDATE workflow_approval_results
SET start_status = CASE
    WHEN workflow_status = 'start_failed' THEN 'failed'
    WHEN instance_uuid <> '' OR workflow_status IN ('waiting', 'approved', 'rejected') THEN 'succeeded'
    ELSE 'pending'
END,
workflow_status = CASE
    WHEN workflow_status = 'start_failed' THEN 'not_started'
    WHEN workflow_status = '' THEN 'not_started'
    ELSE workflow_status
END,
start_idempotency_key = SHA2(CONCAT(owner_system, '\n', process_code, '\n', business_key), 256);
```

- [ ] **Step 5: Run schema and state tests**

Run: `vendor/bin/phpunit tests/DatabaseSchemaTest.php`

Expected: PASS.

- [ ] **Step 6: Commit the state and schema contract**

```bash
git add src/Models database tests/DatabaseSchemaTest.php
git commit -m "feat: add workflow bridge 1.1 state schema"
```

### Task 3: Implement Durable Single-Instance Start Requests

**Files:**
- Create: `src/Start/StartWorkflowProcessor.php`
- Modify: `src/Bridge/WorkflowBridge.php`
- Modify: `src/Jobs/StartWorkflowProcessJob.php`
- Modify: `src/Console/RetryFailedStartsCommand.php`
- Modify: `src/WorkflowBridgeServiceProvider.php`
- Create: `tests/WorkflowBridgeStartTest.php`

- [ ] **Step 1: Write failing durable-start tests**

Cover these exact cases:

```php
public function testRequestProcessCreatesPendingRowBeforeDispatch()
public function testRepeatedRequestReturnsSameBusinessTripleRow()
public function testProcessorMarksSuccessfulStartAndStoresActualVersion()
public function testTimeoutQueryRecoveryMarksStartSucceeded()
public function testConfirmedFailureStoresRetryMetadata()
public function testTerminalResultCannotBeStartedAgain()
public function testProcessorClaimsAResultOnlyOnce()
```

The first test must assert `start_status=pending` before any mocked client request is made. The duplicate test must assert one database row and one stable `start_idempotency_key`.

- [ ] **Step 2: Run the start tests and verify failure**

Run: `vendor/bin/phpunit tests/WorkflowBridgeStartTest.php`

Expected: FAIL because request/claim APIs do not exist.

- [ ] **Step 3: Add the public request API**

Implement these signatures on `WorkflowBridge`:

```php
public function requestProcess($processCode, $businessKey, array $options = []);
public function dispatchProcess($processCode, $businessKey, array $options = []);
public function startProcess($processCode, $businessKey, array $options = []);
```

`requestProcess()` uses `firstOrCreate` semantics around the business triple, stores payload and requested version, and never overwrites a succeeded or terminal record. `dispatchProcess()` persists first and then dispatches `StartWorkflowProcessJob($result->id)`. `startProcess()` preserves the existing synchronous API by requesting and immediately processing the persisted row.

- [ ] **Step 4: Implement atomic claiming and remote processing**

`StartWorkflowProcessor::process($id)` must claim with a conditional update:

```php
$claimed = WorkflowApprovalResult::where('id', $id)
    ->whereIn('start_status', [
        WorkflowApprovalResult::START_PENDING,
        WorkflowApprovalResult::START_FAILED,
    ])
    ->where('start_next_retry_at', '<=', $now)
    ->update([
        'start_status' => WorkflowApprovalResult::START_PROCESSING,
        'start_processing_at' => $now,
        'start_attempts' => DB::raw('start_attempts + 1'),
        'updated_at' => $now,
    ]);
```

Only the claimant calls Workflow. Catch expected request exceptions, perform the existing query-after-timeout recovery, and persist exponential retry timing. Let database errors and PHP `Error`/`TypeError` escape.

- [ ] **Step 5: Change the Job to carry only the persisted row ID**

```php
public function __construct($approvalResultId)
{
    $this->approvalResultId = $approvalResultId;
}

public function handle(StartWorkflowProcessor $processor)
{
    $processor->process($this->approvalResultId);
}
```

Set `$tries = 1`; persisted retry is handled by the command.

- [ ] **Step 6: Make retry-start process pending, failed, and stale processing rows**

The command must select IDs in bounded chunks, process only due rows, and support the existing process/owner/business-key filters. Stale `processing` rows older than the configured lease are reset to `failed` before selection.

- [ ] **Step 7: Run focused and full tests**

Run: `vendor/bin/phpunit tests/WorkflowBridgeStartTest.php`

Expected: PASS.

Run: `vendor/bin/phpunit`

Expected: PASS.

- [ ] **Step 8: Commit durable start processing**

```bash
git add src/Start src/Bridge src/Jobs src/Console src/WorkflowBridgeServiceProvider.php tests/WorkflowBridgeStartTest.php
git commit -m "feat: persist and retry workflow starts"
```

### Task 4: Add Secure Callback Inbox Processing

**Files:**
- Modify: `src/Callback/CallbackVerifier.php`
- Create: `src/Callback/CallbackPayloadValidator.php`
- Modify: `src/Callback/CallbackHandler.php`
- Modify: `src/Http/Controllers/CallbackController.php`
- Create: `tests/CallbackHandlerTest.php`
- Modify: `tests/CallbackVerifierTest.php`

- [ ] **Step 1: Write failing callback security and idempotency tests**

```php
public function testEmptyCallbackSecretIsRejected()
public function testCallbackRequiresWorkflowFinishedEvent()
public function testCallbackRequiresBusinessTripleAndInstanceUuid()
public function testCallbackOnlyAcceptsApprovedOrRejected()
public function testFirstCallbackCreatesDeliveryAndUpdatesProjection()
public function testRepeatedIdempotencyKeyReturnsExistingDeliveryWithoutReapplying()
public function testMismatchedInstanceOrBusinessTripleIsRejected()
public function testTerminalStatusCannotBeOverwrittenByLaterDifferentResult()
```

- [ ] **Step 2: Run callback tests and verify failure**

Run: `vendor/bin/phpunit tests/CallbackVerifierTest.php tests/CallbackHandlerTest.php`

Expected: FAIL on empty-secret and inbox assertions.

- [ ] **Step 3: Make callback verification fail closed**

```php
if ($this->secret === '') {
    throw new RuntimeException('WORKFLOW_CALLBACK_SECRET is empty');
}
```

Validate timestamp as an integer string before comparing its range. Continue using `hash_equals` and canonical sorted JSON.

- [ ] **Step 4: Implement the payload validator**

Expose:

```php
public function validate(array $payload)
```

Require `workflow.finished`, non-empty keys, field lengths matching DDL, and an `approved|rejected` result. Throw `InvalidArgumentException` with a stable public message.

- [ ] **Step 5: Persist callback deliveries transactionally**

Within one transaction:

1. Insert or load `WorkflowCallbackDelivery` by idempotency key.
2. Lock the corresponding `WorkflowApprovalResult` by business triple.
3. Verify `instance_uuid` matches the persisted instance.
4. Reject conflicting terminal results.
5. Update the main projection and leave the immutable delivery payload intact.

Handle duplicate-key races by reloading the existing delivery and returning the associated result.

- [ ] **Step 6: Return safe controller errors**

Return `40001` with validation/signature messages for expected exceptions. Log unexpected exceptions and return a generic `Workflow callback failed` message without database details.

- [ ] **Step 7: Run callback and full tests**

Run: `vendor/bin/phpunit tests/CallbackVerifierTest.php tests/CallbackHandlerTest.php`

Expected: PASS.

Run: `vendor/bin/phpunit`

Expected: PASS.

- [ ] **Step 8: Commit callback inbox processing**

```bash
git add src/Callback src/Http src/Models/WorkflowCallbackDelivery.php tests
git commit -m "feat: secure and audit workflow callbacks"
```

### Task 5: Make Business Result Application Claimable and Retryable

**Files:**
- Create: `src/Application/ResultApplicationService.php`
- Modify: `src/Console/ApplyResultsCommand.php`
- Modify: `src/WorkflowBridgeServiceProvider.php`
- Modify: `src/Contracts/ResultApplier.php`
- Create: `tests/ResultApplicationServiceTest.php`

- [ ] **Step 1: Write failing claim and retry tests**

```php
public function testOnlyOneWorkerCanClaimAnApplication()
public function testSuccessfulApplicationBecomesApplied()
public function testFalseResultBecomesSkipped()
public function testExceptionBecomesFailedWithNextRetryTime()
public function testFailedApplicationCanBeClaimedAfterRetryTime()
public function testThrowableDoesNotLeaveARecordPermanentlyProcessing()
```

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor/bin/phpunit tests/ResultApplicationServiceTest.php`

Expected: FAIL because the application service is absent.

- [ ] **Step 3: Implement conditional claim semantics**

Claim a single row by ID only when it is terminal, due, and `pending` or `failed`. Update it atomically to `processing`, increment attempts, and set `apply_processing_at`.

- [ ] **Step 4: Apply and persist the outcome**

Call `ResultApplier::apply()` after claiming. Persist `applied`, `skipped`, or `failed`; on failure store a truncated message and exponential retry time. Catch `Throwable`, persist the failure, and continue processing other IDs.

Update the contract documentation to require host-side idempotency by result ID or business triple.

- [ ] **Step 5: Refactor the command to process IDs in bounded batches**

`workflow:apply-results` delegates each ID to `ResultApplicationService`. Add `--include-failed` defaulting to enabled behavior and preserve `--dry-run` without claiming rows.

- [ ] **Step 6: Run focused and full tests**

Run: `vendor/bin/phpunit tests/ResultApplicationServiceTest.php`

Expected: PASS.

Run: `vendor/bin/phpunit`

Expected: PASS.

- [ ] **Step 7: Commit result application reliability**

```bash
git add src/Application src/Console/ApplyResultsCommand.php src/Contracts src/WorkflowBridgeServiceProvider.php tests/ResultApplicationServiceTest.php
git commit -m "feat: claim and retry workflow result application"
```

### Task 6: Tighten Query and HTTP Client Contracts

**Files:**
- Modify: `src/Bridge/WorkflowBridge.php`
- Modify: `src/Client/WorkflowClient.php`
- Create: `src/Exceptions/WorkflowRequestException.php`
- Create: `tests/WorkflowClientTest.php`
- Modify: `tests/WorkflowBridgeStartTest.php`

- [ ] **Step 1: Write failing client and query tests**

```php
public function testUnauthorizedResponseClearsTokenAndRetriesLoginOnce()
public function testBusinessErrorThrowsWorkflowRequestException()
public function testMapResultsRequiresProcessCode()
public function testMapResultsReturnsOneEntryPerRequestedBusinessKey()
```

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor/bin/phpunit tests/WorkflowClientTest.php tests/WorkflowBridgeStartTest.php`

Expected: FAIL on exception type, token refresh, and missing process-code validation.

- [ ] **Step 3: Add a typed package exception without PHP 8 syntax**

```php
class WorkflowRequestException extends RuntimeException
{
    protected $statusCode;
    protected $workflowCode;

    public function __construct($message, $statusCode = 0, $workflowCode = 0)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->workflowCode = $workflowCode;
    }
}
```

- [ ] **Step 4: Retry one expired-token request**

When an authenticated request receives HTTP 401, clear the in-memory token, call `login()`, and retry exactly once. Other HTTP/business failures throw `WorkflowRequestException` with response metadata.

- [ ] **Step 5: Make bulk mapping unambiguous**

Require non-empty `processCode` in `mapResultsByBusinessKeys()`. Normalize and deduplicate business keys before `whereIn`, then key results by business key.

- [ ] **Step 6: Run focused and full tests**

Run: `vendor/bin/phpunit tests/WorkflowClientTest.php tests/WorkflowBridgeStartTest.php`

Expected: PASS.

Run: `vendor/bin/phpunit`

Expected: PASS.

- [ ] **Step 7: Commit client and query hardening**

```bash
git add src/Bridge src/Client src/Exceptions tests
git commit -m "fix: harden workflow client and result queries"
```

### Task 7: Update Installation, Operations, and Upgrade Documentation

**Files:**
- Modify: `README.md`
- Modify: `UPGRADE.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/IC_SKC_APPROVAL_INTEGRATION.md`
- Modify: `docs/PUBLISH_AND_IC_INSTALL.md`
- Modify: `config/workflow-bridge.php`
- Create: `tests/ConfigurationTest.php`

- [ ] **Step 1: Add configuration tests**

Assert positive defaults for start/apply lease seconds, retry base seconds, and maximum retry seconds. Assert callback secret remains empty by default but the verifier rejects it at runtime.

- [ ] **Step 2: Add retry and lease configuration**

```php
'start_lease_seconds' => max(30, (int) env('WORKFLOW_START_LEASE_SECONDS', 300)),
'start_retry_base_seconds' => max(10, (int) env('WORKFLOW_START_RETRY_BASE_SECONDS', 60)),
'start_retry_max_seconds' => max(60, (int) env('WORKFLOW_START_RETRY_MAX_SECONDS', 3600)),
'apply_lease_seconds' => max(30, (int) env('WORKFLOW_APPLY_LEASE_SECONDS', 300)),
'apply_retry_base_seconds' => max(10, (int) env('WORKFLOW_APPLY_RETRY_BASE_SECONDS', 60)),
'apply_retry_max_seconds' => max(60, (int) env('WORKFLOW_APPLY_RETRY_MAX_SECONDS', 3600)),
```

- [ ] **Step 3: Update operational examples**

Replace direct Job construction with:

```php
app(WorkflowBridge::class)->dispatchProcess($processCode, $businessKey, [
    'owner_system' => 'ic',
    'business_payload' => $payload,
    'input' => $payload,
]);
```

Document scheduler entries for retry-start and apply-results, CSRF/middleware exclusions for callback routes, fail-closed callback secrets, and host `ResultApplier` idempotency.

- [ ] **Step 4: Document the pre-launch schema reset rule**

State that there is one final DDL. Development databases using experimental schemas may drop and recreate both tables only when no data must be retained; production upgrades begin only after the first live release.

- [ ] **Step 5: Update changelog for 1.1.0**

List schema additions, callback inbox, state semantics, new dispatch API, command behavior changes, and the direct Job constructor compatibility change.

- [ ] **Step 6: Run documentation and configuration checks**

Run: `vendor/bin/phpunit tests/ConfigurationTest.php`

Expected: PASS.

Run: `composer validate --strict`

Expected: valid composer metadata.

- [ ] **Step 7: Commit documentation and configuration**

```bash
git add README.md UPGRADE.md CHANGELOG.md docs config tests/ConfigurationTest.php
git commit -m "docs: document workflow bridge 1.1 operations"
```

### Task 8: Verify Compatibility and Prepare the Release Tree

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `composer.json`

- [ ] **Step 1: Expand CI to test the supported boundaries**

Use explicit dependency jobs so PHP 7.0/7.4 tests Illuminate 5.5 and PHP 8.1 tests Illuminate 10. Do not rely on Composer selecting an arbitrary highest compatible version. Add MySQL service coverage for the final install SQL while retaining SQLite for fast tests.

- [ ] **Step 2: Run the complete local verification suite**

Run:

```bash
composer validate --strict
vendor/bin/phpunit
find src config database/migrations tests -name '*.php' -exec php -l {} \;
git diff --check
```

Expected: all commands exit zero; PHPUnit reports zero failures and zero errors.

- [ ] **Step 3: Inspect the release tree**

Run:

```bash
git status --short
git ls-files | rg '^(vendor/|composer\.lock$|\.env$)'
git diff v1.0.0..HEAD --stat
```

Expected: clean worktree after commits; no tracked vendor, lock, or environment file; the diff contains source, schema, tests, and documentation for `1.1.0`.

- [ ] **Step 4: Commit CI compatibility coverage**

```bash
git add .github/workflows/ci.yml composer.json
git commit -m "ci: verify workflow bridge compatibility matrix"
```

- [ ] **Step 5: Stop before tagging or pushing**

Report the verified commit list and release command recommendation. Do not create `v1.1.0` or push without explicit user instruction.
