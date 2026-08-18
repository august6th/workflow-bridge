<?php

namespace August6th\WorkflowBridge\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowApprovalResult extends Model
{
    const START_PENDING = 'pending';
    const START_PROCESSING = 'processing';
    const START_SUCCEEDED = 'succeeded';
    const START_FAILED = 'failed';

    const STATUS_NOT_STARTED = 'not_started';
    const STATUS_RUNNING = 'running';
    const STATUS_WAITING = 'waiting';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /** @deprecated Use START_FAILED for start state. */
    const STATUS_START_FAILED = 'start_failed';

    const APPLY_PENDING = 'pending';
    const APPLY_PROCESSING = 'processing';
    const APPLY_APPLIED = 'applied';
    const APPLY_SKIPPED = 'skipped';
    const APPLY_FAILED = 'failed';

    protected $table = 'workflow_approval_results';

    protected $guarded = [];

    protected $casts = [
        'requested_process_version' => 'integer',
        'process_version' => 'integer',
        'start_attempts' => 'integer',
        'apply_attempts' => 'integer',
    ];

    public $timestamps = true;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    public static function startIdempotencyKey($ownerSystem, $processCode, $businessKey)
    {
        return hash('sha256', implode("\n", [$ownerSystem, $processCode, $businessKey]));
    }

    public static function statusLabel($status)
    {
        $map = [
            self::STATUS_NOT_STARTED => '未发起',
            self::STATUS_RUNNING => '运行中',
            self::STATUS_WAITING => '审批中',
            self::STATUS_APPROVED => '已通过',
            self::STATUS_REJECTED => '已驳回',
            self::STATUS_FAILED => '流程失败',
            self::STATUS_CANCELLED => '已取消',
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
        return in_array($this->start_status, [self::START_PENDING, self::START_FAILED], true);
    }
}
