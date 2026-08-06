<?php

namespace App\Actions\Web;

use App\Models\User;
use App\Services\CrmNavigationService;
use App\Services\MessengerInboxQueryService;
use App\Services\MessengerViewPresenter;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;

class GetMessengerViewDataAction
{
    /** Nhận CrmNavigationService để tạo menu phù hợp với quyền người dùng; MessengerInboxQueryService để truy vấn dữ liệu; MessengerViewPresenter để định dạng dữ liệu đầu ra. */
    public function __construct(
        private readonly CrmNavigationService $navigation,
        private readonly MessengerInboxQueryService $inbox,
        private readonly MessengerViewPresenter $presenter,
    ) {
    }

    /** Tập hợp bộ lọc, hội thoại đang chọn và dữ liệu sidebar cho màn hình Messenger. */
    public function execute(User $user, ?Conversation $selectedConversation = null, array $input = []): array
    {
        $filters = [
            'search' => trim((string) ($input['search'] ?? '')),
            'tag' => trim((string) ($input['tag'] ?? '')),
            'channel' => in_array(($input['channel'] ?? 'all'), ['facebook', 'zalo'], true) ? (string) $input['channel'] : 'all',
            'status' => in_array(($input['status'] ?? 'all'), ['unread', 'mine'], true) ? (string) $input['status'] : 'all',
        ];
        $conversations = $this->inbox->conversations($user, $filters);
        $active = $this->inbox->active($user, $selectedConversation);
        $messages = $this->presenter->timeline($active, $user);
        $oldestId = (int) ($messages->first()['id'] ?? 0);
        $tags = $this->inbox->tags();

        return [
            'currentUser' => $user, 'activeSection' => 'conversations', 'navItems' => $this->navigation->forUser($user),
            'sidebar' => ['team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox'],
            'filters' => $filters,
            'inboxChannels' => $this->inbox->channelTabs($user, $filters),
            'inboxStatuses' => $this->inbox->statusTabs($user, $filters),
            'tagPresets' => $tags->map(fn (Tag $tag): array => ['id' => (int) $tag->id, 'name' => $tag->name,
                'color' => $tag->color, 'is_default' => (bool) $tag->is_default]),
            'allTags' => $tags, 'allCustomerTags' => $tags,
            'tagManager' => ['index_url' => route('crm.conversation-tags.index'), 'store_url' => route('crm.conversation-tags.store')],
            'assignableAgents' => $this->inbox->assignableAgents($user),
            'conversations' => $conversations, 'activeConversation' => $active,
            'profilePanel' => $this->presenter->profile($active), 'messages' => $messages,
            'hasOlderMessages' => $active && $oldestId > 0 ? $active->messages()->where('id', '<', $oldestId)->exists() : false,
            'activeChannel' => $this->presenter->activeChannel($active),
        ];
    }
}
