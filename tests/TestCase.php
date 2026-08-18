<?php

namespace August6th\WorkflowBridge\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    /** @var Capsule */
    protected $database;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $database = new Capsule($container);
        $database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $database->setEventDispatcher(new Dispatcher($container));
        $database->setAsGlobal();
        $database->bootEloquent();
        $container->instance('db.schema', $database->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        $this->database = $database;

        require_once __DIR__ . '/../database/migrations/2026_08_18_000001_create_workflow_approval_results_table.php';
        (new \CreateWorkflowApprovalResultsTable())->up();

        $upgradeMigration = __DIR__ . '/../database/migrations/2026_08_18_000002_upgrade_workflow_bridge_to_v1_1.php';
        if (file_exists($upgradeMigration)) {
            require_once $upgradeMigration;
            (new \UpgradeWorkflowBridgeToV11())->up();
        }
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }
}
