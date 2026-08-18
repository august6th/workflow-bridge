<?php

namespace August6th\WorkflowBridge\Application;

use August6th\WorkflowBridge\Contracts\ResultApplier;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Throwable;

class ResultApplicationService
{
    /** @var ResultApplier */
    protected $applier;

    /** @var array */
    protected $config;

    public function __construct(ResultApplier $applier, array $config = [])
    {
        $this->applier = $applier;
        $this->config = $config;
    }

    /**
     * @param int $approvalResultId
     * @return WorkflowApprovalResult
     */
    public function process($approvalResultId)
    {
        $now = date('Y-m-d H:i:s');
        $claimed = WorkflowApprovalResult::where('id', $approvalResultId)
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
            return $result;
        }

        try {
            return $this->markCompleted($result, $this->applier->apply($result));
        } catch (Throwable $exception) {
            return $this->markFailed($result, $exception);
        }
    }

    /**
     * @param array $options process_code, owner_system, include_failed, limit
     * @return array{processed:int,applied:int,skipped:int,failed:int}
     */
    public function processDue(array $options = [])
    {
        $ownerSystem = isset($options['owner_system']) ? $options['owner_system'] : '';
        $processCode = isset($options['process_code']) ? $options['process_code'] : '';
        $includeFailed = !isset($options['include_failed']) || (bool) $options['include_failed'];
        $limit = isset($options['limit']) ? min(1000, max(1, (int) $options['limit'])) : 100;

        $this->recoverExpiredLeases($ownerSystem, $processCode);

        $statuses = [WorkflowApprovalResult::APPLY_PENDING];
        if ($includeFailed) {
            $statuses[] = WorkflowApprovalResult::APPLY_FAILED;
        }

        $now = date('Y-m-d H:i:s');
        $query = WorkflowApprovalResult::query()
            ->whereIn('workflow_status', [
                WorkflowApprovalResult::STATUS_APPROVED,
                WorkflowApprovalResult::STATUS_REJECTED,
            ])
            ->where('apply_next_retry_at', '<=', $now)
            ->whereIn('local_apply_status', $statuses)
            ->orderBy('id', 'asc')
            ->limit($limit);
        if ($ownerSystem !== '') {
            $query->where('owner_system', $ownerSystem);
        }
        if ($processCode !== '') {
            $query->where('process_code', $processCode);
        }

        $ids = $query->pluck('id')->all();
        $stats = ['processed' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $result = $this->process($id);
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
     * @param string $ownerSystem
     * @param string $processCode
     * @return int
     */
    protected function recoverExpiredLeases($ownerSystem, $processCode)
    {
        $leaseSeconds = isset($this->config['apply_lease_seconds'])
            ? max(30, (int) $this->config['apply_lease_seconds'])
            : 300;
        $now = date('Y-m-d H:i:s');
        $query = WorkflowApprovalResult::query()
            ->whereIn('workflow_status', [
                WorkflowApprovalResult::STATUS_APPROVED,
                WorkflowApprovalResult::STATUS_REJECTED,
            ])
            ->where('local_apply_status', WorkflowApprovalResult::APPLY_PROCESSING)
            ->where('apply_processing_at', '<=', date('Y-m-d H:i:s', time() - $leaseSeconds));
        if ($ownerSystem !== '') {
            $query->where('owner_system', $ownerSystem);
        }
        if ($processCode !== '') {
            $query->where('process_code', $processCode);
        }

        return $query->update([
            'local_apply_status' => WorkflowApprovalResult::APPLY_FAILED,
            'local_apply_error' => 'Workflow result application lease expired',
            'apply_next_retry_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param WorkflowApprovalResult $result
     * @param bool $applied
     * @return WorkflowApprovalResult
     */
    protected function markCompleted(WorkflowApprovalResult $result, $applied)
    {
        $now = date('Y-m-d H:i:s');
        $result->local_apply_status = $applied
            ? WorkflowApprovalResult::APPLY_APPLIED
            : WorkflowApprovalResult::APPLY_SKIPPED;
        $result->local_apply_error = '';
        $result->apply_next_retry_at = '9999-12-31 23:59:59';
        $result->apply_processing_at = '1970-01-01 00:00:00';
        $result->applied_at = $now;
        $result->updated_at = $now;
        $result->save();

        return $result->fresh();
    }

    /**
     * @param WorkflowApprovalResult $result
     * @param Throwable $exception
     * @return WorkflowApprovalResult
     */
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
        $result->apply_processing_at = '1970-01-01 00:00:00';
        $result->updated_at = date('Y-m-d H:i:s', $now);
        $result->save();

        return $result->fresh();
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
