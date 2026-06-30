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
@php($customerTagOptions = $allCustomerTags->map(fn ($tag) => ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color])->values())
@php($inboxLastMessageId = $conversations->map(fn ($conversation) => (int) ($conversation->messages->first()?->id ?? 0))->max() ?? 0)
@php($tagManager = $tagManager ?? ['index_url' => route('crm.conversation-tags.index'), 'store_url' => route('crm.conversation-tags.store')])
@php($activeSection = 'conversations')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/crm/messenger.css') }}?v={{ filemtime(public_path('css/crm/messenger.css')) }}">
<link rel="stylesheet" href="{{ asset('css/messenger/chat.css') }}?v={{ filemtime(public_path('css/messenger/chat.css')) }}">
<link rel="stylesheet" href="{{ asset('css/crm-professional.css') }}?v={{ filemtime(public_path('css/crm-professional.css')) }}">
@endpush
<div class="crm-shell messenger-crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main messenger-crm-main">
        @include('partials.crm.topbar')

        <div
            class="messenger-shell"
            data-messenger-realtime
            data-conversations-url="{{ route('crm.conversations') }}"
            data-inbox-stream-url="{{ route('crm.conversations.messages.stream.inbox') }}"
            data-inbox-last-message-id="{{ $inboxLastMessageId }}"
            data-current-user-id="{{ $currentUser->id }}"
            data-channel-filter="{{ $filters['channel'] ?? 'all' }}"
            data-status-filter="{{ $filters['status'] ?? 'all' }}"
            data-tag-filter="{{ $filters['tag'] ?? '' }}"
            data-inbox-broadcast-channel="private-crm.conversations"
            data-broadcast-auth-url="{{ url('/broadcasting/auth') }}"
            data-reverb-key="{{ $reverb['key'] }}"
            data-reverb-host="{{ $reverbPublicHost ?: (in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) ? request()->getHost() : $reverb['options']['host']) }}"
            data-reverb-port="{{ $reverbPublicHost ? $reverbPublicPort : (in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) && request()->secure() ? '' : $reverb['options']['port']) }}"
            data-reverb-scheme="{{ $reverbPublicScheme ?: (request()->secure() ? 'wss' : ($reverb['options']['scheme'] === 'https' ? 'wss' : 'ws')) }}"
            data-customer-tag-options-json='@json($customerTagOptions)'
        >
            <button type="button" class="messenger-drawer-backdrop" data-messenger-drawer-close aria-label="Dong bang dieu khien"></button>
<aside class="messenger-list" data-messenger-list-panel>
                <button type="button" class="messenger-panel-close" data-messenger-drawer-close aria-label="Dong danh sach hoi thoai">
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
                <div class="messenger-list-head">
                    <div>
                        <h1>Hội Thoại</h1>
                    </div>
                    <div class="messenger-filter-menu">
                        <button type="button" class="messenger-filter-button" aria-label="Loc tag hoi thoai">
                            <span class="material-symbols-outlined" aria-hidden="true">filter_list</span>
                        </button>
                        <nav class="messenger-tag-menu" aria-label="Loc tag hoi thoai">
                            <a
                                class="{{ blank($filters['tag'] ?? '') ? 'active' : '' }}"
                                href="{{ route('crm.conversations', array_filter([
                                    'channel' => ($filters['channel'] ?? 'all') === 'all' ? null : $filters['channel'],
                                    'status' => ($filters['status'] ?? 'all') === 'all' ? null : $filters['status'],
                                    'q' => $filters['search'] ?: null,
                                ])) }}"
                            >Tat ca tag</a>
                            @foreach($allTags as $tag)
                                <a
                                    class="{{ ($filters['tag'] ?? '') === $tag->name ? 'active' : '' }}"
                                    href="{{ route('crm.conversations', array_filter([
                                        'channel' => ($filters['channel'] ?? 'all') === 'all' ? null : $filters['channel'],
                                        'status' => ($filters['status'] ?? 'all') === 'all' ? null : $filters['status'],
                                        'q' => $filters['search'] ?: null,
                                        'tag' => $tag->name,
                                    ])) }}"
                                >{{ $tag->name }}</a>
                            @endforeach
                        </nav>
                    </div>
                </div>

        <form class="messenger-search" method="GET" action="{{ route('crm.conversations') }}">
            @if(($filters['channel'] ?? 'all') !== 'all')
                <input type="hidden" name="channel" value="{{ $filters['channel'] }}">
            @endif
            @if(($filters['status'] ?? 'all') !== 'all')
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
            @endif
            @if(filled($filters['tag'] ?? ''))
                <input type="hidden" name="tag" value="{{ $filters['tag'] }}">
            @endif
            <label class="messenger-search-box">
                <span aria-hidden="true"></span>
                <input type="search" name="q" placeholder="Tim kiem..." value="{{ $filters['search'] }}" data-auto-search-input>
            </label>
</form>
        <nav class="messenger-status-tabs" aria-label="Trang thai hoi thoai">
            @foreach($inboxStatuses as $statusTab)
                <a
                    class="{{ $statusTab['active'] ? 'active' : '' }}"
                    href="{{ route('crm.conversations', array_filter([
                        'channel' => ($filters['channel'] ?? 'all') === 'all' ? null : $filters['channel'],
                        'status' => $statusTab['key'] === 'all' ? null : $statusTab['key'],
                        'q' => $filters['search'] ?: null,
                        'tag' => $filters['tag'] ?: null,
                    ])) }}"
                >
                    {{ $statusTab['label'] }}
                    @if($statusTab['key'] === 'unread' && $statusTab['count'] > 0)
                        <span>{{ $statusTab['count'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
        <div class="messenger-thread-list">
            @forelse($conversations as $conversation)
                @php($lastMessage = $conversation->messages->first())
                @php($lastMessagePreview = $lastMessage?->conversationPreviewText() ?? 'Chua co tin nhan')
                @php($threadLastMessageAt = $conversation->last_message_at ?: $lastMessage?->created_at)
                @php($isActive = $activeConversation?->id === $conversation->id)
                @php($unreadCount = (int) $conversation->unread_messages_count)
                @php($threadTags = $conversation->tags->take(1)->values())
                @php($threadChannel = $lastMessage?->channel ?: $conversation->customer?->channels?->first()?->channel ?: 'facebook')
                <a
                    class="messenger-thread {{ $isActive ? 'active' : '' }} {{ $unreadCount > 0 ? 'is-unread' : '' }}"
                    href="{{ route('crm.conversations.show', array_filter(['conversation' => $conversation, 'channel' => ($filters['channel'] ?? 'all') === 'all' ? null : $filters['channel'], 'status' => ($filters['status'] ?? 'all') === 'all' ? null : $filters['status'], 'q' => $filters['search'] ?: null, 'tag' => $filters['tag'] ?: null])) }}"
                    data-thread-conversation-id="{{ $conversation->id }}"
                    data-conversation-url="{{ route('crm.conversations.show', array_filter(['conversation' => $conversation, 'channel' => ($filters['channel'] ?? 'all') === 'all' ? null : $filters['channel'], 'status' => ($filters['status'] ?? 'all') === 'all' ? null : $filters['status'], 'q' => $filters['search'] ?: null, 'tag' => $filters['tag'] ?: null])) }}"
                    data-thread-unread-count="{{ $unreadCount }}"
                >
                    <span class="thread-avatar">
                        @if($conversation->customer?->avatar)
                            <img src="{{ $conversation->customer->avatar }}" alt="{{ $conversation->customer?->name ?? 'Customer' }}">
                        @else
                            {{ strtoupper(substr($conversation->customer?->name ?? 'C', 0, 1)) }}
                        @endif
                        <img class="thread-platform-icon" src="{{ asset($threadChannel === 'zalo' ? 'assets/img_zalo.png' : 'assets/img_fb.png') }}" alt="{{ ucfirst($threadChannel) }}">
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
                        <span class="thread-meta" data-thread-meta>{{ $threadLastMessageAt?->diffForHumans() }}</span>
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
            @php($canClaim = ! $activeConversation->assigned_to && ! $currentUser->can('conversation.view_all') && app(\Modules\Conversation\Services\WorkShiftService::class)->userBelongsToShift($currentUser, $activeConversation->queue_shift_id))
            @php($canAssign = ($currentUser->can('conversation.assign') || $currentUser->can('conversation.transfer')) && $assignableAgents->isNotEmpty())
            <header class="messenger-chat-head">
                <button type="button" class="messenger-mobile-toggle" data-messenger-drawer-toggle="list" aria-label="Mo danh sach hoi thoai">
                    <span class="material-symbols-outlined" aria-hidden="true">forum</span>
                </button>
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
                @if($canAssign)
                    <form class="conversation-assign-form" data-assign-form data-assign-url="{{ route('crm.conversations.assign', $activeConversation) }}">
                        <label for="conversation-assignee">Phan cong</label>
                        <select id="conversation-assignee" name="assigned_to" data-assign-select>
                            <option value="">Chọn Nhân Viên</option>
                            @foreach($assignableAgents as $agent)
                                <option value="{{ $agent->id }}" @selected((int) $activeConversation->assigned_to === (int) $agent->id)>{{ $agent->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit">
                            Lưu
                        </button>
                        <small data-assign-status></small>
                    </form>
                @endif
                <button type="button" class="messenger-mobile-toggle" data-messenger-drawer-toggle="profile" aria-label="Mo thong tin khach hang">
                    <span class="material-symbols-outlined" aria-hidden="true">contacts</span>
                </button>
            </header>
            <div class="conversation-claim-bar {{ $canClaim || ! $canReply ? '' : 'is-hidden' }}" data-claim-bar>
                <span data-claim-status>{{ $canClaim ? 'Hoi thoai moi trong ca truc cua ban.' : 'Ban can nhan xu ly truoc khi tra loi.' }}</span>
                <button type="button" data-claim-button data-claim-url="{{ route('crm.conversations.claim', $activeConversation) }}" {{ $canClaim ? '' : 'disabled' }}>
                    Nhan xu ly
                </button>
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
                <div
                    class="composer-tabs"
                    data-conversation-tags
                    data-tags-url="{{ route('crm.conversations.tags.store', $activeConversation) }}"
                    data-tag-manager-url="{{ $tagManager['index_url'] }}"
                    data-tag-manager-store-url="{{ $tagManager['store_url'] }}"
                >
                    <button type="button" class="composer-mode active" data-composer-mode="message">
                        Nhắn Tin
                    </button>
                    <button type="button" class="composer-mode" data-composer-mode="whisper">
                        Thì Thầm
                    </button>
                    <b></b>
                    @foreach($tagPresets as $tag)
                        @php($tagName = $tag['name'])
                        @php($tagColor = $tag['color'] ?: '#64748b')
                        <button
                            type="button"
                            class="composer-tag {{ in_array($tagName, $activeTagNames, true) ? 'is-active' : '' }}"
                            style="--tag-color: {{ $tagColor }}"
                            data-tag-id="{{ $tag['id'] ?? '' }}"
                            data-tag-name="{{ $tagName }}"
                            data-tag-color="{{ $tagColor }}"
                            data-tag-default="{{ ! empty($tag['is_default']) ? '1' : '0' }}"
                        >{{ $tagName }}</button>
                    @endforeach
                    <button type="button" class="composer-tag-add" data-open-tag-manager aria-label="Custom tag">+</button>
                </div>
                <textarea name="content" rows="3" placeholder="Nhập Nội Dung Tin Nhắn" autocomplete="off" {{ $canReply ? '' : 'disabled' }}>{{ old('content') }}</textarea>
                <div class="composer-bottom-row">
                    <div class="composer-actions" aria-label="Message tools">
                        <label class="composer-attach-button" title="Dinh kem file, anh, video">
                            Đính Kèm
                            <input type="file" name="attachments[]" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar" multiple data-composer-files>
                        </label>
                        <button class="composer-send-button" type="submit" title="Gui" {{ $canReply ? '' : 'disabled' }}>
                            <span class="material-symbols-outlined" aria-hidden="true">send</span>
                            <span class="sr-only">Gui</span>
                        </button>
                    </div>
                </div>
                <div class="composer-file-list" data-composer-file-list></div>
            </form>
            @error('content')
                <p class="field-error">{{ $message }}</p>
            @enderror
        @else
            <section class="messenger-no-chat">
                <button type="button" class="messenger-mobile-toggle" data-messenger-drawer-toggle="list" aria-label="Mo danh sach hoi thoai">
                    <span class="material-symbols-outlined" aria-hidden="true">forum</span>
                </button>
                <h2>Chọn Hội Thoại</h2>
                <p>Chọn Hội Thoại Bên Trái Xử Lí</p>
            </section>
        @endif
    </section>
    <aside class="messenger-profile-panel" data-profile-panel data-messenger-profile-panel>
        <button type="button" class="messenger-panel-close" data-messenger-drawer-close aria-label="Dong thong tin khach hang">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
        @if($activeConversation)
            <section class="profile-contact-full" data-contact-full hidden>
                <header>
                    <button type="button" data-contact-back aria-label="Quay lai">
                        <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                    </button>
                    <h4>Chi Tiết Liên Hệ</h4>
                </header>
                <div class="profile-contact-full-body">
                    <div class="profile-section profile-details">
                        <h4>Thông Tin Công Khai</h4>
                        <div class="profile-detail-list" data-public-detail-list>
                            @if(count($profilePanel['details']) === 0)
                                <p class="profile-empty">Chưa Có Thông Tin Công Khai</p>
                            @endif
                            @foreach($profilePanel['details'] as $detail)
                                <span>{{ $detail['label'] }}</span>
                                <strong>{{ $detail['value'] }}</strong>
                            @endforeach
                        </div>
                    </div>
                    <section class="profile-section profile-details" data-contact-section data-contact-url="{{ route('crm.conversations.customer.update', $activeConversation) }}">
                        <h4>Cập Nhật Liên Hệ</h4>
                        <form class="profile-contact-form" data-contact-form>
                            <label>
                                <span>Tên Khách Hàng</span>
                                <input type="text" name="name" value="{{ $profilePanel['contact']['name'] }}" autocomplete="name" required>
                            </label>
                            <label>
                                <span>Số Điện Thoại</span>
                                <input type="tel" name="phone" value="{{ $profilePanel['contact']['phone'] }}" autocomplete="tel">
                            </label>
                            <label>
                                <span>Email</span>
                                <input type="email" name="email" value="{{ $profilePanel['contact']['email'] }}" autocomplete="email">
                            </label>
                            <label>
                                <span>Kênh Liên Hệ</span>
                                <input type="text" value="{{ $profilePanel['contact']['channel'] ?: 'Chua co kenh' }}" data-contact-channel disabled>
                            </label>
                            <div class="profile-contact-actions">
                                <button type="submit">
                                    Save
                                </button>
                                <small data-contact-status></small>
                            </div>
                        </form>
                    </section>
                </div>
            </section>

            <section class="profile-notes-full" data-notes-full hidden>
                <header>
                    <button type="button" data-notes-back aria-label="Quay lai">
                        <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                    </button>
                    <h4>Tất Cả Ghi Chú</h4>
                </header>
                <div class="profile-note-list is-full" data-note-list-full>
                    @if(count($profilePanel['notes']) === 0)
                        <p class="profile-empty">Chưa Có Ghi Chú Nào</p>
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
            <header class="profile-panel-title">
                <h3>Thông Tin Khách Hàng</h3>
                <button type="button" class="profile-contact-toggle" data-contact-toggle>
                    Chinh sua
                </button>
            </header>

            <section class="profile-card-head">
                <span class="channel-lead-badge">{{ ucfirst($activeChannel) }} Lead</span>
                <span class="thread-avatar" data-profile-avatar>
                    @if($activeConversation->customer?->avatar)
                        <img src="{{ $activeConversation->customer->avatar }}" alt="{{ $activeConversation->customer?->name ?? 'Customer' }}">
                    @else
                        {{ strtoupper(substr($activeConversation->customer?->name ?? 'C', 0, 1)) }}
                    @endif
                </span>
                <div>
                    <h3 data-profile-name>{{ $activeConversation->customer?->name ?? 'Customer' }}</h3>
                    <button type="button" class="profile-contact-toggle" data-contact-toggle>
                        Chi Tiết Liên Hệ
                    </button>
                </div>
            </section>

            <section class="profile-contact-summary">
                <article>
                    <span>Số Điện Thoại</span>
                    <strong data-profile-phone>{{ $activeConversation->customer?->phone ?: 'Chua co' }}</strong>
                </article>
            </section>

            <section class="profile-section profile-notes" data-customer-notes data-notes-url="{{ route('crm.conversations.customer-notes.store', $activeConversation) }}">
                <header>
                    <h4>Ghi Chú (<span data-note-count>{{ count($profilePanel['notes']) }}</span>)</h4>
                    <button type="button" data-notes-toggle>
                        Xem tat ca
                    </button>
                </header>
                <textarea rows="3" placeholder="Nhập Ghi Chú Và Ấn Enter" data-note-input></textarea>
            </section>

            <section class="profile-section profile-customer-tags" data-profile-conversation-tags>
                <h4>Trạng Thái Hội Thoại</h4>
                <div class="customer-tag-list" data-profile-conversation-tag-list>
                    @if(count($profilePanel['tags']) === 0)
                        <p class="profile-empty">Chưa Có Trạng Thái</p>
                    @endif
                    @foreach($profilePanel['tags'] as $tag)
                        <span style="--tag-color: {{ $tag['color'] }}">{{ $tag['name'] }}</span>
                    @endforeach
                </div>
            </section>
            </div>
        @else
            <section class="profile-section is-empty">
                <h4>Thông Tin Khách Hàng</h4>
                <p>Chọn Một Conversation Để Xem Chi Tiết.</p>
            </section>
        @endif
    </aside>
        </div>
    </main>
</div>

<div class="tag-manager-modal" data-tag-manager-modal hidden>
    <div class="tag-manager-dialog" role="dialog" aria-modal="true" aria-label="Custom tag">
        <header>
            <div>
                <h3>Custom tag</h3>
                <p>Chi giu 2 tag mac dinh. Tag custom co the sua mau, doi ten va xoa.</p>
            </div>
            <button type="button" class="tag-manager-close" data-close-tag-manager aria-label="Dong">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </header>
        <form class="tag-manager-form" data-tag-manager-form>
            <input type="hidden" name="id" data-tag-manager-id>
            <label>
                <span>Ten tag</span>
                <input type="text" name="name" maxlength="80" placeholder="Vi du: Uu tien" required data-tag-manager-name>
            </label>
            <label>
                <span>Mau</span>
                <input type="color" name="color" value="#2563eb" data-tag-manager-color>
            </label>
            <button type="submit" data-tag-manager-submit>Luu tag</button>
            <button type="button" data-tag-manager-reset>Tag moi</button>
        </form>
        <p class="tag-manager-status" data-tag-manager-status></p>
        <div class="tag-manager-list" data-tag-manager-list></div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/messenger/chat.js') }}?v={{ filemtime(public_path('js/messenger/chat.js')) }}" defer></script>
@endpush
@endsection

