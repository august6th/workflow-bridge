<?php

namespace August6th\WorkflowBridge\Start;

use August6th\WorkflowBridge\Client\WorkflowClient;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class StartWorkflowProcessor
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
     * @param int $approvalResultId
     * @return WorkflowApprovalResult
     */
    public function process($approvalResultId)
    {
        list($result) = $this->processWithClaim($approvalResultId);

        return $result;
    }

    protected function processWithClaim($approvalResultId)
    {
        $now = date('Y-m-d H:i:s');
        $claimed = WorkflowApprovalResult::where('id', $approvalResultId)
            ->whereIn('start_status', [
                WorkflowApprovalResult::START_PENDING,
                WorkflowApprovalResult::START_FAILED,
            ])
            ->where('start_next_retry_at', '<=', $now)
            ->increment('start_attempts', 1, [
                'start_status' => WorkflowApprovalResult::START_PROCESSING,
                'start_processing_at' => $now,
                'updated_at' => $now,
            ]);

        $result = WorkflowApprovalResult::where('id', $approvalResultId)->firstOrFail();
        if ($claimed !== 1) {
            return [$result, false];
        }

        $payload = [
            'business_key' => $result->business_key,
            'owner_system' => $result->owner_system,
            'business_payload' => $this->decodeJson($result->business_payload_json),
            'input' => $this->decodeJson($result->input_json),
        ];
        if ($result->requested_process_version > 0) {
            $payload['process_version'] = $result->requested_process_version;
        }

        try {
            $response = $this->client->startProcess($result->process_code, $payload);
            $instance = $this->extractInstance($response);
        } catch (Exception $exception) {
            return [$this->recoverOrFail($result, $exception), true];
        }

        return [$this->markSucceeded($result, $instance), true];
    }

    /**
     * @param array $options process_code, owner_system, business_keys, limit
     * @return array{processed:int,succeeded:int,failed:int}
     */
    public function processDue(array $options = [])
    {
        $ownerSystem = isset($options['owner_system']) && $options['owner_system'] !== ''
            ? $options['owner_system']
            : (isset($this->config['owner_system']) ? $this->config['owner_system'] : 'erp');
        $processCode = isset($options['process_code']) ? $options['process_code'] : '';
        if (!is_string($ownerSystem) || !is_string($processCode)) {
            throw new InvalidArgumentException('owner_system and process_code must be strings.');
        }
        $ownerSystem = trim($ownerSystem);
        $processCode = trim($processCode);
        if ($ownerSystem === '' || $processCode === '') {
            throw new InvalidArgumentException('owner_system and process_code are required.');
        }
        $businessKeys = isset($options['business_keys']) && is_array($options['business_keys'])
            ? array_values(array_unique($options['business_keys']))
            : [];
        $limit = isset($options['limit']) ? min(1000, max(1, (int) $options['limit'])) : 100;
        $leaseSeconds = isset($this->config['start_lease_seconds'])
            ? max(30, (int) $this->config['start_lease_seconds'])
            : 300;
        $now = date('Y-m-d H:i:s');
        $expiredBefore = date('Y-m-d H:i:s', time() - $leaseSeconds);

        $staleQuery = WorkflowApprovalResult::query();
        $this->applyRoute($staleQuery, $processCode, $ownerSystem);
        $staleQuery
            ->where('start_status', WorkflowApprovalResult::START_PROCESSING)
            ->where('start_processing_at', '<=', $expiredBefore);
        if ($businessKeys) {
            $staleQuery->whereIn('business_key', $businessKeys);
        }
        $staleIds = $staleQuery
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->pluck('id')
            ->all();
        if ($staleIds !== []) {
            WorkflowApprovalResult::whereIn('id', $staleIds)
                ->where('start_status', WorkflowApprovalResult::START_PROCESSING)
                ->where('start_processing_at', '<=', $expiredBefore)
                ->update([
                    'start_status' => WorkflowApprovalResult::START_FAILED,
                    'start_error' => 'Workflow start processing lease expired',
                    'start_next_retry_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $query = WorkflowApprovalResult::query();
        $this->applyRoute($query, $processCode, $ownerSystem);
        $query
            ->whereIn('start_status', [
                WorkflowApprovalResult::START_PENDING,
                WorkflowApprovalResult::START_FAILED,
            ])
            ->where('start_next_retry_at', '<=', $now);
        if ($businessKeys) {
            $query->whereIn('business_key', $businessKeys);
        }
        $ids = $query
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            list($result, $claimedByThisWorker) = $this->processWithClaim($id);
            if (!$claimedByThisWorker) {
                continue;
            }
            $stats['processed']++;
            if ($result->start_status === WorkflowApprovalResult::START_SUCCEEDED) {
                $stats['succeeded']++;
            } elseif ($result->start_status === WorkflowApprovalResult::START_FAILED) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    protected function applyRoute($query, $processCode, $ownerSystem)
    {
        return $query
            ->where('process_code', $processCode)
            ->where('owner_system', $ownerSystem);
    }

    /**
     * @param WorkflowApprovalResult $result
     * @param Exception $startException
     * @return WorkflowApprovalResult
     */
    protected function recoverOrFail(WorkflowApprovalResult $result, Exception $startException)
    {
        try {
            $response = $this->client->queryByBusinessKey(
                $result->business_key,
                $result->owner_system,
                $result->process_code
            );
            $instance = $this->extractInstance($response);

            return $this->markSucceeded($result, $instance);
        } catch (Exception $queryException) {
            return $this->markFailed($result, $startException);
        }
    }

    /**
     * @param WorkflowApprovalResult $result
     * @param array $instance
     * @return WorkflowApprovalResult
     */
    protected function markSucceeded(WorkflowApprovalResult $result, array $instance)
    {
        $now = date('Y-m-d H:i:s');
        $result->instance_uuid = $instance['instance_uuid'];
        $result->process_version = isset($instance['process_version']) ? $instance['process_version'] : 0;
        $result->start_status = WorkflowApprovalResult::START_SUCCEEDED;
        $result->workflow_status = isset($instance['status']) && $instance['status'] !== ''
            ? $instance['status']
            : WorkflowApprovalResult::STATUS_RUNNING;
        $result->start_error = '';
        $result->start_next_retry_at = null;
        $result->start_processing_at = null;
        $result->started_at = isset($instance['started_at']) && $instance['started_at'] !== ''
            ? $instance['started_at']
            : $now;
        $result->updated_at = $now;
        $result->save();

        return $result->fresh();
    }

    /**
     * @param WorkflowApprovalResult $result
     * @param Exception $exception
     * @return WorkflowApprovalResult
     */
    protected function markFailed(WorkflowApprovalResult $result, Exception $exception)
    {
        $now = time();
        $baseSeconds = isset($this->config['start_retry_base_seconds'])
            ? max(10, (int) $this->config['start_retry_base_seconds'])
            : 60;
        $maxSeconds = isset($this->config['start_retry_max_seconds'])
            ? max($baseSeconds, (int) $this->config['start_retry_max_seconds'])
            : 3600;
        $retrySeconds = min(
            $maxSeconds,
            $baseSeconds * (2 ** max(0, $result->start_attempts - 1))
        );

        $result->start_status = WorkflowApprovalResult::START_FAILED;
        $result->workflow_status = WorkflowApprovalResult::STATUS_NOT_STARTED;
        $result->start_error = $this->truncate($exception->getMessage(), 1000);
        $result->start_next_retry_at = date('Y-m-d H:i:s', $now + $retrySeconds);
        $result->start_processing_at = null;
        $result->updated_at = date('Y-m-d H:i:s', $now);
        $result->save();

        return $result->fresh();
    }

    /**
     * @param array $response
     * @return array
     */
    protected function extractInstance(array $response)
    {
        if (!isset($response['data']['instance']) || !is_array($response['data']['instance'])) {
            throw new RuntimeException('Workflow response missing data.instance');
        }
        if (!isset($response['data']['instance']['instance_uuid']) || $response['data']['instance']['instance_uuid'] === '') {
            throw new RuntimeException('Workflow response missing instance_uuid');
        }

        return $response['data']['instance'];
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
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }

        return substr($text, 0, $max);
    }
}
