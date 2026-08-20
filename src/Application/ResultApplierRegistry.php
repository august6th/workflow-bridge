<?php

namespace August6th\WorkflowBridge\Application;

use August6th\WorkflowBridge\Contracts\ResultApplier;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

class ResultApplierRegistry
{
    /** @var Container */
    protected $container;

    /** @var array */
    protected $appliers = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Register one exact owner_system + process_code route.
     * Duplicate routes are rejected instead of silently overwritten.
     *
     * @param string $ownerSystem
     * @param string $processCode
     * @param ResultApplier|string $applier
     * @return $this
     */
    public function register($ownerSystem, $processCode, $applier)
    {
        list($ownerSystem, $processCode) = $this->normalizeRoute($ownerSystem, $processCode);
        if (is_string($applier)) {
            if (trim($applier) === '') {
                throw new InvalidArgumentException('Result applier class must not be empty.');
            }
        } elseif (!$applier instanceof ResultApplier) {
            throw new InvalidArgumentException('Result applier must implement ResultApplier or be a container-resolvable class string.');
        }

        $key = $this->key($ownerSystem, $processCode);
        if (isset($this->appliers[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Result applier route is already registered: owner_system=%s process_code=%s',
                $ownerSystem,
                $processCode
            ));
        }

        $this->appliers[$key] = [
            'owner_system' => $ownerSystem,
            'process_code' => $processCode,
            'applier' => $applier,
        ];

        return $this;
    }

    public function has($ownerSystem, $processCode)
    {
        list($ownerSystem, $processCode) = $this->normalizeRoute($ownerSystem, $processCode);

        return isset($this->appliers[$this->key($ownerSystem, $processCode)]);
    }

    /** @return ResultApplier */
    public function resolve($ownerSystem, $processCode)
    {
        list($ownerSystem, $processCode) = $this->normalizeRoute($ownerSystem, $processCode);
        $key = $this->key($ownerSystem, $processCode);
        if (!isset($this->appliers[$key])) {
            throw new RuntimeException(sprintf(
                'No result applier registered for owner_system=%s process_code=%s',
                $ownerSystem,
                $processCode
            ));
        }

        $applier = $this->appliers[$key]['applier'];
        if (is_string($applier)) {
            $applier = $this->container->make($applier);
        }
        if (!$applier instanceof ResultApplier) {
            throw new RuntimeException(sprintf(
                'Registered result applier for owner_system=%s process_code=%s must implement ResultApplier',
                $ownerSystem,
                $processCode
            ));
        }

        return $applier;
    }

    /** @return array */
    public function routes()
    {
        $routes = [];
        foreach ($this->appliers as $route) {
            $routes[] = [
                'owner_system' => $route['owner_system'],
                'process_code' => $route['process_code'],
            ];
        }

        return $routes;
    }

    protected function normalizeRoute($ownerSystem, $processCode)
    {
        if (!is_string($ownerSystem) || !is_string($processCode)) {
            throw new InvalidArgumentException('Result applier owner_system and process_code must be strings.');
        }

        $ownerSystem = trim($ownerSystem);
        $processCode = trim($processCode);
        if ($ownerSystem === '' || $processCode === '') {
            throw new InvalidArgumentException('Result applier owner_system and process_code must not be empty.');
        }

        return [$ownerSystem, $processCode];
    }

    protected function key($ownerSystem, $processCode)
    {
        return $ownerSystem . "\n" . $processCode;
    }
}
