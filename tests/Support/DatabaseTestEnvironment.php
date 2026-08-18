<?php

namespace August6th\WorkflowBridge\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

trait DatabaseTestEnvironment
{
    /** @var Capsule */
    protected $database;

    protected function setUpDatabaseEnvironment()
    {
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
        $container->instance('db', $database->getDatabaseManager());
        $container->instance('db.schema', $database->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        $this->database = $database;

        require_once __DIR__ . '/../../database/migrations/2026_08_18_000001_create_workflow_approval_results_table.php';
        (new \CreateWorkflowApprovalResultsTable())->up();
    }

    protected function tearDownDatabaseEnvironment()
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }
}
