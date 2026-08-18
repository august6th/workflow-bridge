<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Application\ResultApplicationService;
use August6th\WorkflowBridge\Contracts\ResultApplier;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use RuntimeException;

class ResultApplicationServiceTest extends TestCase
{
    public function testOnlyOneWorkerCanClaimAnApplication()
    {
        $result = $this->createTerminalResult('APPLY-001');
        $applier = $this->createMock(ResultApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(true);
        $service = new ResultApplicationService($applier);

        $first = $service->process($result->id);
        $second = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $first->local_apply_status);
        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $second->local_apply_status);
        $this->assertSame(1, $second->apply_attempts);
    }

    public function testSuccessfulApplicationBecomesApplied()
    {
        $result = $this->createTerminalResult('APPLY-002');
        $applier = $this->createMock(ResultApplier::class);
        $applier->method('apply')->willReturn(true);
        $service = new ResultApplicationService($applier);

        $applied = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $applied->local_apply_status);
        $this->assertSame('', $applied->local_apply_error);
        $this->assertSame(1, $applied->apply_attempts);
        $this->assertNotSame('1970-01-01 00:00:00', $applied->applied_at);
    }

    public function testFalseResultBecomesSkipped()
    {
        $result = $this->createTerminalResult('APPLY-003');
        $applier = $this->createMock(ResultApplier::class);
        $applier->method('apply')->willReturn(false);
        $service = new ResultApplicationService($applier);

        $skipped = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_SKIPPED, $skipped->local_apply_status);
        $this->assertSame('', $skipped->local_apply_error);
    }

    public function testExceptionBecomesFailedWithNextRetryTime()
    {
        $result = $this->createTerminalResult('APPLY-004');
        $applier = $this->createMock(ResultApplier::class);
        $applier->method('apply')->willThrowException(new RuntimeException('host unavailable'));
        $service = new ResultApplicationService($applier, [
            'apply_retry_base_seconds' => 60,
            'apply_retry_max_seconds' => 3600,
        ]);
        $before = date('Y-m-d H:i:s', time() + 59);

        $failed = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_FAILED, $failed->local_apply_status);
        $this->assertSame('host unavailable', $failed->local_apply_error);
        $this->assertGreaterThan($before, $failed->apply_next_retry_at);
    }

    public function testFailedApplicationCanBeClaimedAfterRetryTime()
    {
        $result = $this->createTerminalResult('APPLY-005');
        $result->local_apply_status = WorkflowApprovalResult::APPLY_FAILED;
        $result->apply_attempts = 1;
        $result->apply_next_retry_at = '2026-08-18 00:00:00';
        $result->save();
        $applier = $this->createMock(ResultApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(true);
        $service = new ResultApplicationService($applier);

        $applied = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $applied->local_apply_status);
        $this->assertSame(2, $applied->apply_attempts);
    }

    public function testThrowableDoesNotLeaveARecordPermanentlyProcessing()
    {
        $result = $this->createTerminalResult('APPLY-006');
        $applier = $this->createMock(ResultApplier::class);
        $applier->method('apply')->willReturnCallback(function () {
            throw new \Error('host type failure');
        });
        $service = new ResultApplicationService($applier);

        $failed = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_FAILED, $failed->local_apply_status);
        $this->assertSame('host type failure', $failed->local_apply_error);
    }

    public function testProcessDueRecoversExpiredProcessingLease()
    {
        $result = $this->createTerminalResult('APPLY-007');
        $result->local_apply_status = WorkflowApprovalResult::APPLY_PROCESSING;
        $result->apply_processing_at = '2026-08-18 00:00:00';
        $result->save();
        $applier = $this->createMock(ResultApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(true);
        $service = new ResultApplicationService($applier, ['apply_lease_seconds' => 300]);

        $stats = $service->processDue([
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'limit' => 10,
        ]);

        $this->assertSame(['processed' => 1, 'applied' => 1, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $result->fresh()->local_apply_status);
    }

    public function testProcessDueWithoutOwnerFilterProcessesAllOwners()
    {
        $this->createTerminalResult('APPLY-008');
        $this->createTerminalResult('APPLY-009', 'listing');
        $applier = $this->createMock(ResultApplier::class);
        $applier->expects($this->exactly(2))->method('apply')->willReturn(true);
        $service = new ResultApplicationService($applier, ['owner_system' => 'ic']);

        $stats = $service->processDue(['process_code' => 'skc_approval', 'limit' => 10]);

        $this->assertSame(2, $stats['processed']);
        $this->assertSame(2, $stats['applied']);
    }

    protected function createTerminalResult($businessKey, $ownerSystem = 'ic')
    {
        $now = date('Y-m-d H:i:s');
        $result = new WorkflowApprovalResult();
        $result->business_key = $businessKey;
        $result->owner_system = $ownerSystem;
        $result->process_code = 'skc_approval';
        $result->instance_uuid = 'pi_' . strtolower($businessKey);
        $result->start_status = WorkflowApprovalResult::START_SUCCEEDED;
        $result->workflow_status = WorkflowApprovalResult::STATUS_APPROVED;
        $result->result = WorkflowApprovalResult::STATUS_APPROVED;
        $result->start_idempotency_key = WorkflowApprovalResult::startIdempotencyKey(
            $ownerSystem,
            'skc_approval',
            $businessKey
        );
        $result->idempotency_key = $result->start_idempotency_key;
        $result->local_apply_status = WorkflowApprovalResult::APPLY_PENDING;
        $result->apply_next_retry_at = $now;
        $result->created_at = $now;
        $result->updated_at = $now;
        $result->save();

        return $result;
    }
}
