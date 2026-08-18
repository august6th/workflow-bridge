<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Client\WorkflowClient;
use August6th\WorkflowBridge\Exceptions\WorkflowRequestException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class WorkflowClientTest extends TestCase
{
    public function testUnauthorizedResponseClearsTokenAndRetriesLoginOnce()
    {
        $history = [];
        $client = $this->clientWithResponses([
            $this->jsonResponse(200, ['code' => 20000, 'data' => ['access_token' => 'old-token']]),
            $this->jsonResponse(401, ['code' => 40100, 'message' => 'token expired']),
            $this->jsonResponse(200, ['code' => 20000, 'data' => ['access_token' => 'new-token']]),
            $this->jsonResponse(200, ['code' => 20000, 'data' => ['instance' => ['instance_uuid' => 'pi_1']]]),
        ], $history);

        $response = $client->startProcess('skc_approval', ['business_key' => 'A001']);

        $this->assertSame('pi_1', $response['data']['instance']['instance_uuid']);
        $this->assertCount(4, $history);
        $this->assertSame('Bearer old-token', $history[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer new-token', $history[3]['request']->getHeaderLine('Authorization'));
    }

    public function testBusinessErrorThrowsWorkflowRequestException()
    {
        $history = [];
        $client = $this->clientWithResponses([
            $this->jsonResponse(200, ['code' => 20000, 'data' => ['access_token' => 'token']]),
            $this->jsonResponse(422, ['code' => 40001, 'message' => 'invalid business payload']),
        ], $history);

        try {
            $client->startProcess('skc_approval', ['business_key' => 'A002']);
            $this->fail('Expected WorkflowRequestException was not thrown');
        } catch (WorkflowRequestException $exception) {
            $this->assertSame('invalid business payload', $exception->getMessage());
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame(40001, $exception->getWorkflowCode());
        }
    }

    protected function clientWithResponses(array $responses, array &$history)
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client([
            'base_uri' => 'http://workflow.test/api/',
            'handler' => $stack,
            'http_errors' => false,
        ]);

        return new WorkflowClient([
            'base_url' => 'http://workflow.test/api',
            'sso_secret' => 'sso-secret',
            'token_cache_seconds' => 3600,
            'client' => [
                'user_name' => 'ic_client',
                'name' => 'IC Client',
                'source_system' => 'ic',
                'permissions' => ['workflow:external:start'],
            ],
        ], $http);
    }

    protected function jsonResponse($status, array $payload)
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
