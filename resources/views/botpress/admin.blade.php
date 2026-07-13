<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quản trị Chatbot</title>
    <style>
        :root {
            --bg: #f3f4f6;
            --panel: #ffffff;
            --line: #d9dee8;
            --text: #0f172a;
            --muted: #64748b;
            --blue: #2563eb;
            --red: #d90429;
            --green: #15803d;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1360px;
            margin: 0 auto;
            padding: 28px 20px 48px;
        }

        .topbar {
            margin-bottom: 18px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(26px, 4vw, 40px);
            font-weight: 600;
            letter-spacing: 0;
        }

        h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 500;
        }

        .sub, .meta, .message-line {
            color: var(--muted);
        }

        .notice {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: #eff6ff;
        }

        .notice.error {
            background: #fff1f2;
            border-color: #fecdd3;
            color: #991b1b;
        }

        .grid {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr) 360px;
            gap: 16px;
            align-items: start;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 12px 34px rgba(15, 23, 42, .05);
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
        }

        .panel-body {
            padding: 18px 20px;
        }

        .conversation {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
            transition: background .15s ease;
        }

        .conversation:hover,
        .conversation.active {
            background: #eff6ff;
        }

        .conversation:last-child {
            border-bottom: 0;
        }

        .conversation-name {
            margin-bottom: 6px;
            font-size: 16px;
            font-weight: 500;
        }

        .message-line {
            margin-top: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 5px 10px;
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .chat-header {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
        }

        .chat-scroll {
            max-height: 68vh;
            overflow: auto;
            padding: 18px 18px 22px;
            background:
                radial-gradient(circle, rgba(37, 99, 235, .12) 1px, transparent 1px) 0 0 / 22px 22px,
                #fbfdff;
        }

        .bubble-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            margin-bottom: 10px;
        }

        .bubble-row.out {
            justify-content: flex-end;
        }

        .bubble-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            flex: 0 0 28px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 13px;
        }

        .bubble-row.out .bubble-avatar {
            order: 2;
            background: #fee2e2;
            color: #be123c;
        }

        .bubble {
            width: fit-content;
            max-width: min(520px, 72%);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 8px 10px;
            background: #fff;
            line-height: 1.38;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font-size: 14px;
        }

        .bubble-row.out .bubble {
            background: #eaf2ff;
            border-color: #c7d8f8;
        }

        .bubble-row.whisper .bubble {
            background: #fff7ed;
            border-color: #fed7aa;
        }

        .bubble-author {
            display: block;
            margin-bottom: 3px;
            color: var(--muted);
            font-size: 11px;
        }

        .bubble-time {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 11px;
            text-align: right;
        }

        .kb-list {
            display: grid;
            gap: 12px;
        }

        .kb-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            background: #f8fafc;
        }

        .kb-card strong {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        form {
            display: grid;
            gap: 12px;
        }

        input, textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 14px;
            background: #fff;
            color: var(--text);
            font: inherit;
        }

        textarea {
            min-height: 140px;
            resize: vertical;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 18px;
            border: 1px solid var(--red);
            border-radius: 10px;
            background: var(--red);
            color: #fff;
            font: inherit;
            cursor: pointer;
        }

        @media (max-width: 1100px) {
            .grid {
                grid-template-columns: 320px minmax(0, 1fr);
            }

            .side-panel {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 760px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .chat-scroll {
                max-height: none;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="topbar">
            <h1>Quản trị Chatbot</h1>
            <p class="sub">Xem hội thoại chatbot và gửi thêm tài liệu cho kho tri thức.</p>
        </header>

        @if (session('status'))
            <div class="notice">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="notice error">{{ session('error') }}</div>
        @endif

        <div class="grid">
            <section class="panel">
                <div class="panel-header">
                    <h2>Conversations</h2>
                    <span class="pill">{{ $conversations->count() }} hội thoại</span>
                </div>
                <div>
                    @forelse ($conversations as $link)
                        @php
                            $conversation = $link->conversation;
                            $customer = $conversation?->customer;
                            $lastMessage = ($conversation?->messages ?? collect())->first();
                            $isActive = $selectedConversation?->id === $conversation?->id;
                        @endphp
                        <a class="conversation {{ $isActive ? 'active' : '' }}" href="{{ route('botpress.admin.conversations.show', ['conversation' => $link->conversation_id]) }}">
                            <div>
                                <div class="conversation-name">{{ $customer?->name ?: 'Khách hàng #'.$link->conversation_id }}</div>
                                <div class="meta">{{ $conversation?->status ?: 'Đang xử lý' }}</div>
                                <div class="message-line">
                                    {{ $lastMessage ? $lastMessage->senderName().': '.\Illuminate\Support\Str::limit((string) $lastMessage->content, 90) : 'Chưa có tin nhắn' }}
                                </div>
                            </div>
                            <span class="pill">{{ optional($conversation?->last_message_at)->format('d/m H:i') ?: 'Mới' }}</span>
                        </a>
                    @empty
                        <div class="panel-body">
                            <p class="sub">Chưa có hội thoại nào được đồng bộ.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="panel">
                <div class="panel-header chat-header">
                    <div>
                        <h2>{{ $selectedConversation?->customer?->name ?: 'Chọn hội thoại' }}</h2>
                        @if ($selectedConversation)
                            <p class="sub">Trạng thái: {{ $selectedConversation->status }} · {{ $selectedConversation->messages->count() }} tin gần nhất</p>
                        @else
                            <p class="sub">Bấm một hội thoại bên trái để xem chi tiết.</p>
                        @endif
                    </div>
                    @if ($selectedConversation?->assignee)
                        <span class="pill">{{ $selectedConversation->assignee->name }}</span>
                    @endif
                </div>
                <div class="chat-scroll">
                    @if ($selectedConversation)
                        @foreach ($selectedConversation->messages->sortBy('id') as $message)
                            @php
                                $isOutbound = in_array($message->sender_type, ['system', 'user'], true);
                                $isWhisper = $message->message_type === 'whisper' || $message->channel === 'internal';
                            @endphp
                            <div class="bubble-row {{ $isOutbound ? 'out' : '' }} {{ $isWhisper ? 'whisper' : '' }}">
                                <span class="bubble-avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($message->senderName(), 0, 1)) }}</span>
                                <div class="bubble">
                                    <span class="bubble-author">{{ $message->senderName() }}</span>
                                    {{ $message->content ?: '[Tệp đính kèm]' }}
                                    <span class="bubble-time">{{ optional($message->created_at)->format('H:i d/m/Y') }}</span>
                                </div>
                            </div>
                        @endforeach
                    @else
                        <p class="sub">Chưa chọn hội thoại.</p>
                    @endif
                </div>
            </section>

            <aside class="panel side-panel">
                <div class="panel-header">
                    <h2>Knowledge Bases</h2>
                </div>
                <div class="panel-body">
                    <div class="kb-list">
                        @foreach ($knowledgeBases as $kb)
                            <article class="kb-card">
                                <strong>{{ $kb['name'] }}</strong>
                                <div class="meta">{{ $kb['status'] }}</div>
                                <p class="sub">{{ $kb['description'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>

                <div class="panel-header">
                    <h2>Thêm tài liệu / web</h2>
                </div>
                <div class="panel-body">
                    <form method="post" action="{{ route('botpress.admin.knowledge.store') }}">
                        @csrf
                        <label>
                            <span class="meta">Tiêu đề</span>
                            <input name="title" value="{{ old('title') }}" required maxlength="160" placeholder="Ví dụ: Bảng giá Vios tháng này">
                        </label>
                        <label>
                            <span class="meta">URL tài liệu hoặc website</span>
                            <input name="source_url" value="{{ old('source_url') }}" type="url" placeholder="https://...">
                        </label>
                        <label>
                            <span class="meta">Nội dung ghi chú thêm</span>
                            <textarea name="content" placeholder="Dán nội dung nếu không dùng URL">{{ old('content') }}</textarea>
                        </label>
                        <button class="button" type="submit">Gửi tài liệu</button>
                    </form>
                </div>
            </aside>
        </div>
    </main>
</body>
</html>
