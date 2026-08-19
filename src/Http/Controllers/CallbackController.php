<?php

namespace August6th\WorkflowBridge\Http\Controllers;

use August6th\WorkflowBridge\Callback\CallbackHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CallbackController
{
    /** @var CallbackHandler */
    protected $handler;

    public function __construct(CallbackHandler $handler)
    {
        $this->handler = $handler;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        try {
            $payload = $this->resolveCallbackPayload($request);
            $result = $this->handler->handle($request->headers->all(), $payload);

            return new JsonResponse([
                'code' => 20000,
                'message' => 'success',
                'data' => [
                    'id' => $result->id,
                    'business_key' => $result->business_key,
                    'workflow_status' => $result->workflow_status,
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->expectedFailure($exception);
        } catch (RuntimeException $exception) {
            return $this->expectedFailure($exception);
        } catch (Throwable $exception) {
            Log::error('Workflow callback failed', ['exception' => $exception]);

            return new JsonResponse([
                'code' => 50000,
                'message' => 'Workflow callback failed',
                'data' => null,
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @return array
     */
    protected function resolveCallbackPayload(Request $request)
    {
        $content = (string) $request->getContent();
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $payload = $request->all();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param \Exception $exception
     * @return JsonResponse
     */
    protected function expectedFailure($exception)
    {
        return new JsonResponse([
            'code' => 40001,
            'message' => $exception->getMessage(),
            'data' => null,
        ], 400);
    }
}
