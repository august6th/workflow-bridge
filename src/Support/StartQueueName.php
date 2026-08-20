<?php

namespace August6th\WorkflowBridge\Support;

class StartQueueName
{
    /**
     * @param array|null $config
     * @return string
     */
    public static function resolve(array $config = null)
    {
        if ($config === null && function_exists('config')) {
            $config = config('workflow-bridge', []);
        }
        if (!is_array($config)) {
            $config = [];
        }

        $ownerSystem = trim(isset($config['owner_system']) ? (string) $config['owner_system'] : '');
        if ($ownerSystem === '') {
            $ownerSystem = 'erp';
        }

        $suffix = trim(isset($config['start_queue']) ? (string) $config['start_queue'] : '');
        if ($suffix === '') {
            $suffix = 'workflow-bridge';
        }

        return $ownerSystem . ':' . $suffix;
    }
}
