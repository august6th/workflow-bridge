<?php

namespace August6th\WorkflowBridge\Contracts;

use August6th\WorkflowBridge\Models\WorkflowApprovalResult;

interface ResultApplier
{
    /**
     * Apply a finished workflow result into the host business system.
     * Implementations must be idempotent by result ID or by the business triple
     * because a crashed worker may retry after the lease expires.
     * Return true when applied, or false when intentionally skipped without error.
     *
     * @param WorkflowApprovalResult $result
     * @return bool
     */
    public function apply(WorkflowApprovalResult $result);
}
