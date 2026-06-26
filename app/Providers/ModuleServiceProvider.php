<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Conversation\Events\ConversationAssignedEvent;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Observers\ConversationObserver;
use Modules\Customer\Models\Customer;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Listeners\LogNewMessageActivity;
use Modules\Message\Listeners\QueueNewMessageNotification;
use Modules\Message\Models\Message;
use Modules\Message\Observers\MessageObserver;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogService::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'activity_log' => ActivityLog::class,
            'conversation' => Conversation::class,
            'customer' => Customer::class,
            'message' => Message::class,
            'user' => User::class,
        ]);

        Message::observe(MessageObserver::class);
        Conversation::observe(ConversationObserver::class);

        Event::listen(NewMessageEvent::class, LogNewMessageActivity::class);
        Event::listen(NewMessageEvent::class, QueueNewMessageNotification::class);
        Event::listen(ConversationAssignedEvent::class, \Modules\Notification\Listeners\QueueConversationAssignedNotification::class);
    }
}
