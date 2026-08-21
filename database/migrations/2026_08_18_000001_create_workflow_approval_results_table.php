<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateWorkflowApprovalResultsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflow_approval_results')) {
            Schema::create('workflow_approval_results', function (Blueprint $table) {
                $table->bigIncrements('id')->comment('本地流程结果记录 ID');
                $table->string('business_key', 160)->default('')->comment('业务单号');
                $table->string('owner_system', 60)->default('')->comment('来源系统');
                $table->string('process_code', 120)->default('')->comment('Workflow 流程 code');
                $table->unsignedInteger('requested_process_version')->default(0)->comment('调用方指定流程版本，0 表示未指定');
                $table->unsignedInteger('process_version')->default(0)->comment('Workflow 实际流程版本');
                $table->string('start_status', 30)->default('pending')->comment('发起状态：pending/processing/succeeded/failed');
                $table->string('start_idempotency_key', 64)->default('')->comment('发起幂等键 SHA-256');
                $table->string('instance_uuid', 64)->default('')->comment('Workflow 实例 UUID');
                $table->string('workflow_status', 30)->default('not_started')->comment('流程状态');
                $table->string('start_error', 1000)->default('')->comment('发起失败原因');
                $table->unsignedInteger('start_attempts')->default(0)->comment('流程发起尝试次数');
                $table->dateTime('start_next_retry_at')->nullable()->comment('流程发起下次重试时间，无重试计划时为 NULL');
                $table->dateTime('start_processing_at')->nullable()->comment('流程发起任务抢占时间，未处理时为 NULL');
                $table->string('result', 30)->default('')->comment('终态 approved/rejected');
                $table->string('result_value', 60)->default('')->comment('流程结果映射值');
                $table->string('idempotency_key', 190)->default('')->comment('兼容 1.0 的发起幂等键');
                $table->string('delivery_id', 80)->default('')->comment('最近一次 Workflow 投递 ID');
                $table->mediumText('callback_payload_json')->nullable()->comment('最近一次回调 JSON，应用层默认空对象');
                $table->mediumText('business_payload_json')->nullable()->comment('发起业务快照 JSON，应用层默认空对象');
                $table->mediumText('input_json')->nullable()->comment('流程输入 JSON，应用层默认空对象');
                $table->string('local_apply_status', 40)->default('pending')->comment('业务应用状态：pending/processing/applied/skipped/failed');
                $table->string('local_apply_error', 1000)->default('')->comment('业务结果应用失败原因');
                $table->unsignedInteger('apply_attempts')->default(0)->comment('业务结果应用尝试次数');
                $table->dateTime('apply_next_retry_at')->nullable()->comment('业务结果应用下次重试时间，无重试计划时为 NULL');
                $table->dateTime('apply_processing_at')->nullable()->comment('业务结果应用任务抢占时间，未处理时为 NULL');
                $table->dateTime('started_at')->nullable()->comment('Workflow 实例开始时间，未开始时为 NULL');
                $table->dateTime('finished_at')->nullable()->comment('Workflow 实例结束时间，未结束时为 NULL');
                $table->dateTime('applied_at')->nullable()->comment('业务结果应用完成时间，未完成时为 NULL');
                $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('创建时间');
                $table->dateTime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('更新时间');

                $table->unique('idempotency_key', 'uk_war_idem');
                $table->unique(['business_key', 'process_code', 'owner_system'], 'uk_war_biz');
                $table->index(['process_code', 'owner_system', 'local_apply_status', 'workflow_status', 'apply_next_retry_at'], 'idx_war_route_apply_due');
                $table->index(['process_code', 'owner_system', 'local_apply_status', 'apply_processing_at'], 'idx_war_route_apply_lease');
                $table->index(['process_code', 'owner_system', 'start_status', 'start_next_retry_at'], 'idx_war_route_start_due');
                $table->index(['process_code', 'owner_system', 'start_status', 'start_processing_at'], 'idx_war_route_start_lease');
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
                $table->dateTime('received_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('回调接收时间');
                $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('创建时间');
                $table->dateTime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('更新时间');

                $table->unique('idempotency_key', 'uk_wcd_idempotency');
                $table->index('approval_result_id', 'idx_wcd_result');
                $table->index('instance_uuid', 'idx_wcd_instance');
                $table->index('received_at', 'idx_wcd_received');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('workflow_callback_deliveries');
        Schema::dropIfExists('workflow_approval_results');
    }
}
