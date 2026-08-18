<?php

namespace August6th\WorkflowBridge\Bridge;

use August6th\WorkflowBridge\Client\WorkflowClient;
use August6th\WorkflowBridge\Jobs\StartWorkflowProcessJob;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use August6th\WorkflowBridge\Start\StartWorkflowProcessor;
use Illuminate\Contracts\Bus\Dispatcher;

class WorkflowBridge
{
    /** @var array */
    protected $config;

    /** @var StartWorkflowProcessor */
    protected $startProcessor;

    /** @var Dispatcher|null */
    protected $dispatcher;

    public function __construct(
        WorkflowClient $client,
        array $config = [],
        StartWorkflowProcessor $startProcessor = null,
        Dispatcher $dispatcher = null
    )
    {
        $this->config = $config;
        $this->startProcessor = $startProcessor ?: new StartWorkflowProcessor($client, $config);
        $this->dispatcher = $dispatcher;
    }

    /**
     * Persist a single-instance workflow start request without calling Workflow.
     *
     * @param string $processCode
     * @param string $businessKey
     * @param array $options owner_system, business_payload, input, process_version
     * @return WorkflowApprovalResult
     */
    public function requestProcess($processCode, $businessKey, array $options = [])
    {
        $ownerSystem = isset($options['owner_system']) && $options['owner_system'] !== ''
            ? (string) $options['owner_system']
            : (isset($this->config['owner_system']) ? (string) $this->config['owner_system'] : 'erp');
        $processCode = (string) $processCode;
        $businessKey = (string) $businessKey;

        $existing = WorkflowApprovalResult::where('business_key', $businessKey)
            ->where('owner_system', $ownerSystem)
            ->where('process_code', $processCode)
            ->first();
        if ($existing) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $startIdempotencyKey = WorkflowApprovalResult::startIdempotencyKey(
            $ownerSystem,
            $processCode,
            $businessKey
        );

        $result = new WorkflowApprovalResult();
        $result->business_key = $businessKey;
        $result->owner_system = $ownerSystem;
        $result->process_code = $processCode;
        $result->requested_process_version = isset($options['process_version'])
            ? max(0, (int) $options['process_version'])
            : 0;
        $result->process_version = 0;
        $result->start_status = WorkflowApprovalResult::START_PENDING;
        $result->workflow_status = WorkflowApprovalResult::STATUS_NOT_STARTED;
        $result->start_idempotency_key = $startIdempotencyKey;
        $result->idempotency_key = $startIdempotencyKey;
        $result->business_payload_json = json_encode(
            isset($options['business_payload']) && is_array($options['business_payload'])
                ? $options['business_payload']
                : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $result->input_json = json_encode(
            isset($options['input']) && is_array($options['input']) ? $options['input'] : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $result->local_apply_status = WorkflowApprovalResult::APPLY_PENDING;
        $result->start_next_retry_at = $now;
        $result->created_at = $now;
        $result->updated_at = $now;
        $result->save();

        return $result;
    }

    /**
     * Persist and dispatch a durable workflow start request.
     *
     * @param string $processCode
     * @param string $businessKey
     * @param array $options
     * @return WorkflowApprovalResult
     */
    public function dispatchProcess($processCode, $businessKey, array $options = [])
    {
        $result = $this->requestProcess($processCode, $businessKey, $options);
        if ($result->start_status === WorkflowApprovalResult::START_PENDING
            || $result->start_status === WorkflowApprovalResult::START_FAILED) {
            $dispatcher = $this->dispatcher ?: app(Dispatcher::class);
            $dispatcher->dispatch(new StartWorkflowProcessJob($result->id));
        }

        return $result;
    }

    /**
     * Persist and synchronously process a single-instance workflow request.
     *
     * @param string $processCode
     * @param string $businessKey
     * @param array $options owner_system, business_payload, input, process_version
     * @return WorkflowApprovalResult
     */
    public function startProcess($processCode, $businessKey, array $options = [])
    {
        $result = $this->requestProcess($processCode, $businessKey, $options);

        return $this->startProcessor->process($result->id);
    }

    /**
     * Process due pending and failed workflow starts.
     *
     * @param array $options process_code, owner_system, business_keys, limit
     * @return array{retried:int,succeeded:int,failed:int}
     */
    public function retryFailedStarts(array $options = [])
    {
        $stats = $this->startProcessor->processDue($options);

        return [
            'retried' => $stats['processed'],
            'succeeded' => $stats['succeeded'],
            'failed' => $stats['failed'],
        ];
    }

    /**
     * @param string $businessKey
     * @param string $ownerSystem
     * @param string $processCode
     * @return WorkflowApprovalResult|null
     */
    public function findResult($businessKey, $ownerSystem = '', $processCode = '')
    {
        $ownerSystem = $ownerSystem !== ''
            ? $ownerSystem
            : (isset($this->config['owner_system']) ? (string) $this->config['owner_system'] : 'erp');

        $query = WorkflowApprovalResult::where('business_key', $businessKey)
            ->where('owner_system', $ownerSystem);
        if ($processCode !== '') {
            $query->where('process_code', $processCode);
        }

        return $query->orderBy('id', 'desc')->first();
    }

    /**
     * @param array $businessKeys
     * @param string $ownerSystem
     * @param string $processCode
     * @return \Illuminate\Support\Collection
     */
    public function mapResultsByBusinessKeys(array $businessKeys, $ownerSystem = '', $processCode = '')
    {
        $ownerSystem = $ownerSystem !== ''
            ? $ownerSystem
            : (isset($this->config['owner_system']) ? (string) $this->config['owner_system'] : 'erp');

        if (!$businessKeys) {
            return collect();
        }

        $query = WorkflowApprovalResult::whereIn('business_key', $businessKeys)
            ->where('owner_system', $ownerSystem);
        if ($processCode !== '') {
            $query->where('process_code', $processCode);
        }

        return $query->get()->keyBy('business_key');
    }

}
