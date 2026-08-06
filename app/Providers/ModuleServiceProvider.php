<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Conversation\Events\ConversationAssigned;
use Modules\Conversation\Events\ConversationClaimed;
use Modules\Conversation\Events\ConversationTransferred;
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
    /** Đăng ký hoặc khởi động tài nguyên module trong giai đoạn register của service provider. */
    public function register(): void
    {
        $this->app->singleton(ActivityLogService::class);
    }

    /** Đăng ký hoặc khởi động tài nguyên module trong giai đoạn boot của service provider. */
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
        Event::listen(ConversationAssigned::class, \Modules\Notification\Listeners\QueueConversationAssignedNotification::class);
        Event::listen(ConversationClaimed::class, \Modules\Notification\Listeners\QueueConversationAssignedNotification::class);
        Event::listen(ConversationTransferred::class, \Modules\Notification\Listeners\QueueConversationAssignedNotification::class);
    }
}
