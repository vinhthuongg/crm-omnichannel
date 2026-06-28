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
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Shared\Http\Controllers\ApiController;

class ConversationController extends ApiController
{
    public function index(Request $request, ListConversationsAction $action)
    {
        return ConversationResource::collection($action->execute($request->user(), $request->query()));
    }

    public function show(Request $request, Conversation $conversation, ConversationVisibilityService $visibility): ConversationResource
    {
        abort_unless($visibility->canView($request->user(), $conversation), 403);
        return new ConversationResource($conversation->load(['customer.channels', 'assignee', 'messages.sender', 'tags']));
    }

    public function assign(AssignConversationRequest $request, Conversation $conversation, AssignConversationAction $action): ConversationResource
    {
        try {
            return new ConversationResource($action->execute($conversation, (int) $request->validated('assigned_to'), $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    public function transfer(AssignConversationRequest $request, Conversation $conversation, AssignConversationAction $action): ConversationResource
    {
        try {
            return new ConversationResource($action->transfer($conversation, (int) $request->validated('assigned_to'), $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    public function release(Request $request, Conversation $conversation, AssignConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.transfer') || $request->user()->can('conversation.assign'), 403);

        try {
            return new ConversationResource($action->release($conversation, $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    public function resolve(Request $request, Conversation $conversation, CloseConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.close'), 403);

        try {
            return new ConversationResource($action->resolve($conversation, $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    public function close(Request $request, Conversation $conversation, CloseConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.close'), 403);
        try {
            return new ConversationResource($action->execute($conversation, $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    public function reopen(Request $request, Conversation $conversation, CloseConversationAction $action): ConversationResource
    {
        abort_unless($request->user()->can('conversation.close'), 403);

        try {
            return new ConversationResource($action->reopen($conversation, $request->user()));
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    public function tag(TagConversationRequest $request, Conversation $conversation, TagConversationAction $action): ConversationResource
    {
        return new ConversationResource($action->execute($conversation, $request->validated('tags'), $request->user()));
    }
}
