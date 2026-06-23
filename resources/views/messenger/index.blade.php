@extends('layouts.app', ['title' => 'Messenger - CRM', 'bodyClass' => 'messenger-page'])

@section('content')
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

        <div class="messenger-shell">
            <aside class="messenger-list">
                <div class="messenger-list-head">
                    <div>
                        <h1>All Chat</h1>
                    </div>
                </div>

        <form class="messenger-search" method="GET" action="{{ route('crm.conversations') }}">
            <input type="search" name="q" placeholder="Tim kiem tren Messenger" value="{{ $filters['search'] }}">
            <button type="submit">Search</button>
        </form>

        <div class="messenger-thread-list">
            @forelse($conversations as $conversation)
                @php($lastMessage = $conversation->messages->first())
                @php($isActive = $activeConversation?->id === $conversation->id)
                <a class="messenger-thread {{ $isActive ? 'active' : '' }}" href="{{ route('crm.conversations.show', $conversation) }}" data-thread-conversation-id="{{ $conversation->id }}">
                    <span class="thread-avatar">
                        @if($conversation->customer?->avatar)
                            <img src="{{ $conversation->customer->avatar }}" alt="{{ $conversation->customer?->name ?? 'Customer' }}">
                        @else
                            {{ strtoupper(substr($conversation->customer?->name ?? 'C', 0, 1)) }}
                        @endif
                    </span>
                    <span class="thread-body">
                        <strong>{{ $conversation->customer?->name ?? 'Customer' }}</strong>
                        <small data-thread-last-message>{{ $lastMessage?->content ?? 'Chua co tin nhan' }}</small>
                    </span>
                    <span class="thread-meta" data-thread-meta>{{ $conversation->last_message_at?->diffForHumans() }}</span>
                </a>
            @empty
                <div class="messenger-empty">No matching conversations found.</div>
            @endforelse
        </div>
    </aside>

    <section class="messenger-chat">
        @if($activeConversation)
            @php($reverb = config('broadcasting.connections.reverb'))
            @php($reverbPublicHost = env('REVERB_PUBLIC_HOST'))
            @php($reverbPublicPort = env('REVERB_PUBLIC_PORT'))
            @php($reverbPublicScheme = match (env('REVERB_PUBLIC_SCHEME')) {
                'https' => 'wss',
                'http' => 'ws',
                default => env('REVERB_PUBLIC_SCHEME'),
            })
            <header class="messenger-chat-head">
                <div class="chat-contact">
                    <span class="thread-avatar large">
                        @if($activeConversation->customer?->avatar)
                            <img src="{{ $activeConversation->customer->avatar }}" alt="{{ $activeConversation->customer?->name ?? 'Customer' }}">
                        @else
                            {{ strtoupper(substr($activeConversation->customer?->name ?? 'C', 0, 1)) }}
                        @endif
                    </span>
                    <div>
                        <h2>{{ $activeConversation->customer?->name ?? 'Customer' }}</h2>
                        <p>{{ $activeConversation->assignee?->name ? 'Phu trach: '.$activeConversation->assignee->name : 'Chua gan nhan vien' }}</p>
                    </div>
                </div>
                <nav class="chat-actions" aria-label="Conversation actions">
                    <a href="{{ route('crm.customers', ['q' => $activeConversation->customer?->name]) }}">Info</a>
                    <a href="{{ route('crm.channels', ['channel' => $activeChannel]) }}">{{ ucfirst($activeChannel) }}</a>
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                </nav>
            </header>

            <section
                class="messenger-timeline"
                aria-label="Messages"
                data-messenger-timeline
                data-conversation-id="{{ $activeConversation->id }}"
                data-current-user-id="{{ $currentUser->id }}"
                data-last-message-id="{{ $messages->last()['id'] ?? 0 }}"
                data-poll-url="{{ route('crm.conversations.messages.index', $activeConversation) }}"
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
                    @continue(blank($message['content']) && empty($message['attachments']))
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
                            @if(filled($message['content']))
                                <div class="message-bubble">
                                    <span class="message-sender">{{ $message['sender_name'] }}</span>
                                    <p>{{ $message['content'] }}</p>
                                </div>
                            @endif
                            @if(! empty($message['attachments']))
                                <div class="message-attachments">
                                    @foreach($message['attachments'] as $attachment)
                                        @php($attachmentType = strtolower((string) ($attachment['type'] ?? '')))
                                        @php($attachmentMimeType = strtolower((string) ($attachment['mime_type'] ?? '')))
                                        @php($attachmentUrl = (string) ($attachment['url'] ?? ''))
                                        @php($attachmentPath = strtolower((string) parse_url($attachmentUrl, PHP_URL_PATH)))
                                        @php($isVisualAttachment = in_array($attachmentType, ['image', 'sticker'], true) || str_starts_with($attachmentMimeType, 'image/') || preg_match('/\.(png|jpe?g|gif|webp|bmp|avif)$/', $attachmentPath))
                                        @php($stickerId = (string) data_get($attachment, 'payload.sticker_id', ''))
                                        @php($isEmojiAttachment = in_array($stickerId, ['369239263222822'], true))
                                        @if($isVisualAttachment)
                                            <a class="message-image-link {{ $isEmojiAttachment ? 'is-emoji' : 'is-sticker' }}" href="{{ $attachment['url'] ?? '#' }}" target="_blank" rel="noopener">
                                                <img src="{{ $attachment['url'] ?? '#' }}" alt="{{ $attachment['name'] ?? 'Attachment' }}" loading="lazy">
                                            </a>
                                        @else
                                            <a class="message-file-link" href="{{ $attachment['url'] ?? '#' }}" target="_blank" rel="noopener">
                                                {{ $attachment['name'] ?? 'Attachment' }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            <time>{{ $message['created_at']?->format('H:i') }} - {{ ucfirst($message['channel']) }}</time>
                        </div>
                    </article>
                @endforeach
            </section>

            <form class="messenger-composer" method="POST" action="{{ route('crm.conversations.messages.store', $activeConversation) }}" enctype="multipart/form-data" data-upload-url="{{ route('crm.conversations.attachments.store', $activeConversation) }}" data-messenger-composer>
                @csrf
                <input type="hidden" name="channel" value="{{ $activeChannel }}">
                <label class="composer-file-button" title="Tai file len">
                    +
                    <input type="file" name="attachments[]" multiple data-composer-files>
                </label>
                <input type="text" name="content" placeholder="Aa" autocomplete="off">
                <button type="submit">Send</button>
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
        </div>
    </main>
</div>

<script>
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
        document.querySelector('[data-crm-shell]')?.classList.toggle('sidebar-collapsed');
    });

    const timeline = document.querySelector('[data-messenger-timeline]');
    const composer = document.querySelector('[data-messenger-composer]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    let attachmentUpload = {
        files: [],
        promise: Promise.resolve([]),
        uploaded: [],
    };

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
        const avatar = isMine ? '' : `<span class="thread-avatar mini">${avatarHtml(message.sender_avatar, message.sender_name || 'C')}</span>`;
        const status = messageStatusText(message);
        const content = String(message.content || '').trim();
        const attachments = Array.isArray(message.attachments) && message.attachments.length
            ? `<div class="message-attachments">${message.attachments.map(function (attachment) {
                return attachmentHtml(attachment);
            }).join('')}</div>`
            : '';
        const textBubble = content
            ? `<div class="message-bubble">
                    <span class="message-sender">${escapeHtml(message.sender_name || 'Unknown')}</span>
                    <p>${escapeHtml(content)}</p>
                </div>`
            : '';

        return `
            ${avatar}
            <div class="message-stack">
                ${textBubble}
                ${attachments}
                <time>${escapeHtml(messageTime(message))} - ${escapeHtml((message.channel || '').charAt(0).toUpperCase() + (message.channel || '').slice(1))}${status ? ` - ${escapeHtml(status)}` : ''}</time>
            </div>
        `;
    }

    function avatarHtml(url, name) {
        if (url) {
            return `<img src="${escapeHtml(url)}" alt="${escapeHtml(name || 'Customer')}">`;
        }

        return escapeHtml((name || 'C').slice(0, 1).toUpperCase());
    }

    function hasRenderableMessage(message) {
        const content = String(message?.content || '').trim();
        const attachments = Array.isArray(message?.attachments) ? message.attachments : [];

        return Boolean(content || attachments.length);
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
        const stickerId = String(attachment?.payload?.sticker_id || '');
        const visualClass = stickerId === '369239263222822' ? 'is-emoji' : 'is-sticker';

        if (isVisualAttachment) {
            return `<a class="message-image-link ${visualClass}" href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${name}" loading="lazy"></a>`;
        }

        return `<a class="message-file-link" href="${url}" target="_blank" rel="noopener">${name}</a>`;
    }

    function createClientMessageId() {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }

        return `crm-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function appendPendingMessage(content, channel, files, clientMessageId) {
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

    function updateThreadPreview(message) {
        const thread = document.querySelector(`[data-thread-conversation-id="${message.conversation_id}"]`);

        if (!thread) {
            return;
        }

        const preview = thread.querySelector('[data-thread-last-message]');
        const meta = thread.querySelector('[data-thread-meta]');

        if (preview) {
            preview.textContent = message.content || '';
        }

        if (meta) {
            meta.textContent = 'Vua xong';
        }
    }

    function upsertThread(message) {
        const list = document.querySelector('.messenger-thread-list');

        if (!list || !message?.conversation_id) {
            return;
        }

        let thread = list.querySelector(`[data-thread-conversation-id="${message.conversation_id}"]`);

        if (!thread) {
            thread = document.createElement('a');
            thread.className = 'messenger-thread';
            thread.href = message.conversation_url || `/conversations/${encodeURIComponent(message.conversation_id)}`;
            thread.dataset.threadConversationId = message.conversation_id;
            const customerName = message.conversation_customer_name || message.sender_name || 'Customer';
            const customerAvatar = message.conversation_customer_avatar || message.sender_avatar;
            thread.innerHTML = `
                <span class="thread-avatar">${avatarHtml(customerAvatar, customerName)}</span>
                <span class="thread-body">
                    <strong>${escapeHtml(customerName)}</strong>
                    <small data-thread-last-message></small>
                </span>
                <span class="thread-meta" data-thread-meta></span>
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
        (payload.data || []).forEach(appendMessage);
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
            const response = await fetch(timeline.dataset.broadcastAuthUrl, {
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
            appendMessage(message);
        }
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
            !timeline?.dataset.reverbKey ||
            !timeline?.dataset.reverbHost ||
            !window.WebSocket
        ) {
            return false;
        }

        closeMessageSocket();

        const port = timeline.dataset.reverbPort ? `:${timeline.dataset.reverbPort}` : '';
        const socketUrl = `${timeline.dataset.reverbScheme}://${timeline.dataset.reverbHost}${port}/app/${encodeURIComponent(timeline.dataset.reverbKey)}?protocol=7&client=crm-web&version=1.0&flash=false`;
        console.info('CRM messenger websocket connecting:', socketUrl);
        messageSocket = new WebSocket(socketUrl);
        const socket = messageSocket;

        socket.addEventListener('message', async function (event) {
            try {
                const payload = JSON.parse(event.data);

                if (payload.event === 'pusher:connection_established') {
                    const connection = parsePusherData(payload.data);
                    await subscribeToChannels(socket, connection.socket_id, [
                        timeline.dataset.broadcastChannel,
                        timeline.dataset.inboxBroadcastChannel,
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
    }

    if (timeline) {
        timeline.scrollTop = timeline.scrollHeight;
        startRealtime();

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                fetchNewMessages().finally(function () {
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
    }

    composer?.addEventListener('submit', async function (event) {
        event.preventDefault();

        const input = composer.querySelector('input[name="content"]');
        const button = composer.querySelector('button[type="submit"]');
        const fileInput = composer.querySelector('[data-composer-files]');
        const fileList = composer.querySelector('[data-composer-file-list]');
        const content = String(input?.value || '').trim();
        const channel = String(composer.querySelector('input[name="channel"]')?.value || '');
        const files = fileInput?.files || [];

        if (!content && files.length === 0) {
            return;
        }

        const clientMessageId = createClientMessageId();
        const pendingId = appendPendingMessage(content, channel, files, clientMessageId);

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
