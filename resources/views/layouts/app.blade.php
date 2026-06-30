<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'CRM Omnichannel' }}</title>
    @if($useUi ?? true)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap">
        <link rel="stylesheet" href="{{ asset('css/ui.css') }}?v={{ filemtime(public_path('css/ui.css')) }}">
        <link rel="stylesheet" href="{{ asset('css/crm-professional.css') }}?v={{ filemtime(public_path('css/crm-professional.css')) }}">
    @endif
    @stack('styles')
</head>
<body class="{{ $bodyClass ?? '' }}">
    @yield('content')
    @stack('scripts')
</body>
</html>
