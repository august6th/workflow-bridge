<?php

namespace August6th\WorkflowBridge\Jobs;

use August6th\WorkflowBridge\Start\StartWorkflowProcessor;
use August6th\WorkflowBridge\Support\StartQueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StartWorkflowProcessJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    /** @var int */
    public $approvalResultId;

    /**
     * @param int $approvalResultId
     * @param string|null $queue
     */
    public function __construct($approvalResultId, $queue = null)
    {
        $this->approvalResultId = $approvalResultId;
        $this->onQueue($this->resolveQueue($queue));
    }

    /**
     * @param string|null $queue
     * @return string
     */
    protected function resolveQueue($queue)
    {
        $queue = trim((string) $queue);
        if ($queue !== '') {
            return $queue;
        }

        return StartQueueName::resolve();
    }

    public function handle(StartWorkflowProcessor $processor)
    {
        return $processor->process($this->approvalResultId);
    }
}
