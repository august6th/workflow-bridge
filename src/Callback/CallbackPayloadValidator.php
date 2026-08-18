<?php

namespace August6th\WorkflowBridge\Callback;

use InvalidArgumentException;

class CallbackPayloadValidator
{
    /**
     * @param array $payload
     * @return void
     */
    public function validate(array $payload)
    {
        $this->requireString($payload, 'event', 60);
        $this->requireString($payload, 'business_key', 160);
        $this->requireString($payload, 'owner_system', 60);
        $this->requireString($payload, 'process_code', 120);
        $this->requireString($payload, 'instance_uuid', 64);
        $this->requireString($payload, 'result', 30);
        $this->requireString($payload, 'idempotency_key', 190);

        if ($payload['event'] !== 'workflow.finished') {
            throw new InvalidArgumentException('Unsupported workflow callback event');
        }
        if (!in_array($payload['result'], ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Unsupported workflow callback result');
        }
        if (isset($payload['result_value'])) {
            $this->optionalString($payload, 'result_value', 60);
        }
        if (isset($payload['delivery_id'])) {
            $this->optionalString($payload, 'delivery_id', 80);
        }
        if (isset($payload['finished_at'])) {
            $this->optionalString($payload, 'finished_at', 30);
        }
    }

    protected function requireString(array $payload, $key, $maxLength)
    {
        if (!isset($payload[$key]) || !is_string($payload[$key]) || trim($payload[$key]) === '') {
            throw new InvalidArgumentException('Missing workflow callback field: ' . $key);
        }
        if ($this->length($payload[$key]) > $maxLength) {
            throw new InvalidArgumentException('Workflow callback field is too long: ' . $key);
        }
    }

    protected function optionalString(array $payload, $key, $maxLength)
    {
        if (!is_string($payload[$key])) {
            throw new InvalidArgumentException('Invalid workflow callback field: ' . $key);
        }
        if ($this->length($payload[$key]) > $maxLength) {
            throw new InvalidArgumentException('Workflow callback field is too long: ' . $key);
        }
    }

    protected function length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
