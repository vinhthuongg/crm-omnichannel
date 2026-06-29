@extends('layouts.app', ['title' => 'Ket noi Fanpage - CRM', 'bodyClass' => 'messenger-page'])

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
                    <span class="material-symbols-outlined" aria-hidden="true">add_link</span>
                    Ket noi Facebook
                </a>
                <a href="{{ route('crm.conversations') }}">
                    <span class="material-symbols-outlined" aria-hidden="true">forum</span>
                    Vao CRM
                </a>
            </div>
        </header>

        @if($errors->has('facebook') || session('facebook_pages_error'))
            <p class="field-error">{{ $errors->first('facebook') ?: session('facebook_pages_error') }}</p>
        @endif

        @if($availablePages)
            <div class="facebook-page-list">
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
                                <span class="material-symbols-outlined" aria-hidden="true">{{ $connected ? 'sync' : 'link' }}</span>
                                {{ $connected ? 'Cap nhat ket noi' : 'Ket noi' }}
                            </button>
                        </form>
                        @if($connected)
                            <form method="POST" action="{{ route('facebook.pages.sync-messages', $connectedPages[$page->id]) }}">
                                @csrf
                                <button type="submit">
                                    <span class="material-symbols-outlined" aria-hidden="true">cloud_sync</span>
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
