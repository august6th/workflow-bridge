<?php

namespace August6th\WorkflowBridge\Callback;

use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use RuntimeException;

class CallbackHandler
{
    /** @var CallbackVerifier */
    protected $verifier;

    public function __construct(CallbackVerifier $verifier)
    {
        $this->verifier = $verifier;
    }

    /**
     * @param array $headers
     * @param array $payload
     * @return WorkflowApprovalResult
     */
    public function handle(array $headers, array $payload)
    {
        $this->verifier->verify($headers, $payload);

        $idempotencyKey = $this->header($headers, 'X-Workflow-Idempotency-Key');
        if ($idempotencyKey === '' && isset($payload['idempotency_key'])) {
            $idempotencyKey = (string) $payload['idempotency_key'];
        }
        if ($idempotencyKey === '') {
            throw new RuntimeException('Missing workflow callback idempotency key');
        }

        $existing = WorkflowApprovalResult::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $businessKey = isset($payload['business_key']) ? (string) $payload['business_key'] : '';
        $ownerSystem = isset($payload['owner_system']) ? (string) $payload['owner_system'] : '';
        $processCode = isset($payload['process_code']) ? (string) $payload['process_code'] : '';
        $result = isset($payload['result']) ? (string) $payload['result'] : '';
        $resultValue = isset($payload['result_value']) ? (string) $payload['result_value'] : $result;
        $instanceUuid = isset($payload['instance_uuid']) ? (string) $payload['instance_uuid'] : '';
        $deliveryId = $this->header($headers, 'X-Workflow-Delivery-Id');
        if ($deliveryId === '' && isset($payload['delivery_id'])) {
            $deliveryId = (string) $payload['delivery_id'];
        }
        $finishedAt = isset($payload['finished_at']) ? (string) $payload['finished_at'] : date('Y-m-d H:i:s');
        $now = date('Y-m-d H:i:s');

        $row = null;
        if ($businessKey !== '' && $ownerSystem !== '' && $processCode !== '') {
            $row = WorkflowApprovalResult::where('business_key', $businessKey)
                ->where('owner_system', $ownerSystem)
                ->where('process_code', $processCode)
                ->first();
        }

        if (!$row && $instanceUuid !== '') {
            $row = WorkflowApprovalResult::where('instance_uuid', $instanceUuid)->first();
        }

        if (!$row) {
            $row = new WorkflowApprovalResult();
            $row->business_key = $businessKey;
            $row->owner_system = $ownerSystem;
            $row->process_code = $processCode;
            $row->started_at = $now;
            $row->created_at = $now;
            $row->local_apply_status = WorkflowApprovalResult::APPLY_PENDING;
        }

        $row->instance_uuid = $instanceUuid !== '' ? $instanceUuid : (string) $row->instance_uuid;
        $row->workflow_status = in_array($result, [
            WorkflowApprovalResult::STATUS_APPROVED,
            WorkflowApprovalResult::STATUS_REJECTED,
        ], true) ? $result : WorkflowApprovalResult::STATUS_WAITING;
        $row->result = $result;
        $row->result_value = $resultValue;
        $row->idempotency_key = $idempotencyKey;
        $row->delivery_id = $deliveryId;
        $row->callback_payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row->start_error = '';
        $row->finished_at = $finishedAt !== '' ? $finishedAt : $now;
        $row->updated_at = $now;
        if ($row->local_apply_status === '' || $row->local_apply_status === null) {
            $row->local_apply_status = WorkflowApprovalResult::APPLY_PENDING;
        }
        $row->save();

        return $row;
    }

    /**
     * @param array $headers
     * @param string $name
     * @return string
     */
    protected function header(array $headers, $name)
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                if (is_array($value)) {
                    return isset($value[0]) ? (string) $value[0] : '';
                }

                return (string) $value;
            }
        }

        return '';
    }
}
