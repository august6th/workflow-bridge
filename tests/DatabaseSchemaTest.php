<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSchemaTest extends TestCase
{
    public function testSchemaSupportsStartApplyAndCallbackStates()
    {
        $columns = Schema::getColumnListing('workflow_approval_results');

        $this->assertContains('start_status', $columns);
        $this->assertContains('start_idempotency_key', $columns);
        $this->assertContains('requested_process_version', $columns);
        $this->assertContains('start_attempts', $columns);
        $this->assertContains('apply_attempts', $columns);
        $this->assertTrue(Schema::hasTable('workflow_callback_deliveries'));
    }

    public function testStartIdempotencyKeyHasFixedLength()
    {
        $key = WorkflowApprovalResult::startIdempotencyKey(
            str_repeat('o', 60),
            str_repeat('p', 120),
            str_repeat('b', 160)
        );

        $this->assertSame(64, strlen($key));
    }

    public function testStartWorkflowAndApplyStatesAreSeparated()
    {
        $this->assertSame('pending', WorkflowApprovalResult::START_PENDING);
        $this->assertSame('processing', WorkflowApprovalResult::START_PROCESSING);
        $this->assertSame('succeeded', WorkflowApprovalResult::START_SUCCEEDED);
        $this->assertSame('failed', WorkflowApprovalResult::START_FAILED);
        $this->assertSame('not_started', WorkflowApprovalResult::STATUS_NOT_STARTED);
        $this->assertSame('processing', WorkflowApprovalResult::APPLY_PROCESSING);
    }

    public function testSchemaUsesNullForTimestampsThatHaveNotOccurred()
    {
        $columns = collect(DB::select("PRAGMA table_info('workflow_approval_results')"))->keyBy('name');

        foreach ([
            'start_next_retry_at',
            'start_processing_at',
            'apply_next_retry_at',
            'apply_processing_at',
            'started_at',
            'finished_at',
            'applied_at',
        ] as $column) {
            $this->assertSame(0, (int) $columns->get($column)->notnull, $column . ' should be nullable');
        }
        $this->assertSame(1, (int) $columns->get('created_at')->notnull);
        $this->assertSame(1, (int) $columns->get('updated_at')->notnull);
    }

    public function testDatabaseDefinitionIsConsolidatedWithoutSentinelDates()
    {
        $migrationFiles = glob(__DIR__ . '/../database/migrations/*.php');
        $upgradeSqlFiles = glob(__DIR__ . '/../database/sql/upgrades/*.sql');
        $sql = file_get_contents(__DIR__ . '/../database/sql/workflow_approval_results.sql');

        $this->assertCount(1, $migrationFiles);
        $this->assertCount(1, $upgradeSqlFiles);
        $upgradeSql = file_get_contents($upgradeSqlFiles[0]);
        $this->assertNotSame(false, strpos($upgradeSql, 'idx_war_route_apply_due'));
        $this->assertSame(false, strpos($upgradeSql, 'ALTER TABLE `workflow_approval_results` ADD INDEX IF NOT EXISTS'));
        $this->assertSame(false, strpos($sql, '1970-01-01'));
        $this->assertSame(false, strpos($sql, '9999-12-31'));
        $this->assertNotSame(false, strpos($sql, '`finished_at` datetime NULL DEFAULT NULL'));
    }
}
