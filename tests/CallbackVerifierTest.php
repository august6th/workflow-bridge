<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Callback\CallbackVerifier;
use August6th\WorkflowBridge\Jobs\StartWorkflowProcessJob;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use PHPUnit\Framework\TestCase;

class CallbackVerifierTest extends TestCase
{
    public function testVerifyAcceptsValidSignature()
    {
        $secret = 'callback-secret';
        $payload = [
            'event' => 'workflow.finished',
            'business_key' => 'A001',
            'result' => 'approved',
            'idempotency_key' => 'workflow:uuid:cb:1:approved',
        ];
        $timestamp = (string) time();
        $nonce = 'abc123';
        $verifier = new CallbackVerifier($secret, 300);
        $signature = hash_hmac('sha256', implode("\n", [
            $timestamp,
            $nonce,
            $payload['idempotency_key'],
            $verifier->jsonForSignature($payload),
        ]), $secret);

        $verifier->verify([
            'X-Workflow-Timestamp' => $timestamp,
            'X-Workflow-Nonce' => $nonce,
            'X-Workflow-Signature' => $signature,
            'X-Workflow-Idempotency-Key' => $payload['idempotency_key'],
        ], $payload);

        $this->assertTrue(true);
    }

    public function testVerifyRejectsBadSignature()
    {
        $this->expectException(\RuntimeException::class);
        $verifier = new CallbackVerifier('secret', 300);
        $payload = ['idempotency_key' => 'k1', 'a' => 1];
        $verifier->verify([
            'X-Workflow-Timestamp' => (string) time(),
            'X-Workflow-Nonce' => 'n',
            'X-Workflow-Signature' => 'bad',
            'X-Workflow-Idempotency-Key' => 'k1',
        ], $payload);
    }

    public function testStartIdempotencyKeyIsStableHash()
    {
        $this->assertSame(
            'aaa8e4ad51fa4c426209f29d155f658cbbfb93fc93f605f49de9a873c02df162',
            WorkflowApprovalResult::startIdempotencyKey('ic', 'skc_approval', 'NO1')
        );
    }

    public function testStatusLabel()
    {
        $this->assertSame('发起失败', WorkflowApprovalResult::statusLabel('start_failed'));
        $this->assertSame('审批中', WorkflowApprovalResult::statusLabel('waiting'));
    }

    public function testStartJobCarriesPersistedResultId()
    {
        $job = new StartWorkflowProcessJob(42);

        $this->assertSame(42, $job->approvalResultId);
        $this->assertSame(1, $job->tries);
    }
}
