<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Callback\CallbackHandler;
use August6th\WorkflowBridge\Http\Controllers\CallbackController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

class CallbackControllerTest extends TestCase
{
    public function testExpectedCallbackFailureReturnsPublicMessage()
    {
        $handler = $this->createMock(CallbackHandler::class);
        $handler->method('handle')->willThrowException(
            new RuntimeException('Invalid workflow callback signature')
        );
        $logger = $this->createMock(CallbackControllerTestLogger::class);
        $logger->expects($this->never())->method('error');
        Facade::getFacadeApplication()->instance('log', $logger);
        $controller = new CallbackController($handler);

        $response = $controller(Request::create('/workflow/callback', 'POST', []));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([
            'code' => 40001,
            'message' => 'Invalid workflow callback signature',
            'data' => null,
        ], $response->getData(true));
    }

    public function testUnexpectedCallbackFailureIsLoggedAndHidden()
    {
        $failure = new \Error('database connection details');
        $handler = $this->createMock(CallbackHandler::class);
        $handler->method('handle')->willThrowException($failure);
        $logger = $this->createMock(CallbackControllerTestLogger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Workflow callback failed', $this->callback(function (array $context) use ($failure) {
                return isset($context['exception']) && $context['exception'] === $failure;
            }));
        Facade::getFacadeApplication()->instance('log', $logger);
        $controller = new CallbackController($handler);

        $response = $controller(Request::create('/workflow/callback', 'POST', []));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'code' => 50000,
            'message' => 'Workflow callback failed',
            'data' => null,
        ], $response->getData(true));
    }
}

class CallbackControllerTestLogger
{
    public function error($message, array $context = [])
    {
    }
}
