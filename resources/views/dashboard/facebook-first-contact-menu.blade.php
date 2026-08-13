@php($menu = old('elements') ? ['enabled' => old('enabled'), 'text' => old('text'), 'phone_enabled' => old('phone_enabled'), 'phone_text' => old('phone_text'), 'elements' => old('elements')] : $facebookFirstContactMenu)

<article class="settings-card messenger-menu-settings-card">
    <header>
        <div>
            <h2>Menu Messenger đầu tiên</h2>
            <p>Tùy chỉnh câu dẫn, hình ảnh, nút và payload gửi một lần khi khách lần đầu nhắn cho Page.</p>
        </div>
    </header>

    <form method="POST" action="{{ route('crm.settings.facebook-first-contact-menu.update') }}">
        @csrf
        @method('PATCH')

        <label class="messenger-menu-toggle">
            <input type="checkbox" name="enabled" value="1" @checked((bool) data_get($menu, 'enabled'))>
            <span>Bật menu chào khách lần đầu</span>
        </label>

        <label class="messenger-menu-field">
            <span>Câu dẫn trước carousel</span>
            <textarea name="text" rows="2" maxlength="1024">{{ data_get($menu, 'text') }}</textarea>
            @error('text')<small>{{ $message }}</small>@enderror
        </label>

        <section class="messenger-phone-settings">
            <label class="messenger-menu-toggle">
                <input type="checkbox" name="phone_enabled" value="1" @checked((bool) data_get($menu, 'phone_enabled', true))>
                <span>Hiển thị nút chia sẻ số điện thoại dưới carousel</span>
            </label>
            <label class="messenger-menu-field">
                <span>Câu mời chia sẻ số điện thoại</span>
                <textarea name="phone_text" rows="2" maxlength="1024">{{ data_get($menu, 'phone_text') }}</textarea>
                @error('phone_text')<small>{{ $message }}</small>@enderror
            </label>
        </section>

        <div class="messenger-menu-elements">
            @foreach((array) data_get($menu, 'elements', []) as $elementIndex => $element)
                <fieldset>
                    <legend>Thẻ {{ $elementIndex + 1 }}</legend>
                    <div class="messenger-menu-card-grid">
                        <label class="messenger-menu-field">
                            <span>Tiêu đề</span>
                            <input name="elements[{{ $elementIndex }}][title]" value="{{ data_get($element, 'title') }}" maxlength="80" required>
                        </label>
                        <label class="messenger-menu-field">
                            <span>Mô tả</span>
                            <input name="elements[{{ $elementIndex }}][subtitle]" value="{{ data_get($element, 'subtitle') }}" maxlength="80">
                        </label>
                        <label class="messenger-menu-field messenger-menu-image-field">
                            <span>URL ảnh HTTPS</span>
                            <input type="url" name="elements[{{ $elementIndex }}][image_url]" value="{{ data_get($element, 'image_url') }}" placeholder="https://...">
                        </label>
                    </div>

                    <div class="messenger-menu-buttons">
                        @foreach((array) data_get($element, 'buttons', []) as $buttonIndex => $button)
                            <section>
                                <strong>Nút {{ $buttonIndex + 1 }}</strong>
                                <label class="messenger-menu-field">
                                    <span>Tên nút</span>
                                    <input name="elements[{{ $elementIndex }}][buttons][{{ $buttonIndex }}][title]" value="{{ data_get($button, 'title') }}" maxlength="20" required>
                                </label>
                                <label class="messenger-menu-field">
                                    <span>Payload gửi cho bot</span>
                                    <textarea name="elements[{{ $elementIndex }}][buttons][{{ $buttonIndex }}][payload]" rows="3" maxlength="1000" required>{{ data_get($button, 'payload') }}</textarea>
                                </label>
                            </section>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        </div>

        @error('elements')<small>{{ $message }}</small>@enderror
        <button type="submit">Lưu menu Messenger</button>
    </form>
</article>
