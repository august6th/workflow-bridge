<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Callback\CallbackHandler;
use August6th\WorkflowBridge\Http\Controllers\CallbackController;
use August6th\WorkflowBridge\Models\WorkflowApprovalResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CallbackControllerTest extends TestCase
{
    public function testUsesRawJsonBodyWhenRequestBagWasMutatedByGlobalMiddleware()
    {
        $rawPayload = [
            'event' => 'workflow.finished',
            'business_key' => 'A001',
            'owner_system' => 'ic',
            'process_code' => 'skc_approval',
            'instance_uuid' => 'pi_test',
            'result' => 'approved',
            'idempotency_key' => 'workflow:test:approved',
            'approval_history' => [
                [
                    'resolved_assignees' => [
                        [
                            'email' => '',
                            'mobile' => '',
                        ],
                    ],
                ],
            ],
        ];
        $json = json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $handler = $this->createMock(CallbackHandler::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with(
                $this->anything(),
                $this->callback(function (array $payload) {
                    return $payload['approval_history'][0]['resolved_assignees'][0]['email'] === ''
                        && $payload['approval_history'][0]['resolved_assignees'][0]['mobile'] === '';
                })
            )
            ->willReturn(new WorkflowApprovalResult([
                'id' => 1,
                'business_key' => 'A001',
                'workflow_status' => WorkflowApprovalResult::STATUS_APPROVED,
            ]));

        $request = Request::create(
            '/workflow/callback',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json
        );
        $mutated = $rawPayload;
        $mutated['approval_history'][0]['resolved_assignees'][0]['email'] = null;
        $mutated['approval_history'][0]['resolved_assignees'][0]['mobile'] = null;
        $request->json()->replace($mutated);

        $controller = new CallbackController($handler);
        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(20000, $response->getData(true)['code']);
    }

    public function testExpectedCallbackFailureReturnsPublicMessage()
    {
        $handler = $this->createMock(CallbackHandler::class);
        $handler->method('handle')->willThrowException(
            new RuntimeException('Invalid workflow callback signature')
        );
        $logger = $this->createMock(CallbackControllerTestLogger::class);
        $logger->expects($this->never())->method('error');
        Facade::getFacadeApplication()->instance(LoggerInterface::class, $logger);
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
        Facade::getFacadeApplication()->instance(LoggerInterface::class, $logger);
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
