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
        $migrationSql = file_get_contents($migrationFiles[0]);

        $this->assertCount(1, $migrationFiles);
        $this->assertCount(1, $upgradeSqlFiles);
        $upgradeSql = file_get_contents($upgradeSqlFiles[0]);
        $this->assertNotSame(false, strpos($upgradeSql, 'idx_war_route_apply_due'));
        $expectedIndexes = [
            'idx_war_route_apply_due' => '`process_code`,`owner_system`,`local_apply_status`,`workflow_status`,`apply_next_retry_at`',
            'idx_war_route_apply_lease' => '`process_code`,`owner_system`,`local_apply_status`,`apply_processing_at`',
            'idx_war_route_start_due' => '`process_code`,`owner_system`,`start_status`,`start_next_retry_at`',
            'idx_war_route_start_lease' => '`process_code`,`owner_system`,`start_status`,`start_processing_at`',
        ];
        foreach ($expectedIndexes as $name => $columns) {
            $this->assertNotSame(false, strpos($sql, 'KEY `' . $name . '` (' . $columns . ')'));
            $this->assertNotSame(false, strpos($upgradeSql, $name));
        }
        foreach ([
            "['process_code', 'owner_system', 'local_apply_status', 'workflow_status', 'apply_next_retry_at'], 'idx_war_route_apply_due'",
            "['process_code', 'owner_system', 'local_apply_status', 'apply_processing_at'], 'idx_war_route_apply_lease'",
            "['process_code', 'owner_system', 'start_status', 'start_next_retry_at'], 'idx_war_route_start_due'",
            "['process_code', 'owner_system', 'start_status', 'start_processing_at'], 'idx_war_route_start_lease'",
        ] as $migrationIndex) {
            $this->assertNotSame(false, strpos($migrationSql, $migrationIndex));
        }
        $this->assertNotSame(false, strpos($upgradeSql, 'GROUP_CONCAT(`column_name` ORDER BY `seq_in_index`'));
        $this->assertNotSame(false, strpos($upgradeSql, 'SELECT DISTINCT `index_name`'));
        $this->assertNotSame(false, strpos($upgradeSql, 'DROP INDEX `idx_war_route_apply_due`'));
        foreach ([
            'idx_war_instance',
            'idx_war_status_updated',
            'idx_war_apply_created',
            'idx_war_start_due',
            'idx_war_apply_due',
            'idx_wcd_biz',
        ] as $removedIndex) {
            $this->assertSame(false, strpos($sql, 'KEY `' . $removedIndex . '`'));
            $this->assertSame(false, strpos($migrationSql, "'" . $removedIndex . "'"));
            $this->assertNotSame(false, strpos($upgradeSql, "CONCAT('DROP INDEX `', `index_name`, '`')"));
            $this->assertNotSame(false, strpos($upgradeSql, "'" . $removedIndex . "'"));
        }
        $resultTableSql = substr($sql, 0, strpos($sql, 'CREATE TABLE IF NOT EXISTS `workflow_callback_deliveries`'));
        $callbackTableSql = substr($sql, strpos($sql, 'CREATE TABLE IF NOT EXISTS `workflow_callback_deliveries`'));
        preg_match_all('/(?:PRIMARY KEY \(`[^`]+`\)|(?:UNIQUE KEY|KEY) `([^`]+)` \()/', $resultTableSql, $resultIndexMatches);
        preg_match_all('/(?:PRIMARY KEY \(`[^`]+`\)|(?:UNIQUE KEY|KEY) `([^`]+)` \()/', $callbackTableSql, $callbackIndexMatches);
        $resultIndexes = array_map(function ($name) {
            return $name === '' ? 'PRIMARY' : $name;
        }, $resultIndexMatches[1]);
        $callbackIndexes = array_map(function ($name) {
            return $name === '' ? 'PRIMARY' : $name;
        }, $callbackIndexMatches[1]);
        $this->assertSame([
            'PRIMARY',
            'uk_war_idem',
            'uk_war_biz',
            'idx_war_route_apply_due',
            'idx_war_route_apply_lease',
            'idx_war_route_start_due',
            'idx_war_route_start_lease',
        ], $resultIndexes);
        $this->assertSame([
            'PRIMARY',
            'uk_wcd_idempotency',
            'idx_wcd_result',
            'idx_wcd_instance',
            'idx_wcd_received',
        ], $callbackIndexes);
        $this->assertSame(false, strpos($sql, '`apply_next_retry_at`,`id`'));
        $this->assertSame(false, strpos($sql, '`start_next_retry_at`,`id`'));
        $callbackExpectedIndexes = [
            1 => ['idx_wcd_result', 'approval_result_id'],
            2 => ['idx_wcd_instance', 'instance_uuid'],
            3 => ['idx_wcd_received', 'received_at'],
        ];
        foreach ($callbackExpectedIndexes as $sequence => $definition) {
            list($name, $column) = $definition;
            $this->assertNotSame(false, strpos($callbackTableSql, 'KEY `' . $name . '` (`' . $column . '`)'));
            $this->assertNotSame(false, strpos($migrationSql, "\$table->index('" . $column . "', '" . $name . "')"));
            $this->assertNotSame(false, strpos($upgradeSql, '@wcd_index_' . $sequence . '_columns'));
            $this->assertNotSame(false, strpos($upgradeSql, '@wcd_index_sql = IF(@wcd_index_' . $sequence . "_columns = '" . $column . "'"));
            $this->assertNotSame(false, strpos($upgradeSql, "IF(@wcd_index_" . $sequence . "_columns IS NULL, '', 'DROP INDEX `" . $name . "`, ')"));
            $this->assertNotSame(false, strpos($upgradeSql, "ADD INDEX `" . $name . "` (`" . $column . "`)"));
        }
        $this->assertSame(1, substr_count($upgradeSql, "AND `index_name` IN ('idx_wcd_biz')"));
        $this->assertSame(false, strpos($upgradeSql, "AND `index_name` IN ('idx_wcd_result'"));
        $this->assertSame(false, strpos($upgradeSql, "AND `index_name` IN ('idx_wcd_instance'"));
        $this->assertSame(false, strpos($upgradeSql, 'ADD INDEX IF NOT EXISTS `'));
        $this->assertSame(false, strpos($upgradeSql, 'DROP INDEX IF EXISTS `'));
        $this->assertSame(false, strpos($sql, '1970-01-01'));
        $this->assertSame(false, strpos($sql, '9999-12-31'));
        $this->assertNotSame(false, strpos($sql, '`finished_at` datetime NULL DEFAULT NULL'));
    }
}
