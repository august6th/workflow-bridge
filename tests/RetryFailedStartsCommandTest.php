<?php

namespace August6th\WorkflowBridge\Tests;

use August6th\WorkflowBridge\Bridge\WorkflowBridge;
use August6th\WorkflowBridge\Console\RetryFailedStartsCommand;

class RetryFailedStartsCommandTest extends TestCase
{
    public function testCommandRejectsMissingProcessRoute()
    {
        $bridge = $this->createMock(WorkflowBridge::class);
        $bridge->expects($this->never())->method('retryFailedStarts');
        $command = new TestRetryFailedStartsCommand([
            'owner' => 'ic',
            'process' => null,
        ]);

        $this->assertSame(1, $command->handle($bridge));
        $this->assertSame('--owner and --process must be provided together and must not be empty.', $command->errorMessage);
    }

    public function testCommandRejectsEmptyOwnerRoute()
    {
        $bridge = $this->createMock(WorkflowBridge::class);
        $bridge->expects($this->never())->method('retryFailedStarts');
        $command = new TestRetryFailedStartsCommand([
            'owner' => '  ',
            'process' => 'skc_approval',
        ]);

        $this->assertSame(1, $command->handle($bridge));
    }
}

class TestRetryFailedStartsCommand extends RetryFailedStartsCommand
{
    public $errorMessage = '';

    private $options;

    public function __construct(array $options)
    {
        parent::__construct();
        $this->options = $options;
    }

    public function option($key = null)
    {
        if ($key === null) {
            return $this->options;
        }

        return isset($this->options[$key]) ? $this->options[$key] : null;
    }

    public function error($string, $verbosity = null)
    {
        $this->errorMessage = $string;
    }
}
