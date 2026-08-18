<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Callback\CallbackVerifier;

class ConfigurationTest extends TestCase
{
    public function testRetryAndLeaseDefaultsArePositive()
    {
        $config = require __DIR__ . '/../config/workflow-bridge.php';

        $this->assertGreaterThan(0, $config['start_lease_seconds']);
        $this->assertGreaterThan(0, $config['start_retry_base_seconds']);
        $this->assertGreaterThanOrEqual(
            $config['start_retry_base_seconds'],
            $config['start_retry_max_seconds']
        );
        $this->assertGreaterThan(0, $config['apply_lease_seconds']);
        $this->assertGreaterThan(0, $config['apply_retry_base_seconds']);
        $this->assertGreaterThanOrEqual(
            $config['apply_retry_base_seconds'],
            $config['apply_retry_max_seconds']
        );
    }

    public function testEmptyCallbackSecretRemainsFailClosed()
    {
        $config = require __DIR__ . '/../config/workflow-bridge.php';
        $this->assertSame('', $config['callback_secret']);
        $this->expectException(\RuntimeException::class);

        $verifier = new CallbackVerifier($config['callback_secret']);
        $verifier->verify([], []);
    }
}
