<?php

namespace August6th\WorkflowBridge\Console;

use August6th\WorkflowBridge\Contracts\ResultApplier;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Exception;
use Illuminate\Console\Command;

class ApplyResultsCommand extends Command
{
    protected $signature = 'workflow:apply-results
        {--process= : process_code filter}
        {--owner= : owner_system filter}
        {--limit=100 : max rows}
        {--dry-run : only list pending rows, do not apply}';

    protected $description = 'Apply finished workflow results into host business tables (requires ResultApplier binding)';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $processCode = (string) $this->option('process');
        $ownerSystem = (string) $this->option('owner');
        $limit = max(1, (int) $this->option('limit'));

        $query = WorkflowApprovalResult::query()
            ->whereIn('workflow_status', [
                WorkflowApprovalResult::STATUS_APPROVED,
                WorkflowApprovalResult::STATUS_REJECTED,
            ])
            ->where('local_apply_status', WorkflowApprovalResult::APPLY_PENDING)
            ->orderBy('id', 'asc')
            ->limit($limit);

        if ($processCode !== '') {
            $query->where('process_code', $processCode);
        }
        if ($ownerSystem !== '') {
            $query->where('owner_system', $ownerSystem);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('no pending results');
            return 0;
        }

        if ($dryRun || !app()->bound(ResultApplier::class)) {
            foreach ($rows as $row) {
                $this->line(sprintf(
                    '[dry-run] id=%d business_key=%s status=%s',
                    $row->id,
                    $row->business_key,
                    $row->workflow_status
                ));
            }
            if (!app()->bound(ResultApplier::class) && !$dryRun) {
                $this->warn('ResultApplier is not bound; nothing applied. Bind a host implementation to enable mapping.');
            }
            return 0;
        }

        /** @var ResultApplier $applier */
        $applier = app(ResultApplier::class);
        $applied = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $ok = $applier->apply($row);
                $row->local_apply_status = $ok
                    ? WorkflowApprovalResult::APPLY_APPLIED
                    : WorkflowApprovalResult::APPLY_SKIPPED;
                $row->local_apply_error = '';
                $row->applied_at = date('Y-m-d H:i:s');
                $row->updated_at = date('Y-m-d H:i:s');
                $row->save();
                $applied++;
            } catch (Exception $e) {
                $row->local_apply_status = WorkflowApprovalResult::APPLY_FAILED;
                $row->local_apply_error = function_exists('mb_substr')
                    ? mb_substr($e->getMessage(), 0, 1000)
                    : substr($e->getMessage(), 0, 1000);
                $row->updated_at = date('Y-m-d H:i:s');
                $row->save();
                $failed++;
                $this->error('apply failed id=' . $row->id . ' ' . $e->getMessage());
            }
        }

        $this->info(sprintf('applied_or_skipped=%d failed=%d', $applied, $failed));

        return 0;
    }
}
