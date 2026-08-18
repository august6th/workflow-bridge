<?php

namespace August6th\WorkflowBridge\Console;

use August6th\WorkflowBridge\Application\ResultApplicationService;
use August6th\WorkflowBridge\Contracts\ResultApplier;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Illuminate\Console\Command;

class ApplyResultsCommand extends Command
{
    protected $signature = 'workflow:apply-results
        {--process= : process_code filter}
        {--owner= : owner_system filter}
        {--limit=100 : max rows}
        {--include-failed=1 : include due failed rows, use 0 for pending only}
        {--dry-run : only list pending rows, do not apply}';

    protected $description = 'Apply finished workflow results into host business tables (requires ResultApplier binding)';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $processCode = (string) $this->option('process');
        $ownerSystem = (string) $this->option('owner');
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $includeFailed = (string) $this->option('include-failed') !== '0';

        if ($dryRun || !app()->bound(ResultApplier::class)) {
            $rows = $this->dueResults($processCode, $ownerSystem, $includeFailed, $limit);
            if ($rows->isEmpty()) {
                $this->info('no due results');

                return 0;
            }
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

        /** @var ResultApplicationService $service */
        $service = app(ResultApplicationService::class);
        $stats = $service->processDue([
            'process_code' => $processCode,
            'owner_system' => $ownerSystem,
            'include_failed' => $includeFailed,
            'limit' => $limit,
        ]);

        $this->info(sprintf(
            'processed=%d applied=%d skipped=%d failed=%d',
            $stats['processed'],
            $stats['applied'],
            $stats['skipped'],
            $stats['failed']
        ));

        return 0;
    }

    /**
     * @param string $processCode
     * @param string $ownerSystem
     * @param bool $includeFailed
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function dueResults($processCode, $ownerSystem, $includeFailed, $limit)
    {
        $statuses = [WorkflowApprovalResult::APPLY_PENDING];
        if ($includeFailed) {
            $statuses[] = WorkflowApprovalResult::APPLY_FAILED;
        }

        $query = WorkflowApprovalResult::query()
            ->whereIn('workflow_status', [
                WorkflowApprovalResult::STATUS_APPROVED,
                WorkflowApprovalResult::STATUS_REJECTED,
            ])
            ->where('apply_next_retry_at', '<=', date('Y-m-d H:i:s'))
            ->whereIn('local_apply_status', $statuses)
            ->orderBy('id', 'asc')
            ->limit($limit);
        if ($processCode !== '') {
            $query->where('process_code', $processCode);
        }
        if ($ownerSystem !== '') {
            $query->where('owner_system', $ownerSystem);
        }

        return $query->get(['id', 'business_key', 'workflow_status']);
    }
}
