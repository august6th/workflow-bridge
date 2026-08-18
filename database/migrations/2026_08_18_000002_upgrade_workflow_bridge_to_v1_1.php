<?php

use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpgradeWorkflowBridgeToV11 extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('workflow_approval_results', 'start_status')) {
            Schema::table('workflow_approval_results', function (Blueprint $table) {
                $table->string('start_status', 30)->default('pending')->comment('发起状态：pending/processing/succeeded/failed');
                $table->string('start_idempotency_key', 64)->default('')->comment('发起幂等键 SHA-256');
                $table->unsignedInteger('requested_process_version')->default(0)->comment('调用方指定的流程版本，0 表示未指定');
                $table->unsignedInteger('process_version')->default(0)->comment('Workflow 实际流程版本');
                $table->unsignedInteger('start_attempts')->default(0)->comment('流程发起尝试次数');
                $table->dateTime('start_next_retry_at')->default('1970-01-01 00:00:00')->comment('流程发起下次重试时间');
                $table->dateTime('start_processing_at')->default('1970-01-01 00:00:00')->comment('流程发起任务抢占时间');
                $table->unsignedInteger('apply_attempts')->default(0)->comment('业务结果应用尝试次数');
                $table->dateTime('apply_next_retry_at')->default('1970-01-01 00:00:00')->comment('业务结果应用下次重试时间');
                $table->dateTime('apply_processing_at')->default('1970-01-01 00:00:00')->comment('业务结果应用任务抢占时间');

                $table->index(['start_next_retry_at', 'start_status'], 'idx_war_start_due');
                $table->index(['apply_next_retry_at', 'local_apply_status'], 'idx_war_apply_due');
            });
        }

        if (!Schema::hasTable('workflow_callback_deliveries')) {
            Schema::create('workflow_callback_deliveries', function (Blueprint $table) {
                $table->bigIncrements('id')->comment('回调投递记录 ID');
                $table->unsignedBigInteger('approval_result_id')->default(0)->comment('本地流程结果记录 ID');
                $table->string('business_key', 160)->default('')->comment('业务单号快照');
                $table->string('owner_system', 60)->default('')->comment('来源系统快照');
                $table->string('process_code', 120)->default('')->comment('Workflow 流程 code 快照');
                $table->string('instance_uuid', 64)->default('')->comment('Workflow 实例 UUID 快照');
                $table->string('event', 60)->default('')->comment('回调事件名称');
                $table->string('result', 30)->default('')->comment('回调审批结果');
                $table->string('result_value', 60)->default('')->comment('回调结果映射值');
                $table->string('idempotency_key', 190)->default('')->comment('Workflow 回调幂等键');
                $table->string('delivery_id', 80)->default('')->comment('Workflow 投递 ID');
                $table->mediumText('payload_json')->nullable()->comment('原始回调 JSON，应用层默认空对象');
                $table->dateTime('received_at')->default('1970-01-01 00:00:00')->comment('回调接收时间');
                $table->dateTime('created_at')->default('1970-01-01 00:00:00')->comment('创建时间');
                $table->dateTime('updated_at')->default('1970-01-01 00:00:00')->comment('更新时间');

                $table->unique('idempotency_key', 'uk_wcd_idempotency');
                $table->index('approval_result_id', 'idx_wcd_result');
                $table->index('instance_uuid', 'idx_wcd_instance');
                $table->index(['business_key', 'process_code', 'owner_system'], 'idx_wcd_biz');
            });
        }

        WorkflowApprovalResult::where('workflow_status', WorkflowApprovalResult::STATUS_START_FAILED)
            ->update([
                'start_status' => WorkflowApprovalResult::START_FAILED,
                'workflow_status' => WorkflowApprovalResult::STATUS_NOT_STARTED,
            ]);

        WorkflowApprovalResult::where('workflow_status', '<>', WorkflowApprovalResult::STATUS_NOT_STARTED)
            ->where(function ($query) {
                $query->where('instance_uuid', '<>', '')
                    ->orWhereIn('workflow_status', [
                        WorkflowApprovalResult::STATUS_RUNNING,
                        WorkflowApprovalResult::STATUS_WAITING,
                        WorkflowApprovalResult::STATUS_APPROVED,
                        WorkflowApprovalResult::STATUS_REJECTED,
                        WorkflowApprovalResult::STATUS_FAILED,
                        WorkflowApprovalResult::STATUS_CANCELLED,
                    ]);
            })
            ->update(['start_status' => WorkflowApprovalResult::START_SUCCEEDED]);
    }

    public function down()
    {
        Schema::dropIfExists('workflow_callback_deliveries');

        if (Schema::hasColumn('workflow_approval_results', 'start_status')) {
            Schema::table('workflow_approval_results', function (Blueprint $table) {
                $table->dropIndex('idx_war_start_due');
                $table->dropIndex('idx_war_apply_due');
                $table->dropColumn([
                    'start_status',
                    'start_idempotency_key',
                    'requested_process_version',
                    'process_version',
                    'start_attempts',
                    'start_next_retry_at',
                    'start_processing_at',
                    'apply_attempts',
                    'apply_next_retry_at',
                    'apply_processing_at',
                ]);
            });
        }
    }
}
