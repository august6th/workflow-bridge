<?php

namespace August6th\WorkflowBridge\Contracts;

use August6th\WorkflowBridge\Models\WorkflowApprovalResult;

interface ResultApplier
{
    /**
     * Apply a finished workflow result into the host business system.
     * Return true when applied or intentionally skipped without error.
     *
     * @param WorkflowApprovalResult $result
     * @return bool
     */
    public function apply(WorkflowApprovalResult $result);
}
