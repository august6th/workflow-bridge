<?php

namespace August6th\WorkflowBridge\Bridge;

use August6th\WorkflowBridge\Client\WorkflowClient;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Exception;
use Throwable;

class WorkflowBridge
{
    /** @var WorkflowClient */
    protected $client;

    /** @var array */
    protected $config;

    public function __construct(WorkflowClient $client, array $config = [])
    {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * Start a workflow process for a business document.
     * Never throws to callers for transport/business start failures; writes start_failed instead.
     *
     * @param string $processCode
     * @param string $businessKey
     * @param array $options owner_system, business_payload, input, allow_duplicate, process_version, force
     * @return WorkflowApprovalResult
     */
    public function startProcess($processCode, $businessKey, array $options = [])
    {
        $ownerSystem = isset($options['owner_system']) && $options['owner_system'] !== ''
            ? (string) $options['owner_system']
            : (isset($this->config['owner_system']) ? (string) $this->config['owner_system'] : 'erp');
        $businessKey = (string) $businessKey;
        $processCode = (string) $processCode;
        $force = !empty($options['force']);
        $now = date('Y-m-d H:i:s');

        $row = WorkflowApprovalResult::where('business_key', $businessKey)
            ->where('owner_system', $ownerSystem)
            ->where('process_code', $processCode)
            ->first();

        if ($row && !$force) {
            if (in_array($row->workflow_status, [
                WorkflowApprovalResult::STATUS_WAITING,
                WorkflowApprovalResult::STATUS_APPROVED,
                WorkflowApprovalResult::STATUS_REJECTED,
            ], true)) {
                return $row;
            }
        }

        if (!$row) {
            $row = new WorkflowApprovalResult();
            $row->business_key = $businessKey;
            $row->owner_system = $ownerSystem;
            $row->process_code = $processCode;
            $row->idempotency_key = WorkflowApprovalResult::startIdempotencyKey($ownerSystem, $processCode, $businessKey);
            $row->local_apply_status = WorkflowApprovalResult::APPLY_PENDING;
            $row->created_at = $now;
            $row->started_at = $now;
        }

        $businessPayload = isset($options['business_payload']) && is_array($options['business_payload'])
            ? $options['business_payload']
            : [];
        $input = isset($options['input']) && is_array($options['input']) ? $options['input'] : [];

        $row->business_payload_json = json_encode($businessPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row->input_json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row->updated_at = $now;

        $payload = [
            'business_key' => $businessKey,
            'owner_system' => $ownerSystem,
            'allow_duplicate' => !empty($options['allow_duplicate']),
            'business_payload' => $businessPayload,
            'input' => $input,
        ];
        if (isset($options['process_version']) && $options['process_version'] !== '' && $options['process_version'] !== null) {
            $payload['process_version'] = (int) $options['process_version'];
        }

        try {
            $response = $this->client->startProcess($processCode, $payload);
            $instance = isset($response['data']['instance']) && is_array($response['data']['instance'])
                ? $response['data']['instance']
                : [];

            $row->instance_uuid = isset($instance['instance_uuid']) ? (string) $instance['instance_uuid'] : '';
            $row->workflow_status = isset($instance['status']) && $instance['status'] !== ''
                ? (string) $instance['status']
                : WorkflowApprovalResult::STATUS_WAITING;
            $row->start_error = '';
            $row->started_at = isset($instance['started_at']) && $instance['started_at'] !== ''
                ? (string) $instance['started_at']
                : $now;
            if ($row->idempotency_key === '' || strpos($row->idempotency_key, 'start:') === 0) {
                $row->idempotency_key = WorkflowApprovalResult::startIdempotencyKey($ownerSystem, $processCode, $businessKey);
            }
            $row->save();

            return $row;
        } catch (Throwable $e) {
            // Timeout / ambiguous: try query before marking failed.
            try {
                $queried = $this->client->queryByBusinessKey($businessKey, $ownerSystem, $processCode);
                $instance = $this->extractInstance($queried);
                if ($instance) {
                    $row->instance_uuid = isset($instance['instance_uuid']) ? (string) $instance['instance_uuid'] : '';
                    $row->workflow_status = isset($instance['status']) && $instance['status'] !== ''
                        ? (string) $instance['status']
                        : WorkflowApprovalResult::STATUS_WAITING;
                    $row->start_error = '';
                    $row->save();

                    return $row;
                }
            } catch (Throwable $ignored) {
                // keep original error
            }

            $row->workflow_status = WorkflowApprovalResult::STATUS_START_FAILED;
            $row->start_error = $this->truncate($e->getMessage(), 1000);
            $row->instance_uuid = $row->instance_uuid ?: '';
            if ($row->idempotency_key === '') {
                $row->idempotency_key = WorkflowApprovalResult::startIdempotencyKey($ownerSystem, $processCode, $businessKey);
            }
            $row->save();

            return $row;
        }
    }

    /**
     * Retry rows with start_failed, or start missing business keys.
     *
     * @param array $options process_code, owner_system, business_keys, limit
     * @return array{retried:int,succeeded:int,failed:int}
     */
    public function retryFailedStarts(array $options = [])
    {
        $processCode = isset($options['process_code']) ? (string) $options['process_code'] : '';
        $ownerSystem = isset($options['owner_system']) && $options['owner_system'] !== ''
            ? (string) $options['owner_system']
            : (isset($this->config['owner_system']) ? (string) $this->config['owner_system'] : 'erp');
        $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 100;
        $businessKeys = isset($options['business_keys']) && is_array($options['business_keys'])
            ? $options['business_keys']
            : [];

        $query = WorkflowApprovalResult::query()
            ->where('owner_system', $ownerSystem)
            ->where('workflow_status', WorkflowApprovalResult::STATUS_START_FAILED)
            ->orderBy('id', 'asc')
            ->limit($limit);

        if ($processCode !== '') {
            $query->where('process_code', $processCode);
        }
        if ($businessKeys) {
            $query->whereIn('business_key', $businessKeys);
        }

        $rows = $query->get();
        $retried = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $retried++;
            $payload = $this->decodeJson($row->business_payload_json);
            $input = $this->decodeJson($row->input_json);
            $result = $this->startProcess($row->process_code, $row->business_key, [
                'owner_system' => $row->owner_system,
                'business_payload' => $payload,
                'input' => $input,
                'force' => true,
            ]);
            if ($result->workflow_status === WorkflowApprovalResult::STATUS_START_FAILED) {
                $failed++;
            } else {
                $succeeded++;
            }
        }

        return [
            'retried' => $retried,
            'succeeded' => $succeeded,
            'failed' => $failed,
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

    /**
     * @param array $response
     * @return array|null
     */
    protected function extractInstance(array $response)
    {
        if (isset($response['data']['instance']) && is_array($response['data']['instance'])) {
            return $response['data']['instance'];
        }
        if (isset($response['data']['list'][0]) && is_array($response['data']['list'][0])) {
            return $response['data']['list'][0];
        }
        if (isset($response['data'][0]) && is_array($response['data'][0])) {
            return $response['data'][0];
        }
        if (isset($response['data']) && is_array($response['data']) && isset($response['data']['instance_uuid'])) {
            return $response['data'];
        }

        return null;
    }

    /**
     * @param string|null $json
     * @return array
     */
    protected function decodeJson($json)
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param string $text
     * @param int $max
     * @return string
     */
    protected function truncate($text, $max)
    {
        $text = (string) $text;
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }

        return substr($text, 0, $max);
    }
}
