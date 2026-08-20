<?php

namespace August6th\WorkflowBridge\Console;

use August6th\WorkflowBridge\Application\ResultApplicationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ApplyResultsCommand extends Command
{
    protected $signature = 'workflow:apply-results
        {--process= : process_code filter (must be used with --owner)}
        {--owner= : owner_system filter (must be used with --process)}
        {--limit=100 : max rows}
        {--include-failed=1 : include due failed rows, use 0 for pending only}
        {--dry-run : only list due registered rows, do not apply}';

    protected $description = 'Apply finished workflow results through registered owner and process routes';

    /** @var ResultApplicationService */
    protected $service;

    public function __construct(ResultApplicationService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $options = [
            'process_code' => $this->option('process'),
            'owner_system' => $this->option('owner'),
            'include_failed' => (string) $this->option('include-failed') !== '0',
            'limit' => min(1000, max(1, (int) $this->option('limit'))),
        ];

        try {
            if ($dryRun) {
                return $this->showDueResults($this->service->dueResults($options));
            }

            $stats = $this->service->processDue($options);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        $this->info(sprintf(
            'processed=%d applied=%d skipped=%d failed=%d',
            $stats['processed'],
            $stats['applied'],
            $stats['skipped'],
            $stats['failed']
        ));

        return 0;
    }

    protected function showDueResults($rows)
    {
        if ($rows->isEmpty()) {
            $this->info('no due registered results');

            return 0;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '[dry-run] id=%d owner_system=%s process_code=%s business_key=%s status=%s',
                $row->id,
                $row->owner_system,
                $row->process_code,
                $row->business_key,
                $row->workflow_status
            ));
        }

        return 0;
    }
}
