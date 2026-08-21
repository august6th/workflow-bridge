<?php

namespace August6th\WorkflowBridge\Console;

use August6th\WorkflowBridge\Bridge\WorkflowBridge;
use Illuminate\Console\Command;

class RetryFailedStartsCommand extends Command
{
    protected $signature = 'workflow:retry-start
        {--process= : required process_code route}
        {--owner= : required owner_system route}
        {--business-key=* : business_key list}
        {--limit=100 : max rows}';

    protected $description = 'Retry due workflow starts in pending or failed start status';

    public function handle(WorkflowBridge $bridge)
    {
        $ownerSystem = $this->option('owner');
        $processCode = $this->option('process');
        if (!is_string($ownerSystem) || !is_string($processCode)
            || trim($ownerSystem) === '' || trim($processCode) === '') {
            $this->error('--owner and --process must be provided together and must not be empty.');

            return 1;
        }

        $stats = $bridge->retryFailedStarts([
            'process_code' => trim($processCode),
            'owner_system' => trim($ownerSystem),
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
