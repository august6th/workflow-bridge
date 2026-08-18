<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Bridge\WorkflowBridge;
use August6th\WorkflowBridge\Client\WorkflowClient;
use August6th\WorkflowBridge\Jobs\StartWorkflowProcessJob;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use August6th\WorkflowBridge\Start\StartWorkflowProcessor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class WorkflowBridgeStartTest extends TestCase
{
    public function testRequestProcessCreatesPendingRowBeforeRemoteStart()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->expects($this->never())->method('startProcess');
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);

        $result = $bridge->requestProcess('skc_approval', 'A001', [
            'business_payload' => ['approval_no' => 'A001'],
            'input' => ['approval_no' => 'A001'],
            'process_version' => 3,
        ]);

        $this->assertSame(WorkflowApprovalResult::START_PENDING, $result->start_status);
        $this->assertSame(WorkflowApprovalResult::STATUS_NOT_STARTED, $result->workflow_status);
        $this->assertSame(3, $result->requested_process_version);
        $this->assertSame(1, WorkflowApprovalResult::count());
    }

    public function testRepeatedRequestReturnsSameBusinessTripleRow()
    {
        $client = $this->createMock(WorkflowClient::class);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);

        $first = $bridge->requestProcess('skc_approval', 'A002');
        $second = $bridge->requestProcess('skc_approval', 'A002');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->start_idempotency_key, $second->start_idempotency_key);
        $this->assertSame(1, WorkflowApprovalResult::count());
    }

    public function testConcurrentRequestReturnsRowCreatedByUniqueKeyRace()
    {
        $client = $this->createMock(WorkflowClient::class);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $inserted = false;
        WorkflowApprovalResult::creating(function () use (&$inserted) {
            if ($inserted) {
                return;
            }
            $inserted = true;
            $now = date('Y-m-d H:i:s');
            $idempotencyKey = WorkflowApprovalResult::startIdempotencyKey(
                'ic',
                'skc_approval',
                'A002-RACE'
            );
            DB::table('workflow_approval_results')->insert([
                'business_key' => 'A002-RACE',
                'owner_system' => 'ic',
                'process_code' => 'skc_approval',
                'start_status' => WorkflowApprovalResult::START_PENDING,
                'workflow_status' => WorkflowApprovalResult::STATUS_NOT_STARTED,
                'start_idempotency_key' => $idempotencyKey,
                'idempotency_key' => $idempotencyKey,
                'local_apply_status' => WorkflowApprovalResult::APPLY_PENDING,
                'start_next_retry_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        try {
            $result = $bridge->requestProcess('skc_approval', 'A002-RACE');
        } finally {
            WorkflowApprovalResult::flushEventListeners();
        }

        $this->assertSame('A002-RACE', $result->business_key);
        $this->assertSame(1, WorkflowApprovalResult::where('business_key', 'A002-RACE')->count());
    }

    public function testProcessorMarksSuccessfulStartAndStoresActualVersion()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->expects($this->once())
            ->method('startProcess')
            ->with('skc_approval', $this->callback(function (array $payload) {
                return $payload['business_key'] === 'A003'
                    && $payload['owner_system'] === 'ic'
                    && $payload['process_version'] === 3;
            }))
            ->willReturn([
                'data' => [
                    'instance' => [
                        'instance_uuid' => 'pi_a003',
                        'process_version' => 3,
                        'status' => 'waiting',
                        'started_at' => '2026-08-18 10:00:00',
                    ],
                ],
            ]);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $request = $bridge->requestProcess('skc_approval', 'A003', ['process_version' => 3]);
        $processor = new StartWorkflowProcessor($client, ['owner_system' => 'ic']);

        $result = $processor->process($request->id);

        $this->assertSame(WorkflowApprovalResult::START_SUCCEEDED, $result->start_status);
        $this->assertSame(WorkflowApprovalResult::STATUS_WAITING, $result->workflow_status);
        $this->assertSame('pi_a003', $result->instance_uuid);
        $this->assertSame(3, $result->process_version);
        $this->assertSame(1, $result->start_attempts);
        $this->assertNull($result->start_next_retry_at);
        $this->assertNull($result->start_processing_at);
    }

    public function testTimeoutQueryRecoveryMarksStartSucceeded()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->method('startProcess')->willThrowException(new RuntimeException('timeout'));
        $client->expects($this->once())
            ->method('queryByBusinessKey')
            ->with('A004', 'ic', 'skc_approval')
            ->willReturn([
                'data' => [
                    'instance' => [
                        'instance_uuid' => 'pi_a004',
                        'process_version' => 2,
                        'status' => 'waiting',
                    ],
                ],
            ]);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $request = $bridge->requestProcess('skc_approval', 'A004');
        $processor = new StartWorkflowProcessor($client, ['owner_system' => 'ic']);

        $result = $processor->process($request->id);

        $this->assertSame(WorkflowApprovalResult::START_SUCCEEDED, $result->start_status);
        $this->assertSame('pi_a004', $result->instance_uuid);
        $this->assertSame('', $result->start_error);
    }

    public function testConfirmedFailureStoresRetryMetadata()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->method('startProcess')->willThrowException(new RuntimeException('workflow unavailable'));
        $client->method('queryByBusinessKey')->willThrowException(new RuntimeException('query unavailable'));
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $request = $bridge->requestProcess('skc_approval', 'A005');
        $processor = new StartWorkflowProcessor($client, [
            'owner_system' => 'ic',
            'start_retry_base_seconds' => 60,
            'start_retry_max_seconds' => 3600,
        ]);
        $before = date('Y-m-d H:i:s', time() + 59);

        $result = $processor->process($request->id);

        $this->assertSame(WorkflowApprovalResult::START_FAILED, $result->start_status);
        $this->assertSame(WorkflowApprovalResult::STATUS_NOT_STARTED, $result->workflow_status);
        $this->assertSame('workflow unavailable', $result->start_error);
        $this->assertGreaterThan($before, $result->start_next_retry_at);
    }

    public function testProcessorClaimsAResultOnlyOnce()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->expects($this->once())->method('startProcess')->willReturn([
            'data' => [
                'instance' => [
                    'instance_uuid' => 'pi_a006',
                    'process_version' => 1,
                    'status' => 'waiting',
                ],
            ],
        ]);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $request = $bridge->requestProcess('skc_approval', 'A006');
        $processor = new StartWorkflowProcessor($client, ['owner_system' => 'ic']);

        $first = $processor->process($request->id);
        $second = $processor->process($request->id);

        $this->assertSame(WorkflowApprovalResult::START_SUCCEEDED, $first->start_status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $second->start_attempts);
    }

    public function testSynchronousStartUsesPersistedRequestAndProcessor()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->expects($this->once())->method('startProcess')->willReturn([
            'data' => [
                'instance' => [
                    'instance_uuid' => 'pi_a007',
                    'process_version' => 4,
                    'status' => 'waiting',
                ],
            ],
        ]);
        $processor = new StartWorkflowProcessor($client, ['owner_system' => 'ic']);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic'], $processor);

        $result = $bridge->startProcess('skc_approval', 'A007', ['process_version' => 4]);

        $this->assertSame(WorkflowApprovalResult::START_SUCCEEDED, $result->start_status);
        $this->assertSame('pi_a007', $result->instance_uuid);
        $this->assertSame(1, WorkflowApprovalResult::count());
    }

    public function testTerminalResultCannotBeStartedAgain()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->expects($this->never())->method('startProcess');
        $processor = new StartWorkflowProcessor($client, ['owner_system' => 'ic']);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic'], $processor);
        $request = $bridge->requestProcess('skc_approval', 'A008');
        $request->start_status = WorkflowApprovalResult::START_SUCCEEDED;
        $request->workflow_status = WorkflowApprovalResult::STATUS_APPROVED;
        $request->save();

        $result = $bridge->startProcess('skc_approval', 'A008');

        $this->assertSame($request->id, $result->id);
        $this->assertSame(WorkflowApprovalResult::STATUS_APPROVED, $result->workflow_status);
    }

    public function testStartJobCarriesOnlyPersistedResultId()
    {
        $job = new StartWorkflowProcessJob(123);

        $this->assertSame(123, $job->approvalResultId);
    }

    public function testProcessDueStartsOnlyEligibleRows()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->expects($this->once())->method('startProcess')->willReturn([
            'data' => [
                'instance' => [
                    'instance_uuid' => 'pi_due',
                    'process_version' => 1,
                    'status' => 'waiting',
                ],
            ],
        ]);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $bridge->requestProcess('skc_approval', 'DUE');
        $future = $bridge->requestProcess('skc_approval', 'FUTURE');
        $future->start_status = WorkflowApprovalResult::START_FAILED;
        $future->start_next_retry_at = '2099-01-01 00:00:00';
        $future->save();
        $processor = new StartWorkflowProcessor($client, ['owner_system' => 'ic']);

        $stats = $processor->processDue([
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'limit' => 10,
        ]);

        $this->assertSame(['processed' => 1, 'succeeded' => 1, 'failed' => 0], $stats);
        $this->assertSame(WorkflowApprovalResult::START_FAILED, $future->fresh()->start_status);
    }

    public function testDispatchProcessPersistsBeforeDispatchingResultId()
    {
        $client = $this->createMock(WorkflowClient::class);
        $processor = new StartWorkflowProcessor($client, ['owner_system' => 'ic']);
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (StartWorkflowProcessJob $job) {
                $result = WorkflowApprovalResult::find($job->approvalResultId);

                return $result && $result->start_status === WorkflowApprovalResult::START_PENDING;
            }));
        $bridge = new WorkflowBridge(
            $client,
            ['owner_system' => 'ic'],
            $processor,
            $dispatcher
        );

        $result = $bridge->dispatchProcess('skc_approval', 'A009');

        $this->assertSame(WorkflowApprovalResult::START_PENDING, $result->start_status);
    }

    public function testProcessDueRecoversExpiredProcessingLease()
    {
        $client = $this->createMock(WorkflowClient::class);
        $client->expects($this->once())->method('startProcess')->willReturn([
            'data' => [
                'instance' => [
                    'instance_uuid' => 'pi_stale',
                    'process_version' => 1,
                    'status' => 'waiting',
                ],
            ],
        ]);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $stale = $bridge->requestProcess('skc_approval', 'STALE');
        $stale->start_status = WorkflowApprovalResult::START_PROCESSING;
        $stale->start_processing_at = '2026-08-18 00:00:00';
        $stale->start_next_retry_at = '2026-08-18 00:00:00';
        $stale->save();
        $processor = new StartWorkflowProcessor($client, [
            'owner_system' => 'ic',
            'start_lease_seconds' => 300,
        ]);

        $stats = $processor->processDue(['owner_system' => 'ic']);

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(WorkflowApprovalResult::START_SUCCEEDED, $stale->fresh()->start_status);
    }

    public function testMapResultsRequiresProcessCode()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('process_code is required');

        $client = $this->createMock(WorkflowClient::class);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $bridge->mapResultsByBusinessKeys(['A010'], 'ic');
    }

    public function testMapResultsReturnsOneEntryPerRequestedBusinessKey()
    {
        $client = $this->createMock(WorkflowClient::class);
        $bridge = new WorkflowBridge($client, ['owner_system' => 'ic']);
        $first = $bridge->requestProcess('skc_approval', 'A010');
        $second = $bridge->requestProcess('skc_approval', 'A011');
        $otherProcess = $bridge->requestProcess('other_approval', 'A010');
        $first->workflow_status = WorkflowApprovalResult::STATUS_APPROVED;
        $first->save();
        $second->workflow_status = WorkflowApprovalResult::STATUS_REJECTED;
        $second->save();
        $otherProcess->workflow_status = WorkflowApprovalResult::STATUS_REJECTED;
        $otherProcess->save();

        $mapped = $bridge->mapResultsByBusinessKeys(
            ['A010', 'A010', '', 'A011', 'MISSING'],
            'ic',
            'skc_approval'
        );

        $this->assertSame(['A010', 'A011', 'MISSING'], $mapped->keys()->all());
        $this->assertSame($first->id, $mapped->get('A010')->id);
        $this->assertSame($second->id, $mapped->get('A011')->id);
        $this->assertNull($mapped->get('MISSING'));
    }
}
