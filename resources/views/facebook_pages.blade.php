@extends('layouts.app', ['title' => 'Ket noi Fanpage - CRM', 'bodyClass' => 'messenger-page'])

@section('content')
<main class="facebook-pages-shell">
    <section class="facebook-pages-panel">
        <header>
            <div>
                <p>Facebook Pages</p>
                <h1>Chon fanpage cua Messenger App</h1>
            </div>
            <div class="facebook-page-actions">
                <a href="{{ route('facebook.redirect') }}">Ket noi Facebook</a>
                <a href="{{ route('crm.conversations') }}">Vao CRM</a>
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
                            <button type="submit">{{ $connected ? 'Cap nhat ket noi' : 'Ket noi' }}</button>
                        </form>
                        @if($connected)
                            <form method="POST" action="{{ route('facebook.pages.sync-messages', $connectedPages[$page->id]) }}">
                                @csrf
                                <button type="submit">Dong bo tin nhan</button>
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
</main>
@endsection
