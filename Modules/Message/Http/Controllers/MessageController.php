<?php

namespace Modules\Message\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Actions\SendMessageAction;
use Modules\Message\Http\Requests\SendMessageRequest;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Shared\Http\Controllers\ApiController;

class MessageController extends ApiController
{
    public function index(Request $request, Conversation $conversation)
    {
        abort_unless($request->user()->can('conversation.view_all') || $conversation->assigned_to === $request->user()->id, 403);
        return MessageResource::collection($conversation->messages()->with('sender')->latest()->paginate((int) $request->query('per_page', 50)));
    }

    public function store(SendMessageRequest $request, Conversation $conversation, SendMessageAction $action): MessageResource
    {
        abort_unless($request->user()->can('conversation.view_all') || $conversation->assigned_to === $request->user()->id, 403);
        return new MessageResource($action->execute($conversation, $request->user(), $request->validated()));
    }
}