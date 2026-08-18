<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWorkflowApprovalResultsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflow_approval_results')) {
            return;
        }

        Schema::create('workflow_approval_results', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('business_key', 160)->default('');
            $table->string('owner_system', 60)->default('');
            $table->string('process_code', 120)->default('');
            $table->string('instance_uuid', 64)->default('');
            $table->string('workflow_status', 30)->default('');
            $table->string('start_error', 1000)->default('');
            $table->string('result', 30)->default('');
            $table->string('result_value', 60)->default('');
            $table->string('idempotency_key', 190)->default('');
            $table->string('delivery_id', 80)->default('');
            $table->mediumText('callback_payload_json')->nullable();
            $table->mediumText('business_payload_json')->nullable();
            $table->mediumText('input_json')->nullable();
            $table->string('local_apply_status', 40)->default('pending');
            $table->string('local_apply_error', 1000)->default('');
            $table->dateTime('started_at')->default('1970-01-01 00:00:00');
            $table->dateTime('finished_at')->default('1970-01-01 00:00:00');
            $table->dateTime('applied_at')->default('1970-01-01 00:00:00');
            $table->dateTime('created_at')->default('1970-01-01 00:00:00');
            $table->dateTime('updated_at')->default('1970-01-01 00:00:00');

            $table->unique('idempotency_key', 'uk_workflow_approval_idempotency');
            $table->unique(['business_key', 'owner_system', 'process_code'], 'uk_workflow_approval_biz');
            $table->index('instance_uuid', 'idx_workflow_approval_instance');
            $table->index(['workflow_status', 'updated_at'], 'idx_workflow_approval_status_updated');
            $table->index(['local_apply_status', 'created_at'], 'idx_workflow_approval_apply');
        });
    }

    public function down()
    {
        Schema::dropIfExists('workflow_approval_results');
    }
}
