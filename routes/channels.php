<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('crm.conversations', fn ($user) => $user !== null);
Broadcast::channel('crm.conversation.{conversationId}', fn ($user, int $conversationId) => $user !== null);