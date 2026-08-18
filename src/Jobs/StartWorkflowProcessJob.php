<?php

namespace August6th\WorkflowBridge\Jobs;

use August6th\WorkflowBridge\Bridge\WorkflowBridge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StartWorkflowProcessJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    /** @var string */
    public $processCode;

    /** @var string */
    public $businessKey;

    /** @var array */
    public $options;

    /**
     * @param string $processCode
     * @param string $businessKey
     * @param array $options owner_system, business_payload, input, allow_duplicate, process_version
     */
    public function __construct($processCode, $businessKey, array $options = [])
    {
        $this->processCode = (string) $processCode;
        $this->businessKey = (string) $businessKey;
        $this->options = $options;
    }

    public function handle(WorkflowBridge $bridge)
    {
        return $bridge->startProcess(
            $this->processCode,
            $this->businessKey,
            $this->options
        );
    }
}
