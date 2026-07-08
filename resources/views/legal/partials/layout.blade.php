<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Toyota CRM' }}</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f3f4f6;
            --panel: #ffffff;
            --text: #111827;
            --muted: #667085;
            --border: #d9dee8;
            --accent: #c8102e;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.65;
        }

        main {
            width: min(920px, calc(100% - 32px));
            margin: 48px auto;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 34px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .06);
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 4vw, 40px);
            line-height: 1.15;
            letter-spacing: 0;
        }

        h2 {
            margin: 28px 0 10px;
            font-size: 20px;
            letter-spacing: 0;
        }

        p, li {
            color: var(--muted);
            font-size: 16px;
        }

        ul {
            padding-left: 22px;
        }

        a {
            color: var(--accent);
            text-decoration: none;
        }

        .meta {
            margin: 0 0 24px;
            color: var(--muted);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            color: var(--accent);
        }

        .brand img {
            width: 36px;
            height: 36px;
            object-fit: contain;
        }

        @media (max-width: 640px) {
            main {
                width: min(100% - 20px, 920px);
                margin: 16px auto;
                padding: 22px;
                border-radius: 12px;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="brand">
            <img src="{{ asset('assets/logo.png') }}" alt="Toyota">
            <span>Omnichannel CRM</span>
        </div>

        {{ $slot }}
    </main>
</body>
</html>
