<?php

namespace Modules\Conversation\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Conversation\Actions\AssignConversationAction;
use Modules\Conversation\Actions\CloseConversationAction;
use Modules\Conversation\Actions\ListConversationsAction;
use Modules\Conversation\Actions\TagConversationAction;
use Modules\Conversation\Http\Requests\AssignConversationRequest;
use Modules\Conversation\Http\Requests\TagConversationRequest;
use Modules\Conversation\Http\Resources\ConversationResource;
use Modules\Conversation\Models\Conversation;
use Modules\Shared\Http\Controllers\ApiController;

class ConversationController extends ApiController
{
    public function index(Request $request, ListConversationsAction $action)
    {
        return ConversationResource::collection($action->execute($request->user(), $request->query()));
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        abort_unless($request->user()->can('conversation.view_all') || $conversation->assigned_to === $request->user()->id, 403);
        return new ConversationResource($conversation->load(['customer.channels', 'assignee', 'messages.sender', 'tags']));
    }

    public function assign(AssignConversationRequest $request, Conversation $conversation, AssignConversationAction $action): ConversationResource
    {
        return new ConversationResource($action->execute($conversation, (int) $request->validated('assigned_to'), $request->user()));
    }

    public function close(Request $request, Conversation $conversation, CloseConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.close'), 403);
        return new ConversationResource($action->execute($conversation, $request->user()));
    }

    public function tag(TagConversationRequest $request, Conversation $conversation, TagConversationAction $action): ConversationResource
    {
        return new ConversationResource($action->execute($conversation, $request->validated('tags'), $request->user()));
    }
}