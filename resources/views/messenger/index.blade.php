@extends('layouts.app', ['title' => 'Messenger - CRM', 'bodyClass' => 'messenger-page'])

@section('content')
@php($reverb = config('broadcasting.connections.reverb'))
@php($reverbPublicHost = config('reverb.public.host'))
@php($reverbPublicPort = config('reverb.public.port'))
@php($reverbPublicScheme = match (config('reverb.public.scheme')) {
    'https' => 'wss',
    'http' => 'ws',
    default => config('reverb.public.scheme'),
})
<div class="crm-shell messenger-crm-shell" data-crm-shell>
    <aside class="crm-sidebar">
        <a class="crm-logo" href="{{ route('dashboard') }}">CRM</a>

        <nav class="side-nav" aria-label="CRM navigation">
            @foreach($navItems as $item)
                <a class="{{ $activeSection === $item['section'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
                    <span>{{ $item['icon'] }}</span>{{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <a class="team-switcher" href="{{ route('crm.agents') }}">
            <span>{{ substr($sidebar['team_name'], 0, 1) }}</span>
            <strong>{{ $sidebar['team_name'] }}</strong>
            <b>v</b>
        </a>
    </aside>

    <main class="crm-main messenger-crm-main">
        <header class="crm-topbar">
            <button class="collapse-button" type="button" aria-label="Toggle sidebar" data-sidebar-toggle>&lt;&gt;</button>
            <div class="topbar-spacer"></div>
            <a class="help-link" href="{{ route('crm.settings', ['panel' => 'help']) }}"><span>?</span> Help Center</a>
            <a class="profile-link" href="{{ route('crm.settings', ['panel' => 'profile']) }}">{{ $currentUser->name }}</a>
            <form method="POST" action="{{ route('logout') }}" class="account-menu">
                @csrf
                <button type="submit">Logout</button>
            </form>
        </header>

        <div
            class="messenger-shell"
            data-messenger-realtime
            data-conversations-url="{{ route('crm.conversations') }}"
            data-channel-filter="{{ $filters['channel'] ?? 'all' }}"
            data-inbox-broadcast-channel="private-crm.conversations"
            data-broadcast-auth-url="{{ url('/broadcasting/auth') }}"
            data-reverb-key="{{ $reverb['key'] }}"
            data-reverb-host="{{ $reverbPublicHost ?: (in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) ? request()->getHost() : $reverb['options']['host']) }}"
            data-reverb-port="{{ $reverbPublicHost ? $reverbPublicPort : (in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) && request()->secure() ? '' : $reverb['options']['port']) }}"
            data-reverb-scheme="{{ $reverbPublicScheme ?: (request()->secure() ? 'wss' : ($reverb['options']['scheme'] === 'https' ? 'wss' : 'ws')) }}"
        >
            <nav class="messenger-channel-tabs" aria-label="Inbox channels">
                @foreach($inboxChannels as $inboxChannel)
                    <a
                        class="{{ $inboxChannel['active'] ? 'active' : '' }}"
                        href="{{ route('crm.conversations', array_filter([
                            'channel' => $inboxChannel['key'] === 'all' ? null : $inboxChannel['key'],
                            'q' => $filters['search'] ?: null,
                            'tag' => $filters['tag'] ?: null,
                        ])) }}"
                    >
                        {{ $inboxChannel['label'] }}
                        @if($inboxChannel['unread'] > 0)
                            <span>{{ $inboxChannel['unread'] }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
            <aside class="messenger-list">
                <div class="messenger-list-head">
                    <div>
                        <h1>All Chat</h1>
                    </div>
                </div>

        <form class="messenger-search" method="GET" action="{{ route('crm.conversations') }}">
            @if(($filters['channel'] ?? 'all') !== 'all')
                <input type="hidden" name="channel" value="{{ $filters['channel'] }}">
            @endif
            <input type="search" name="q" placeholder="Tim kiem tren Messenger" value="{{ $filters['search'] }}">
            <select name="tag" aria-label="Loc theo tag">
                <option value="">Tat ca tag</option>
                @foreach($allTags as $tag)
                    <option value="{{ $tag->name }}" @selected(($filters['tag'] ?? '') === $tag->name)>{{ $tag->name }}</option>
                @endforeach
            </select>
            <button type="submit">Search</button>
        </form>

        <div class="messenger-thread-list">
            @forelse($conversations as $conversation)
                @php($lastMessage = $conversation->messages->first())
                @php($lastMessagePreview = $lastMessage?->conversationPreviewText() ?? 'Chua co tin nhan')
                @php($isActive = $activeConversation?->id === $conversation->id)
                @php($unreadCount = (int) $conversation->unread_messages_count)
                @php($threadTags = ($conversation->customer?->tags?->isNotEmpty() ? $conversation->customer->tags : $conversation->tags)->take(1)->values())
                <a
                    class="messenger-thread {{ $isActive ? 'active' : '' }} {{ $unreadCount > 0 ? 'is-unread' : '' }}"
                    href="{{ route('crm.conversations.show', array_filter(['conversation' => $conversation, 'channel' => ($filters['channel'] ?? 'all') === 'all' ? null : $filters['channel'], 'q' => $filters['search'] ?: null, 'tag' => $filters['tag'] ?: null])) }}"
                    data-thread-conversation-id="{{ $conversation->id }}"
                    data-conversation-url="{{ route('crm.conversations.show', array_filter(['conversation' => $conversation, 'channel' => ($filters['channel'] ?? 'all') === 'all' ? null : $filters['channel'], 'q' => $filters['search'] ?: null, 'tag' => $filters['tag'] ?: null])) }}"
                    data-thread-unread-count="{{ $unreadCount }}"
                >
                    <span class="thread-avatar">
                        @if($conversation->customer?->avatar)
                            <img src="{{ $conversation->customer->avatar }}" alt="{{ $conversation->customer?->name ?? 'Customer' }}">
                        @else
                            {{ strtoupper(substr($conversation->customer?->name ?? 'C', 0, 1)) }}
                        @endif
                    </span>
                    <span class="thread-body">
                        <strong>{{ $conversation->customer?->name ?? 'Customer' }}</strong>
                        <small data-thread-last-message>{{ $lastMessagePreview }}</small>
                        @if($threadTags->isNotEmpty())
                            <span class="thread-tags">
                                @foreach($threadTags as $tag)
                                    <b style="--tag-color: {{ $tag->color ?: '#64748b' }}">{{ $tag->name }}</b>
                                @endforeach
                            </span>
                        @endif
                    </span>
                    <span class="thread-side">
                        <span class="thread-meta" data-thread-meta>{{ $conversation->last_message_at?->diffForHumans() }}</span>
                        <span class="thread-unread-badge" data-thread-unread-badge>{{ $unreadCount > 0 ? $unreadCount : '' }}</span>
                    </span>
                </a>
            @empty
                <div class="messenger-empty">No matching conversations found.</div>
            @endforelse
        </div>
    </aside>

    <section class="messenger-chat">
        @if($activeConversation)
            @php($canReply = $currentUser->can('conversation.view_all') || (int) $activeConversation->assigned_to === (int) $currentUser->id)
            @php($canClaim = ! $activeConversation->assigned_to && ! $currentUser->can('conversation.view_all') && app(\Modules\Conversation\Services\WorkShiftService::class)->userIsInCurrentShift($currentUser, $activeConversation->work_shift_id))
            <header class="messenger-chat-head">
                <div class="chat-contact">
                    <span class="thread-avatar large" data-chat-avatar>
                        @if($activeConversation->customer?->avatar)
                            <img src="{{ $activeConversation->customer->avatar }}" alt="{{ $activeConversation->customer?->name ?? 'Customer' }}">
                        @else
                            {{ strtoupper(substr($activeConversation->customer?->name ?? 'C', 0, 1)) }}
                        @endif
                    </span>
                    <div>
                        <h2 data-chat-customer-name>{{ $activeConversation->customer?->name ?? 'Customer' }}</h2>
                        <p class="chat-customer-phone" data-chat-customer-phone>{{ $activeConversation->customer?->phone ?: 'Chua co so dien thoai' }}</p>
                        <p data-chat-assignee>{{ $activeConversation->assignee?->name ? 'Phu trach: '.$activeConversation->assignee->name : 'Chua gan nhan vien' }}</p>
                    </div>
                </div>
                <nav class="chat-actions" aria-label="Conversation actions">
                    <a href="{{ route('crm.customers', ['q' => $activeConversation->customer?->name]) }}">Info</a>
                    <a href="{{ route('crm.channels', ['channel' => $activeChannel]) }}" data-chat-channel>{{ ucfirst($activeChannel) }}</a>
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <button
                        type="button"
                        class="chat-delete-messages"
                        title="Xoa hoi thoai"
                        aria-label="Xoa hoi thoai"
                        data-delete-conversation-url="{{ route('crm.conversations.destroy', $activeConversation) }}"
                        data-delete-conversation-button
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-.7 11H7.7L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z" fill="currentColor"/>
                        </svg>
                    </button>
                </nav>
            </header>
            <div class="conversation-claim-bar {{ $canClaim || ! $canReply ? '' : 'is-hidden' }}" data-claim-bar>
                <span data-claim-status>{{ $canClaim ? 'Hoi thoai moi trong ca truc cua ban.' : 'Ban can nhan xu ly truoc khi tra loi.' }}</span>
                <button type="button" data-claim-button data-claim-url="{{ route('crm.conversations.claim', $activeConversation) }}" {{ $canClaim ? '' : 'disabled' }}>Nhan xu ly</button>
            </div>

            <section
                class="messenger-timeline"
                aria-label="Messages"
                data-messenger-timeline
                data-conversation-id="{{ $activeConversation->id }}"
                data-current-user-id="{{ $currentUser->id }}"
                data-last-message-id="{{ $messages->last()['id'] ?? 0 }}"
                data-oldest-message-id="{{ $messages->first()['id'] ?? 0 }}"
                data-has-older-messages="{{ $hasOlderMessages ? '1' : '0' }}"
                data-poll-url="{{ route('crm.conversations.messages.index', $activeConversation) }}"
                data-read-url="{{ route('crm.conversations.read', $activeConversation) }}"
                data-stream-url="{{ route('crm.conversations.messages.stream', $activeConversation) }}"
                data-broadcast-channel="private-crm.conversation.{{ $activeConversation->id }}"
                data-inbox-broadcast-channel="private-crm.conversations"
                data-broadcast-auth-url="{{ url('/broadcasting/auth') }}"
                data-reverb-key="{{ $reverb['key'] }}"
                data-reverb-host="{{ $reverbPublicHost ?: (in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) ? request()->getHost() : $reverb['options']['host']) }}"
                data-reverb-port="{{ $reverbPublicHost ? $reverbPublicPort : (in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) && request()->secure() ? '' : $reverb['options']['port']) }}"
                data-reverb-scheme="{{ $reverbPublicScheme ?: (request()->secure() ? 'wss' : ($reverb['options']['scheme'] === 'https' ? 'wss' : 'ws')) }}"
            >
                <div class="conversation-date">
                    {{ $activeConversation->created_at?->format('d/m/Y') }}
                </div>

                @foreach($messages as $message)
                    @continue(empty($message['is_recalled']) && blank($message['content']) && empty($message['attachments']))
                    @php($isWhisper = ($message['message_type'] ?? '') === 'whisper' || ($message['channel'] ?? '') === 'internal')
                    <article class="message-row {{ $message['is_mine'] ? 'mine' : 'theirs' }}" data-message-id="{{ $message['id'] }}" data-client-message-id="{{ $message['client_message_id'] ?? '' }}">
                        @unless($message['is_mine'])
                            <span class="thread-avatar mini">
                                @if(! empty($message['sender_avatar']))
                                    <img src="{{ $message['sender_avatar'] }}" alt="{{ $message['sender_name'] }}">
                                @else
                                    {{ strtoupper(substr($message['sender_name'], 0, 1)) }}
                                @endif
                            </span>
                        @endunless

                        <div class="message-stack">
                            @if(! empty($message['is_recalled']))
                                <div class="message-bubble is-recalled">
                                    <p>Tin nhan da duoc thu hoi</p>
                                </div>
                            @elseif(filled($message['content']))
                                <div class="message-bubble {{ $isWhisper ? 'is-whisper' : '' }}">
                                    <span class="message-sender">{{ $isWhisper ? 'Thi tham - '.$message['sender_name'] : $message['sender_name'] }}</span>
                                    <p>{{ $message['content'] }}</p>
                                </div>
                            @endif
                            @if(empty($message['is_recalled']) && ! empty($message['attachments']))
                                <div class="message-attachments">
                                    @foreach($message['attachments'] as $attachment)
                                        @php($attachmentType = strtolower((string) ($attachment['type'] ?? '')))
                                        @php($attachmentMimeType = strtolower((string) ($attachment['mime_type'] ?? '')))
                                        @php($attachmentUrl = (string) ($attachment['url'] ?? ''))
                                        @php($attachmentPath = strtolower((string) parse_url($attachmentUrl, PHP_URL_PATH)))
                                        @php($isVisualAttachment = in_array($attachmentType, ['image', 'sticker'], true) || str_starts_with($attachmentMimeType, 'image/') || preg_match('/\.(png|jpe?g|gif|webp|bmp|avif)$/', $attachmentPath))
                                        @php($isVideoAttachment = $attachmentType === 'video' || str_starts_with($attachmentMimeType, 'video/') || preg_match('/\.(mp4|mov|m4v|webm|ogg)$/', $attachmentPath))
                                        @php($isAudioAttachment = $attachmentType === 'audio' || str_starts_with($attachmentMimeType, 'audio/') || preg_match('/\.(mp3|m4a|wav|aac|oga|ogg)$/', $attachmentPath))
                                        @php($stickerId = (string) data_get($attachment, 'payload.sticker_id', ''))
                                        @php($isEmojiAttachment = in_array($stickerId, ['369239263222822'], true))
                                        @if($isVisualAttachment)
                                            <a class="message-image-link {{ $isEmojiAttachment ? 'is-emoji' : 'is-sticker' }}" href="{{ $attachment['url'] ?? '#' }}" target="_blank" rel="noopener">
                                                <img src="{{ $attachment['url'] ?? '#' }}" alt="{{ $attachment['name'] ?? 'Attachment' }}" loading="lazy">
                                            </a>
                                        @elseif($isVideoAttachment)
                                            <video class="message-video" src="{{ $attachmentUrl }}" controls preload="metadata"></video>
                                        @elseif($isAudioAttachment)
                                            <audio class="message-audio" src="{{ $attachmentUrl }}" controls preload="metadata"></audio>
                                        @else
                                            <a class="message-file-link" href="{{ $attachment['url'] ?? '#' }}" target="_blank" rel="noopener">
                                                {{ $attachment['name'] ?? 'Attachment' }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            <time>{{ $message['created_at']?->format('H:i') }} - {{ $isWhisper ? 'Noi bo' : ucfirst($message['channel']) }}</time>
                        </div>
                    </article>
                @endforeach
            </section>

            <form class="messenger-composer {{ $canReply ? '' : 'is-disabled' }}" method="POST" action="{{ route('crm.conversations.messages.store', $activeConversation) }}" enctype="multipart/form-data" data-upload-url="{{ route('crm.conversations.attachments.store', $activeConversation) }}" data-messenger-composer data-can-reply="{{ $canReply ? '1' : '0' }}">
                @csrf
                <input type="hidden" name="channel" value="{{ $activeChannel }}">
                <input type="hidden" name="message_mode" value="message" data-message-mode>
                @php($activeTagNames = $activeConversation->tags->pluck('name')->all())
                <div class="composer-tabs" data-conversation-tags data-tags-url="{{ route('crm.conversations.tags.store', $activeConversation) }}">
                    <button type="button" class="composer-mode active" data-composer-mode="message">Nhan tin</button>
                    <button type="button" class="composer-mode" data-composer-mode="whisper">Thi tham</button>
                    <b></b>
                    @foreach($tagPresets as $tag)
                        @php($tagName = $tag['name'])
                        @php($tagColor = $tag['color'] ?: '#64748b')
                        <button
                            type="button"
                            class="composer-tag {{ in_array($tagName, $activeTagNames, true) ? 'is-active' : '' }}"
                            style="--tag-color: {{ $tagColor }}"
                            data-tag-name="{{ $tagName }}"
                            data-tag-color="{{ $tagColor }}"
                        >{{ $tagName }}</button>
                    @endforeach
                    <span class="plus">+</span>
                </div>
                <textarea name="content" rows="3" placeholder="Nhap noi dung tin nhan va nhan Enter de gui" autocomplete="off" {{ $canReply ? '' : 'disabled' }}></textarea>
                <div class="composer-bottom-row">
                    <span class="thread-avatar mini composer-user-avatar">
                        @if($currentUser->avatar ?? null)
                            <img src="{{ $currentUser->avatar }}" alt="{{ $currentUser->name }}">
                        @else
                            {{ strtoupper(substr($currentUser->name, 0, 1)) }}
                        @endif
                    </span>
                    <div class="composer-actions" aria-label="Message tools">
                        <button type="button" title="Bieu cam">☺</button>
                        <button type="button" title="Mau tin">▱</button>
                        <label title="Dinh kem">
                            ♧
                            <input type="file" name="attachments[]" multiple data-composer-files>
                        </label>
                        <button type="button" title="Hinh anh" data-upload-trigger>▧</button>
                        <button type="button" title="Video" data-upload-trigger>▻</button>
                        <button type="button" title="Ghi chu">▤</button>
                        <button type="button" title="Lich">□</button>
                        <button type="button" title="San pham">◇</button>
                        <button class="composer-send-button" type="submit" title="Gui" {{ $canReply ? '' : 'disabled' }}>➤</button>
                    </div>
                </div>
                <div class="composer-file-list" data-composer-file-list></div>
            </form>
            @error('content')
                <p class="field-error">{{ $message }}</p>
            @enderror
        @else
            <section class="messenger-no-chat">
                <h2>Select a conversation</h2>
                <p>No conversation data available.</p>
            </section>
        @endif
    </section>
    <aside class="messenger-profile-panel" data-profile-panel>
        @if($activeConversation)
            <section class="profile-contact-full" data-contact-full hidden>
                <header>
                    <button type="button" data-contact-back>&lt;</button>
                    <h4>Chi tiet lien he</h4>
                </header>
                <div class="profile-contact-full-body">
                    <div class="profile-section profile-details">
                        <h4>Thong tin cong khai</h4>
                        <div class="profile-detail-list" data-public-detail-list>
                            @if(count($profilePanel['details']) === 0)
                                <p class="profile-empty">Chua co thong tin cong khai.</p>
                            @endif
                            @foreach($profilePanel['details'] as $detail)
                                <span>{{ $detail['label'] }}</span>
                                <strong>{{ $detail['value'] }}</strong>
                            @endforeach
                        </div>
                    </div>
                    <section class="profile-section profile-details" data-contact-section data-contact-url="{{ route('crm.conversations.customer.update', $activeConversation) }}">
                        <h4>Cap nhat lien he</h4>
                        <form class="profile-contact-form" data-contact-form>
                            <label>
                                <span>Ten khach hang</span>
                                <input type="text" name="name" value="{{ $profilePanel['contact']['name'] }}" autocomplete="name" required>
                            </label>
                            <label>
                                <span>So dien thoai</span>
                                <input type="tel" name="phone" value="{{ $profilePanel['contact']['phone'] }}" autocomplete="tel">
                            </label>
                            <label>
                                <span>Email</span>
                                <input type="email" name="email" value="{{ $profilePanel['contact']['email'] }}" autocomplete="email">
                            </label>
                            <label>
                                <span>Kenh lien he</span>
                                <input type="text" value="{{ $profilePanel['contact']['channel'] ?: 'Chua co kenh' }}" data-contact-channel disabled>
                            </label>
                            <div class="profile-contact-actions">
                                <button type="submit">Save</button>
                                <small data-contact-status></small>
                            </div>
                        </form>
                    </section>
                </div>
            </section>

            <section class="profile-notes-full" data-notes-full hidden>
                <header>
                    <button type="button" data-notes-back>&lt;</button>
                    <h4>Tat ca ghi chu</h4>
                </header>
                <div class="profile-note-list is-full" data-note-list-full>
                    @if(count($profilePanel['notes']) === 0)
                        <p class="profile-empty">Chua co ghi chu nao.</p>
                    @endif
                    @foreach($profilePanel['notes'] as $note)
                        <article data-note-item>
                            <p>{{ $note['body'] }}</p>
                            <time>{{ $note['author'] }} - {{ $note['created_at'] }}</time>
                        </article>
                    @endforeach
                </div>
            </section>

            <div class="profile-panel-main" data-profile-main>
            <header class="profile-card-head">
                <span class="thread-avatar" data-profile-avatar>
                    @if($activeConversation->customer?->avatar)
                        <img src="{{ $activeConversation->customer->avatar }}" alt="{{ $activeConversation->customer?->name ?? 'Customer' }}">
                    @else
                        {{ strtoupper(substr($activeConversation->customer?->name ?? 'C', 0, 1)) }}
                    @endif
                </span>
                <div>
                    <h3 data-profile-name>{{ $activeConversation->customer?->name ?? 'Customer' }}</h3>
                    <button type="button" class="profile-contact-toggle" data-contact-toggle>Chi tiet lien he &gt;</button>
                </div>
                <button type="button" aria-label="More profile actions">...</button>
            </header>

            <section class="profile-section profile-notes" data-customer-notes data-notes-url="{{ route('crm.conversations.customer-notes.store', $activeConversation) }}">
                <header>
                    <h4>Ghi chu (<span data-note-count>{{ count($profilePanel['notes']) }}</span>) (F6)</h4>
                    <button type="button" data-notes-toggle>Xem tat ca &gt;</button>
                </header>
                <textarea rows="3" placeholder="Nhap ghi chu va an enter" data-note-input></textarea>
            </section>

            <section class="profile-section profile-customer-tags" data-customer-tags data-tags-url="{{ route('crm.conversations.customer-tags.store', $activeConversation) }}">
                <h4>Tag khach hang (F2)</h4>
                <div class="customer-tag-dropdown">
                    <input type="search" placeholder="Tim kiem tag co san..." data-customer-tag-input>
                    <div class="customer-tag-options" data-customer-tag-options></div>
                </div>
                <div class="customer-tag-list" data-customer-tag-list>
                    @if(count($profilePanel['tags']) === 0)
                        <p class="profile-empty">Khach hang chua co the nao</p>
                    @endif
                    @foreach($profilePanel['tags'] as $tag)
                        <span style="--tag-color: {{ $tag['color'] }}">{{ $tag['name'] }}</span>
                    @endforeach
                </div>
            </section>
            </div>
        @else
            <section class="profile-section is-empty">
                <h4>Thong tin khach hang</h4>
                <p>Chon mot conversation de xem chi tiet.</p>
            </section>
        @endif
    </aside>
        </div>
    </main>
</div>

<script>
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
        document.querySelector('[data-crm-shell]')?.classList.toggle('sidebar-collapsed');
    });

    document.querySelector('.messenger-thread-list')?.addEventListener('click', function (event) {
        const thread = event.target.closest('.messenger-thread');

        if (!thread || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        loadConversation(thread.dataset.conversationUrl || thread.href);
    });

    window.addEventListener('popstate', function () {
        loadConversation(window.location.href);
    });

    document.querySelector('.chat-actions')?.addEventListener('click', function (event) {
        const deleteButton = event.target.closest('[data-delete-conversation-button]');

        if (!deleteButton) {
            return;
        }

        event.preventDefault();
        deleteConversation(deleteButton);
    });

    document.querySelector('[data-profile-panel]')?.addEventListener('keydown', function (event) {
        if (event.target.matches('[data-note-input]') && event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            storeCustomerNote(event.target);
        }

        if (event.target.matches('[data-customer-tag-input]') && event.key === 'Enter') {
            event.preventDefault();
            const tagName = matchingCustomerTagName(event.target.value);

            if (tagName) {
                storeCustomerTag(tagName);
            }

            event.target.value = '';
        }
    });

    document.querySelector('[data-profile-panel]')?.addEventListener('input', function (event) {
        if (event.target.matches('[data-customer-tag-input]')) {
            renderCustomerTagOptions(event.target.value);
        }
    });

    document.querySelector('[data-profile-panel]')?.addEventListener('submit', function (event) {
        if (event.target.matches('[data-contact-form]')) {
            event.preventDefault();
            saveCustomerContact(event.target);
        }
    });

    document.querySelector('[data-profile-panel]')?.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-notes-toggle]');
        const back = event.target.closest('[data-notes-back]');
        const contactToggle = event.target.closest('[data-contact-toggle]');
        const contactBack = event.target.closest('[data-contact-back]');
        const selectedTag = event.target.closest('[data-select-customer-tag]');

        if (toggle) {
            event.preventDefault();
            showAllNotesPanel();
        }

        if (contactToggle) {
            event.preventDefault();
            showContactDetailsPanel();
        }

        if (back) {
            event.preventDefault();
            showMainProfilePanel();
        }

        if (contactBack) {
            event.preventDefault();
            showMainProfilePanel();
        }

        if (selectedTag) {
            event.preventDefault();
            storeCustomerTag(selectedTag.dataset.selectCustomerTag || '');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'F6') {
            event.preventDefault();
            document.querySelector('[data-note-input]')?.focus();
        }

        if (event.key === 'F2') {
            event.preventDefault();
            document.querySelector('[data-customer-tag-input]')?.focus();
        }
    });

    let timeline = document.querySelector('[data-messenger-timeline]');
    let composer = document.querySelector('[data-messenger-composer]');
    const realtimeRoot = document.querySelector('[data-messenger-realtime]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    let customerTagOptions = @json($allCustomerTags->map(fn ($tag) => ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color])->values());
    let attachmentUpload = {
        files: [],
        promise: Promise.resolve([]),
        uploaded: [],
    };
    let olderMessagesLoading = false;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[character];
        });
    }

    function messageTime(message) {
        if (!message.created_at) {
            return '';
        }

        return new Intl.DateTimeFormat('vi-VN', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(new Date(message.created_at));
    }

    function appendMessage(message) {
        if (!timeline || !message?.id || !hasRenderableMessage(message)) {
            return;
        }

        const existing = timeline.querySelector(`[data-message-id="${message.id}"]`);

        if (existing) {
            updateMessageRow(existing, message);
            return;
        }

        const currentUserId = Number(timeline.dataset.currentUserId);
        const isMine = message.sender_type === 'user' && Number(message.sender_id) === currentUserId;
        const pending = isMine ? matchingPendingMessage(message) : null;

        if (pending) {
            updateMessageRow(pending, message);
            pending.dataset.messageId = message.id;
            delete pending.dataset.pendingMessageId;
            timeline.dataset.lastMessageId = String(Math.max(Number(timeline.dataset.lastMessageId || 0), Number(message.id)));
            return;
        }

        const row = document.createElement('article');
        row.className = `message-row ${isMine ? 'mine' : 'theirs'}`;
        row.dataset.messageId = message.id;
        row.dataset.clientMessageId = message.client_message_id || '';
        row.innerHTML = messageRowHtml(message, isMine);

        timeline.appendChild(row);
        timeline.dataset.lastMessageId = String(Math.max(Number(timeline.dataset.lastMessageId || 0), Number(message.id)));
        updateThreadPreview(message);
        timeline.scrollTop = timeline.scrollHeight;
    }

    function prependMessage(message) {
        if (!timeline || !message?.id || !hasRenderableMessage(message)) {
            return null;
        }

        const existing = timeline.querySelector(`[data-message-id="${message.id}"]`);

        if (existing) {
            updateMessageRow(existing, message);
            return existing;
        }

        const currentUserId = Number(timeline.dataset.currentUserId);
        const isMine = message.sender_type === 'user' && Number(message.sender_id) === currentUserId;
        const row = document.createElement('article');
        row.className = `message-row ${isMine ? 'mine' : 'theirs'}`;
        row.dataset.messageId = message.id;
        row.dataset.clientMessageId = message.client_message_id || '';
        row.innerHTML = messageRowHtml(message, isMine);

        const firstMessage = timeline.querySelector('.message-row');
        timeline.insertBefore(row, firstMessage);

        return row;
    }

    function matchingPendingMessage(message) {
        const pendingMessages = Array.from(timeline?.querySelectorAll('[data-pending-message-id]') || []);
        const clientMessageId = String(message.client_message_id || '');

        if (clientMessageId) {
            const escapedClientMessageId = window.CSS?.escape ? CSS.escape(clientMessageId) : clientMessageId.replace(/"/g, '\\"');
            const exact = timeline?.querySelector(`[data-client-message-id="${escapedClientMessageId}"]`);

            if (exact) {
                return exact;
            }
        }

        const messageContent = String(message.content || '').trim();
        const messageAttachmentNames = attachmentNames(message);

        return pendingMessages.find(function (pending) {
            const pendingContent = pending.dataset.pendingContent || '';
            const pendingAttachmentNames = String(pending.dataset.pendingAttachments || '').split('|').filter(Boolean);
            const sameContent = pendingContent === messageContent;
            const sameAttachments = messageAttachmentNames.length > 0
                && messageAttachmentNames.join('|') === pendingAttachmentNames.join('|');

            return sameContent && (sameAttachments || (messageAttachmentNames.length === 0 && pendingAttachmentNames.length === 0));
        }) || null;
    }

    function attachmentNames(message) {
        return (Array.isArray(message.attachments) ? message.attachments : [])
            .map(function (attachment) {
                return String(attachment.name || '').trim();
            })
            .filter(Boolean)
            .sort();
    }

    function updateMessageRow(row, message) {
        if (!hasRenderableMessage(message)) {
            row.remove();
            return;
        }

        const currentUserId = Number(timeline.dataset.currentUserId);
        const isMine = message.sender_type === 'user' && Number(message.sender_id) === currentUserId;

        row.className = `message-row ${isMine ? 'mine' : 'theirs'}`;
        row.classList.remove('is-pending');
        row.classList.toggle('is-failed', message?.outbound_status === 'failed');
        row.dataset.clientMessageId = message.client_message_id || row.dataset.clientMessageId || '';
        row.innerHTML = messageRowHtml(mergePendingPreview(row, message), isMine);
        updateThreadPreview(message);
    }

    function messageRowHtml(message, isMine) {
        const isWhisper = message.message_type === 'whisper' || message.channel === 'internal';
        const avatar = isMine ? '' : `<span class="thread-avatar mini">${avatarHtml(message.sender_avatar, message.sender_name || 'C')}</span>`;
        const status = messageStatusText(message);
        const content = String(message.content || '').trim();
        const attachments = !message.is_recalled && Array.isArray(message.attachments) && message.attachments.length
            ? `<div class="message-attachments">${message.attachments.map(function (attachment) {
                return attachmentHtml(attachment);
            }).join('')}</div>`
            : '';
        const textBubble = message.is_recalled
            ? `<div class="message-bubble is-recalled"><p>Tin nhan da duoc thu hoi</p></div>`
            : content
            ? `<div class="message-bubble ${isWhisper ? 'is-whisper' : ''}">
                    <span class="message-sender">${escapeHtml(isWhisper ? `Thi tham - ${message.sender_name || 'Nhan vien'}` : (message.sender_name || 'Unknown'))}</span>
                    <p>${escapeHtml(content)}</p>
                </div>`
            : '';

        return `
            ${avatar}
            <div class="message-stack">
                ${textBubble}
                ${attachments}
                <time>${escapeHtml(messageTime(message))} - ${escapeHtml(isWhisper ? 'Noi bo' : ((message.channel || '').charAt(0).toUpperCase() + (message.channel || '').slice(1)))}${status ? ` - ${escapeHtml(status)}` : ''}</time>
            </div>
        `;
    }

    function avatarHtml(url, name) {
        if (url) {
            return `<img src="${escapeHtml(url)}" alt="${escapeHtml(name || 'Customer')}">`;
        }

        return escapeHtml((name || 'C').slice(0, 1).toUpperCase());
    }

    function updateProfilePanel(conversation) {
        const panel = document.querySelector('[data-profile-panel]');

        if (!panel || !conversation) {
            return;
        }

        showMainProfilePanel();
        const customerName = conversation.customer_name || conversation.conversation_customer_name || 'Customer';
        const customerAvatar = conversation.customer_avatar || conversation.conversation_customer_avatar || '';
        const avatar = panel.querySelector('[data-profile-avatar]');
        const name = panel.querySelector('[data-profile-name]');

        if (avatar) {
            avatar.innerHTML = avatarHtml(customerAvatar, customerName);
        }

        if (name) {
            name.textContent = customerName;
        }

        if (conversation.customer_contact || Object.prototype.hasOwnProperty.call(conversation, 'customer_email')) {
            renderCustomerContact(conversation.customer_contact || {
                name: customerName,
                phone: conversation.customer_phone || '',
                email: conversation.customer_email || '',
                channel: conversation.active_channel || '',
            });
        }

        if (Array.isArray(conversation.customer_public_details)) {
            renderCustomerPublicDetails(conversation.customer_public_details);
        }

        if (Array.isArray(conversation.customer_notes)) {
            renderCustomerNotes(conversation.customer_notes);
        }

        if (Array.isArray(conversation.customer_tags)) {
            renderCustomerTags(conversation.customer_tags, conversation.all_customer_tags || customerTagOptions);
        }

        const notes = panel.querySelector('[data-customer-notes]');
        const tags = panel.querySelector('[data-customer-tags]');
        const contact = panel.querySelector('[data-contact-section]');

        if (notes && conversation.customer_notes_url) {
            notes.dataset.notesUrl = conversation.customer_notes_url;
        }

        if (tags && conversation.customer_tags_url) {
            tags.dataset.tagsUrl = conversation.customer_tags_url;
        }

        if (conversation.customer_update_url) {
            panel.querySelectorAll('[data-contact-section]').forEach(function (section) {
                section.dataset.contactUrl = conversation.customer_update_url;
            });
        }
    }

    function renderCustomerContact(contact) {
        const forms = document.querySelectorAll('[data-contact-form]');

        if (!forms.length) {
            return;
        }

        const fields = {
            name: contact?.name || '',
            phone: contact?.phone || '',
            email: contact?.email || '',
        };

        forms.forEach(function (form) {
            Object.entries(fields).forEach(function ([field, value]) {
                const input = form.querySelector(`[name="${field}"]`);

                if (input) {
                    input.value = value;
                }
            });

            const channel = form.querySelector('[data-contact-channel]');

            if (channel) {
                channel.value = contact?.channel || 'Chua co kenh';
            }
        });
    }

    function renderCustomerPublicDetails(details) {
        const container = document.querySelector('[data-public-detail-list]');

        if (!container) {
            return;
        }

        if (!Array.isArray(details) || !details.length) {
            container.innerHTML = '<p class="profile-empty">Chua co thong tin cong khai.</p>';
            return;
        }

        container.innerHTML = details.map(function (detail) {
            return `
                <span>${escapeHtml(detail.label || '')}</span>
                <strong>${escapeHtml(detail.value || '')}</strong>
            `;
        }).join('');
    }

    async function saveCustomerContact(form) {
        const section = form.closest('[data-contact-section]');
        const status = section?.querySelector('[data-contact-status]');
        const submit = form.querySelector('button[type="submit"]');
        const url = section?.dataset.contactUrl;

        if (!url) {
            return;
        }

        if (status) {
            status.textContent = 'Dang luu...';
        }

        if (submit) {
            submit.disabled = true;
        }

        try {
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify(Object.fromEntries(new FormData(form))),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'Khong luu duoc thong tin.');
            }

            applyCustomerContact(payload.data || {});

            if (status) {
                status.textContent = 'Da luu';
            }
        } catch (error) {
            if (status) {
                status.textContent = error.message || 'Luu that bai';
            }
        } finally {
            if (submit) {
                submit.disabled = false;
            }
        }
    }

    function applyCustomerContact(conversation) {
        const customerName = conversation.customer_name || 'Customer';
        const customerAvatar = conversation.customer_avatar || '';
        const chatAvatar = document.querySelector('[data-chat-avatar]');
        const chatName = document.querySelector('[data-chat-customer-name]');
        const chatPhone = document.querySelector('[data-chat-customer-phone]');
        const profileName = document.querySelector('[data-profile-name]');
        const thread = document.querySelector(`[data-thread-conversation-id="${conversation.id}"]`);
        const threadName = thread?.querySelector('.thread-body strong');
        const threadAvatar = thread?.querySelector('.thread-avatar');

        renderCustomerContact(conversation.customer_contact || {
            name: customerName,
            phone: conversation.customer_phone || '',
            email: conversation.customer_email || '',
            channel: '',
        });
        renderCustomerPublicDetails(conversation.customer_public_details || []);

        if (chatAvatar) {
            chatAvatar.innerHTML = avatarHtml(customerAvatar, customerName);
        }

        if (chatName) {
            chatName.textContent = customerName;
        }

        if (chatPhone) {
            chatPhone.textContent = conversation.customer_phone || 'Chua co so dien thoai';
        }

        if (profileName) {
            profileName.textContent = customerName;
        }

        if (threadName) {
            threadName.textContent = customerName;
        }

        if (threadAvatar) {
            threadAvatar.innerHTML = avatarHtml(customerAvatar, customerName);
        }
    }

    function renderCustomerNotes(notes) {
        const count = document.querySelector('[data-note-count]');

        if (count) {
            count.textContent = String(notes.length);
        }

        renderCustomerNotesFull(notes);
    }

    function renderCustomerNotesFull(notes) {
        const container = document.querySelector('[data-note-list-full]');

        if (!container) {
            return;
        }

        if (!notes.length) {
            container.innerHTML = '<p class="profile-empty">Chua co ghi chu nao.</p>';
            return;
        }

        container.innerHTML = notes.map(function (note) {
            return `
                <article data-note-item>
                    <p>${escapeHtml(note.body)}</p>
                    <time>${escapeHtml(note.author || 'Admin')} - ${escapeHtml(note.created_at || '')}</time>
                </article>
            `;
        }).join('');
    }

    function showAllNotesPanel() {
        const main = document.querySelector('[data-profile-main]');
        const full = document.querySelector('[data-notes-full]');
        const contact = document.querySelector('[data-contact-full]');

        if (!main || !full) {
            return;
        }

        main.hidden = true;
        if (contact) {
            contact.hidden = true;
        }
        full.hidden = false;
    }

    function showContactDetailsPanel() {
        const main = document.querySelector('[data-profile-main]');
        const notes = document.querySelector('[data-notes-full]');
        const contact = document.querySelector('[data-contact-full]');

        if (!main || !contact) {
            return;
        }

        main.hidden = true;
        if (notes) {
            notes.hidden = true;
        }
        contact.hidden = false;
    }

    function showMainProfilePanel() {
        const main = document.querySelector('[data-profile-main]');
        const full = document.querySelector('[data-notes-full]');
        const contact = document.querySelector('[data-contact-full]');

        if (!main) {
            return;
        }

        if (full) {
            full.hidden = true;
        }
        if (contact) {
            contact.hidden = true;
        }
        main.hidden = false;
    }

    function renderCustomerTags(tags, allTags) {
        const list = document.querySelector('[data-customer-tag-list]');

        customerTagOptions = Array.isArray(allTags) ? allTags : customerTagOptions;

        if (list) {
            const visibleTags = (tags || []).slice(0, 1);
            list.innerHTML = visibleTags.length
                ? visibleTags.map((tag) => `<span style="--tag-color: ${escapeHtml(tag.color || '#2563eb')}">${escapeHtml(tag.name)}</span>`).join('')
                : '<p class="profile-empty">Khach hang chua co the nao</p>';
        }

        renderCustomerTagOptions('');
    }

    function renderCustomerTagOptions(filter) {
        const options = document.querySelector('[data-customer-tag-options]');

        if (!options) {
            return;
        }

        const normalizedFilter = String(filter || '').toLowerCase();
        const visible = customerTagOptions
            .filter((tag) => !normalizedFilter || String(tag.name || '').toLowerCase().includes(normalizedFilter));

        options.innerHTML = visible.length
            ? visible.map((tag) => `<button type="button" data-select-customer-tag="${escapeHtml(tag.name)}">${escapeHtml(tag.name)}</button>`).join('')
            : '<p>Khong co tag phu hop.</p>';
    }

    function matchingCustomerTagName(value) {
        const search = String(value || '').trim().toLowerCase();

        if (!search) {
            return '';
        }

        const exact = customerTagOptions.find((tag) => String(tag.name || '').toLowerCase() === search);

        if (exact) {
            return exact.name;
        }

        return customerTagOptions.find((tag) => String(tag.name || '').toLowerCase().includes(search))?.name || '';
    }

    async function storeCustomerNote(input) {
        const section = document.querySelector('[data-customer-notes]');
        const body = String(input?.value || '').trim();

        if (!section?.dataset.notesUrl || !body) {
            return;
        }

        input.disabled = true;

        try {
            const response = await fetch(section.dataset.notesUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify({body}),
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            input.value = '';
            renderCustomerNotes(payload.data?.notes || []);
        } finally {
            input.disabled = false;
            input.focus();
        }
    }

    async function storeCustomerTag(name) {
        const section = document.querySelector('[data-customer-tags]');
        const tagName = String(name || '').trim();

        if (!section?.dataset.tagsUrl || !tagName) {
            return;
        }

        const response = await fetch(section.dataset.tagsUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
            },
            body: JSON.stringify({name: tagName}),
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        renderCustomerTags(payload.data?.tags || [], payload.data?.all_tags || customerTagOptions);
        await refreshThreadList();
    }

    function renderMessages(messages) {
        if (!timeline) {
            return;
        }

        timeline.querySelectorAll('.message-row').forEach((row) => row.remove());
        (messages || []).forEach(function (message) {
            if (!hasRenderableMessage(message)) {
                return;
            }

            const currentUserId = Number(timeline.dataset.currentUserId);
            const isMine = message.sender_type === 'user' && Number(message.sender_id) === currentUserId;
            const row = document.createElement('article');
            row.className = `message-row ${isMine ? 'mine' : 'theirs'}`;
            row.dataset.messageId = message.id;
            row.dataset.clientMessageId = message.client_message_id || '';
            row.innerHTML = messageRowHtml(message, isMine);
            timeline.appendChild(row);
        });

        timeline.scrollTop = timeline.scrollHeight;
    }

    function renderConversation(conversation, url) {
        if (!timeline || !composer || !conversation) {
            window.location.href = url;
            return;
        }

        document.querySelectorAll('.messenger-thread.active').forEach((thread) => thread.classList.remove('active'));
        document.querySelector(`[data-thread-conversation-id="${conversation.id}"]`)?.classList.add('active');

        const customerName = conversation.customer_name || 'Customer';
        const chatAvatar = document.querySelector('[data-chat-avatar]');
        const chatName = document.querySelector('[data-chat-customer-name]');
        const chatPhone = document.querySelector('[data-chat-customer-phone]');
        const chatAssignee = document.querySelector('[data-chat-assignee]');
        const chatChannel = document.querySelector('[data-chat-channel]');
        const deleteButton = document.querySelector('[data-delete-conversation-button]');

        if (chatAvatar) {
            chatAvatar.innerHTML = avatarHtml(conversation.customer_avatar, customerName);
        }

        updateProfilePanel(conversation);

        if (chatName) {
            chatName.textContent = customerName;
        }

        if (chatPhone) {
            chatPhone.textContent = conversation.customer_phone || 'Chua co so dien thoai';
        }

        if (chatAssignee) {
            chatAssignee.textContent = conversation.assignee_name ? `Phu trach: ${conversation.assignee_name}` : 'Chua gan nhan vien';
        }

        if (chatChannel) {
            chatChannel.textContent = (conversation.active_channel || 'facebook').replace(/^./, (char) => char.toUpperCase());
            chatChannel.href = `/channels?channel=${encodeURIComponent(conversation.active_channel || 'facebook')}`;
        }

        if (deleteButton) {
            deleteButton.dataset.deleteConversationUrl = conversation.delete_url || '';
        }

        timeline.dataset.conversationId = conversation.id;
        timeline.dataset.pollUrl = conversation.messages_url;
        timeline.dataset.readUrl = conversation.read_url;
        timeline.dataset.streamUrl = conversation.stream_url;
        timeline.dataset.broadcastChannel = conversation.broadcast_channel;
        timeline.dataset.lastMessageId = String(conversation.meta?.last_message_id || 0);
        timeline.dataset.oldestMessageId = String(conversation.meta?.oldest_message_id || 0);
        timeline.dataset.hasOlderMessages = conversation.meta?.has_older_messages ? '1' : '0';

        composer.action = conversation.send_url;
        composer.dataset.uploadUrl = conversation.attachments_url;
        updateConversationTags(conversation.tags_url, conversation.tags || []);
        const channelInput = composer.querySelector('input[name="channel"]');

        if (channelInput) {
            channelInput.value = conversation.active_channel || 'facebook';
        }

        updateClaimState(conversation);

        renderMessages(conversation.messages || []);
        window.history.pushState({conversationUrl: url}, '', url);
        startRealtime();
    }

    function updateConversationTags(tagsUrl, tags) {
        const tabs = document.querySelector('[data-conversation-tags]');

        if (!tabs) {
            return;
        }

        tabs.dataset.tagsUrl = tagsUrl || '';
        const activeName = String((tags || [])[0]?.name || '');

        tabs.querySelectorAll('[data-tag-name]').forEach(function (button) {
            button.classList.toggle('is-active', activeName !== '' && activeName === (button.dataset.tagName || ''));
        });
    }

    function tagBadgesHtml(tags) {
        const visibleTags = (tags || []).filter((tag) => tag?.name).slice(0, 1);

        if (!visibleTags.length) {
            return '';
        }

        return `<span class="thread-tags">${visibleTags.map(function (tag) {
            return `<b style="--tag-color: ${escapeHtml(tag.color || '#64748b')}">${escapeHtml(tag.name)}</b>`;
        }).join('')}</span>`;
    }

    function updateThreadTags(conversationId, tags) {
        const thread = document.querySelector(`[data-thread-conversation-id="${conversationId}"]`);
        const body = thread?.querySelector('.thread-body');

        if (!body) {
            return;
        }

        body.querySelector('.thread-tags')?.remove();
        body.insertAdjacentHTML('beforeend', tagBadgesHtml(tags));
    }

    function selectedConversationTags() {
        const tabs = document.querySelector('[data-conversation-tags]');

        if (!tabs) {
            return [];
        }

        const button = tabs.querySelector('[data-tag-name].is-active');

        return button ? [{
            name: button.dataset.tagName || button.textContent.trim(),
            color: button.dataset.tagColor || null,
        }] : [];
    }

    async function loadConversation(url) {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            window.location.href = url;
            return;
        }

        const payload = await response.json();
        renderConversation(payload.data, url);
    }

    function updateClaimState(conversation) {
        const claimBar = document.querySelector('[data-claim-bar]');
        const claimStatus = document.querySelector('[data-claim-status]');
        const claimButton = document.querySelector('[data-claim-button]');
        const contentInput = composer?.querySelector('[name="content"]');
        const sendButton = composer?.querySelector('button[type="submit"]');
        const fileInput = composer?.querySelector('[data-composer-files]');
        const canReply = Boolean(conversation.can_reply);
        const canClaim = Boolean(conversation.can_claim);

        composer?.classList.toggle('is-disabled', !canReply);
        if (composer) {
            composer.dataset.canReply = canReply ? '1' : '0';
        }

        [contentInput, sendButton, fileInput].forEach(function (element) {
            if (element) {
                element.disabled = !canReply;
            }
        });

        if (!claimBar || !claimButton || !claimStatus) {
            return;
        }

        claimBar.classList.toggle('is-hidden', canReply && !canClaim);
        claimButton.disabled = !canClaim;
        claimButton.dataset.claimUrl = conversation.claim_url || '';
        claimStatus.textContent = canClaim
            ? 'Hoi thoai moi trong ca truc cua ban.'
            : (canReply ? 'Ban dang phu trach hoi thoai nay.' : 'Ban can nhan xu ly truoc khi tra loi.');
    }

    document.querySelector('[data-claim-button]')?.addEventListener('click', async function () {
        const button = this;
        const url = button.dataset.claimUrl;

        if (!url || button.disabled) {
            return;
        }

        button.disabled = true;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'Khong nhan duoc hoi thoai.');
            }

            const conversation = {
                id: timeline?.dataset.conversationId,
                can_claim: false,
                can_reply: true,
                claim_url: url,
            };
            updateClaimState(conversation);

            const assignee = document.querySelector('[data-chat-assignee]');
            if (assignee && payload.data?.assignee_name) {
                assignee.textContent = `Phu trach: ${payload.data.assignee_name}`;
            }
        } catch (error) {
            const status = document.querySelector('[data-claim-status]');
            if (status) {
                status.textContent = error.message;
            }
            button.disabled = false;
        }
    });

    function hasRenderableMessage(message) {
        const content = String(message?.content || '').trim();
        const attachments = Array.isArray(message?.attachments) ? message.attachments : [];

        return Boolean(message?.is_recalled || content || attachments.length);
    }

    function messageStatusText(message) {
        const status = String(message.outbound_status || message.status || '').toLowerCase();

        if (status === 'failed') {
            return 'gui loi';
        }

        return '';
    }

    function attachmentHtml(attachment) {
        const url = escapeHtml(attachment.url || '#');
        const name = escapeHtml(attachment.name || 'Attachment');
        const type = String(attachment.type || '').toLowerCase();
        const mimeType = String(attachment.mime_type || '').toLowerCase();
        const urlPath = String(attachment.url || '').split('?')[0].toLowerCase();
        const isVisualAttachment = ['image', 'sticker'].includes(type)
            || mimeType.startsWith('image/')
            || /\.(png|jpe?g|gif|webp|bmp|avif)$/.test(urlPath);
        const isVideoAttachment = type === 'video'
            || mimeType.startsWith('video/')
            || /\.(mp4|mov|m4v|webm|ogg)$/.test(urlPath);
        const isAudioAttachment = type === 'audio'
            || mimeType.startsWith('audio/')
            || /\.(mp3|m4a|wav|aac|oga|ogg)$/.test(urlPath);
        const stickerId = String(attachment?.payload?.sticker_id || '');
        const visualClass = stickerId === '369239263222822' ? 'is-emoji' : 'is-sticker';

        if (isVisualAttachment) {
            return `<a class="message-image-link ${visualClass}" href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${name}" loading="lazy"></a>`;
        }

        if (isVideoAttachment) {
            return `<video class="message-video" src="${url}" controls preload="metadata"></video>`;
        }

        if (isAudioAttachment) {
            return `<audio class="message-audio" src="${url}" controls preload="metadata"></audio>`;
        }

        return `<a class="message-file-link" href="${url}" target="_blank" rel="noopener">${name}</a>`;
    }

    function createClientMessageId() {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }

        return `crm-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function appendPendingMessage(content, channel, files, clientMessageId, messageMode = 'message') {
        if (!timeline) {
            return null;
        }

        const tempId = `pending-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        const message = {
            id: tempId,
            conversation_id: Number(timeline.dataset.conversationId),
            sender_type: 'user',
            sender_id: Number(timeline.dataset.currentUserId),
            sender_name: 'Admin',
            channel,
            content,
            message_type: messageMode === 'whisper' ? 'whisper' : (files.length ? 'attachment' : 'text'),
            client_message_id: clientMessageId,
            attachments: Array.from(files || []).map(function (file) {
                return {
                    name: file.name,
                    url: file.type.startsWith('image/') ? URL.createObjectURL(file) : '#',
                    mime_type: file.type,
                    type: file.type.startsWith('image/') ? 'image' : 'file',
                };
            }),
            created_at: new Date().toISOString(),
            status: 'dang gui',
            outbound_status: 'sending',
        };
        const row = document.createElement('article');
        row.className = 'message-row mine is-pending';
        row.dataset.pendingMessageId = tempId;
        row.dataset.clientMessageId = clientMessageId;
        row.dataset.pendingContent = content;
        row.dataset.pendingAttachments = attachmentNames(message).join('|');
        row.dataset.pendingAttachmentPreview = JSON.stringify(message.attachments || []);
        row.innerHTML = messageRowHtml(message, true);

        timeline.appendChild(row);
        updateThreadPreview(message);
        timeline.scrollTop = timeline.scrollHeight;

        return tempId;
    }

    function replacePendingMessage(tempId, message) {
        const pending = tempId ? timeline?.querySelector(`[data-pending-message-id="${tempId}"]`) : null;
        const existing = message?.id ? timeline?.querySelector(`[data-message-id="${message.id}"]`) : null;

        if (existing) {
            pending?.remove();
            updateThreadPreview(message);
            return;
        }

        if (!pending) {
            appendMessage(message);
            return;
        }

        pending.classList.remove('is-pending');
        pending.classList.toggle('is-failed', message?.outbound_status === 'failed');
        pending.dataset.messageId = message.id;
        pending.dataset.clientMessageId = message.client_message_id || pending.dataset.clientMessageId || '';
        delete pending.dataset.pendingMessageId;
        pending.innerHTML = messageRowHtml(mergePendingPreview(pending, message), true);
        timeline.dataset.lastMessageId = String(Math.max(Number(timeline.dataset.lastMessageId || 0), Number(message.id)));
        updateThreadPreview(message);
    }

    function mergePendingPreview(pending, message) {
        const hasServerAttachments = Array.isArray(message?.attachments) && message.attachments.length > 0;

        if (hasServerAttachments || !pending) {
            return message;
        }

        const pendingAttachments = safeJson(pending.dataset.pendingAttachmentPreview, []);

        if (!pendingAttachments.length) {
            return message;
        }

        return {
            ...message,
            attachments: pendingAttachments,
        };
    }

    function safeJson(value, fallback) {
        try {
            return JSON.parse(value || '');
        } catch (error) {
            return fallback;
        }
    }

    function markPendingFailed(tempId, error) {
        const pending = tempId ? timeline?.querySelector(`[data-pending-message-id="${tempId}"]`) : null;

        if (!pending) {
            return;
        }

        pending.classList.remove('is-pending');
        pending.classList.add('is-failed');
        const time = pending.querySelector('time');

        if (time) {
            time.textContent = `Gui that bai - ${error}`;
        }
    }

    function attachmentPreviewText(attachments) {
        const first = Array.isArray(attachments) ? attachments[0] : null;

        if (!first) {
            return '';
        }

        const type = String(first.type || '').toLowerCase();
        const mime = String(first.mime_type || '').toLowerCase();
        const payload = first.payload || {};

        if (type === 'sticker' || payload.sticker_id) {
            return '[Emoji]';
        }

        if (type === 'image' || mime.startsWith('image/') || payload.image_data?.url) {
            return '[Hinh anh]';
        }

        if (type === 'video' || mime.startsWith('video/') || payload.video_data?.url) {
            return '[Video]';
        }

        if (type === 'audio' || mime.startsWith('audio/') || payload.audio_data?.url) {
            return '[Audio]';
        }

        return '[Tep dinh kem]';
    }

    function messagePreviewText(message) {
        let content = String(message?.content || '').trim();

        if (!content) {
            content = attachmentPreviewText(message?.attachments || []);
        }

        if (!content) {
            content = 'Chua co tin nhan';
        }

        if (message?.is_recalled) {
            content = 'Tin nhan da duoc thu hoi';
        }

        if (message?.message_type === 'whisper' || message?.channel === 'internal') {
            return `Thi tham: ${content}`;
        }

        if (message?.sender_type === 'user') {
            return `Ban: ${content}`;
        }

        return content;
    }

    function updateThreadPreview(message) {
        const thread = document.querySelector(`[data-thread-conversation-id="${message.conversation_id}"]`);

        if (!thread) {
            return;
        }

        const preview = thread.querySelector('[data-thread-last-message]');
        const meta = thread.querySelector('[data-thread-meta]');

        if (preview) {
            preview.textContent = messagePreviewText(message);
        }

        if (meta) {
            meta.textContent = 'Vua xong';
        }
    }

    function updateThreadUnread(thread, count) {
        if (!thread) {
            return;
        }

        const unreadCount = Math.max(0, Number(count || 0));
        const badge = thread.querySelector('[data-thread-unread-badge]');

        thread.dataset.threadUnreadCount = String(unreadCount);
        thread.classList.toggle('is-unread', unreadCount > 0);

        if (badge) {
            badge.textContent = unreadCount > 0 ? String(unreadCount) : '';
        }
    }

    function markThreadRead(conversationId) {
        const thread = document.querySelector(`[data-thread-conversation-id="${conversationId}"]`);
        updateThreadUnread(thread, 0);
    }

    async function markActiveConversationRead() {
        if (!timeline?.dataset.readUrl) {
            return;
        }

        try {
            await fetch(timeline.dataset.readUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
            });
        } catch (error) {
            console.warn('CRM messenger mark read failed:', error);
        }
    }

    function upsertThread(message) {
        const list = document.querySelector('.messenger-thread-list');

        if (!list || !message?.conversation_id) {
            return;
        }

        if (!messageMatchesChannelFilter(message)) {
            return;
        }

        list.querySelector('.messenger-empty')?.remove();

        let thread = list.querySelector(`[data-thread-conversation-id="${message.conversation_id}"]`);

        if (!thread) {
            thread = document.createElement('a');
            thread.className = 'messenger-thread';
            thread.href = message.conversation_url || `/conversations/${encodeURIComponent(message.conversation_id)}`;
            thread.dataset.threadConversationId = message.conversation_id;
            thread.dataset.conversationUrl = thread.href;
            thread.dataset.threadUnreadCount = '0';
            const customerName = message.conversation_customer_name || message.sender_name || 'Customer';
            const customerAvatar = message.conversation_customer_avatar || message.sender_avatar;
            thread.innerHTML = `
                <span class="thread-avatar">${avatarHtml(customerAvatar, customerName)}</span>
                <span class="thread-body">
                    <strong>${escapeHtml(customerName)}</strong>
                    <small data-thread-last-message></small>
                </span>
                <span class="thread-side">
                    <span class="thread-meta" data-thread-meta></span>
                    <span class="thread-unread-badge" data-thread-unread-badge></span>
                </span>
            `;
            list.prepend(thread);
        } else {
            list.prepend(thread);
            const avatar = thread.querySelector('.thread-avatar');
            const name = thread.querySelector('.thread-body strong');

            const customerName = message.conversation_customer_name || (message.sender_type === 'customer' ? message.sender_name : '');
            const customerAvatar = message.conversation_customer_avatar || (message.sender_type === 'customer' ? message.sender_avatar : '');

            if (customerName && avatar) {
                avatar.innerHTML = avatarHtml(customerAvatar, customerName);
            }

            if (customerName && name) {
                name.textContent = customerName;
            }
        }

        updateThreadPreview(message);
        updateThreadTags(message.conversation_id, message.conversation_tags || []);

        const isActiveThread = String(message.conversation_id) === String(timeline?.dataset.conversationId);

        if (isActiveThread) {
            updateConversationTags(document.querySelector('[data-conversation-tags]')?.dataset.tagsUrl || '', message.conversation_tags || []);

            if (message.conversation_unread_messages_count !== undefined) {
                updateThreadUnread(thread, message.conversation_unread_messages_count);
            }

            return;
        }

        if (message.conversation_unread_messages_count !== undefined) {
            updateThreadUnread(thread, message.conversation_unread_messages_count);
            return;
        }

        if (message.sender_type === 'customer') {
            updateThreadUnread(thread, Number(thread.dataset.threadUnreadCount || 0) + 1);
        } else if (message.sender_type === 'user') {
            updateThreadUnread(thread, 0);
        }
    }

    function messageMatchesChannelFilter(message) {
        const channelFilter = realtimeRoot?.dataset.channelFilter || 'all';
        const messageChannel = String(message?.channel || '');

        if (channelFilter === 'all' || !['facebook', 'zalo'].includes(messageChannel)) {
            return true;
        }

        return messageChannel === channelFilter;
    }

    async function refreshThreadList() {
        const list = document.querySelector('.messenger-thread-list');

        if (!list || !realtimeRoot?.dataset.conversationsUrl) {
            return;
        }

        const url = new URL(window.location.href);
        url.pathname = new URL(realtimeRoot.dataset.conversationsUrl, window.location.origin).pathname;

        const response = await fetch(url, {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const freshList = doc.querySelector('.messenger-thread-list');

        if (freshList) {
            list.innerHTML = freshList.innerHTML;
            const activeId = timeline?.dataset.conversationId;

            if (activeId) {
                list.querySelector(`[data-thread-conversation-id="${activeId}"]`)?.classList.add('active');
            }
        }
    }

    async function fetchNewMessages() {
        if (!timeline?.dataset.pollUrl) {
            return;
        }

        const url = new URL(timeline.dataset.pollUrl, window.location.origin);
        url.searchParams.set('after_id', timeline.dataset.lastMessageId || '0');

        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        const messages = payload.data || [];
        messages.forEach(appendMessage);
    }

    async function loadOlderMessages() {
        if (
            !timeline?.dataset.pollUrl ||
            olderMessagesLoading ||
            timeline.dataset.hasOlderMessages !== '1'
        ) {
            return;
        }

        const beforeId = Number(timeline.dataset.oldestMessageId || 0);

        if (!beforeId) {
            return;
        }

        olderMessagesLoading = true;
        const previousScrollHeight = timeline.scrollHeight;
        const previousScrollTop = timeline.scrollTop;

        try {
            const url = new URL(timeline.dataset.pollUrl, window.location.origin);
            url.searchParams.set('before_id', String(beforeId));
            url.searchParams.set('limit', '10');

            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            const messages = Array.isArray(payload.data) ? payload.data : [];

            messages.forEach(prependMessage);

            const meta = payload.meta || {};
            const oldestId = Number(meta.oldest_id || messages[0]?.id || beforeId);
            timeline.dataset.oldestMessageId = String(oldestId || beforeId);
            timeline.dataset.hasOlderMessages = meta.has_more ? '1' : '0';
            timeline.scrollTop = timeline.scrollHeight - previousScrollHeight + previousScrollTop;
        } finally {
            olderMessagesLoading = false;
        }
    }

    let messageSocket = null;
    let pollingTimer = null;
    let fallbackTimer = null;
    let pollingInFlight = false;
    let realtimeMode = 'none';
    let reconnectTimer = null;

    function parsePusherData(data) {
        return typeof data === 'string' ? JSON.parse(data) : data;
    }

    async function subscribeToChannels(socket, socketId, channels) {
        for (const channel of [...new Set(channels)]) {
            const response = await fetch(realtimeRoot.dataset.broadcastAuthUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify({
                    socket_id: socketId,
                    channel_name: channel,
                }),
            });

            if (!response.ok) {
                throw new Error('Broadcast authentication failed.');
            }

            const auth = await response.json();
            socket.send(JSON.stringify({
                event: 'pusher:subscribe',
                data: {
                    auth: auth.auth,
                    channel,
                },
            }));
        }
    }

    function handleRealtimeMessage(message) {
        upsertThread(message);

        if (String(message.conversation_id) === String(timeline?.dataset.conversationId)) {
            const chatPhone = document.querySelector('[data-chat-customer-phone]');

            if (chatPhone && message.conversation_customer_phone) {
                chatPhone.textContent = message.conversation_customer_phone;
            }

            updateProfilePanel(message);
            appendMessage(message);
        }
    }

    function removeMessageRows(messageIds) {
        (messageIds || []).forEach(function (messageId) {
            timeline?.querySelector(`[data-message-id="${messageId}"]`)?.remove();
        });
    }

    async function deleteConversation(button) {
        const url = button?.dataset.deleteConversationUrl;

        if (!url || !window.confirm('Xoa hoi thoai nay khoi CRM?')) {
            return;
        }

        button.disabled = true;

        try {
            const response = await fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'Khong xoa duoc hoi thoai.');
            }

            handleDeletedMessages(payload.data || {});
        } catch (error) {
            window.alert(error.message || 'Khong xoa duoc hoi thoai.');
        } finally {
            button.disabled = false;
        }
    }

    function handleDeletedMessages(payload) {
        if (payload.delete_conversation) {
            removeConversationThread(payload.conversation_id);
            return;
        }

        if (String(payload?.conversation_id) !== String(timeline?.dataset.conversationId)) {
            return;
        }

        if (payload.clear_all) {
            timeline?.querySelectorAll('.message-row').forEach((row) => row.remove());
            timeline.dataset.oldestMessageId = '0';
            timeline.dataset.lastMessageId = '0';
            timeline.dataset.hasOlderMessages = '0';
            updateThreadPreview({
                conversation_id: payload.conversation_id,
                content: 'Da xoa toan bo tin nhan',
            });
            markThreadRead(payload.conversation_id);
            return;
        }

        removeMessageRows(payload.message_ids || []);
        updateThreadPreview({
            conversation_id: payload.conversation_id,
            content: 'Da xoa tin nhan',
        });
    }

    function removeConversationThread(conversationId) {
        const thread = document.querySelector(`[data-thread-conversation-id="${conversationId}"]`);
        const wasActive = String(conversationId) === String(timeline?.dataset.conversationId);
        const fallbackThread = thread?.nextElementSibling?.matches?.('.messenger-thread')
            ? thread.nextElementSibling
            : thread?.previousElementSibling?.matches?.('.messenger-thread')
            ? thread.previousElementSibling
            : document.querySelector(`.messenger-thread:not([data-thread-conversation-id="${conversationId}"])`);

        thread?.remove();

        if (!wasActive) {
            return;
        }

        if (fallbackThread) {
            loadConversation(fallbackThread.dataset.conversationUrl || fallbackThread.href);
            return;
        }

        window.location.href = realtimeRoot?.dataset.conversationsUrl || '/conversations';
    }

    async function runPollingLoop() {
        if (document.hidden || realtimeMode === 'websocket') {
            stopPolling();
            return;
        }

        if (!pollingInFlight) {
            pollingInFlight = true;

            try {
                await fetchNewMessages();
                await refreshThreadList();
            } finally {
                pollingInFlight = false;
            }
        }

        pollingTimer = window.setTimeout(runPollingLoop, 30000);
    }

    function startPolling() {
        if (!pollingTimer && realtimeMode !== 'websocket') {
            console.info('CRM messenger realtime fallback: polling every 30s');
            pollingTimer = window.setTimeout(runPollingLoop, 0);
        }
    }

    function stopPolling() {
        if (pollingTimer) {
            window.clearTimeout(pollingTimer);
            pollingTimer = null;
        }
    }

    function stopFallbackTimer() {
        if (fallbackTimer) {
            window.clearTimeout(fallbackTimer);
            fallbackTimer = null;
        }
    }

    function stopReconnectTimer() {
        if (reconnectTimer) {
            window.clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
    }

    function closeMessageSocket() {
        if (messageSocket) {
            messageSocket.manualClose = true;
            messageSocket.close();
            messageSocket = null;
        }
    }

    function startBroadcastSocket() {
        if (
            !realtimeRoot?.dataset.reverbKey ||
            !realtimeRoot?.dataset.reverbHost ||
            !window.WebSocket
        ) {
            return false;
        }

        closeMessageSocket();

        const port = realtimeRoot.dataset.reverbPort ? `:${realtimeRoot.dataset.reverbPort}` : '';
        const socketUrl = `${realtimeRoot.dataset.reverbScheme}://${realtimeRoot.dataset.reverbHost}${port}/app/${encodeURIComponent(realtimeRoot.dataset.reverbKey)}?protocol=7&client=crm-web&version=1.0&flash=false`;
        console.info('CRM messenger websocket connecting:', socketUrl);
        messageSocket = new WebSocket(socketUrl);
        const socket = messageSocket;

        socket.addEventListener('message', async function (event) {
            try {
                const payload = JSON.parse(event.data);

                if (payload.event === 'pusher:connection_established') {
                    const connection = parsePusherData(payload.data);
                    await subscribeToChannels(socket, connection.socket_id, [
                        timeline?.dataset.broadcastChannel,
                        realtimeRoot.dataset.inboxBroadcastChannel,
                    ].filter(Boolean));

                    return;
                }

                if (payload.event === 'pusher_internal:subscription_succeeded') {
                    console.info('CRM messenger realtime: websocket connected');
                    realtimeMode = 'websocket';
                    stopFallbackTimer();
                    stopPolling();
                    return;
                }

                if (payload.event === 'message.created' || payload.event === 'message.updated') {
                    const data = parsePusherData(payload.data);
                    handleRealtimeMessage(data.message || data);
                }

                if (payload.event === 'message.deleted') {
                    handleDeletedMessages(parsePusherData(payload.data));
                }
            } catch (error) {
                console.warn('CRM messenger websocket message failed:', error);
                closeMessageSocket();
                realtimeMode = 'polling';
                startPolling();
            }
        });

        socket.addEventListener('close', function () {
            if (socket.manualClose) {
                return;
            }

            if (messageSocket === socket) {
                messageSocket = null;
            }

            console.warn('CRM messenger websocket closed:', {
                code: event.code,
                reason: event.reason,
                wasClean: event.wasClean,
            });

            if (!document.hidden) {
                realtimeMode = 'polling';
                stopFallbackTimer();
                startPolling();
                stopReconnectTimer();
                reconnectTimer = window.setTimeout(startRealtime, 10000);
            }
        });

        socket.addEventListener('error', function (event) {
            console.warn('CRM messenger websocket error:', event);
            closeMessageSocket();
            realtimeMode = 'polling';
            stopFallbackTimer();
            startPolling();
        });

        return true;
    }

    function startRealtime() {
        closeMessageSocket();
        stopReconnectTimer();
        stopFallbackTimer();
        stopPolling();
        realtimeMode = 'connecting';

        if (startBroadcastSocket()) {
            fallbackTimer = window.setTimeout(function () {
                if (realtimeMode !== 'websocket') {
                    realtimeMode = 'polling';
                    startPolling();
                }
            }, 8000);
            return;
        }

        realtimeMode = 'polling';
        startPolling();
    }

    if (timeline) {
        timeline.scrollTop = timeline.scrollHeight;
        timeline.addEventListener('scroll', function () {
            if (timeline.scrollTop <= 80) {
                loadOlderMessages();
            }
        });
    }

    startRealtime();
    renderCustomerTagOptions('');

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            Promise.all([
                fetchNewMessages(),
                refreshThreadList(),
            ]).finally(function () {
                if (realtimeMode === 'none' || realtimeMode === 'polling') {
                    startRealtime();
                }
            });

            return;
        }

        closeMessageSocket();
        stopFallbackTimer();
        stopReconnectTimer();
        stopPolling();
        realtimeMode = 'none';
    });

    composer?.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (composer.dataset.canReply !== '1') {
            document.querySelector('[data-claim-status]')?.scrollIntoView({block: 'center'});
            return;
        }

        const input = composer.querySelector('[name="content"]');
        const button = composer.querySelector('button[type="submit"]');
        const fileInput = composer.querySelector('[data-composer-files]');
        const fileList = composer.querySelector('[data-composer-file-list]');
        const content = String(input?.value || '').trim();
        const channel = String(composer.querySelector('input[name="channel"]')?.value || '');
        const messageMode = String(composer.querySelector('[name="message_mode"]')?.value || 'message');
        const files = fileInput?.files || [];

        if (!content && files.length === 0) {
            return;
        }

        const clientMessageId = createClientMessageId();
        const pendingId = appendPendingMessage(content, messageMode === 'whisper' ? 'internal' : channel, files, clientMessageId, messageMode);

        button.disabled = true;
        input.value = '';
        if (fileList) {
            fileList.textContent = '';
        }
        input.focus();

        try {
            const uploadedAttachments = files.length ? await attachmentUpload.promise : [];
            const formData = new FormData();
            formData.set('channel', channel);
            formData.set('message_mode', messageMode);
            formData.set('client_message_id', clientMessageId);

            if (content) {
                formData.set('content', content);
            }

            uploadedAttachments.forEach(function (attachment, index) {
                Object.entries(attachment).forEach(function ([key, value]) {
                    if (value !== null && value !== undefined) {
                        formData.append(`uploaded_attachments[${index}][${key}]`, String(value));
                    }
                });
            });

            const response = await fetch(composer.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: formData,
            });

            if (!response.ok) {
                const payload = await response.json().catch(function () {
                    return {};
                });

                throw new Error(payload.message || 'Khong gui duoc tin nhan.');
            }

            const payload = await response.json();
            replacePendingMessage(pendingId, payload.data);
            if (
                Array.isArray(payload.data?.conversation_tags)
                && String(payload.data?.conversation_id) === String(timeline?.dataset.conversationId)
            ) {
                updateConversationTags(document.querySelector('[data-conversation-tags]')?.dataset.tagsUrl || '', payload.data.conversation_tags);
                updateThreadTags(payload.data.conversation_id, payload.data.conversation_tags);
            }
            if (fileInput) {
                fileInput.value = '';
            }
            attachmentUpload = {
                files: [],
                promise: Promise.resolve([]),
                uploaded: [],
            };
        } catch (error) {
            markPendingFailed(pendingId, error.message);
        } finally {
            button.disabled = false;
        }
    });

    composer?.querySelector('[data-composer-files]')?.addEventListener('change', function (event) {
        const fileList = composer.querySelector('[data-composer-file-list]');
        const files = Array.from(event.target.files || []);

        if (fileList) {
            fileList.textContent = files.length ? 'Dang tai: ' + files.map((file) => file.name).join(', ') : '';
        }

        attachmentUpload.files = files;
        attachmentUpload.uploaded = [];
        attachmentUpload.promise = uploadComposerAttachments(files)
            .then(function (attachments) {
                attachmentUpload.uploaded = attachments;

                if (fileList) {
                    fileList.textContent = attachments.map((attachment) => attachment.name).join(', ');
                }

                return attachments;
            })
            .catch(function (error) {
                if (fileList) {
                    fileList.textContent = `Tai file loi: ${error.message}`;
                }

                throw error;
            });
    });

    composer?.querySelector('[name="content"]')?.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
            return;
        }

        event.preventDefault();
        composer.requestSubmit();
    });

    composer?.querySelectorAll('[data-composer-mode]')?.forEach(function (button) {
        button.addEventListener('click', function () {
            const mode = button.dataset.composerMode || 'message';
            const modeInput = composer.querySelector('[name="message_mode"]');
            const textInput = composer.querySelector('[name="content"]');

            composer.querySelectorAll('[data-composer-mode]').forEach(function (modeButton) {
                modeButton.classList.toggle('active', modeButton === button);
            });

            if (modeInput) {
                modeInput.value = mode;
            }

            if (textInput) {
                textInput.placeholder = mode === 'whisper'
                    ? 'Nhap ghi chu noi bo, chi nhan vien thay'
                    : 'Nhap noi dung tin nhan va nhan Enter de gui';
                textInput.focus();
            }
        });
    });

    composer?.querySelectorAll('[data-upload-trigger]')?.forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            composer.querySelector('[data-composer-files]')?.click();
        });
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-tag-name]');
        const tabs = button?.closest('[data-conversation-tags]');

        if (!button || !tabs) {
            return;
        }

        event.preventDefault();
        const wasActive = button.classList.contains('is-active');
        const conversationId = timeline?.dataset.conversationId;
        const previousTags = selectedConversationTags();

        tabs.querySelectorAll('[data-tag-name].is-active').forEach(function (activeButton) {
            activeButton.classList.remove('is-active');
        });

        if (!wasActive) {
            button.classList.add('is-active');
        }

        updateThreadTags(conversationId, selectedConversationTags());

        try {
            const response = await fetch(tabs.dataset.tagsUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify({
                    tags: selectedConversationTags(),
                }),
            });

            if (!response.ok) {
                throw new Error('Khong luu duoc tag.');
            }

            const payload = await response.json();
            updateConversationTags(tabs.dataset.tagsUrl, payload.data?.tags || []);
            updateThreadTags(payload.data?.id || timeline?.dataset.conversationId, payload.data?.tags || []);
            await refreshThreadList();
        } catch (error) {
            updateConversationTags(tabs.dataset.tagsUrl, previousTags);
            updateThreadTags(conversationId, previousTags);
            console.warn('CRM conversation tag update failed:', error);
        }
    });

    async function uploadComposerAttachments(files) {
        if (!files.length) {
            return [];
        }

        const uploadUrl = composer?.dataset.uploadUrl;

        if (!uploadUrl) {
            throw new Error('Upload endpoint is missing.');
        }

        const formData = new FormData();

        files.forEach(function (file) {
            formData.append('attachments[]', file);
        });

        const response = await fetch(uploadUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
            },
            body: formData,
        });

        if (!response.ok) {
            const payload = await response.json().catch(function () {
                return {};
            });

            throw new Error(payload.message || 'Khong tai duoc file.');
        }

        const payload = await response.json();

        return Array.isArray(payload.data) ? payload.data : [];
    }
</script>
@endsection
