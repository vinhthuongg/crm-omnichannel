<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'CRM Omnichannel' }}</title>
    @if($useUi ?? true)
        <link rel="stylesheet" href="{{ asset('css/ui.css') }}?v={{ filemtime(public_path('css/ui.css')) }}">
    @endif
    @stack('styles')
</head>
<body class="{{ $bodyClass ?? '' }}">
    @yield('content')
    @stack('scripts')
</body>
</html>
