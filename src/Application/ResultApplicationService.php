<?php

namespace August6th\WorkflowBridge\Application;

use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use InvalidArgumentException;
use Throwable;

class ResultApplicationService
{
    /** @var ResultApplierRegistry */
    protected $registry;

    /** @var array */
    protected $config;

    public function __construct(ResultApplierRegistry $registry, array $config = [])
    {
        $this->registry = $registry;
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
        $result = WorkflowApprovalResult::where('id', $approvalResultId)->firstOrFail();
        if (!$this->registry->has($result->owner_system, $result->process_code)) {
            return [$result, false];
        }

        $applier = $this->registry->resolve($result->owner_system, $result->process_code);
        $now = date('Y-m-d H:i:s');
        $claimed = WorkflowApprovalResult::where('id', $approvalResultId)
            ->where('process_code', $result->process_code)
            ->where('owner_system', $result->owner_system)
            ->whereIn('workflow_status', [
                WorkflowApprovalResult::STATUS_APPROVED,
                WorkflowApprovalResult::STATUS_REJECTED,
            ])
            ->where('apply_next_retry_at', '<=', $now)
            ->whereIn('local_apply_status', [
                WorkflowApprovalResult::APPLY_PENDING,
                WorkflowApprovalResult::APPLY_FAILED,
            ])
            ->increment('apply_attempts', 1, [
                'local_apply_status' => WorkflowApprovalResult::APPLY_PROCESSING,
                'apply_processing_at' => $now,
                'updated_at' => $now,
            ]);

        $result = WorkflowApprovalResult::where('id', $approvalResultId)->firstOrFail();
        if ($claimed !== 1) {
            return [$result, false];
        }

        try {
            return [$this->markCompleted($result, $applier->apply($result)), true];
        } catch (Throwable $exception) {
            return [$this->markFailed($result, $exception), true];
        }
    }

    /**
     * @param array $options process_code, owner_system, include_failed, limit
     * @return array{processed:int,applied:int,skipped:int,failed:int}
     */
    public function processDue(array $options = [])
    {
        $scope = $this->routeScope($options);
        $limit = isset($options['limit']) ? min(1000, max(1, (int) $options['limit'])) : 100;

        $this->recoverExpiredLeases($scope);

        $ids = $this->dueQuery($scope, $options)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->pluck('id')
            ->all();
        $stats = ['processed' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            list($result, $claimedByThisWorker) = $this->processWithClaim($id);
            if (!$claimedByThisWorker) {
                continue;
            }
            $stats['processed']++;
            if ($result->local_apply_status === WorkflowApprovalResult::APPLY_APPLIED) {
                $stats['applied']++;
            } elseif ($result->local_apply_status === WorkflowApprovalResult::APPLY_SKIPPED) {
                $stats['skipped']++;
            } elseif ($result->local_apply_status === WorkflowApprovalResult::APPLY_FAILED) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * @param array $options
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function dueResults(array $options = [])
    {
        $scope = $this->routeScope($options);
        $limit = isset($options['limit']) ? min(1000, max(1, (int) $options['limit'])) : 100;

        return $this->dueQuery($scope, $options)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get(['id', 'business_key', 'owner_system', 'process_code', 'workflow_status', 'local_apply_status']);
    }

    protected function routeScope(array $options)
    {
        $ownerSystem = isset($options['owner_system']) ? $options['owner_system'] : '';
        $processCode = isset($options['process_code']) ? $options['process_code'] : '';
        if (!is_string($ownerSystem) || !is_string($processCode)) {
            throw new InvalidArgumentException('owner_system and process_code filters must be strings.');
        }
        $ownerSystem = trim($ownerSystem);
        $processCode = trim($processCode);
        if (($ownerSystem === '') !== ($processCode === '')) {
            throw new InvalidArgumentException('owner_system and process_code must be provided together.');
        }
        if ($ownerSystem !== '') {
            if (!$this->registry->has($ownerSystem, $processCode)) {
                throw new InvalidArgumentException(sprintf(
                    'No result applier registered for owner_system=%s process_code=%s',
                    $ownerSystem,
                    $processCode
                ));
            }

            return [['owner_system' => $ownerSystem, 'process_code' => $processCode]];
        }

        return $this->registry->routes();
    }

    protected function dueQuery(array $scope, array $options)
    {
        $includeFailed = !isset($options['include_failed']) || (bool) $options['include_failed'];
        $statuses = [WorkflowApprovalResult::APPLY_PENDING];
        if ($includeFailed) {
            $statuses[] = WorkflowApprovalResult::APPLY_FAILED;
        }

        $query = WorkflowApprovalResult::query();
        if (isset($options['business_keys'])) {
            if (!is_array($options['business_keys'])) {
                throw new InvalidArgumentException('business_keys filter must be an array.');
            }
            if ($options['business_keys'] === []) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereIn('business_key', $options['business_keys']);
        }
        $query = $this->applyRouteScope($query, $scope);

        return $query
            ->where('apply_next_retry_at', '<=', date('Y-m-d H:i:s'))
            ->whereIn('local_apply_status', $statuses)
            ->whereIn('workflow_status', [
                WorkflowApprovalResult::STATUS_APPROVED,
                WorkflowApprovalResult::STATUS_REJECTED,
            ]);
    }

    protected function applyRouteScope($query, array $scope)
    {
        if (empty($scope)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($routeQuery) use ($scope) {
            foreach ($scope as $route) {
                $routeQuery->orWhere(function ($query) use ($route) {
                    $query->where('process_code', $route['process_code'])
                        ->where('owner_system', $route['owner_system']);
                });
            }
        });
    }

    protected function recoverExpiredLeases(array $scope)
    {
        if (empty($scope)) {
            return 0;
        }

        $leaseSeconds = isset($this->config['apply_lease_seconds'])
            ? max(30, (int) $this->config['apply_lease_seconds'])
            : 300;
        $now = date('Y-m-d H:i:s');
        $query = $this->applyRouteScope(WorkflowApprovalResult::query(), $scope)
            ->where('local_apply_status', WorkflowApprovalResult::APPLY_PROCESSING)
            ->where('apply_processing_at', '<=', date('Y-m-d H:i:s', time() - $leaseSeconds))
            ->whereIn('workflow_status', [
                WorkflowApprovalResult::STATUS_APPROVED,
                WorkflowApprovalResult::STATUS_REJECTED,
            ]);

        return $query->update([
            'local_apply_status' => WorkflowApprovalResult::APPLY_FAILED,
            'local_apply_error' => 'Workflow result application lease expired',
            'apply_next_retry_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function markCompleted(WorkflowApprovalResult $result, $applied)
    {
        $now = date('Y-m-d H:i:s');
        $result->local_apply_status = $applied
            ? WorkflowApprovalResult::APPLY_APPLIED
            : WorkflowApprovalResult::APPLY_SKIPPED;
        $result->local_apply_error = '';
        $result->apply_next_retry_at = null;
        $result->apply_processing_at = null;
        $result->applied_at = $now;
        $result->updated_at = $now;
        $result->save();

        return $result->fresh();
    }

    protected function markFailed(WorkflowApprovalResult $result, Throwable $exception)
    {
        $now = time();
        $baseSeconds = isset($this->config['apply_retry_base_seconds'])
            ? max(10, (int) $this->config['apply_retry_base_seconds'])
            : 60;
        $maxSeconds = isset($this->config['apply_retry_max_seconds'])
            ? max($baseSeconds, (int) $this->config['apply_retry_max_seconds'])
            : 3600;
        $retrySeconds = min(
            $maxSeconds,
            $baseSeconds * (2 ** max(0, $result->apply_attempts - 1))
        );

        $result->local_apply_status = WorkflowApprovalResult::APPLY_FAILED;
        $result->local_apply_error = $this->truncate($exception->getMessage(), 1000);
        $result->apply_next_retry_at = date('Y-m-d H:i:s', $now + $retrySeconds);
        $result->apply_processing_at = null;
        $result->updated_at = date('Y-m-d H:i:s', $now);
        $result->save();

        return $result->fresh();
    }

    protected function truncate($text, $max)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }

        return substr($text, 0, $max);
    }
}
