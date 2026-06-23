<?php

namespace Modules\Zalo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Shared\Http\Controllers\ApiController;
use Modules\Zalo\Actions\HandleZaloWebhookAction;

class ZaloWebhookController extends ApiController
{
    public function __invoke(Request $request, HandleZaloWebhookAction $action): JsonResponse
    {
        $message = $action->execute($request->all());
        return response()->json(['data' => $message ? new MessageResource($message) : null]);
    }
}