<?php

namespace August6th\WorkflowBridge\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowApprovalResult extends Model
{
    const STATUS_WAITING = 'waiting';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_START_FAILED = 'start_failed';

    const APPLY_PENDING = 'pending';
    const APPLY_APPLIED = 'applied';
    const APPLY_SKIPPED = 'skipped';
    const APPLY_FAILED = 'failed';

    protected $table = 'workflow_approval_results';

    protected $guarded = [];

    public $timestamps = true;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    public static function startIdempotencyKey($ownerSystem, $processCode, $businessKey)
    {
        return 'start:' . $ownerSystem . ':' . $processCode . ':' . $businessKey;
    }

    public static function statusLabel($status)
    {
        $map = [
            self::STATUS_WAITING => '审批中',
            self::STATUS_APPROVED => '已通过',
            self::STATUS_REJECTED => '已驳回',
            self::STATUS_START_FAILED => '发起失败',
        ];

        return isset($map[$status]) ? $map[$status] : (string) $status;
    }

    public function isTerminal()
    {
        return in_array($this->workflow_status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true);
    }

    public function canRetryStart()
    {
        return $this->workflow_status === self::STATUS_START_FAILED
            || $this->workflow_status === '';
    }
}
