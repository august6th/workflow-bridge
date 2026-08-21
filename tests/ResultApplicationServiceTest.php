<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Application\ResultApplicationService;
use August6th\WorkflowBridge\Application\ResultApplierRegistry;
use August6th\WorkflowBridge\Contracts\ResultApplier;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Illuminate\Container\Container;
use InvalidArgumentException;
use RuntimeException;

class ResultApplicationServiceTest extends TestCase
{
    public function testOnlyOneWorkerCanClaimAnApplication()
    {
        $result = $this->createTerminalResult('APPLY-001');
        $applier = $this->createMock(ResultApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(true);
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier));

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
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier));

        $applied = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $applied->local_apply_status);
        $this->assertSame('', $applied->local_apply_error);
        $this->assertSame(1, $applied->apply_attempts);
        $this->assertNotNull($applied->applied_at);
        $this->assertNull($applied->apply_next_retry_at);
        $this->assertNull($applied->apply_processing_at);
    }

    public function testFalseResultBecomesSkipped()
    {
        $result = $this->createTerminalResult('APPLY-003');
        $applier = $this->createMock(ResultApplier::class);
        $applier->method('apply')->willReturn(false);
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier));

        $skipped = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_SKIPPED, $skipped->local_apply_status);
        $this->assertSame('', $skipped->local_apply_error);
    }

    public function testExceptionBecomesFailedWithNextRetryTime()
    {
        $result = $this->createTerminalResult('APPLY-004');
        $applier = $this->createMock(ResultApplier::class);
        $applier->method('apply')->willThrowException(new RuntimeException('host unavailable'));
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier), [
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
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier));

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
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier));

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
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier), ['apply_lease_seconds' => 300]);

        $stats = $service->processDue([
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'limit' => 10,
        ]);

        $this->assertSame(['processed' => 1, 'applied' => 1, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $result->fresh()->local_apply_status);
    }

    public function testProcessDueRecoversAtMostLimitExpiredLeases()
    {
        $first = $this->createTerminalResult('APPLY-LEASE-LIMIT-1');
        $second = $this->createTerminalResult('APPLY-LEASE-LIMIT-2');
        foreach ([$first, $second] as $result) {
            $result->local_apply_status = WorkflowApprovalResult::APPLY_PROCESSING;
            $result->apply_processing_at = '2026-08-18 00:00:00';
            $result->save();
        }
        $applier = $this->createMock(ResultApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(true);
        $service = new ResultApplicationService(
            $this->registryWith('ic', 'skc_approval', $applier),
            ['apply_lease_seconds' => 300]
        );

        $stats = $service->processDue([
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'limit' => 1,
        ]);

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $first->fresh()->local_apply_status);
        $this->assertSame(WorkflowApprovalResult::APPLY_PROCESSING, $second->fresh()->local_apply_status);
    }

    public function testProcessDueDoesNotCountAResultClaimedByAnotherWorker()
    {
        $result = $this->createTerminalResult('APPLY-CLAIMED');
        $applier = $this->createMock(ResultApplier::class);
        $applier->expects($this->never())->method('apply');
        $service = new CompetingResultApplicationService($this->registryWith('ic', 'skc_approval', $applier));

        $stats = $service->processDue([
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'limit' => 10,
        ]);

        $this->assertSame(['processed' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertSame(WorkflowApprovalResult::APPLY_PROCESSING, $result->fresh()->local_apply_status);
    }

    public function testBusinessKeyCandidatesStillRespectRetryBackoff()
    {
        $due = $this->createTerminalResult('APPLY-DUE');
        $future = $this->createTerminalResult('APPLY-FUTURE');
        $future->local_apply_status = WorkflowApprovalResult::APPLY_FAILED;
        $future->apply_next_retry_at = date('Y-m-d H:i:s', time() + 3600);
        $future->save();
        $applier = $this->createMock(ResultApplier::class);
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier));

        $ids = $service->dueResults([
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'business_keys' => ['APPLY-DUE', 'APPLY-FUTURE'],
            'include_failed' => true,
            'limit' => 10,
        ])->pluck('id')->all();

        $this->assertSame([$due->id], $ids);
    }

    public function testProcessDueRoutesEachResultToItsExactApplier()
    {
        $this->createTerminalResult('APPLY-008', 'ic', 'skc_approval');
        $this->createTerminalResult('APPLY-009', 'listing', 'publish_approval');
        $icApplier = $this->createMock(ResultApplier::class);
        $icApplier->expects($this->once())->method('apply')->willReturn(true);
        $listingApplier = $this->createMock(ResultApplier::class);
        $listingApplier->expects($this->once())->method('apply')->willReturn(false);
        $registry = $this->registryWith('ic', 'skc_approval', $icApplier);
        $registry->register('listing', 'publish_approval', $listingApplier);

        $stats = (new ResultApplicationService($registry))->processDue(['limit' => 10]);

        $this->assertSame(['processed' => 2, 'applied' => 1, 'skipped' => 1, 'failed' => 0], $stats);
    }

    public function testUnregisteredResultIsNotListedClaimedOrChanged()
    {
        $registered = $this->createTerminalResult('APPLY-010', 'ic', 'skc_approval');
        $unregistered = $this->createTerminalResult('APPLY-011', 'ic', 'other_process');
        $applier = $this->createMock(ResultApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(true);
        $service = new ResultApplicationService($this->registryWith('ic', 'skc_approval', $applier));

        $dueIds = $service->dueResults(['limit' => 10])->pluck('id')->all();
        $stats = $service->processDue(['limit' => 10]);

        $this->assertSame([$registered->id], $dueIds);
        $this->assertSame(1, $stats['processed']);
        $this->assertSame(WorkflowApprovalResult::APPLY_PENDING, $unregistered->fresh()->local_apply_status);
        $this->assertSame(0, $unregistered->fresh()->apply_attempts);
    }

    public function testDirectProcessDoesNotClaimUnregisteredResult()
    {
        $result = $this->createTerminalResult('APPLY-012', 'listing', 'publish_approval');
        $service = new ResultApplicationService(new ResultApplierRegistry(new Container()));

        $processed = $service->process($result->id);

        $this->assertSame(WorkflowApprovalResult::APPLY_PENDING, $processed->local_apply_status);
        $this->assertSame(0, $processed->apply_attempts);
    }

    public function testExplicitRouteFilterMustProvideBothKeys()
    {
        $service = new ResultApplicationService(new ResultApplierRegistry(new Container()));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be provided together');

        $service->dueResults(['owner_system' => 'ic']);
    }

    public function testExplicitRouteFilterRejectsNonStringValues()
    {
        $service = new ResultApplicationService(new ResultApplierRegistry(new Container()));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('filters must be strings');

        $service->dueResults([
            'owner_system' => ['ic'],
            'process_code' => 'skc_approval',
        ]);
    }

    public function testExplicitRouteFilterMustBeRegistered()
    {
        $service = new ResultApplicationService(new ResultApplierRegistry(new Container()));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No result applier registered');

        $service->processDue([
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
        ]);
    }

    public function testExplicitRouteFilterOnlyProcessesThatRoute()
    {
        $icResult = $this->createTerminalResult('APPLY-013', 'ic', 'skc_approval');
        $listingResult = $this->createTerminalResult('APPLY-014', 'listing', 'publish_approval');
        $icApplier = $this->createMock(ResultApplier::class);
        $icApplier->expects($this->once())->method('apply')->willReturn(true);
        $listingApplier = $this->createMock(ResultApplier::class);
        $listingApplier->expects($this->never())->method('apply');
        $registry = $this->registryWith('ic', 'skc_approval', $icApplier);
        $registry->register('listing', 'publish_approval', $listingApplier);
        $service = new ResultApplicationService($registry);

        $stats = $service->processDue([
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'limit' => 10,
        ]);

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(WorkflowApprovalResult::APPLY_APPLIED, $icResult->fresh()->local_apply_status);
        $this->assertSame(WorkflowApprovalResult::APPLY_PENDING, $listingResult->fresh()->local_apply_status);
    }

    protected function registryWith($ownerSystem, $processCode, ResultApplier $applier)
    {
        $registry = new ResultApplierRegistry(new Container());
        $registry->register($ownerSystem, $processCode, $applier);

        return $registry;
    }

    protected function createTerminalResult($businessKey, $ownerSystem = 'ic', $processCode = 'skc_approval')
    {
        $now = date('Y-m-d H:i:s');
        $result = new WorkflowApprovalResult();
        $result->business_key = $businessKey;
        $result->owner_system = $ownerSystem;
        $result->process_code = $processCode;
        $result->instance_uuid = 'pi_' . strtolower($businessKey);
        $result->start_status = WorkflowApprovalResult::START_SUCCEEDED;
        $result->workflow_status = WorkflowApprovalResult::STATUS_APPROVED;
        $result->result = WorkflowApprovalResult::STATUS_APPROVED;
        $result->start_idempotency_key = WorkflowApprovalResult::startIdempotencyKey(
            $ownerSystem,
            $processCode,
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

class CompetingResultApplicationService extends ResultApplicationService
{
    protected function processWithClaim($approvalResultId)
    {
        WorkflowApprovalResult::where('id', $approvalResultId)->update([
            'local_apply_status' => WorkflowApprovalResult::APPLY_PROCESSING,
            'apply_processing_at' => date('Y-m-d H:i:s'),
        ]);

        return parent::processWithClaim($approvalResultId);
    }
}
