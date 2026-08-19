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

    public function testOperationalSettingsUsePackageDefaultsWithoutExtraConfigSections()
    {
        $config = require __DIR__ . '/../config/workflow-bridge.php';

        $this->assertSame(300, $config['sso_clock_skew_seconds']);
        $this->assertSame(300, $config['callback_clock_skew_seconds']);
        $this->assertSame(300, $config['start_lease_seconds']);
        $this->assertSame(60, $config['start_retry_base_seconds']);
        $this->assertSame(3600, $config['start_retry_max_seconds']);
        $this->assertSame(300, $config['apply_lease_seconds']);
        $this->assertSame(60, $config['apply_retry_base_seconds']);
        $this->assertSame(3600, $config['apply_retry_max_seconds']);
        $this->assertSame(15, $config['http_timeout']);
        $this->assertSame(3600, $config['token_cache_seconds']);
        $this->assertArrayNotHasKey('client', $config);
        $this->assertArrayNotHasKey('processes', $config);
    }

    public function testOperationalSettingsMayBeOverriddenByEnvironment()
    {
        putenv('WORKFLOW_HTTP_TIMEOUT=27');

        try {
            $config = require __DIR__ . '/../config/workflow-bridge.php';
            $this->assertSame(27, $config['http_timeout']);
        } finally {
            putenv('WORKFLOW_HTTP_TIMEOUT');
        }
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
