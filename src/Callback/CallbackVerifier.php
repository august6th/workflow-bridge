<?php

namespace August6th\WorkflowBridge\Callback;

use RuntimeException;

class CallbackVerifier
{
    /** @var string */
    protected $secret;

    /** @var int */
    protected $clockSkewSeconds;

    public function __construct($secret, $clockSkewSeconds = 300)
    {
        $this->secret = trim((string) $secret);
        $this->clockSkewSeconds = max(30, (int) $clockSkewSeconds);
    }

    /**
     * @param array $headers
     * @param array $payload
     * @return void
     */
    public function verify(array $headers, array $payload)
    {
        if ($this->secret === '') {
            throw new RuntimeException('WORKFLOW_CALLBACK_SECRET is empty');
        }

        $timestamp = $this->header($headers, 'X-Workflow-Timestamp');
        $nonce = $this->header($headers, 'X-Workflow-Nonce');
        $signature = $this->header($headers, 'X-Workflow-Signature');
        $idempotencyKey = $this->header($headers, 'X-Workflow-Idempotency-Key');
        if ($idempotencyKey === '' && isset($payload['idempotency_key'])) {
            $idempotencyKey = (string) $payload['idempotency_key'];
        }

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            throw new RuntimeException('Missing workflow callback signature headers');
        }
        if (preg_match('/^\d+$/D', $timestamp) !== 1) {
            throw new RuntimeException('Invalid workflow callback timestamp');
        }

        if (abs(time() - (int) $timestamp) > $this->clockSkewSeconds) {
            throw new RuntimeException('Workflow callback timestamp out of range');
        }

        $expected = hash_hmac('sha256', implode("\n", [
            $timestamp,
            $nonce,
            $idempotencyKey,
            $this->jsonForSignature($payload),
        ]), $this->secret);

        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid workflow callback signature');
        }
    }

    /**
     * @param array $payload
     * @return string
     */
    public function jsonForSignature(array $payload)
    {
        return json_encode($this->sortArrayKeysRecursive($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function sortArrayKeysRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = empty($value) || array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_map(function ($item) {
                return $this->sortArrayKeysRecursive($item);
            }, $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortArrayKeysRecursive($item);
        }

        return $value;
    }

    /**
     * @param array $headers
     * @param string $name
     * @return string
     */
    protected function header(array $headers, $name)
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                if (is_array($value)) {
                    return isset($value[0]) ? (string) $value[0] : '';
                }

                return (string) $value;
            }
        }

        return '';
    }
}
