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

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: var(--blue);
            text-decoration: none;
        }

        .page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 28px 20px 48px;
        }

        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 20px;
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

        .sub {
            margin: 0;
            color: var(--muted);
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 18px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--panel);
            color: var(--text);
            font: inherit;
            cursor: pointer;
        }

        .button.primary {
            border-color: var(--red);
            background: var(--red);
            color: #ffffff;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, .8fr);
            gap: 16px;
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

        .status-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .status {
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #f8fafc;
        }

        .label {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 6px;
        }

        .value {
            word-break: break-word;
        }

        .ok {
            color: var(--green);
        }

        .warn {
            color: var(--red);
        }

        .conversation {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 14px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
        }

        .conversation:last-child {
            border-bottom: 0;
        }

        .conversation-name {
            margin-bottom: 6px;
            font-size: 17px;
        }

        .meta,
        .message-line {
            color: var(--muted);
            font-size: 13px;
        }

        .message-list {
            display: grid;
            gap: 6px;
            margin-top: 10px;
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

        input,
        textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 14px;
            background: #fff;
            color: var(--text);
            font: inherit;
        }

        textarea {
            min-height: 150px;
            resize: vertical;
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

        @media (max-width: 900px) {
            .topbar,
            .grid {
                grid-template-columns: 1fr;
                display: grid;
            }

            .status-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="topbar">
            <div>
                <h1>Quản trị Chatbot</h1>
                <p class="sub">Link này dùng để xem nhanh hội thoại Botpress và gửi tài liệu mới vào luồng tri thức.</p>
            </div>
            @if ($studioUrl !== '')
                <a class="button primary" href="{{ $studioUrl }}" target="_blank" rel="noreferrer">Mở Botpress</a>
            @endif
        </header>

        @if (session('status'))
            <div class="notice">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="notice error">{{ session('error') }}</div>
        @endif

        <section class="status-grid" aria-label="Trạng thái tích hợp">
            <article class="status">
                <span class="label">Botpress</span>
                <span class="value {{ $integration['enabled'] ? 'ok' : 'warn' }}">{{ $integration['enabled'] ? 'Đang bật' : 'Đang tắt' }}</span>
            </article>
            <article class="status">
                <span class="label">API key</span>
                <span class="value {{ $integration['has_api_key'] ? 'ok' : 'warn' }}">{{ $integration['has_api_key'] ? 'Đã cấu hình' : 'Chưa có' }}</span>
            </article>
            <article class="status">
                <span class="label">Webhook ID</span>
                <span class="value">{{ $integration['webhook_id'] ?: 'Chưa có' }}</span>
            </article>
            <article class="status">
                <span class="label">Callback</span>
                <span class="value">{{ $integration['prefer_callback'] ? 'Ưu tiên callback' : 'Polling' }}</span>
            </article>
            <article class="status">
                <span class="label">Knowledge upload</span>
                <span class="value {{ $canUploadKnowledge ? 'ok' : 'warn' }}">{{ $canUploadKnowledge ? 'Sẵn sàng' : 'Chưa cấu hình' }}</span>
            </article>
        </section>

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
                        @endphp
                        <article class="conversation">
                            <div>
                                <div class="conversation-name">{{ $customer?->name ?: 'Khách hàng #'.$link->conversation_id }}</div>
                                <div class="meta">
                                    CRM #{{ $link->conversation_id }} · Botpress {{ $link->botpress_conversation_id ?: 'chưa có id' }}
                                </div>
                                <div class="message-list">
                                    @foreach (($conversation?->messages ?? collect())->sortByDesc('id')->take(3) as $message)
                                        <div class="message-line">
                                            {{ $message->senderName() }}: {{ \Illuminate\Support\Str::limit((string) $message->content, 120) }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <span class="pill">{{ optional($conversation?->last_message_at)->format('d/m H:i') ?: 'Chưa có tin' }}</span>
                        </article>
                    @empty
                        <div class="panel-body">
                            <p class="sub">Chưa có hội thoại Botpress nào được đồng bộ về CRM.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <aside class="panel">
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
                    <form method="post" action="{{ route('botpress.admin.knowledge.store', ['token' => $token]) }}">
                        @csrf
                        <label>
                            <span class="label">Tiêu đề</span>
                            <input name="title" value="{{ old('title') }}" required maxlength="160" placeholder="Ví dụ: Bảng giá Vios tháng này">
                        </label>
                        <label>
                            <span class="label">URL tài liệu hoặc website</span>
                            <input name="source_url" value="{{ old('source_url') }}" type="url" placeholder="https://...">
                        </label>
                        <label>
                            <span class="label">Nội dung ghi chú thêm</span>
                            <textarea name="content" placeholder="Dán nội dung nếu không dùng URL">{{ old('content') }}</textarea>
                        </label>
                        <button class="button primary" type="submit">Gửi vào Botpress</button>
                    </form>
                </div>
            </aside>
        </div>
    </main>
</body>
</html>
