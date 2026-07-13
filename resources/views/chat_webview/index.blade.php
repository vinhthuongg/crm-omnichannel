@php
    use Illuminate\Support\Str;
    use Modules\Message\Models\Message;

    $customer = $selectedConversation?->customer;
    $selectedId = $selectedConversation?->id;

    $statusLabels = [
        'closed' => 'Đóng',
        'waiting_customer' => 'Đợi khách trả lời',
        'bot_consulting' => 'Bot đang tư vấn',
        'waiting_agent' => 'Khách đợi rep tin nhắn',
        'waiting' => 'Khách đợi rep tin nhắn',
        'in_progress' => 'Đang xử lý',
    ];

    $messageLabel = function (Message $message): string {
        return match ($message->sender_type) {
            'customer' => 'Khách hàng',
            'system' => 'Bot',
            default => $message->message_type === 'whisper' || $message->channel === 'internal' ? 'Thì thầm' : 'Nhân viên',
        };
    };

    $messagePreview = function (?Message $message): string {
        if (! $message) {
            return 'Chưa có tin nhắn';
        }

        $content = trim((string) $message->content);
        if ($content !== '') {
            return Str::limit($content, 58);
        }

        $attachment = collect($message->attachments ?? [])->first();
        $type = strtolower((string) data_get($attachment, 'type', 'file'));

        return match ($type) {
            'image' => '[Hình ảnh]',
            'video' => '[Video]',
            'audio' => '[Âm thanh]',
            default => '[Tệp đính kèm]',
        };
    };

    $attachmentUrl = function (array $attachment): ?string {
        return data_get($attachment, 'url')
            ?: data_get($attachment, 'payload.url')
            ?: data_get($attachment, 'payload.image_data.url')
            ?: data_get($attachment, 'payload.video_data.url')
            ?: data_get($attachment, 'payload.audio_data.url');
    };
@endphp

<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Webview Hội Thoại</title>
    <style>
        :root {
            --bg: #eef2f7;
            --panel: #ffffff;
            --line: #d9e1ec;
            --text: #0f172a;
            --muted: #64748b;
            --blue: #2563eb;
            --red: #d1112b;
            --soft-blue: #eaf2ff;
            --bubble: #f8fafc;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background: var(--bg);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        a { color: inherit; text-decoration: none; }

        .chat-webview {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr) 320px;
            gap: 12px;
            height: 100vh;
            padding: 12px;
        }

        .panel {
            min-width: 0;
            overflow: hidden;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 14px 36px rgba(15, 23, 42, .06);
        }

        .sidebar,
        .profile {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px;
            border-bottom: 1px solid var(--line);
        }
        .panel-title {
            margin: 0;
            font-size: 18px;
            font-weight: 500;
        }

        .conversation-list {
            overflow-y: auto;
            padding: 8px;
        }
        .conversation-item {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            padding: 10px;
            border-radius: 14px;
            border: 1px solid transparent;
        }
        .conversation-item + .conversation-item { margin-top: 6px; }
        .conversation-item.active {
            background: var(--soft-blue);
            border-color: #bfdbfe;
        }
        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            overflow: hidden;
            color: #174ea6;
            background: #dbeafe;
            flex: 0 0 auto;
        }
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .name {
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-size: 15px;
            font-weight: 500;
        }
        .preview {
            margin-top: 3px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            color: var(--muted);
            font-size: 13px;
        }
        .time {
            color: var(--blue);
            font-size: 12px;
            white-space: nowrap;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 4px 8px;
            border-radius: 999px;
            color: var(--red);
            background: #ffe4e9;
            font-size: 12px;
        }

        .chat {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr) auto;
            min-height: 0;
        }
        .chat-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
            background: var(--panel);
        }
        .chat-person {
            min-width: 0;
            flex: 1;
        }
        .chat-person .name {
            font-size: 18px;
        }
        .assignment {
            margin-top: 2px;
            color: #047857;
            font-size: 13px;
        }
        .readonly {
            color: var(--muted);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 13px;
            white-space: nowrap;
        }

        .messages {
            overflow-y: auto;
            padding: 18px;
            background-color: #fbfdff;
            background-image: radial-gradient(#dbe7f7 1px, transparent 1px);
            background-size: 18px 18px;
        }
        .message-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            margin: 12px 0;
        }
        .message-row.mine {
            justify-content: flex-end;
        }
        .message-row.mine .avatar {
            display: none;
        }
        .bubble-wrap {
            max-width: min(620px, 78%);
        }
        .bubble {
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--bubble);
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .mine .bubble {
            background: #e9f1ff;
            border-color: #cfe0ff;
        }
        .whisper .bubble {
            background: #fff7ed;
            border-color: #fed7aa;
        }
        .bubble small {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 12px;
        }
        .meta {
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
        }
        .mine .meta { text-align: right; }
        .attachment {
            display: block;
            max-width: 360px;
            margin-top: 8px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--line);
            background: #fff;
        }
        .attachment img,
        .attachment video {
            display: block;
            width: 100%;
            max-height: 320px;
            object-fit: cover;
        }
        .attachment-file {
            padding: 10px 12px;
            color: var(--blue);
        }

        .composer-placeholder {
            padding: 12px 16px;
            border-top: 1px solid var(--line);
            background: var(--panel);
        }
        .composer-placeholder div {
            min-height: 52px;
            padding: 15px 16px;
            color: var(--muted);
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #f8fafc;
        }

        .empty {
            display: grid;
            place-items: center;
            padding: 24px;
            color: var(--muted);
            text-align: center;
        }
        .profile-body {
            overflow-y: auto;
            padding: 16px;
        }
        .profile-card {
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #f8fafc;
        }
        .profile-main {
            display: grid;
            justify-items: center;
            gap: 10px;
            padding: 18px 0;
            text-align: center;
        }
        .profile-main .avatar {
            width: 72px;
            height: 72px;
            font-size: 22px;
        }
        .section-title {
            margin: 18px 0 8px;
            color: var(--muted);
            font-size: 12px;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .info-line {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #e8eef6;
        }
        .info-line:last-child { border-bottom: 0; }
        .info-line span:first-child {
            color: var(--muted);
            font-size: 13px;
        }
        .tag-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .tag {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            color: #0f172a;
            background: #e2e8f0;
            font-size: 12px;
        }

        @media (max-width: 1100px) {
            .chat-webview {
                grid-template-columns: 280px minmax(0, 1fr);
            }
            .profile {
                display: none;
            }
        }

        @media (max-width: 760px) {
            .chat-webview {
                display: flex;
                flex-direction: column;
                height: auto;
                min-height: 100vh;
                padding: 8px;
            }
            .sidebar {
                max-height: 34vh;
            }
            .chat {
                min-height: 66vh;
            }
            .bubble-wrap {
                max-width: 88%;
            }
            .readonly {
                display: none;
            }
        }
    </style>
</head>
<body>
    <main class="chat-webview">
        <aside class="panel sidebar">
            <div class="panel-head">
                <h1 class="panel-title">Hội thoại</h1>
                <span class="time">{{ $conversations->count() }} cuộc</span>
            </div>
            <div class="conversation-list">
                @forelse ($conversations as $conversation)
                    @php
                        $itemCustomer = $conversation->customer;
                        $latestMessage = $conversation->messages->first();
                        $isActive = (int) $conversation->id === (int) $selectedId;
                    @endphp
                    <a class="conversation-item {{ $isActive ? 'active' : '' }}" href="{{ route('chat-webview.show', [$token, $conversation]) }}">
                        <span class="avatar">
                            @if ($itemCustomer?->avatar)
                                <img src="{{ $itemCustomer->avatar }}" alt="">
                            @else
                                {{ Str::upper(Str::substr($itemCustomer?->name ?: 'K', 0, 1)) }}
                            @endif
                        </span>
                        <span style="min-width:0">
                            <span class="name">{{ $itemCustomer?->name ?: 'Khách hàng' }}</span>
                            <span class="preview">{{ $messagePreview($latestMessage) }}</span>
                            @if ($conversation->tags->isNotEmpty())
                                <span class="badge">{{ $conversation->tags->first()->name }}</span>
                            @endif
                        </span>
                        <span class="time">{{ $conversation->last_message_at?->diffForHumans() }}</span>
                    </a>
                @empty
                    <div class="empty">Chưa có hội thoại.</div>
                @endforelse
            </div>
        </aside>

        <section class="panel chat">
            @if ($selectedConversation)
                <header class="chat-head">
                    <span class="avatar">
                        @if ($customer?->avatar)
                            <img src="{{ $customer->avatar }}" alt="">
                        @else
                            {{ Str::upper(Str::substr($customer?->name ?: 'K', 0, 1)) }}
                        @endif
                    </span>
                    <div class="chat-person">
                        <div class="name">{{ $customer?->name ?: 'Khách hàng' }}</div>
                        <div class="assignment">Phụ trách: {{ $selectedConversation->assignee?->name ?: 'Chưa gán nhân viên' }}</div>
                    </div>
                    <span class="readonly">Webview chỉ xem</span>
                </header>

                <div class="messages">
                    @forelse ($messages as $message)
                        @php
                            $isMine = $message->sender_type !== 'customer';
                            $isWhisper = $message->message_type === 'whisper' || $message->channel === 'internal';
                        @endphp
                        <article class="message-row {{ $isMine ? 'mine' : '' }} {{ $isWhisper ? 'whisper' : '' }}">
                            <span class="avatar">
                                @if ($customer?->avatar)
                                    <img src="{{ $customer->avatar }}" alt="">
                                @else
                                    {{ Str::upper(Str::substr($customer?->name ?: 'K', 0, 1)) }}
                                @endif
                            </span>
                            <div class="bubble-wrap">
                                <div class="bubble">
                                    <small>{{ $messageLabel($message) }}</small>
                                    @if (filled($message->content))
                                        {{ $message->content }}
                                    @endif

                                    @foreach (($message->attachments ?? []) as $attachment)
                                        @php
                                            $url = $attachmentUrl($attachment);
                                            $type = strtolower((string) data_get($attachment, 'type', 'file'));
                                            $mime = strtolower((string) data_get($attachment, 'mime_type', ''));
                                            $isImage = $type === 'image' || str_starts_with($mime, 'image/');
                                            $isVideo = $type === 'video' || str_starts_with($mime, 'video/');
                                        @endphp
                                        @if ($url)
                                            <a class="attachment" href="{{ $url }}" target="_blank" rel="noopener">
                                                @if ($isImage)
                                                    <img src="{{ $url }}" alt="Hình ảnh">
                                                @elseif ($isVideo)
                                                    <video src="{{ $url }}" controls></video>
                                                @else
                                                    <span class="attachment-file">{{ data_get($attachment, 'name', 'Tệp đính kèm') }}</span>
                                                @endif
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                                <div class="meta">{{ $message->created_at?->format('H:i d/m/Y') }} - {{ $message->channel === 'internal' ? 'Nội bộ' : ucfirst((string) $message->channel) }}</div>
                            </div>
                        </article>
                    @empty
                        <div class="empty">Cuộc hội thoại này chưa có tin nhắn.</div>
                    @endforelse
                </div>

                <footer class="composer-placeholder">
                    <div>Link public chỉ dùng để xem hội thoại. Vui lòng trả lời khách trong CRM.</div>
                </footer>
            @else
                <div class="empty">Chọn một hội thoại để xem chi tiết.</div>
            @endif
        </section>

        <aside class="panel profile">
            <div class="panel-head">
                <h2 class="panel-title">Thông tin khách hàng</h2>
            </div>
            @if ($selectedConversation)
                <div class="profile-body">
                    <div class="profile-main">
                        <span class="avatar">
                            @if ($customer?->avatar)
                                <img src="{{ $customer->avatar }}" alt="">
                            @else
                                {{ Str::upper(Str::substr($customer?->name ?: 'K', 0, 1)) }}
                            @endif
                        </span>
                        <div>
                            <div class="name">{{ $customer?->name ?: 'Khách hàng' }}</div>
                            <div class="preview">{{ $customer?->is_potential ? 'Khách hàng tiềm năng' : 'Khách hàng' }}</div>
                        </div>
                    </div>

                    <div class="section-title">Thông tin</div>
                    <div class="profile-card">
                        <div class="info-line"><span>Số điện thoại</span><span>{{ $customer?->phone ?: 'Chưa có' }}</span></div>
                        <div class="info-line"><span>Email</span><span>{{ $customer?->email ?: 'Chưa có' }}</span></div>
                        <div class="info-line"><span>Trạng thái</span><span>{{ $statusLabels[$selectedConversation->status] ?? $selectedConversation->status }}</span></div>
                    </div>

                    <div class="section-title">Mục quan tâm</div>
                    <div class="tag-row">
                        @forelse ($customer?->tags ?? collect() as $tag)
                            <span class="tag" style="background: {{ $tag->color }}22; color: {{ $tag->color }}">{{ $tag->name }}</span>
                        @empty
                            <span class="preview">Chưa có mục quan tâm.</span>
                        @endforelse
                    </div>

                    <div class="section-title">Nhãn hội thoại</div>
                    <div class="tag-row">
                        @forelse ($selectedConversation->tags as $tag)
                            <span class="tag" style="background: {{ $tag->color }}22; color: {{ $tag->color }}">{{ $tag->name }}</span>
                        @empty
                            <span class="preview">Chưa có nhãn hội thoại.</span>
                        @endforelse
                    </div>
                </div>
            @else
                <div class="empty">Chưa chọn hội thoại.</div>
            @endif
        </aside>
    </main>
</body>
</html>
