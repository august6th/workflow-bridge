<?php

namespace August6th\WorkflowBridge\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    use DatabaseTestEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseEnvironment();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseEnvironment();
        parent::tearDown();
    }
}
