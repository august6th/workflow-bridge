<?php

namespace August6th\WorkflowBridge\Client;

use August6th\WorkflowBridge\Exceptions\WorkflowRequestException;
use Exception;
use GuzzleHttp\Client;
use RuntimeException;

class WorkflowClient
{
    /** @var array */
    protected $config;

    /** @var Client */
    protected $http;

    /** @var string */
    protected $accessToken = '';

    /** @var int */
    protected $tokenExpiresAt = 0;

    public function __construct(array $config, Client $http = null)
    {
        $this->config = $config;
        $this->http = $http ?: new Client([
            'base_uri' => rtrim($config['base_url'], '/') . '/',
            'timeout' => isset($config['http_timeout']) ? (int) $config['http_timeout'] : 15,
            'http_errors' => false,
        ]);
    }

    /**
     * @param array $overrides
     * @return string
     */
    public function login(array $overrides = [])
    {
        $client = isset($this->config['client']) ? $this->config['client'] : [];
        $claims = array_merge([
            'user_name' => isset($client['user_name']) ? $client['user_name'] : 'workflow_client',
            'name' => isset($client['name']) ? $client['name'] : 'Workflow Client',
            'source_system' => isset($client['source_system']) ? $client['source_system'] : 'erp',
            'permissions' => isset($client['permissions']) ? $client['permissions'] : [
                'workflow:external:start',
                'workflow:external:view',
            ],
            'issued_at' => time(),
            'nonce' => bin2hex(random_bytes(16)),
        ], $overrides);

        $assertion = $this->base64UrlEncode(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $secret = isset($this->config['sso_secret']) ? (string) $this->config['sso_secret'] : '';
        if ($secret === '') {
            throw new RuntimeException('WORKFLOW_SSO_SECRET is empty');
        }

        $signature = $this->base64UrlEncode(hash_hmac('sha256', $assertion, $secret, true));
        $response = $this->request('POST', 'auth/sso-login', [
            'json' => [
                'assertion' => $assertion,
                'signature' => $signature,
            ],
        ], false);

        $token = '';
        if (isset($response['data']['access_token'])) {
            $token = (string) $response['data']['access_token'];
        } elseif (isset($response['data']['token'])) {
            $token = (string) $response['data']['token'];
        }

        if ($token === '') {
            throw new WorkflowRequestException('Workflow SSO login failed: missing access_token');
        }

        $this->accessToken = $token;
        $ttl = isset($this->config['token_cache_seconds']) ? (int) $this->config['token_cache_seconds'] : 3600;
        $this->tokenExpiresAt = time() + max(60, $ttl - 60);

        return $this->accessToken;
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        if ($this->accessToken !== '' && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        return $this->login();
    }

    /**
     * @param string $processCode
     * @param array $payload
     * @return array
     */
    public function startProcess($processCode, array $payload)
    {
        return $this->request(
            'POST',
            'external/processes/' . rawurlencode($processCode) . '/start',
            ['json' => $payload]
        );
    }

    /**
     * @param string $businessKey
     * @param string $ownerSystem
     * @param string $processCode
     * @return array
     */
    public function queryByBusinessKey($businessKey, $ownerSystem = '', $processCode = '')
    {
        $query = ['business_key' => $businessKey];
        if ($ownerSystem !== '') {
            $query['owner_system'] = $ownerSystem;
        }
        if ($processCode !== '') {
            $query['process_code'] = $processCode;
        }

        return $this->request('GET', 'external/process-instances', ['query' => $query]);
    }

    /**
     * @param string $instanceUuid
     * @return array
     */
    public function queryByInstanceUuid($instanceUuid)
    {
        return $this->request(
            'GET',
            'external/process-instances/' . rawurlencode($instanceUuid)
        );
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     * @param bool $auth
     * @return array
     */
    protected function request($method, $uri, array $options = [], $auth = true, $retried = false)
    {
        if ($auth) {
            $options['headers'] = array_merge(
                isset($options['headers']) ? $options['headers'] : [],
                ['Authorization' => 'Bearer ' . $this->getAccessToken()]
            );
        }

        try {
            $response = $this->http->request($method, ltrim($uri, '/'), $options);
        } catch (Exception $exception) {
            throw new WorkflowRequestException(
                'Workflow request failed: ' . $exception->getMessage(),
                0,
                0,
                $exception
            );
        }

        if ($auth && $response->getStatusCode() === 401 && !$retried) {
            $this->accessToken = '';
            $this->tokenExpiresAt = 0;
            $this->login();

            return $this->request($method, $uri, $options, true, true);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new WorkflowRequestException(
                'Workflow response is not JSON: ' . substr($body, 0, 200),
                $response->getStatusCode()
            );
        }

        $code = isset($decoded['code']) ? (int) $decoded['code'] : 0;
        if ($response->getStatusCode() >= 400 || ($code !== 0 && $code !== 20000)) {
            $message = isset($decoded['message']) ? (string) $decoded['message'] : 'Workflow request failed';
            throw new WorkflowRequestException($message, $response->getStatusCode(), $code);
        }

        return $decoded;
    }

    /**
     * @param string $value
     * @return string
     */
    protected function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
