<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Support\StartQueueName;

class StartQueueNameTest extends TestCase
{
    public function testResolveUsesOwnerSystemAndDefaultSuffix()
    {
        $this->assertSame(
            'ic:workflow-bridge',
            StartQueueName::resolve(['owner_system' => 'ic', 'start_queue' => 'workflow-bridge'])
        );
    }

    public function testResolveUsesCustomSuffix()
    {
        $this->assertSame(
            'pms:workflow-start',
            StartQueueName::resolve(['owner_system' => 'pms', 'start_queue' => 'workflow-start'])
        );
    }

    public function testResolveFallsBackToErpWhenOwnerMissing()
    {
        $this->assertSame(
            'erp:workflow-bridge',
            StartQueueName::resolve(['start_queue' => 'workflow-bridge'])
        );
    }
}
