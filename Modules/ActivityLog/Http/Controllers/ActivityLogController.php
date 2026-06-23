<?php

namespace Modules\ActivityLog\Http\Controllers;

use Illuminate\Http\Request;
use Modules\ActivityLog\Actions\ListActivityLogsAction;
use Modules\ActivityLog\Http\Resources\ActivityLogResource;
use Modules\Shared\Http\Controllers\ApiController;

class ActivityLogController extends ApiController
{
    public function index(Request $request, ListActivityLogsAction $action)
    {
        abort_unless($request->user()->can('report.view'), 403);
        return ActivityLogResource::collection($action->execute($request->query()));
    }
}