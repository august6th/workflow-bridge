<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Callback\CallbackHandler;
use August6th\WorkflowBridge\Callback\CallbackVerifier;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use August6th\WorkflowBridge\Models\WorkflowCallbackDelivery;
use InvalidArgumentException;

class CallbackHandlerTest extends TestCase
{
    public function testCallbackRequiresWorkflowFinishedEvent()
    {
        $this->expectException(InvalidArgumentException::class);

        list($handler, $headers, $payload) = $this->signedCallback([
            'event' => 'workflow.started',
        ]);
        $handler->handle($headers, $payload);
    }

    public function testCallbackRequiresBusinessTripleAndInstanceUuid()
    {
        $this->expectException(InvalidArgumentException::class);

        list($handler, $headers, $payload) = $this->signedCallback([
            'business_key' => '',
            'instance_uuid' => '',
        ]);
        $handler->handle($headers, $payload);
    }

    public function testFirstCallbackCreatesDeliveryAndUpdatesProjection()
    {
        $result = $this->createWaitingResult('A100', 'pi_a100');
        list($handler, $headers, $payload) = $this->signedCallback([
            'business_key' => 'A100',
            'instance_uuid' => 'pi_a100',
        ]);

        $handled = $handler->handle($headers, $payload);

        $this->assertSame($result->id, $handled->id);
        $this->assertSame(WorkflowApprovalResult::STATUS_APPROVED, $handled->workflow_status);
        $this->assertSame(WorkflowApprovalResult::APPLY_PENDING, $handled->local_apply_status);
        $this->assertSame(1, WorkflowCallbackDelivery::count());
        $delivery = WorkflowCallbackDelivery::first();
        $this->assertSame($result->id, $delivery->approval_result_id);
        $this->assertSame('callback:a100:approved', $delivery->idempotency_key);
    }

    public function testRepeatedIdempotencyKeyDoesNotCreateOrApplyTwice()
    {
        $this->createWaitingResult('A101', 'pi_a101');
        list($handler, $headers, $payload) = $this->signedCallback([
            'business_key' => 'A101',
            'instance_uuid' => 'pi_a101',
            'idempotency_key' => 'callback:a101:approved',
        ]);

        $first = $handler->handle($headers, $payload);
        $second = $handler->handle($headers, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WorkflowCallbackDelivery::count());
    }

    public function testMismatchedInstanceIsRejected()
    {
        $this->createWaitingResult('A102', 'pi_a102');
        list($handler, $headers, $payload) = $this->signedCallback([
            'business_key' => 'A102',
            'instance_uuid' => 'pi_other',
            'idempotency_key' => 'callback:a102:approved',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $handler->handle($headers, $payload);
    }

    public function testTerminalStatusCannotBeOverwrittenByDifferentResult()
    {
        $result = $this->createWaitingResult('A103', 'pi_a103');
        $result->workflow_status = WorkflowApprovalResult::STATUS_APPROVED;
        $result->result = WorkflowApprovalResult::STATUS_APPROVED;
        $result->save();
        list($handler, $headers, $payload) = $this->signedCallback([
            'business_key' => 'A103',
            'instance_uuid' => 'pi_a103',
            'result' => 'rejected',
            'result_value' => 'rejected',
            'idempotency_key' => 'callback:a103:rejected',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $handler->handle($headers, $payload);
    }

    protected function createWaitingResult($businessKey, $instanceUuid)
    {
        $now = date('Y-m-d H:i:s');
        $result = new WorkflowApprovalResult();
        $result->business_key = $businessKey;
        $result->owner_system = 'ic';
        $result->process_code = 'skc_approval';
        $result->instance_uuid = $instanceUuid;
        $result->start_status = WorkflowApprovalResult::START_SUCCEEDED;
        $result->workflow_status = WorkflowApprovalResult::STATUS_WAITING;
        $result->start_idempotency_key = WorkflowApprovalResult::startIdempotencyKey('ic', 'skc_approval', $businessKey);
        $result->idempotency_key = $result->start_idempotency_key;
        $result->local_apply_status = WorkflowApprovalResult::APPLY_PENDING;
        $result->created_at = $now;
        $result->updated_at = $now;
        $result->save();

        return $result;
    }

    protected function signedCallback(array $overrides = [])
    {
        $secret = 'callback-secret';
        $payload = array_merge([
            'event' => 'workflow.finished',
            'business_key' => 'A100',
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'instance_uuid' => 'pi_a100',
            'result' => 'approved',
            'result_value' => 'approved',
            'idempotency_key' => 'callback:a100:approved',
            'delivery_id' => 'delivery-a100',
            'finished_at' => '2026-08-18 12:00:00',
        ], $overrides);
        $timestamp = (string) time();
        $nonce = 'nonce-' . $payload['idempotency_key'];
        $verifier = new CallbackVerifier($secret, 300);
        $signature = hash_hmac('sha256', implode("\n", [
            $timestamp,
            $nonce,
            $payload['idempotency_key'],
            $verifier->jsonForSignature($payload),
        ]), $secret);
        $headers = [
            'X-Workflow-Timestamp' => $timestamp,
            'X-Workflow-Nonce' => $nonce,
            'X-Workflow-Signature' => $signature,
            'X-Workflow-Idempotency-Key' => $payload['idempotency_key'],
            'X-Workflow-Delivery-Id' => $payload['delivery_id'],
        ];

        return [new CallbackHandler($verifier), $headers, $payload];
    }
}
