<?php

namespace August6th\WorkflowBridge\Jobs;

use August6th\WorkflowBridge\Start\StartWorkflowProcessor;
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
     */
    public function __construct($approvalResultId)
    {
        $this->approvalResultId = $approvalResultId;
    }

    public function handle(StartWorkflowProcessor $processor)
    {
        return $processor->process($this->approvalResultId);
    }
}
