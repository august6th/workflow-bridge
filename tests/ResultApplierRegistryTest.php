<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Application\ResultApplierRegistry;
use August6th\WorkflowBridge\Contracts\ResultApplier;
use Illuminate\Container\Container;
use InvalidArgumentException;
use RuntimeException;

class ResultApplierRegistryTest extends TestCase
{
    public function testRegistersAndResolvesExactRoutes()
    {
        $container = new Container();
        $applier = $this->createMock(ResultApplier::class);
        $container->instance(TestContainerApplier::class, $applier);
        $registry = new ResultApplierRegistry($container);
        $registry->register(' ic ', ' skc_approval ', TestContainerApplier::class);

        $this->assertTrue($registry->has('ic', 'skc_approval'));
        $this->assertFalse($registry->has('ic', 'other'));
        $this->assertSame($applier, $registry->resolve('ic', 'skc_approval'));
        $this->assertSame([
            ['owner_system' => 'ic', 'process_code' => 'skc_approval'],
        ], $registry->routes());
    }

    public function testRejectsEmptyRouteKeys()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        (new ResultApplierRegistry(new Container()))->register(' ', 'skc_approval', TestContainerApplier::class);
    }

    public function testRejectsDuplicateExactRoute()
    {
        $registry = new ResultApplierRegistry(new Container());
        $applier = $this->createMock(ResultApplier::class);
        $registry->register('ic', 'skc_approval', $applier);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        $registry->register('ic', 'skc_approval', $applier);
    }

    public function testRejectsInvalidApplierAtRegistration()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement ResultApplier');

        (new ResultApplierRegistry(new Container()))->register('ic', 'skc_approval', new \stdClass());
    }

    public function testRejectsResolvedClassThatDoesNotImplementContract()
    {
        $registry = new ResultApplierRegistry(new Container());
        $registry->register('ic', 'skc_approval', \stdClass::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement ResultApplier');

        $registry->resolve('ic', 'skc_approval');
    }
}

class TestContainerApplier
{
}
