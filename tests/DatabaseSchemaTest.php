<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
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
}
