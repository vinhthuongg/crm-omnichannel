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
@php($customerTagOptionsJson = $allCustomerTags->map(fn ($tag) => ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color])->values()->toJson())
@php($navLabels = [
    'dashboard' => 'Dashboard',
    'conversations' => 'Hoi thoai',
    'customers' => 'Khach hang',
    'agents' => 'Nhan vien',
    'work_shifts' => 'Ca truc',
    'channels' => 'Ket noi mang xa hoi',
    'reports' => 'Bao cao',
    'activity' => 'Hoat dong',
    'activity_log' => 'Thong bao',
    'notifications' => 'Thong bao',
    'settings' => 'Cai dat',
])
@push('styles')
<link rel="stylesheet" href="{{ asset('css/messenger/chat.css') }}?v={{ filemtime(public_path('css/messenger/chat.css')) }}">
@endpush
<div class="crm-shell messenger-crm-shell" data-crm-shell>
    <aside class="crm-sidebar">
        <a class="crm-logo" href="{{ route('dashboard') }}">Toyota CRM</a>

        <a class="sidebar-user-card" href="{{ route('crm.settings', ['panel' => 'profile']) }}">
            <span class="sidebar-user-avatar">
                @if($currentUser->avatar ?? null)
                    <img src="{{ $currentUser->avatar }}" alt="{{ $currentUser->name }}">
                @else
                    {{ strtoupper(substr($currentUser->name, 0, 1)) }}
                @endif
            </span>
            <span>
                <strong>{{ $currentUser->name }}</strong>
                <small>{{ $currentUser->can('conversation.view_all') ? 'CRM Admin' : 'Sales Consultant' }}</small>
            </span>
        </a>

        <nav class="side-nav" aria-label="CRM navigation">
            @foreach($navItems as $item)
                <a class="{{ $activeSection === $item['section'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
                    <span>{{ $item['icon'] }}</span>{{ $navLabels[$item['section']] ?? $item['label'] }}
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
            <div class="topbar-brand">
                <strong>Toyota CRM</strong>
                <span>Omnichannel CRM</span>
            </div>
            <form class="topbar-global-search" method="GET" action="{{ route('crm.conversations') }}">
                <input type="search" name="q" placeholder="Tim kiem khach hang, tin nhan..." value="{{ $filters['search'] }}" data-auto-search-input>
            </form>
            <div class="topbar-spacer"></div>
            <a class="help-link" href="{{ route('crm.settings', ['panel' => 'help']) }}"><span>?</span> Help Center</a>
            <a class="profile-link" href="{{ route('crm.settings', ['panel' => 'profile']) }}">{{ $currentUser->name }}</a>
            <form method="POST" action="{{ route('logout') }}" class="account-menu">
                @csrf
                <button type="submit">Dang xuat</button>
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
            data-customer-tag-options="{{ e($customerTagOptionsJson) }}"
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
                        <h1>Hoi thoai</h1>
                    </div>
                </div>

        <form class="messenger-search" method="GET" action="{{ route('crm.conversations') }}">
            @if(($filters['channel'] ?? 'all') !== 'all')
                <input type="hidden" name="channel" value="{{ $filters['channel'] }}">
            @endif
            <label class="messenger-search-box">
                <span aria-hidden="true"></span>
                <input type="search" name="q" placeholder="Tim kiem..." value="{{ $filters['search'] }}" data-auto-search-input>
            </label>
            <select name="tag" aria-label="Loc theo tag">
                <option value="">Tat ca tag</option>
                @foreach($allTags as $tag)
                    <option value="{{ $tag->name }}" @selected(($filters['tag'] ?? '') === $tag->name)>{{ $tag->name }}</option>
                @endforeach
            </select>
        </form>

        <div class="messenger-thread-list">
            @forelse($conversations as $conversation)
                @php($lastMessage = $conversation->messages->first())
                @php($lastMessagePreview = $lastMessage?->conversationPreviewText() ?? 'Chua co tin nhan')
                @php($isActive = $activeConversation?->id === $conversation->id)
                @php($unreadCount = (int) $conversation->unread_messages_count)
                @php($threadTags = $conversation->tags->take(1)->values())
                @php($threadChannel = $lastMessage?->channel ?: $conversation->customer?->channels?->first()?->channel ?: 'facebook')
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
            @php($canClaim = ! $activeConversation->assigned_to && ! $currentUser->can('conversation.view_all') && app(\Modules\Conversation\Services\WorkShiftService::class)->userBelongsToShift($currentUser, $activeConversation->queue_shift_id))
            @php($canAssign = ($currentUser->can('conversation.assign') || $currentUser->can('conversation.transfer')) && $assignableAgents->isNotEmpty())
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
                @if($canAssign)
                    <form class="conversation-assign-form" data-assign-form data-assign-url="{{ route('crm.conversations.assign', $activeConversation) }}">
                        <label for="conversation-assignee">Phan cong</label>
                        <select id="conversation-assignee" name="assigned_to" data-assign-select>
                            <option value="">Chon nhan vien</option>
                            @foreach($assignableAgents as $agent)
                                <option value="{{ $agent->id }}" @selected((int) $activeConversation->assigned_to === (int) $agent->id)>{{ $agent->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit">Luu</button>
                        <small data-assign-status></small>
                    </form>
                @endif
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
                <textarea name="content" rows="3" placeholder="Nhap noi dung tin nhan va nhan Enter de gui" autocomplete="off" {{ $canReply ? '' : 'disabled' }}>{{ old('content') }}</textarea>
                <div class="composer-bottom-row">
                    <span class="thread-avatar mini composer-user-avatar">
                        @if($currentUser->avatar ?? null)
                            <img src="{{ $currentUser->avatar }}" alt="{{ $currentUser->name }}">
                        @else
                            {{ strtoupper(substr($currentUser->name, 0, 1)) }}
                        @endif
                    </span>
                    <div class="composer-actions" aria-label="Message tools">
                        <button type="button" title="Bieu cam">:)</button>
                        <button type="button" title="Mau tin">M</button>
                        <label title="Dinh kem">
                            +
                            <input type="file" name="attachments[]" multiple data-composer-files>
                        </label>
                        <button type="button" title="Hinh anh" data-upload-trigger>Img</button>
                        <button type="button" title="Video" data-upload-trigger>Vid</button>
                        <button type="button" title="Ghi chu">Note</button>
                        <button type="button" title="Lich">Cal</button>
                        <button type="button" title="San pham">Xe</button>
                        <button class="composer-send-button" type="submit" title="Gui" {{ $canReply ? '' : 'disabled' }}>Go</button>
                    </div>
                </div>
                <div class="composer-file-list" data-composer-file-list></div>
            </form>
            @error('content')
                <p class="field-error">{{ $message }}</p>
            @enderror
        @else
            <section class="messenger-no-chat">
                <h2>Chon hoi thoai</h2>
                <p>Chon hoi thoai ben trai de bat dau xu ly.</p>
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
            <header class="profile-panel-title">
                <h3>Thong tin Khach hang</h3>
                <button type="button" class="profile-contact-toggle" data-contact-toggle>Chinh sua</button>
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
                    <button type="button" class="profile-contact-toggle" data-contact-toggle>Chi tiet lien he &gt;</button>
                </div>
                <button type="button" aria-label="More profile actions">...</button>
                <div class="profile-quick-actions">
                    @if(filled($activeConversation->customer?->phone))
                        <a href="tel:{{ $activeConversation->customer->phone }}">Goi</a>
                    @else
                        <span>Chua co SDT</span>
                    @endif
                    @if(filled($activeConversation->customer?->email))
                        <a href="mailto:{{ $activeConversation->customer->email }}">Email</a>
                    @else
                        <span>Chua co email</span>
                    @endif
                </div>
            </section>

            <section class="profile-contact-summary">
                <article>
                    <span>So dien thoai</span>
                    <strong data-profile-phone>{{ $activeConversation->customer?->phone ?: 'Chua co' }}</strong>
                </article>
                <article>
                    <span>Kenh lien he</span>
                    <strong>{{ ucfirst($activeChannel) }}</strong>
                </article>
            </section>

            <section class="profile-section profile-notes" data-customer-notes data-notes-url="{{ route('crm.conversations.customer-notes.store', $activeConversation) }}">
                <header>
                    <h4>Ghi chu (<span data-note-count>{{ count($profilePanel['notes']) }}</span>) (F6)</h4>
                    <button type="button" data-notes-toggle>Xem tat ca &gt;</button>
                </header>
                <textarea rows="3" placeholder="Nhap ghi chu va an enter" data-note-input></textarea>
            </section>

            <section class="profile-section profile-customer-tags" data-profile-conversation-tags>
                <h4>Trang thai hoi thoai</h4>
                <div class="customer-tag-list" data-profile-conversation-tag-list>
                    @if(count($profilePanel['tags']) === 0)
                        <p class="profile-empty">Chua co trang thai.</p>
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

@push('scripts')
<script src="{{ asset('js/messenger/chat.js') }}?v={{ filemtime(public_path('js/messenger/chat.js')) }}" defer></script>
@endpush
@endsection
