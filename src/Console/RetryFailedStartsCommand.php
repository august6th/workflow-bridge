<?php

namespace August6th\WorkflowBridge\Console;

use August6th\WorkflowBridge\Bridge\WorkflowBridge;
use Illuminate\Console\Command;

class RetryFailedStartsCommand extends Command
{
    protected $signature = 'workflow:retry-start
        {--process= : process_code filter}
        {--owner= : owner_system filter}
        {--business-key=* : business_key list}
        {--limit=100 : max rows}';

    protected $description = 'Retry workflow starts that are in start_failed status';

    public function handle(WorkflowBridge $bridge)
    {
        $stats = $bridge->retryFailedStarts([
            'process_code' => (string) $this->option('process'),
            'owner_system' => (string) $this->option('owner'),
            'business_keys' => (array) $this->option('business-key'),
            'limit' => (int) $this->option('limit'),
        ]);

        $this->info(sprintf(
            'retried=%d succeeded=%d failed=%d',
            $stats['retried'],
            $stats['succeeded'],
            $stats['failed']
        ));

        return 0;
    }
}
