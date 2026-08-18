<?php

namespace August6th\WorkflowBridge\Exceptions;

use RuntimeException;

class WorkflowRequestException extends RuntimeException
{
    /** @var int */
    protected $statusCode;

    /** @var int */
    protected $workflowCode;

    public function __construct($message, $statusCode = 0, $workflowCode = 0, \Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->workflowCode = $workflowCode;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getWorkflowCode()
    {
        return $this->workflowCode;
    }
}
