<?php

namespace August6th\WorkflowBridge;

use August6th\WorkflowBridge\Application\ResultApplicationService;
use August6th\WorkflowBridge\Bridge\WorkflowBridge;
use August6th\WorkflowBridge\Callback\CallbackHandler;
use August6th\WorkflowBridge\Callback\CallbackPayloadValidator;
use August6th\WorkflowBridge\Callback\CallbackVerifier;
use August6th\WorkflowBridge\Client\WorkflowClient;
use August6th\WorkflowBridge\Console\ApplyResultsCommand;
use August6th\WorkflowBridge\Console\RetryFailedStartsCommand;
use August6th\WorkflowBridge\Contracts\ResultApplier;
use August6th\WorkflowBridge\Start\StartWorkflowProcessor;
use Illuminate\Support\ServiceProvider;

class WorkflowBridgeServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/workflow-bridge.php' => function_exists('config_path')
                ? config_path('workflow-bridge.php')
                : base_path('config/workflow-bridge.php'),
        ], 'workflow-bridge-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'workflow-bridge-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RetryFailedStartsCommand::class,
                ApplyResultsCommand::class,
            ]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/workflow-bridge.php', 'workflow-bridge');

        $this->app->singleton(WorkflowClient::class, function ($app) {
            return new WorkflowClient($app['config']->get('workflow-bridge', []));
        });

        $this->app->singleton(CallbackVerifier::class, function ($app) {
            $config = $app['config']->get('workflow-bridge', []);

            return new CallbackVerifier(
                isset($config['callback_secret']) ? $config['callback_secret'] : '',
                isset($config['callback_clock_skew_seconds']) ? $config['callback_clock_skew_seconds'] : 300
            );
        });

        $this->app->singleton(CallbackHandler::class, function ($app) {
            return new CallbackHandler(
                $app->make(CallbackVerifier::class),
                $app->make(CallbackPayloadValidator::class)
            );
        });

        $this->app->singleton(CallbackPayloadValidator::class, function () {
            return new CallbackPayloadValidator();
        });

        $this->app->singleton(WorkflowBridge::class, function ($app) {
            return new WorkflowBridge(
                $app->make(WorkflowClient::class),
                $app['config']->get('workflow-bridge', []),
                $app->make(StartWorkflowProcessor::class),
                $app->make(\Illuminate\Contracts\Bus\Dispatcher::class)
            );
        });

        $this->app->singleton(StartWorkflowProcessor::class, function ($app) {
            return new StartWorkflowProcessor(
                $app->make(WorkflowClient::class),
                $app['config']->get('workflow-bridge', [])
            );
        });

        $this->app->singleton(ResultApplicationService::class, function ($app) {
            return new ResultApplicationService(
                $app->make(ResultApplier::class),
                $app['config']->get('workflow-bridge', [])
            );
        });
    }
}
