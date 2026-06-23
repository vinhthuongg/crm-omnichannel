<?php

namespace Modules\Notification\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Shared\Http\Controllers\ApiController;

class NotificationController extends ApiController
{
    public function index(Request $request)
    {
        return response()->json(['data' => $request->user()->notifications()->latest()->paginate((int) $request->query('per_page', 20))]);
    }

    public function markAsRead(Request $request, string $id)
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();
        return response()->json(status: 204);
    }
}