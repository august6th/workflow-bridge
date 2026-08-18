<?php

namespace August6th\WorkflowBridge\Http\Controllers;

use August6th\WorkflowBridge\Callback\CallbackHandler;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CallbackController extends Controller
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
            $payload = $request->all();
            if (!is_array($payload)) {
                $payload = [];
            }
            $result = $this->handler->handle($request->headers->all(), $payload);

            return response()->json([
                'code' => 20000,
                'message' => 'success',
                'data' => [
                    'id' => $result->id,
                    'business_key' => $result->business_key,
                    'workflow_status' => $result->workflow_status,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'code' => 40001,
                'message' => $e->getMessage(),
                'data' => null,
            ], 400);
        }
    }
}
