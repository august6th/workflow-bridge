<?php

namespace August6th\WorkflowBridge\Callback;

use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use August6th\WorkflowBridge\Models\WorkflowCallbackDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class CallbackHandler
{
    /** @var CallbackVerifier */
    protected $verifier;

    /** @var CallbackPayloadValidator */
    protected $validator;

    public function __construct(CallbackVerifier $verifier, CallbackPayloadValidator $validator = null)
    {
        $this->verifier = $verifier;
        $this->validator = $validator ?: new CallbackPayloadValidator();
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
        $payload['idempotency_key'] = $idempotencyKey;

        $deliveryId = $this->header($headers, 'X-Workflow-Delivery-Id');
        if ($deliveryId === '' && isset($payload['delivery_id'])) {
            $deliveryId = $payload['delivery_id'];
        }
        $payload['delivery_id'] = $deliveryId;
        $this->validator->validate($payload);

        $existingDelivery = WorkflowCallbackDelivery::where('idempotency_key', $idempotencyKey)->first();
        if ($existingDelivery) {
            return $this->existingDeliveryResult($existingDelivery, $payload);
        }

        try {
            return DB::transaction(function () use ($payload, $idempotencyKey, $deliveryId) {
                $existingDelivery = WorkflowCallbackDelivery::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existingDelivery) {
                    return $this->existingDeliveryResult($existingDelivery, $payload);
                }

                $row = WorkflowApprovalResult::where('business_key', $payload['business_key'])
                    ->where('owner_system', $payload['owner_system'])
                    ->where('process_code', $payload['process_code'])
                    ->lockForUpdate()
                    ->first();
                if (!$row) {
                    throw new InvalidArgumentException('Workflow callback business record not found');
                }
                if ($row->instance_uuid !== $payload['instance_uuid']) {
                    throw new InvalidArgumentException('Workflow callback instance does not match business record');
                }
                if ($row->isTerminal() && $row->workflow_status !== $payload['result']) {
                    throw new InvalidArgumentException('Workflow callback cannot overwrite terminal result');
                }

                $now = date('Y-m-d H:i:s');
                $delivery = new WorkflowCallbackDelivery();
                $delivery->approval_result_id = $row->id;
                $delivery->business_key = $payload['business_key'];
                $delivery->owner_system = $payload['owner_system'];
                $delivery->process_code = $payload['process_code'];
                $delivery->instance_uuid = $payload['instance_uuid'];
                $delivery->event = $payload['event'];
                $delivery->result = $payload['result'];
                $delivery->result_value = isset($payload['result_value']) ? $payload['result_value'] : $payload['result'];
                $delivery->idempotency_key = $idempotencyKey;
                $delivery->delivery_id = $deliveryId;
                $delivery->payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $delivery->received_at = $now;
                $delivery->created_at = $now;
                $delivery->updated_at = $now;
                $delivery->save();

                $wasTerminal = $row->isTerminal();
                $row->start_status = WorkflowApprovalResult::START_SUCCEEDED;
                $row->start_next_retry_at = null;
                $row->start_processing_at = null;
                $row->workflow_status = $payload['result'];
                $row->result = $payload['result'];
                $row->result_value = $delivery->result_value;
                $row->delivery_id = $deliveryId;
                $row->callback_payload_json = $delivery->payload_json;
                $row->start_error = '';
                $row->finished_at = isset($payload['finished_at']) && $payload['finished_at'] !== ''
                    ? $payload['finished_at']
                    : $now;
                if (!$wasTerminal) {
                    $row->local_apply_status = WorkflowApprovalResult::APPLY_PENDING;
                    $row->apply_next_retry_at = $now;
                }
                $row->updated_at = $now;
                $row->save();

                return $row->fresh();
            });
        } catch (QueryException $exception) {
            $existingDelivery = WorkflowCallbackDelivery::where('idempotency_key', $idempotencyKey)->first();
            if ($existingDelivery) {
                return $this->existingDeliveryResult($existingDelivery, $payload);
            }

            throw $exception;
        }
    }

    /**
     * @param WorkflowCallbackDelivery $delivery
     * @param array $payload
     * @return WorkflowApprovalResult
     */
    protected function existingDeliveryResult(WorkflowCallbackDelivery $delivery, array $payload)
    {
        foreach (['business_key', 'owner_system', 'process_code', 'instance_uuid', 'event', 'result'] as $field) {
            if ($delivery->{$field} !== $payload[$field]) {
                throw new InvalidArgumentException(
                    'Workflow callback idempotency key conflicts with existing delivery'
                );
            }
        }

        $resultValue = isset($payload['result_value']) ? $payload['result_value'] : $payload['result'];
        if ($delivery->result_value !== $resultValue) {
            throw new InvalidArgumentException(
                'Workflow callback idempotency key conflicts with existing delivery'
            );
        }

        return WorkflowApprovalResult::where('id', $delivery->approval_result_id)->firstOrFail();
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
