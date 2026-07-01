@extends('layouts.app', ['title' => 'Ket noi Fanpage - CRM', 'bodyClass' => 'messenger-page'])

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/crm/facebook-pages.css') }}?v={{ filemtime(public_path('css/crm/facebook-pages.css')) }}">
@endpush

@section('content')
<div class="crm-shell" data-crm-shell>
    @include('partials.crm.chrome')

    <main class="crm-main">
        @include('partials.crm.topbar')

<section class="facebook-pages-shell">
    <section class="facebook-pages-panel">
        <header>
            <div>
                <p>Facebook Pages</p>
                <h1>Chon fanpage cua Messenger App</h1>
            </div>
            <div class="facebook-page-actions">
                <a href="{{ route('facebook.redirect') }}">
                    Ket noi Facebook
                </a>
                <a href="{{ route('crm.conversations') }}">
                    Vao CRM
                </a>
            </div>
        </header>

        @if($errors->has('facebook') || session('facebook_pages_error'))
            <p class="field-error">{{ $errors->first('facebook') ?: session('facebook_pages_error') }}</p>
        @endif

        @php($availablePageIds = collect($availablePages)->map(fn ($page) => $page->id)->all())
        @php($connectedOnlyPages = $connectedPages->reject(fn ($page) => in_array($page->page_id, $availablePageIds, true)))

        @if($availablePages || $connectedOnlyPages->isNotEmpty())
            <div class="facebook-page-list">
                @foreach($connectedOnlyPages as $connectedPage)
                    <article class="facebook-page-card">
                        <span class="thread-avatar large">
                            @if($connectedPage->page_avatar)
                                <img src="{{ $connectedPage->page_avatar }}" alt="{{ $connectedPage->page_name }}">
                            @else
                                {{ strtoupper(substr($connectedPage->page_name, 0, 1)) }}
                            @endif
                        </span>
                        <div>
                            <h2>{{ $connectedPage->page_name }}</h2>
                            <p>{{ $connectedPage->page_id }}</p>
                            <p>Da ket noi</p>
                        </div>
                        <form method="POST" action="{{ route('facebook.pages.sync-messages', $connectedPage) }}">
                            @csrf
                            <button type="submit">
                                Dong bo tin nhan
                            </button>
                        </form>
                    </article>
                @endforeach

                @foreach($availablePages as $page)
                    @php($connected = $connectedPages->has($page->id))
                    <article class="facebook-page-card">
                        <span class="thread-avatar large">
                            @if($page->avatar)
                                <img src="{{ $page->avatar }}" alt="{{ $page->name }}">
                            @else
                                {{ strtoupper(substr($page->name, 0, 1)) }}
                            @endif
                        </span>
                        <div>
                            <h2>{{ $page->name }}</h2>
                            <p>{{ $page->id }}</p>
                        </div>
                        <form method="POST" action="{{ route('facebook.connect-page') }}">
                            @csrf
                            <input type="hidden" name="page_id" value="{{ $page->id }}">
                            <button type="submit">
                                {{ $connected ? 'Cap nhat ket noi' : 'Ket noi' }}
                            </button>
                        </form>
                        @if($connected)
                            <form method="POST" action="{{ route('facebook.pages.sync-messages', $connectedPages[$page->id]) }}">
                                @csrf
                                <button type="submit">
                                    Dong bo tin nhan
                                </button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        @else
            <div class="messenger-empty">
                Chua co fanpage nao. Bam Ket noi Facebook de cap quyen va chon fanpage, hoac cau hinh Zalo OA truoc khi vao Conversations.
            </div>
        @endif
    </section>
</section>
    </main>
</div>
@endsection
