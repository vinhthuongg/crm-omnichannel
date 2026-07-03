    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
        document.querySelector('[data-crm-shell]')?.classList.toggle('sidebar-collapsed');
    });

    const messengerShell = document.querySelector('[data-messenger-realtime]');

    function closeMessengerDrawers() {
        messengerShell?.classList.remove('is-list-open', 'is-profile-open');
    }

    function openMessengerDrawer(panel) {
        if (!messengerShell) {
            return;
        }

        closeMessengerDrawers();

        if (panel === 'list') {
            messengerShell.classList.add('is-list-open');
        }

        if (panel === 'profile') {
            messengerShell.classList.add('is-profile-open');
        }
    }

    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-messenger-drawer-toggle]');
        const close = event.target.closest('[data-messenger-drawer-close]');

        if (toggle) {
            event.preventDefault();
            openMessengerDrawer(toggle.dataset.messengerDrawerToggle || '');
        }

        if (close) {
            event.preventDefault();
            closeMessengerDrawers();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMessengerDrawers();
        }
    });

    document.querySelector('.messenger-thread-list')?.addEventListener('click', function (event) {
        const thread = event.target.closest('.messenger-thread');

        if (!thread || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        closeMessengerDrawers();
        loadConversation(thread.dataset.conversationUrl || thread.href);
    });

    document.querySelector('.messenger-status-tabs')?.addEventListener('click', function (event) {
        const link = event.target.closest('a');

        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        runConversationSearchUrl(link.href);
    });

    document.querySelector('.messenger-filter-menu')?.addEventListener('click', function (event) {
        const link = event.target.closest('a');

        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        runConversationSearchUrl(link.href);
    });

    let searchDebounceTimer = null;

    document.querySelectorAll('.messenger-search, .topbar-global-search').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            runConversationSearch(form);
        });

        form.addEventListener('input', function (event) {
            if (!event.target.matches('[name="q"]')) {
                return;
            }

            window.clearTimeout(searchDebounceTimer);
            searchDebounceTimer = window.setTimeout(function () {
                runConversationSearch(form);
            }, 280);
        });

        form.addEventListener('change', function (event) {
            if (event.target.matches('[name="tag"]')) {
                runConversationSearch(form);
            }
        });
    });

    window.addEventListener('popstate', function () {
        loadConversation(window.location.href);
    });

    document.querySelector('[data-assign-form]')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        assignConversation(event.target);
    });

    document.querySelector('[data-profile-panel]')?.addEventListener('keydown', function (event) {
        if (event.target.matches('[data-note-input]') && event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            storeCustomerNote(event.target);
        }

        if (event.target.matches('[data-customer-tag-input]') && event.key === 'Enter') {
            event.preventDefault();
            const tagName = matchingCustomerTagName(event.target.value);

            if (tagName) {
                storeCustomerTag(tagName);
            }

            event.target.value = '';
        }
    });

    document.querySelector('[data-profile-panel]')?.addEventListener('input', function (event) {
        if (event.target.matches('[data-customer-tag-input]')) {
            renderCustomerTagOptions(event.target.value);
        }
    });

    document.querySelector('[data-profile-panel]')?.addEventListener('submit', function (event) {
        if (event.target.matches('[data-contact-form]')) {
            event.preventDefault();
            saveCustomerContact(event.target);
        }
    });

    document.querySelector('[data-profile-panel]')?.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-notes-toggle]');
        const back = event.target.closest('[data-notes-back]');
        const contactToggle = event.target.closest('[data-contact-toggle]');
        const contactBack = event.target.closest('[data-contact-back]');
        const selectedTag = event.target.closest('[data-select-customer-tag]');
        const detectedPhone = event.target.closest('[data-use-detected-phone]');

        if (toggle) {
            event.preventDefault();
            showAllNotesPanel();
        }

        if (contactToggle) {
            event.preventDefault();
            showContactDetailsPanel();
        }

        if (back) {
            event.preventDefault();
            showMainProfilePanel();
        }

        if (contactBack) {
            event.preventDefault();
            showMainProfilePanel();
        }

        if (selectedTag) {
            event.preventDefault();
            storeCustomerTag(selectedTag.dataset.selectCustomerTag || '');
        }

        if (detectedPhone) {
            event.preventDefault();
            detectedPhone.disabled = true;
            useDetectedPhone(detectedPhone.dataset.useDetectedPhone || '')
                .catch((error) => {
            window.alert(error.message || 'Không cập nhật được số điện thoại.');
                })
                .finally(() => {
                    detectedPhone.disabled = false;
                });
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'F6') {
            event.preventDefault();
            document.querySelector('[data-note-input]')?.focus();
        }

        if (event.key === 'F2') {
            event.preventDefault();
            document.querySelector('[data-customer-tag-input]')?.focus();
        }
    });

    let timeline = document.querySelector('[data-messenger-timeline]');
    let composer = document.querySelector('[data-messenger-composer]');
    const realtimeRoot = messengerShell;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    let customerTagOptions = readJsonDataset(realtimeRoot?.dataset.customerTagOptionsJson, []);
    let attachmentUpload = {
        files: [],
        promise: Promise.resolve([]),
        uploaded: [],
    };
    let olderMessagesLoading = false;
    let suggestionRequestId = 0;
    const replySuggestionCache = new Map();
    let conversationSummaryMessages = [];

    function readJsonDataset(value, fallback) {
        try {
            if (!value) {
                return fallback;
            }

            const normalized = String(value).includes('&quot;')
                ? decodeHtmlEntities(value)
                : value;

            return JSON.parse(normalized);
        } catch (error) {
            console.warn('CRM messenger dataset JSON parse failed:', error);
            return fallback;
        }
    }

    function decodeHtmlEntities(value) {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = String(value || '');
        return textarea.value;
    }

    function normalizeSuggestionText(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/đ/g, 'd')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function latestCustomerMessageText() {
        const rows = Array.from(timeline?.querySelectorAll('.message-row.theirs') || []);
        const row = rows.reverse().find(function (item) {
            return item.querySelector('.message-bubble p');
        });

        return row?.querySelector('.message-bubble p')?.textContent?.trim() || '';
    }

    function currentConversationContext() {
        return {
            customerName: document.querySelector('[data-chat-customer-name]')?.textContent?.trim() || 'Anh/Chị',
            lastCustomerMessage: latestCustomerMessageText(),
            activeTags: Array.from(document.querySelectorAll('[data-conversation-tags] .composer-tag.is-active'))
                .map((button) => button.dataset.tagName || button.textContent || '')
                .filter(Boolean),
        };
    }

    function replySuggestionsForContext(context) {
        const text = normalizeSuggestionText(context.lastCustomerMessage);
        const suggestions = [];

        if (!text) {
            return [
                'Em chào Anh/Chị, em có thể hỗ trợ mình thông tin gì về xe Toyota hôm nay ạ?',
                'Anh/Chị đang quan tâm mẫu xe nào để em tư vấn đúng nhu cầu hơn ạ?',
                'Nếu thuận tiện, Anh/Chị cho em xin nhu cầu chính: báo giá, trả góp, lái thử hay bảo dưỡng ạ?',
            ];
        }

        if (/(gia|bao gia|lan banh|bao nhieu|nhieu tien|khuyen mai|uu dai)/.test(text)) {
            suggestions.push(
                'Dạ được ạ. Anh/Chị cho em biết mình đang quan tâm mẫu xe và khu vực đăng ký biển số để em báo giá lăn bánh sát nhất ạ.',
                'Dạ em kiểm tra giá và ưu đãi hiện hành cho mình. Anh/Chị đang xem phiên bản hoặc màu xe nào chưa ạ?',
                'Anh/Chị muốn em báo giá theo phương án trả thẳng hay trả góp để em gửi đúng bảng chi phí ạ?'
            );
        }

        if (/(tra gop|gop|vay|ngan hang|lai suat|truoc bao nhieu|tra truoc)/.test(text)) {
            suggestions.push(
                'Dạ bên em có hỗ trợ trả góp qua ngân hàng liên kết. Anh/Chị dự kiến trả trước khoảng bao nhiêu để em tính phương án phù hợp ạ?',
                'Anh/Chị muốn vay trong khoảng mấy năm để em ước tính khoản thanh toán hàng tháng dễ chịu nhất ạ?',
                'Để kiểm tra hồ sơ nhanh hơn, Anh/Chị cho em xin số điện thoại/Zalo, bên em sẽ tư vấn phương án trả góp cụ thể ạ.'
            );
        }

        if (/(lai thu|test drive|chay thu|thu xe|dat lich)/.test(text)) {
            suggestions.push(
                'Dạ em hỗ trợ đặt lịch lái thử cho mình. Anh/Chị muốn ghé showroom vào ngày nào và khung giờ nào ạ?',
                'Dạ được ạ. Anh/Chị cho em xin số điện thoại để bên em xác nhận lịch lái thử và chuẩn bị xe trước khi mình ghé ạ.',
                'Anh/Chị muốn lái thử mẫu xe nào để em kiểm tra xe sẵn lịch cho mình ạ?'
            );
        }

        if (/(bao duong|sua chua|dich vu|dat hen|bao hanh)/.test(text)) {
            suggestions.push(
                'Dạ em ghi nhận nhu cầu dịch vụ của mình. Anh/Chị cho em xin biển số xe và số điện thoại để bên em kiểm tra lịch trống ạ.',
                'Anh/Chị muốn đặt lịch bảo dưỡng ngày nào và khung giờ nào để em hỗ trợ giữ lịch trước ạ?',
                'Dạ trường hợp này em sẽ chuyển bộ phận dịch vụ hỗ trợ kỹ hơn. Anh/Chị cho em xin số điện thoại/Zalo nhé ạ.'
            );
        }

        if (/(sdt|so dien thoai|zalo|lien he|goi)/.test(text)) {
            suggestions.push(
                'Dạ Anh/Chị để lại số điện thoại/Zalo giúp em, bên em sẽ liên hệ tư vấn chi tiết và nhanh nhất ạ.',
                'Dạ em nhận thông tin rồi ạ. Anh/Chị cho em xin thêm tên mình để bên em tiện xưng hô và hỗ trợ đúng hồ sơ ạ.',
                'Dạ sau khi có số điện thoại, em sẽ nhờ tư vấn viên phụ trách liên hệ lại ngay cho mình ạ.'
            );
        }

        if (/(cam on|thank|ok|duoc|uh|ừ|vâng|vang)/.test(text)) {
            suggestions.push(
                'Dạ em cảm ơn Anh/Chị. Nếu mình cần thêm báo giá, trả góp hoặc lịch lái thử, em hỗ trợ ngay ạ.',
                'Dạ vâng ạ. Anh/Chị cần em kiểm tra thêm thông tin nào để mình dễ quyết định hơn không ạ?',
                'Dạ em luôn sẵn sàng hỗ trợ. Anh/Chị cứ nhắn mẫu xe hoặc nhu cầu, em kiểm tra ngay cho mình ạ.'
            );
        }

        if (suggestions.length === 0) {
            suggestions.push(
                'Dạ em nghe Anh/Chị ạ. Mình cần em hỗ trợ thêm thông tin gì về xe, giá, trả góp hay lịch lái thử ạ?',
                'Dạ để em tư vấn đúng hơn, Anh/Chị cho em biết mình đang quan tâm mẫu xe hoặc nhu cầu chính hiện tại ạ.',
                'Dạ em có thể hỗ trợ báo giá, chương trình ưu đãi, trả góp hoặc đặt lịch lái thử cho mình ạ.'
            );
        }

        return [...new Set(suggestions)].slice(0, 4);
    }

    function replySuggestionCacheKey() {
        return [
            composer?.dataset.suggestionsUrl || '',
            timeline?.dataset.lastMessageId || '0',
        ].join('#');
    }

    async function fetchNimReplySuggestions(requestId, refresh = false) {
        const url = composer?.dataset.suggestionsUrl || '';

        if (!url) {
            return {suggestions: [], pending: false};
        }

        const endpoint = new URL(url, window.location.origin);

        if (refresh) {
            endpoint.searchParams.set('refresh', '1');
        }

        const response = await fetch(endpoint.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok || requestId !== suggestionRequestId) {
            return {suggestions: [], pending: false};
        }

        const payload = await response.json().catch(() => ({}));
        const data = payload.data || {};

        return {
            suggestions: Array.isArray(data.suggestions) ? data.suggestions : [],
            pending: Boolean(data.pending),
            cached: Boolean(data.cached),
            messageId: data.message_id || null,
        };
    }

    function paintReplySuggestions(suggestions) {
        const box = document.querySelector('[data-reply-suggestions]');
        const list = document.querySelector('[data-reply-suggestion-list]');

        if (!box || !list || !composer || composer.dataset.canReply !== '1') {
            box?.classList.add('is-empty');
            return;
        }

        box.classList.toggle('is-empty', suggestions.length === 0);
        list.innerHTML = suggestions.map(function (suggestion) {
            return `<button type="button" class="composer-suggestion-button" data-reply-suggestion="${escapeHtml(suggestion)}">${escapeHtml(suggestion)}</button>`;
        }).join('');
    }

    async function renderReplySuggestions(options = {}) {
        const requestId = ++suggestionRequestId;
        const localSuggestions = replySuggestionsForContext(currentConversationContext());
        const key = replySuggestionCacheKey();
        const cachedSuggestions = replySuggestionCache.get(key);

        paintReplySuggestions(cachedSuggestions || localSuggestions);

        try {
            const result = await fetchNimReplySuggestions(requestId, Boolean(options.refresh));

            if (requestId !== suggestionRequestId) {
                return;
            }

            if (result.suggestions.length) {
                replySuggestionCache.set(key, result.suggestions);
                paintReplySuggestions(result.suggestions);
                return;
            }

            paintReplySuggestions(cachedSuggestions || localSuggestions);

            if (result.pending && Number(options.attempt || 0) < 2) {
                window.setTimeout(function () {
                    if (requestId === suggestionRequestId) {
                        renderReplySuggestions({attempt: Number(options.attempt || 0) + 1});
                    }
                }, 2500);
            }
        } catch (error) {
            if (requestId === suggestionRequestId) {
                paintReplySuggestions(cachedSuggestions || localSuggestions);
            }
            console.warn('NIM reply suggestions unavailable:', error);
        }
    }

    function applyReplySuggestion(text) {
        const input = composer?.querySelector('[name="content"]');

        if (!input) {
            return;
        }

        input.value = text;
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
    }

    function syncSearchInputs(value) {
        document.querySelectorAll('[data-auto-search-input]').forEach(function (input) {
            if (input.value !== value) {
                input.value = value;
            }
        });
    }

    async function runConversationSearch(form) {
        const list = document.querySelector('.messenger-thread-list');

        if (!list || !form) {
            form?.submit();
            return;
        }

        const params = new URLSearchParams(new FormData(form));
        const currentUrl = new URL(window.location.href);
        const targetUrl = new URL(form.action || realtimeRoot?.dataset.conversationsUrl || currentUrl.pathname, window.location.origin);

        ['channel', 'status', 'q', 'tag'].forEach(function (key) {
            const value = params.has(key) ? String(params.get(key) || '') : (currentUrl.searchParams.get(key) || '');

            if (value) {
                targetUrl.searchParams.set(key, value);
            } else {
                targetUrl.searchParams.delete(key);
            }
        });

        syncSearchInputs(targetUrl.searchParams.get('q') || '');

        await runConversationSearchUrl(targetUrl);
    }

    async function runConversationSearchUrl(url) {
        const list = document.querySelector('.messenger-thread-list');

        if (!list) {
            window.location.href = String(url);
            return;
        }

        const targetUrl = new URL(url, window.location.origin);
        syncSearchInputs(targetUrl.searchParams.get('q') || '');
        syncRealtimeFilters(targetUrl);

        try {
            const response = await fetch(targetUrl, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return;
            }

            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const freshList = doc.querySelector('.messenger-thread-list');
            const freshTabs = doc.querySelector('.messenger-status-tabs');
            const currentTabs = document.querySelector('.messenger-status-tabs');
            const freshTagMenu = doc.querySelector('.messenger-tag-menu');
            const currentTagMenu = document.querySelector('.messenger-tag-menu');

            if (freshList) {
                list.innerHTML = freshList.innerHTML;
                const activeId = timeline?.dataset.conversationId;

                if (activeId) {
                    list.querySelector(`[data-thread-conversation-id="${activeId}"]`)?.classList.add('active');
                }
            }

            if (freshTabs && currentTabs) {
                currentTabs.innerHTML = freshTabs.innerHTML;
            }

            if (freshTagMenu && currentTagMenu) {
                currentTagMenu.innerHTML = freshTagMenu.innerHTML;
            }

            window.history.replaceState({conversationUrl: window.location.href}, '', targetUrl);
        } catch (error) {
            console.warn('CRM messenger search failed:', error);
        }
    }

    function syncRealtimeFilters(url) {
        if (!realtimeRoot) {
            return;
        }

        realtimeRoot.dataset.channelFilter = url.searchParams.get('channel') || 'all';
        realtimeRoot.dataset.statusFilter = url.searchParams.get('status') || 'all';
        realtimeRoot.dataset.tagFilter = url.searchParams.get('tag') || '';
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[character];
        });
    }

    function messageTime(message) {
        if (!message.created_at) {
            return '';
        }

        return new Intl.DateTimeFormat('vi-VN', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(new Date(message.created_at));
    }

    function summaryText(value, maxLength = 120) {
        const text = String(value || '').replace(/\s+/g, ' ').trim();

        if (text.length <= maxLength) {
            return text;
        }

        return text.slice(0, maxLength - 1).trimEnd() + '…';
    }

    function relativeThreadTime(timestamp) {
        if (!timestamp) {
            return '';
        }

        const date = new Date(timestamp);

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        const diffSeconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));

        if (diffSeconds < 60) {
            return 'Vua xong';
        }

        const diffMinutes = Math.floor(diffSeconds / 60);

        if (diffMinutes < 60) {
            return `${diffMinutes} minutes ago`;
        }

        const diffHours = Math.floor(diffMinutes / 60);

        if (diffHours < 24) {
            return `${diffHours} hours ago`;
        }

        const diffDays = Math.floor(diffHours / 24);

        if (diffDays < 7) {
            return `${diffDays} days ago`;
        }

        return new Intl.DateTimeFormat('vi-VN', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        }).format(date);
    }

    function isOutboundMessage(message, currentUserId) {
        return message?.sender_type === 'system'
            || (message?.sender_type === 'user' && Number(message?.sender_id) === Number(currentUserId));
    }

    function appendMessage(message) {
        if (!timeline || !message?.id || !hasRenderableMessage(message)) {
            return;
        }

        const existing = timeline.querySelector(`[data-message-id="${message.id}"]`);

        if (existing) {
            updateMessageRow(existing, message);
            return;
        }

        const currentUserId = Number(timeline.dataset.currentUserId);
        const isMine = isOutboundMessage(message, currentUserId);
        const pending = isMine ? matchingPendingMessage(message) : null;

        if (pending) {
            updateMessageRow(pending, message);
            pending.dataset.messageId = message.id;
            delete pending.dataset.pendingMessageId;
            timeline.dataset.lastMessageId = String(Math.max(Number(timeline.dataset.lastMessageId || 0), Number(message.id)));
            return;
        }

        const row = document.createElement('article');
        row.className = `message-row ${isMine ? 'mine' : 'theirs'}`;
        row.dataset.messageId = message.id;
        row.dataset.clientMessageId = message.client_message_id || '';
        row.innerHTML = messageRowHtml(message, isMine);

        timeline.appendChild(row);
        timeline.dataset.lastMessageId = String(Math.max(Number(timeline.dataset.lastMessageId || 0), Number(message.id)));
        updateThreadPreview(message);
        appendConversationSummaryMessage(message);
        renderReplySuggestions();
        timeline.scrollTop = timeline.scrollHeight;
    }

    function prependMessage(message) {
        if (!timeline || !message?.id || !hasRenderableMessage(message)) {
            return null;
        }

        const existing = timeline.querySelector(`[data-message-id="${message.id}"]`);

        if (existing) {
            updateMessageRow(existing, message);
            return existing;
        }

        const currentUserId = Number(timeline.dataset.currentUserId);
        const isMine = isOutboundMessage(message, currentUserId);
        const row = document.createElement('article');
        row.className = `message-row ${isMine ? 'mine' : 'theirs'}`;
        row.dataset.messageId = message.id;
        row.dataset.clientMessageId = message.client_message_id || '';
        row.innerHTML = messageRowHtml(message, isMine);

        const firstMessage = timeline.querySelector('.message-row');
        timeline.insertBefore(row, firstMessage);

        return row;
    }

    function matchingPendingMessage(message) {
        const pendingMessages = Array.from(timeline?.querySelectorAll('[data-pending-message-id]') || []);
        const clientMessageId = String(message.client_message_id || '');

        if (clientMessageId) {
            const escapedClientMessageId = window.CSS?.escape ? CSS.escape(clientMessageId) : clientMessageId.replace(/"/g, '\\"');
            const exact = timeline?.querySelector(`[data-client-message-id="${escapedClientMessageId}"]`);

            if (exact) {
                return exact;
            }
        }

        const messageContent = String(message.content || '').trim();
        const messageAttachmentNames = attachmentNames(message);

        return pendingMessages.find(function (pending) {
            const pendingContent = pending.dataset.pendingContent || '';
            const pendingAttachmentNames = String(pending.dataset.pendingAttachments || '').split('|').filter(Boolean);
            const sameContent = pendingContent === messageContent;
            const sameAttachments = messageAttachmentNames.length > 0
                && messageAttachmentNames.join('|') === pendingAttachmentNames.join('|');

            return sameContent && (sameAttachments || (messageAttachmentNames.length === 0 && pendingAttachmentNames.length === 0));
        }) || null;
    }

    function attachmentNames(message) {
        return (Array.isArray(message.attachments) ? message.attachments : [])
            .map(function (attachment) {
                return String(attachment.name || '').trim();
            })
            .filter(Boolean)
            .sort();
    }

    function updateMessageRow(row, message) {
        if (!hasRenderableMessage(message)) {
            row.remove();
            return;
        }

        const currentUserId = Number(timeline.dataset.currentUserId);
        const isMine = isOutboundMessage(message, currentUserId);

        row.className = `message-row ${isMine ? 'mine' : 'theirs'}`;
        row.classList.remove('is-pending');
        row.classList.toggle('is-failed', message?.outbound_status === 'failed');
        row.dataset.clientMessageId = message.client_message_id || row.dataset.clientMessageId || '';
        row.innerHTML = messageRowHtml(mergePendingPreview(row, message), isMine);
        updateThreadPreview(message);
        renderReplySuggestions();
    }

    function messageRowHtml(message, isMine) {
        const isWhisper = message.message_type === 'whisper' || message.channel === 'internal';
        const avatar = isMine ? '' : `<span class="thread-avatar mini">${avatarHtml(message.sender_avatar, message.sender_name || 'C')}</span>`;
        const status = messageStatusText(message);
        const content = String(message.content || '').trim();
        const attachments = !message.is_recalled && Array.isArray(message.attachments) && message.attachments.length
            ? `<div class="message-attachments">${message.attachments.map(function (attachment) {
                return attachmentHtml(attachment);
            }).join('')}</div>`
            : '';
        const textBubble = message.is_recalled
            ? `<div class="message-bubble is-recalled"><p>Tin nhan da duoc thu hoi</p></div>`
            : content
            ? `<div class="message-bubble ${isWhisper ? 'is-whisper' : ''}">
                    <span class="message-sender">${escapeHtml(isWhisper ? `Thì thầm - ${message.sender_name || 'Nhân viên'}` : (message.sender_name || 'Unknown'))}</span>
                    <p>${escapeHtml(content)}</p>
                </div>`
            : '';

        return `
            ${avatar}
            <div class="message-stack">
                ${textBubble}
                ${attachments}
                <time>${escapeHtml(messageTime(message))} - ${escapeHtml(isWhisper ? 'Nội bộ' : ((message.channel || '').charAt(0).toUpperCase() + (message.channel || '').slice(1)))}${status ? ` - ${escapeHtml(status)}` : ''}</time>
            </div>
        `;
    }

    function avatarHtml(url, name) {
        if (url) {
            return `<img src="${escapeHtml(url)}" alt="${escapeHtml(name || 'Customer')}">`;
        }

        return escapeHtml((name || 'C').slice(0, 1).toUpperCase());
    }

    function updateProfilePanel(conversation) {
        const panel = document.querySelector('[data-profile-panel]');

        if (!panel || !conversation) {
            return;
        }

        showMainProfilePanel();
        const customerName = conversation.customer_name || conversation.conversation_customer_name || 'Customer';
        const customerAvatar = conversation.customer_avatar || conversation.conversation_customer_avatar || '';
        const avatar = panel.querySelector('[data-profile-avatar]');
        const name = panel.querySelector('[data-profile-name]');

        if (avatar) {
            avatar.innerHTML = avatarHtml(customerAvatar, customerName);
        }

        if (name) {
            name.textContent = customerName;
        }

        if (conversation.customer_contact || Object.prototype.hasOwnProperty.call(conversation, 'customer_email')) {
            renderCustomerContact(conversation.customer_contact || {
                name: customerName,
                phone: conversation.customer_phone || '',
                email: conversation.customer_email || '',
                channel: conversation.active_channel || '',
            });
        }

        if (Array.isArray(conversation.customer_public_details)) {
            renderCustomerPublicDetails(conversation.customer_public_details);
        }

        if (Array.isArray(conversation.customer_notes)) {
            renderCustomerNotes(conversation.customer_notes);
        }

        if (Array.isArray(conversation.tags)) {
            renderProfileConversationTags(conversation.tags);
        }

        if (conversation.conversation_summary) {
            renderConversationSummary(conversation.conversation_summary);
            renderDetectedPhones(
                conversation.conversation_summary?.facts?.phones || [],
                conversation.customer_phone || conversation.customer_contact?.phone || '',
                conversation.customer_update_url || ''
            );
        } else if (Array.isArray(conversation.messages)) {
            const summary = semanticSummaryFromMessages(conversation.messages);
            renderConversationSummary(summary);
            renderDetectedPhones(summary?.facts?.phones || [], conversation.customer_phone || '', conversation.customer_update_url || '');
        }

        const notes = panel.querySelector('[data-customer-notes]');
        const contact = panel.querySelector('[data-contact-section]');

        if (notes && conversation.customer_notes_url) {
            notes.dataset.notesUrl = conversation.customer_notes_url;
        }

        if (conversation.customer_update_url) {
            panel.querySelectorAll('[data-contact-section]').forEach(function (section) {
                section.dataset.contactUrl = conversation.customer_update_url;
            });
        }
    }

    function renderCustomerContact(contact) {
        const forms = document.querySelectorAll('[data-contact-form]');

        if (!forms.length) {
            return;
        }

        const fields = {
            name: contact?.name || '',
            phone: contact?.phone || '',
            email: contact?.email || '',
        };

        forms.forEach(function (form) {
            Object.entries(fields).forEach(function ([field, value]) {
                const input = form.querySelector(`[name="${field}"]`);

                if (input) {
                    input.value = value;
                }
            });

            const channel = form.querySelector('[data-contact-channel]');

            if (channel) {
                channel.value = contact?.channel || 'Chưa có kênh';
            }
        });
    }

    function renderCustomerPublicDetails(details) {
        const container = document.querySelector('[data-public-detail-list]');

        if (!container) {
            return;
        }

        if (!Array.isArray(details) || !details.length) {
            container.innerHTML = '<p class="profile-empty">Chưa có thông tin công khai.</p>';
            return;
        }

        container.innerHTML = details.map(function (detail) {
            return `
                <span>${escapeHtml(detail.label || '')}</span>
                <strong>${escapeHtml(detail.value || '')}</strong>
            `;
        }).join('');
    }

    async function saveCustomerContact(form) {
        const section = form.closest('[data-contact-section]');
        const status = section?.querySelector('[data-contact-status]');
        const submit = form.querySelector('button[type="submit"]');
        const url = section?.dataset.contactUrl;

        if (!url) {
            return;
        }

        if (status) {
            status.textContent = 'Đang lưu...';
        }

        if (submit) {
            submit.disabled = true;
        }

        try {
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify(Object.fromEntries(new FormData(form))),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'Không lưu được thông tin.');
            }

            applyCustomerContact(payload.data || {});

            if (status) {
                status.textContent = 'Da luu';
            }
        } catch (error) {
            if (status) {
                status.textContent = error.message || 'Lưu thất bại';
            }
        } finally {
            if (submit) {
                submit.disabled = false;
            }
        }
    }

    function applyCustomerContact(conversation) {
        const customerName = conversation.customer_name || 'Customer';
        const customerAvatar = conversation.customer_avatar || '';
        const chatAvatar = document.querySelector('[data-chat-avatar]');
        const chatName = document.querySelector('[data-chat-customer-name]');
        const chatPhone = document.querySelector('[data-chat-customer-phone]');
        const profileName = document.querySelector('[data-profile-name]');
        const thread = document.querySelector(`[data-thread-conversation-id="${conversation.id}"]`);
        const threadName = thread?.querySelector('.thread-body strong');
        const threadAvatar = thread?.querySelector('.thread-avatar');

        renderCustomerContact(conversation.customer_contact || {
            name: customerName,
            phone: conversation.customer_phone || '',
            email: conversation.customer_email || '',
            channel: '',
        });
        renderCustomerPublicDetails(conversation.customer_public_details || []);

        if (conversation.conversation_summary) {
            renderConversationSummary(conversation.conversation_summary);
        }

        if (chatAvatar) {
            chatAvatar.innerHTML = avatarHtml(customerAvatar, customerName);
        }

        if (chatName) {
            chatName.textContent = customerName;
        }

        if (chatPhone) {
            chatPhone.textContent = conversation.customer_phone || 'Chưa có số điện thoại';
        }

        renderDetectedPhones(
            document.querySelector('[data-conversation-summary-list]')?.dataset.detectedPhones?.split('|').filter(Boolean) || [],
            conversation.customer_phone || '',
            conversation.customer_update_url || document.querySelector('[data-phone-candidates-list]')?.dataset.contactUrl || ''
        );

        if (profileName) {
            profileName.textContent = customerName;
        }

        if (threadName) {
            threadName.textContent = customerName;
        }

        if (threadAvatar) {
            threadAvatar.innerHTML = avatarHtml(customerAvatar, customerName);
        }
    }

    function renderCustomerNotes(notes) {
        const count = document.querySelector('[data-note-count]');

        if (count) {
            count.textContent = String(notes.length);
        }

        renderCustomerNotesFull(notes);
    }

    function renderCustomerNotesFull(notes) {
        const container = document.querySelector('[data-note-list-full]');

        if (!container) {
            return;
        }

        if (!notes.length) {
            container.innerHTML = '<p class="profile-empty">Chưa có ghi chú nào.</p>';
            return;
        }

        container.innerHTML = notes.map(function (note) {
            return `
                <article data-note-item>
                    <p>${escapeHtml(note.body)}</p>
                    <time>${escapeHtml(note.author || 'Admin')} - ${escapeHtml(note.created_at || '')}</time>
                </article>
            `;
        }).join('');
    }

    function showAllNotesPanel() {
        const main = document.querySelector('[data-profile-main]');
        const full = document.querySelector('[data-notes-full]');
        const contact = document.querySelector('[data-contact-full]');

        if (!main || !full) {
            return;
        }

        main.hidden = true;
        if (contact) {
            contact.hidden = true;
        }
        full.hidden = false;
    }

    function showContactDetailsPanel() {
        const main = document.querySelector('[data-profile-main]');
        const notes = document.querySelector('[data-notes-full]');
        const contact = document.querySelector('[data-contact-full]');

        if (!main || !contact) {
            return;
        }

        main.hidden = true;
        if (notes) {
            notes.hidden = true;
        }
        contact.hidden = false;
    }

    function showMainProfilePanel() {
        const main = document.querySelector('[data-profile-main]');
        const full = document.querySelector('[data-notes-full]');
        const contact = document.querySelector('[data-contact-full]');

        if (!main) {
            return;
        }

        if (full) {
            full.hidden = true;
        }
        if (contact) {
            contact.hidden = true;
        }
        main.hidden = false;
    }

    function renderCustomerTags(tags, allTags) {
        const list = document.querySelector('[data-customer-tag-list]');

        customerTagOptions = Array.isArray(allTags) ? allTags : customerTagOptions;

        if (list) {
            const visibleTags = (tags || []).slice(0, 1);
            list.innerHTML = visibleTags.length
                ? visibleTags.map((tag) => `<span style="--tag-color: ${escapeHtml(tag.color || '#2563eb')}">${escapeHtml(tag.name)}</span>`).join('')
                : '<p class="profile-empty">Khách hàng chưa có thẻ nào</p>';
        }

        renderCustomerTagOptions('');
    }

    function renderCustomerTagOptions(filter) {
        const options = document.querySelector('[data-customer-tag-options]');

        if (!options || options === realtimeRoot) {
            return;
        }

        const normalizedFilter = String(filter || '').toLowerCase();
        const visible = customerTagOptions
            .filter((tag) => !normalizedFilter || String(tag.name || '').toLowerCase().includes(normalizedFilter));

        options.innerHTML = visible.length
            ? visible.map((tag) => `<button type="button" data-select-customer-tag="${escapeHtml(tag.name)}">${escapeHtml(tag.name)}</button>`).join('')
                : '<p>Không có tag phù hợp.</p>';
    }

    function matchingCustomerTagName(value) {
        const search = String(value || '').trim().toLowerCase();

        if (!search) {
            return '';
        }

        const exact = customerTagOptions.find((tag) => String(tag.name || '').toLowerCase() === search);

        if (exact) {
            return exact.name;
        }

        return customerTagOptions.find((tag) => String(tag.name || '').toLowerCase().includes(search))?.name || '';
    }

    async function storeCustomerNote(input) {
        const section = document.querySelector('[data-customer-notes]');
        const body = String(input?.value || '').trim();

        if (!section?.dataset.notesUrl || !body) {
            return;
        }

        input.disabled = true;

        try {
            const response = await fetch(section.dataset.notesUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify({body}),
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            input.value = '';
            renderCustomerNotes(payload.data?.notes || []);
        } finally {
            input.disabled = false;
            input.focus();
        }
    }

    async function storeCustomerTag(name) {
        const section = document.querySelector('[data-customer-tags]');
        const tagName = String(name || '').trim();

        if (!section?.dataset.tagsUrl || !tagName) {
            return;
        }

        const response = await fetch(section.dataset.tagsUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
            },
            body: JSON.stringify({name: tagName}),
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        renderCustomerTags(payload.data?.tags || [], payload.data?.all_tags || customerTagOptions);
        await refreshThreadList();
    }

    function renderMessages(messages) {
        if (!timeline) {
            return;
        }

        conversationSummaryMessages = (messages || []).filter(hasRenderableMessage).slice(-40);
        timeline.querySelectorAll('.message-row').forEach((row) => row.remove());
        (messages || []).forEach(function (message) {
            if (!hasRenderableMessage(message)) {
                return;
            }

            const currentUserId = Number(timeline.dataset.currentUserId);
            const isMine = isOutboundMessage(message, currentUserId);
            const row = document.createElement('article');
            row.className = `message-row ${isMine ? 'mine' : 'theirs'}`;
            row.dataset.messageId = message.id;
            row.dataset.clientMessageId = message.client_message_id || '';
            row.innerHTML = messageRowHtml(message, isMine);
            timeline.appendChild(row);
        });

        timeline.scrollTop = timeline.scrollHeight;
        renderReplySuggestions();
    }

    function renderConversation(conversation, url) {
        if (!timeline || !composer || !conversation) {
            window.location.href = url;
            return;
        }

        document.querySelectorAll('.messenger-thread.active').forEach((thread) => thread.classList.remove('active'));
        document.querySelector(`[data-thread-conversation-id="${conversation.id}"]`)?.classList.add('active');

        const customerName = conversation.customer_name || 'Customer';
        const chatAvatar = document.querySelector('[data-chat-avatar]');
        const chatName = document.querySelector('[data-chat-customer-name]');
        const chatPhone = document.querySelector('[data-chat-customer-phone]');
        const chatAssignee = document.querySelector('[data-chat-assignee]');
        const assignForm = document.querySelector('[data-assign-form]');
        const assignSelect = document.querySelector('[data-assign-select]');

        if (chatAvatar) {
            chatAvatar.innerHTML = avatarHtml(conversation.customer_avatar, customerName);
        }

        updateProfilePanel(conversation);

        if (chatName) {
            chatName.textContent = customerName;
        }

        if (chatPhone) {
            chatPhone.textContent = conversation.customer_phone || 'Chưa có số điện thoại';
        }

        if (chatAssignee) {
            chatAssignee.textContent = conversation.assignee_name ? `Phụ trách: ${conversation.assignee_name}` : 'Chưa gán nhân viên';
        }

        if (assignForm && conversation.assign_url) {
            assignForm.dataset.assignUrl = conversation.assign_url;
        }

        if (assignSelect) {
            assignSelect.value = conversation.assigned_to ? String(conversation.assigned_to) : '';
        }

        timeline.dataset.conversationId = conversation.id;
        timeline.dataset.pollUrl = conversation.messages_url;
        timeline.dataset.readUrl = conversation.read_url;
        timeline.dataset.streamUrl = conversation.stream_url;
        timeline.dataset.broadcastChannel = conversation.broadcast_channel;
        timeline.dataset.lastMessageId = String(conversation.meta?.last_message_id || 0);
        timeline.dataset.oldestMessageId = String(conversation.meta?.oldest_message_id || 0);
        timeline.dataset.hasOlderMessages = conversation.meta?.has_older_messages ? '1' : '0';

        composer.action = conversation.send_url;
        composer.dataset.uploadUrl = conversation.attachments_url;
        composer.dataset.suggestionsUrl = conversation.reply_suggestions_url || '';
        updateConversationTags(conversation.tags_url, conversation.tags || []);
        const channelInput = composer.querySelector('input[name="channel"]');

        if (channelInput) {
            channelInput.value = conversation.active_channel || 'facebook';
        }

        updateClaimState(conversation);

        renderMessages(conversation.messages || []);
        renderReplySuggestions();
        window.history.pushState({conversationUrl: url}, '', url);

        if (realtimeMode !== 'websocket') {
            closeMessageStream();
            startMessageStream();
        }
    }

    function updateConversationTags(tagsUrl, tags) {
        const tabs = document.querySelector('[data-conversation-tags]');
        const normalizedTags = tags || [];

        renderProfileConversationTags(normalizedTags);

        if (!tabs) {
            return;
        }

        tabs.dataset.tagsUrl = tagsUrl || '';
        const activeName = String(normalizedTags[0]?.name || '');

        tabs.querySelectorAll('[data-tag-name]').forEach(function (button) {
            button.classList.toggle('is-active', activeName !== '' && activeName === (button.dataset.tagName || ''));
        });
    }

    function tagManagerEndpoints() {
        const tabs = document.querySelector('[data-conversation-tags]');

        return {
            index: tabs?.dataset.tagManagerUrl || '',
            store: tabs?.dataset.tagManagerStoreUrl || '',
        };
    }

    function tagManagerModal() {
        return document.querySelector('[data-tag-manager-modal]');
    }

    function tagManagerForm() {
        return document.querySelector('[data-tag-manager-form]');
    }

    function setTagManagerStatus(message, isError = false) {
        const status = document.querySelector('[data-tag-manager-status]');

        if (!status) {
            return;
        }

        status.textContent = message || '';
        status.classList.toggle('is-error', Boolean(isError));
    }

    function resetTagManagerForm() {
        const form = tagManagerForm();

        if (!form) {
            return;
        }

        form.reset();
        form.querySelector('[data-tag-manager-id]').value = '';
        form.querySelector('[data-tag-manager-name]').readOnly = false;
        form.querySelector('[data-tag-manager-color]').value = '#2563eb';
        form.querySelector('[data-tag-manager-submit]').textContent = 'Lưu tag';
    }

    function tagPayloadFromButton(button) {
        return {
            id: Number(button.dataset.tagId || 0),
            name: button.dataset.tagName || button.textContent.trim(),
            color: button.dataset.tagColor || '#2563eb',
            is_default: button.dataset.tagDefault === '1',
        };
    }

    function renderComposerTagButtons(tags) {
        const tabs = document.querySelector('[data-conversation-tags]');

        if (!tabs) {
            return;
        }

        const activeName = tabs.querySelector('[data-tag-name].is-active')?.dataset.tagName || '';
        const addButton = tabs.querySelector('[data-open-tag-manager]');

        tabs.querySelectorAll('[data-tag-name]').forEach((button) => button.remove());

        (tags || []).forEach(function (tag) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'composer-tag';
            button.style.setProperty('--tag-color', tag.color || '#64748b');
            button.dataset.tagId = String(tag.id || '');
            button.dataset.tagName = tag.name || '';
            button.dataset.tagColor = tag.color || '#64748b';
            button.dataset.tagDefault = tag.is_default ? '1' : '0';
            button.textContent = tag.name || '';

            if (activeName !== '' && activeName === tag.name) {
                button.classList.add('is-active');
            }

            tabs.insertBefore(button, addButton || null);
        });
    }

    function renderTagManagerList(tags) {
        const list = document.querySelector('[data-tag-manager-list]');

        if (!list) {
            return;
        }

        list.innerHTML = (tags || []).map(function (tag) {
            const locked = tag.is_default ? '1' : '0';

            return `
                <article class="tag-manager-row" data-tag-manager-row
                    data-tag-id="${escapeHtml(tag.id)}"
                    data-tag-name="${escapeHtml(tag.name)}"
                    data-tag-color="${escapeHtml(tag.color || '#2563eb')}"
                    data-tag-default="${locked}"
                    data-tag-update-url="${escapeHtml(tag.update_url || '')}"
                    data-tag-delete-url="${escapeHtml(tag.delete_url || '')}"
                    style="--tag-color: ${escapeHtml(tag.color || '#2563eb')}">
                    <span class="tag-manager-chip">${escapeHtml(tag.name)}</span>
                    ${tag.is_default ? '<small>Mac dinh</small>' : ''}
                    <button type="button" data-edit-tag> Sua </button>
                    <button type="button" data-delete-tag ${tag.is_default ? 'disabled' : ''}> Xoa </button>
                </article>
            `;
        }).join('');
    }

    function applyTagCatalog(tags) {
        renderComposerTagButtons(tags || []);
        renderTagManagerList(tags || []);
        customerTagOptions = (tags || []).map(function (tag) {
            return {
                id: tag.id,
                name: tag.name,
                color: tag.color,
            };
        });
    }

    async function loadTagCatalog() {
        const endpoints = tagManagerEndpoints();

        if (!endpoints.index) {
            return;
        }

        const response = await fetch(endpoints.index, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error('Không tải được tag.');
        }

        const payload = await response.json();
        applyTagCatalog(payload.data || []);
    }

    async function openTagManager() {
        const modal = tagManagerModal();

        if (!modal) {
            return;
        }

        modal.hidden = false;
        resetTagManagerForm();
        setTagManagerStatus('');

        try {
            await loadTagCatalog();
        } catch (error) {
            setTagManagerStatus(error.message || 'Không tải được tag.', true);
        }

        modal.querySelector('[data-tag-manager-name]')?.focus();
    }

    function closeTagManager() {
        const modal = tagManagerModal();

        if (modal) {
            modal.hidden = true;
        }
    }

    function renderProfileConversationTags(tags) {
        const list = document.querySelector('[data-profile-conversation-tag-list]');

        if (!list) {
            return;
        }

        const visibleTags = (tags || []).filter((tag) => tag?.name).slice(0, 1);

        list.innerHTML = visibleTags.length
            ? visibleTags.map((tag) => `<span style="--tag-color: ${escapeHtml(tag.color || '#2563eb')}">${escapeHtml(tag.name)}</span>`).join('')
            : '<p class="profile-empty">Chưa có trạng thái.</p>';
    }

    function normalizedVietnameseText(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/đ/g, 'd')
            .replace(/Đ/g, 'D')
            .toLowerCase();
    }

    function detectSummaryVehicle(text) {
        const vehicles = [
            ['Toyota Camry', ['camry']],
            ['Toyota Vios', ['vios']],
            ['Toyota Corolla Cross', ['corolla cross']],
            ['Toyota Yaris Cross', ['yaris cross']],
            ['Toyota Veloz Cross', ['veloz']],
            ['Toyota Avanza Premio', ['avanza']],
            ['Toyota Raize', ['raize']],
            ['Toyota Fortuner', ['fortuner']],
            ['Toyota Innova Cross', ['innova']],
            ['Toyota Hilux', ['hilux']],
            ['Toyota Land Cruiser Prado', ['prado']],
            ['Toyota Land Cruiser', ['land cruiser']],
        ];

        for (const [vehicle, aliases] of vehicles) {
            if (aliases.some((alias) => text.includes(alias))) {
                return vehicle;
            }
        }

        return '';
    }

    function semanticSummaryFromMessages(messages) {
        const usableMessages = (messages || []).filter(function (message) {
            return message && message.channel !== 'internal' && message.message_type !== 'whisper';
        });
        const fullText = normalizedVietnameseText(usableMessages.map((message) => message.content || '').join(' '));
        const customerMessage = usableMessages.find((message) => message.sender_type === 'customer');
        const customerName = customerMessage?.sender_name && !/^\d+$/.test(String(customerMessage.sender_name))
            ? customerMessage.sender_name
            : (document.querySelector('[data-profile-name]')?.textContent || 'Khách hàng');
        const vehicle = detectSummaryVehicle(fullText);
        const needs = [];

        if (/(mua|quan tam|tham khao|tu van)/.test(fullText) || vehicle) {
            needs.push('muon tham khao ' + (vehicle || 'xe Toyota'));
        }

        if (/(bao gia|gia|lan banh|bao nhieu|nhieu tien)/.test(fullText)) {
            needs.push('can bao gia');
        }

        if (/(tra gop|vay|ngan hang|lai suat|tra truoc)/.test(fullText)) {
            needs.push('quan tam tra gop');
        }

        if (/(lai thu|test drive|chay thu)/.test(fullText)) {
            needs.push('muon lai thu');
        }

        if (/(dat lich|hen|lich hen|sap xep)/.test(fullText)) {
            needs.push('muon dat lich');
        }

        if (/(bao duong|sua chua|dich vu|phu tung)/.test(fullText)) {
            needs.push('can ho tro dich vu');
        }

        if (/(khuyen mai|uu dai|giam gia|ctkm)/.test(fullText)) {
            needs.push('hoi ve uu dai');
        }

        const advisors = [...new Set(usableMessages
            .filter((message) => message.sender_type === 'user' || message.sender_type === 'system')
            .map((message) => message.sender_type === 'system' ? 'Bot' : (message.sender_name || 'Nhân viên'))
            .filter(Boolean))]
            .slice(0, 3);
        const phoneMatch = usableMessages
            .map((message) => message.content || '')
            .join(' ')
            .match(/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/);
        const phones = detectedPhonesFromMessages(usableMessages);
        const savedPhone = document.querySelector('[data-profile-phone]')?.textContent?.trim() || '';
        const latestPhone = phones.length ? phones[phones.length - 1] : '';
        const phoneText = savedPhone && latestPhone && savedPhone !== latestPhone && savedPhone !== 'Chưa có'
            ? 'dang luu so ' + savedPhone + ', khach vua gui them so ' + latestPhone
            : phoneMatch
            ? 'da co so dien thoai ' + latestPhone
            : 'chua lay duoc so dien thoai';
        const needText = needs.length
            ? [...new Set(needs)].join(' va ')
            : 'dang trao doi voi Toyota Kien Giang';
        const advisorText = advisors.length
            ? advisors.join(', ').replace(/, ([^,]*)$/, ' va $1') + ' da tu van'
            : 'chua co nhan vien tu van';

        return {
            text: `${customerName} ${needText}. ${advisorText} va ${phoneText}.`,
            facts: {phones, stored_phone: savedPhone, latest_phone: latestPhone},
        };
    }

    function detectedPhonesFromMessages(messages) {
        const phones = [];
        const pattern = /(?:\+?84|0)(?:[\s.\-]?\d){8,10}/g;

        (messages || []).forEach(function (message) {
            const matches = String(message.content || '').match(pattern) || [];

            matches.forEach(function (match) {
                const phone = match.replace(/[^\d+]/g, '');

                if (!phone) {
                    return;
                }

                const existingIndex = phones.indexOf(phone);

                if (existingIndex >= 0) {
                    phones.splice(existingIndex, 1);
                }

                phones.push(phone);
            });
        });

        return phones;
    }

    function renderConversationSummary(summary) {
        const list = document.querySelector('[data-conversation-summary-list]');

        if (!list) {
            return;
        }

        const text = typeof summary === 'string'
            ? summary
            : (summary?.text || 'Chưa có đủ nội dung để tóm tắt hội thoại.');

        list.dataset.detectedPhones = (summary?.facts?.phones || []).join('|');
        list.innerHTML = `<article class="summary-item"><p>${escapeHtml(text)}</p></article>`;
    }

    function appendConversationSummaryMessage(message) {
        if (!document.querySelector('[data-conversation-summary-list]') || !message || !hasRenderableMessage(message)) {
            return;
        }

        conversationSummaryMessages = [...conversationSummaryMessages.filter((item) => String(item.id) !== String(message.id)), message].slice(-40);
        const summary = semanticSummaryFromMessages(conversationSummaryMessages);
        renderConversationSummary(summary);
        renderDetectedPhones(summary?.facts?.phones || [], document.querySelector('[data-profile-phone]')?.textContent?.trim() || '', document.querySelector('[data-phone-candidates-list]')?.dataset.contactUrl || '');
    }

    function renderDetectedPhones(phones, savedPhone, contactUrl) {
        const list = document.querySelector('[data-phone-candidates-list]');
        const uniquePhones = [...new Set(phones || [])].filter(Boolean);
        const normalizedSavedPhone = String(savedPhone || '').trim();

        if (!list) {
            return;
        }

        if (contactUrl) {
            list.dataset.contactUrl = contactUrl;
        }

        if (!uniquePhones.length) {
            list.innerHTML = '';
            return;
        }

        list.innerHTML = `
            <span>Số điện thoại phát hiện</span>
            ${uniquePhones.map(function (phone) {
                const active = phone === normalizedSavedPhone;

                return `<button type="button" data-use-detected-phone="${escapeHtml(phone)}" class="${active ? 'is-active' : ''}">${escapeHtml(phone)}${active ? ' - dang luu' : ''}</button>`;
            }).join('')}
        `;
    }

    async function useDetectedPhone(phone) {
        const list = document.querySelector('[data-phone-candidates-list]');
        const url = list?.dataset.contactUrl || document.querySelector('[data-contact-section]')?.dataset.contactUrl || '';
        const contactForm = document.querySelector('[data-contact-form]');

        if (!url || !phone) {
            return;
        }

        const body = {
            name: contactForm?.querySelector('[name="name"]')?.value || document.querySelector('[data-profile-name]')?.textContent || 'Customer',
            phone,
            email: contactForm?.querySelector('[name="email"]')?.value || '',
        };

        const response = await fetch(url, {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
            },
            body: JSON.stringify(body),
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || 'Không cập nhật được số điện thoại.');
        }

        applyCustomerContact(payload.data || {});
    }

    function tagBadgesHtml(tags) {
        const visibleTags = (tags || []).filter((tag) => tag?.name).slice(0, 1);

        if (!visibleTags.length) {
            return '';
        }

        return `<span class="thread-tags">${visibleTags.map(function (tag) {
            return `<b style="--tag-color: ${escapeHtml(tag.color || '#64748b')}">${escapeHtml(tag.name)}</b>`;
        }).join('')}</span>`;
    }

    function updateThreadTags(conversationId, tags) {
        const thread = document.querySelector(`[data-thread-conversation-id="${conversationId}"]`);
        const body = thread?.querySelector('.thread-body');

        if (!body) {
            return;
        }

        body.querySelector('.thread-tags')?.remove();
        body.insertAdjacentHTML('beforeend', tagBadgesHtml(tags));
    }

    function selectedConversationTags() {
        const tabs = document.querySelector('[data-conversation-tags]');

        if (!tabs) {
            return [];
        }

        const button = tabs.querySelector('[data-tag-name].is-active');

        return button ? [{
            name: button.dataset.tagName || button.textContent.trim(),
            color: button.dataset.tagColor || null,
        }] : [];
    }

    async function loadConversation(url) {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            window.location.href = url;
            return;
        }

        const payload = await response.json();
        renderConversation(payload.data, url);
    }

    function updateClaimState(conversation) {
        const claimBar = document.querySelector('[data-claim-bar]');
        const claimStatus = document.querySelector('[data-claim-status]');
        const claimButton = document.querySelector('[data-claim-button]');
        const contentInput = composer?.querySelector('[name="content"]');
        const sendButton = composer?.querySelector('button[type="submit"]');
        const fileInput = composer?.querySelector('[data-composer-files]');
        const canReply = Boolean(conversation.can_reply);
        const canClaim = Boolean(conversation.can_claim);

        composer?.classList.toggle('is-disabled', !canReply);
        if (composer) {
            composer.dataset.canReply = canReply ? '1' : '0';
        }

        [contentInput, sendButton, fileInput].forEach(function (element) {
            if (element) {
                element.disabled = !canReply;
            }
        });

        if (!claimBar || !claimButton || !claimStatus) {
            return;
        }

        claimBar.classList.toggle('is-hidden', canReply && !canClaim);
        claimButton.disabled = !canClaim;
        claimButton.dataset.claimUrl = conversation.claim_url || '';
        claimStatus.textContent = canClaim
            ? 'Hội thoại mới trong ca trực của bạn.'
            : (canReply ? 'Bạn đang phụ trách hội thoại này.' : 'Bạn cần nhận xử lý trước khi trả lời.');
    }

    document.querySelector('[data-claim-button]')?.addEventListener('click', async function () {
        const button = this;
        const url = button.dataset.claimUrl;

        if (!url || button.disabled) {
            return;
        }

        button.disabled = true;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
            throw new Error(payload.message || 'Không nhận được hội thoại.');
            }

            const conversation = {
                id: timeline?.dataset.conversationId,
                can_claim: false,
                can_reply: true,
                claim_url: url,
            };
            updateClaimState(conversation);

            const assignee = document.querySelector('[data-chat-assignee]');
            if (assignee && payload.data?.assignee_name) {
                assignee.textContent = `Phụ trách: ${payload.data.assignee_name}`;
            }
        } catch (error) {
            const status = document.querySelector('[data-claim-status]');
            if (status) {
                status.textContent = error.message;
            }
            button.disabled = false;
        }
    });

    async function assignConversation(form) {
        const select = form.querySelector('[data-assign-select]');
        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('[data-assign-status]');
        const assignedTo = select?.value || '';
        const url = form.dataset.assignUrl;

        if (!assignedTo || !url) {
            if (status) {
                status.textContent = 'Chọn nhân viên';
            }
            return;
        }

        if (button) {
            button.disabled = true;
        }

        if (status) {
            status.textContent = 'Đang lưu...';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify({assigned_to: assignedTo}),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'Không phân công được hội thoại.');
            }

            const data = payload.data || {};
            const assignee = document.querySelector('[data-chat-assignee]');

            if (assignee) {
                assignee.textContent = data.assignee_name ? `Phụ trách: ${data.assignee_name}` : 'Chưa gán nhân viên';
            }

            updateClaimState({
                can_claim: false,
                can_reply: Boolean(data.can_reply),
                claim_url: data.claim_url || document.querySelector('[data-claim-button]')?.dataset.claimUrl || '',
            });
            await refreshThreadList();

            if (status) {
                status.textContent = 'Da luu';
            }
        } catch (error) {
            if (status) {
                status.textContent = error.message || 'Lưu thất bại';
            }
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    }

    function hasRenderableMessage(message) {
        const content = String(message?.content || '').trim();
        const attachments = Array.isArray(message?.attachments) ? message.attachments : [];

        return Boolean(message?.is_recalled || content || attachments.length);
    }

    function messageStatusText(message) {
        const status = String(message.outbound_status || message.status || '').toLowerCase();

        if (status === 'failed') {
            const error = String(message.outbound_error || '').trim();

            if (!error) {
                return 'gui loi';
            }

            return `gui loi: ${error.slice(0, 120)}`;
        }

        return '';
    }

    function attachmentHtml(attachment) {
        const url = escapeHtml(attachment.url || '#');
        const name = escapeHtml(attachment.name || 'Attachment');
        const type = String(attachment.type || '').toLowerCase();
        const mimeType = String(attachment.mime_type || '').toLowerCase();
        const urlPath = String(attachment.url || '').split('?')[0].toLowerCase();
        const isVisualAttachment = ['image', 'sticker'].includes(type)
            || mimeType.startsWith('image/')
            || /\.(png|jpe?g|gif|webp|bmp|avif)$/.test(urlPath);
        const isVideoAttachment = type === 'video'
            || mimeType.startsWith('video/')
            || /\.(mp4|mov|m4v|webm|ogg)$/.test(urlPath);
        const isAudioAttachment = type === 'audio'
            || mimeType.startsWith('audio/')
            || /\.(mp3|m4a|wav|aac|oga|ogg)$/.test(urlPath);
        const stickerId = String(attachment?.payload?.sticker_id || '');
        const visualClass = stickerId === '369239263222822' ? 'is-emoji' : 'is-sticker';

        if (isVisualAttachment) {
            return `<a class="message-image-link ${visualClass}" href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${name}" loading="lazy"></a>`;
        }

        if (isVideoAttachment) {
            return `<video class="message-video" src="${url}" controls preload="metadata"></video>`;
        }

        if (isAudioAttachment) {
            return `<audio class="message-audio" src="${url}" controls preload="metadata"></audio>`;
        }

        return `<a class="message-file-link" href="${url}" target="_blank" rel="noopener">${name}</a>`;
    }

    function createClientMessageId() {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }

        return `crm-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function appendPendingMessage(content, channel, files, clientMessageId, messageMode = 'message') {
        if (!timeline) {
            return null;
        }

        const tempId = `pending-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        const message = {
            id: tempId,
            conversation_id: Number(timeline.dataset.conversationId),
            sender_type: 'user',
            sender_id: Number(timeline.dataset.currentUserId),
            sender_name: 'Admin',
            channel,
            content,
            message_type: messageMode === 'whisper' ? 'whisper' : (files.length ? 'attachment' : 'text'),
            client_message_id: clientMessageId,
            attachments: Array.from(files || []).map(function (file) {
                return {
                    name: file.name,
                    url: file.type.startsWith('image/') ? URL.createObjectURL(file) : '#',
                    mime_type: file.type,
                    type: file.type.startsWith('image/') ? 'image' : 'file',
                };
            }),
            created_at: new Date().toISOString(),
            status: 'dang gui',
            outbound_status: 'sending',
        };
        const row = document.createElement('article');
        row.className = 'message-row mine is-pending';
        row.dataset.pendingMessageId = tempId;
        row.dataset.clientMessageId = clientMessageId;
        row.dataset.pendingContent = content;
        row.dataset.pendingAttachments = attachmentNames(message).join('|');
        row.dataset.pendingAttachmentPreview = JSON.stringify(message.attachments || []);
        row.innerHTML = messageRowHtml(message, true);

        timeline.appendChild(row);
        updateThreadPreview(message);
        timeline.scrollTop = timeline.scrollHeight;

        return tempId;
    }

    function replacePendingMessage(tempId, message) {
        const pending = tempId ? timeline?.querySelector(`[data-pending-message-id="${tempId}"]`) : null;
        const existing = message?.id ? timeline?.querySelector(`[data-message-id="${message.id}"]`) : null;

        if (existing) {
            pending?.remove();
            updateThreadPreview(message);
            return;
        }

        if (!pending) {
            appendMessage(message);
            return;
        }

        pending.classList.remove('is-pending');
        pending.classList.toggle('is-failed', message?.outbound_status === 'failed');
        pending.dataset.messageId = message.id;
        pending.dataset.clientMessageId = message.client_message_id || pending.dataset.clientMessageId || '';
        delete pending.dataset.pendingMessageId;
        pending.innerHTML = messageRowHtml(mergePendingPreview(pending, message), true);
        timeline.dataset.lastMessageId = String(Math.max(Number(timeline.dataset.lastMessageId || 0), Number(message.id)));
        updateThreadPreview(message);
        appendConversationSummaryMessage(message);
    }

    function mergePendingPreview(pending, message) {
        const hasServerAttachments = Array.isArray(message?.attachments) && message.attachments.length > 0;

        if (hasServerAttachments || !pending) {
            return message;
        }

        const pendingAttachments = safeJson(pending.dataset.pendingAttachmentPreview, []);

        if (!pendingAttachments.length) {
            return message;
        }

        return {
            ...message,
            attachments: pendingAttachments,
        };
    }

    function safeJson(value, fallback) {
        try {
            return JSON.parse(value || '');
        } catch (error) {
            return fallback;
        }
    }

    function markPendingFailed(tempId, error) {
        const pending = tempId ? timeline?.querySelector(`[data-pending-message-id="${tempId}"]`) : null;

        if (!pending) {
            return;
        }

        pending.classList.remove('is-pending');
        pending.classList.add('is-failed');
        const time = pending.querySelector('time');

        if (time) {
            time.textContent = `Gửi thất bại - ${error}`;
        }
    }

    function attachmentPreviewText(attachments) {
        const first = Array.isArray(attachments) ? attachments[0] : null;

        if (!first) {
            return '';
        }

        const type = String(first.type || '').toLowerCase();
        const mime = String(first.mime_type || '').toLowerCase();
        const payload = first.payload || {};

        if (type === 'sticker' || payload.sticker_id) {
            return '[Emoji]';
        }

        if (type === 'image' || mime.startsWith('image/') || payload.image_data?.url) {
            return '[Hinh anh]';
        }

        if (type === 'video' || mime.startsWith('video/') || payload.video_data?.url) {
            return '[Video]';
        }

        if (type === 'audio' || mime.startsWith('audio/') || payload.audio_data?.url) {
            return '[Audio]';
        }

        return '[Tep dinh kem]';
    }

    function messagePreviewText(message) {
        let content = String(message?.content || '').trim();

        if (!content) {
            content = attachmentPreviewText(message?.attachments || []);
        }

        if (!content) {
            content = 'Chưa có tin nhắn';
        }

        if (message?.is_recalled) {
            content = 'Tin nhan da duoc thu hoi';
        }

        if (message?.message_type === 'whisper' || message?.channel === 'internal') {
            return `Thi tham: ${content}`;
        }

        if (message?.sender_type === 'user') {
            return `Ban: ${content}`;
        }

        return content;
    }

    function updateThreadPreview(message) {
        const thread = document.querySelector(`[data-thread-conversation-id="${message.conversation_id}"]`);

        if (!thread) {
            return;
        }

        const preview = thread.querySelector('[data-thread-last-message]');
        const meta = thread.querySelector('[data-thread-meta]');

        if (preview) {
            preview.textContent = messagePreviewText(message);
        }

        if (meta) {
            meta.textContent = relativeThreadTime(message.conversation_last_message_at || message.created_at);
        }
    }

    function updateThreadUnread(thread, count) {
        if (!thread) {
            return;
        }

        const unreadCount = Math.max(0, Number(count || 0));
        const badge = thread.querySelector('[data-thread-unread-badge]');

        thread.dataset.threadUnreadCount = String(unreadCount);
        thread.classList.toggle('is-unread', unreadCount > 0);

        if (badge) {
            badge.textContent = unreadCount > 0 ? String(unreadCount) : '';
            badge.hidden = unreadCount === 0;
        }
    }

    function markThreadRead(conversationId) {
        const thread = document.querySelector(`[data-thread-conversation-id="${conversationId}"]`);
        updateThreadUnread(thread, 0);
    }

    async function markActiveConversationRead() {
        if (!timeline?.dataset.readUrl) {
            return;
        }

        try {
            await fetch(timeline.dataset.readUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
            });
            markThreadRead(timeline.dataset.conversationId);
        } catch (error) {
            console.warn('CRM messenger mark read failed:', error);
        }
    }

    function upsertThread(message) {
        const list = document.querySelector('.messenger-thread-list');

        if (!list || !message?.conversation_id) {
            return;
        }

        let thread = list.querySelector(`[data-thread-conversation-id="${message.conversation_id}"]`);
        const isActiveThread = String(message.conversation_id) === String(timeline?.dataset.conversationId);

        if (!messageMatchesCurrentFilters(message)) {
            if (thread && !isActiveThread) {
                thread.remove();
            }
            return;
        }

        list.querySelector('.messenger-empty')?.remove();

        if (!thread) {
            thread = document.createElement('a');
            thread.className = 'messenger-thread';
            thread.href = message.conversation_url || `/conversations/${encodeURIComponent(message.conversation_id)}`;
            thread.dataset.threadConversationId = message.conversation_id;
            thread.dataset.conversationUrl = thread.href;
            thread.dataset.threadUnreadCount = '0';
            const customerName = message.conversation_customer_name || message.sender_name || 'Customer';
            const customerAvatar = message.conversation_customer_avatar || message.sender_avatar;
            thread.innerHTML = `
                <span class="thread-avatar">${avatarHtml(customerAvatar, customerName)}${platformIconHtml(message.channel)}</span>
                <span class="thread-body">
                    <strong>${escapeHtml(customerName)}</strong>
                    <small data-thread-last-message></small>
                </span>
                <span class="thread-side">
                    <span class="thread-meta" data-thread-meta></span>
                    <span class="thread-unread-badge" data-thread-unread-badge></span>
                </span>
            `;
            list.prepend(thread);
        } else {
            list.prepend(thread);
            const avatar = thread.querySelector('.thread-avatar');
            const name = thread.querySelector('.thread-body strong');

            const customerName = message.conversation_customer_name || (message.sender_type === 'customer' ? message.sender_name : '');
            const customerAvatar = message.conversation_customer_avatar || (message.sender_type === 'customer' ? message.sender_avatar : '');

            if (customerName && avatar) {
                avatar.innerHTML = avatarHtml(customerAvatar, customerName) + platformIconHtml(message.channel);
            }

            if (customerName && name) {
                name.textContent = customerName;
            }
        }

        updateThreadPreview(message);
        updateThreadTags(message.conversation_id, message.conversation_tags || []);

        if (isActiveThread) {
            updateConversationTags(document.querySelector('[data-conversation-tags]')?.dataset.tagsUrl || '', message.conversation_tags || []);

            if (message.conversation_unread_messages_count !== undefined) {
                updateThreadUnread(thread, message.conversation_unread_messages_count);
            }

            return;
        }

        if (message.conversation_unread_messages_count !== undefined) {
            updateThreadUnread(thread, message.conversation_unread_messages_count);
            return;
        }

        if (message.sender_type === 'customer') {
            updateThreadUnread(thread, Number(thread.dataset.threadUnreadCount || 0) + 1);
        } else if (message.sender_type === 'user' || message.sender_type === 'system') {
            updateThreadUnread(thread, 0);
        }
    }

    function messageMatchesCurrentFilters(message) {
        const channelFilter = realtimeRoot?.dataset.channelFilter || 'all';
        const messageChannel = String(message?.channel || '');

        if (channelFilter !== 'all' && ['facebook', 'zalo'].includes(messageChannel) && messageChannel !== channelFilter) {
            return false;
        }

        const statusFilter = realtimeRoot?.dataset.statusFilter || 'all';
        const currentUserId = Number(realtimeRoot?.dataset.currentUserId || timeline?.dataset.currentUserId || 0);

        if (statusFilter === 'unread' && Number(message?.conversation_unread_messages_count || 0) <= 0) {
            return false;
        }

        if (statusFilter === 'mine' && Number(message?.conversation_assigned_to || 0) !== currentUserId) {
            return false;
        }

        const tagFilter = String(realtimeRoot?.dataset.tagFilter || '').trim().toLowerCase();

        if (tagFilter) {
            const tags = Array.isArray(message?.conversation_tags) ? message.conversation_tags : [];
            return tags.some((tag) => String(tag?.name || '').trim().toLowerCase() === tagFilter);
        }

        return true;
    }

    function platformIconHtml(channel) {
        const normalized = String(channel || 'facebook').toLowerCase();
        const asset = normalized === 'zalo' ? '/assets/img_zalo.png' : '/assets/img_fb.png';
        const label = normalized === 'zalo' ? 'Zalo' : 'Facebook';

        return `<img class="thread-platform-icon" src="${asset}" alt="${label}">`;
    }

    async function refreshThreadList() {
        const list = document.querySelector('.messenger-thread-list');

        if (!list || !realtimeRoot?.dataset.conversationsUrl) {
            return;
        }

        const url = new URL(window.location.href);
        url.pathname = new URL(realtimeRoot.dataset.conversationsUrl, window.location.origin).pathname;

        const response = await fetch(url, {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const freshList = doc.querySelector('.messenger-thread-list');

        if (freshList) {
            list.innerHTML = freshList.innerHTML;
            const activeId = timeline?.dataset.conversationId;

            if (activeId) {
                list.querySelector(`[data-thread-conversation-id="${activeId}"]`)?.classList.add('active');
            }
        }
    }

    async function fetchNewMessages() {
        if (!timeline?.dataset.pollUrl) {
            return;
        }

        const url = new URL(timeline.dataset.pollUrl, window.location.origin);
        url.searchParams.set('after_id', timeline.dataset.lastMessageId || '0');

        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        const messages = payload.data || [];
        messages.forEach(appendMessage);
    }

    async function loadOlderMessages() {
        if (
            !timeline?.dataset.pollUrl ||
            olderMessagesLoading ||
            timeline.dataset.hasOlderMessages !== '1'
        ) {
            return;
        }

        const beforeId = Number(timeline.dataset.oldestMessageId || 0);

        if (!beforeId) {
            return;
        }

        olderMessagesLoading = true;
        const previousScrollHeight = timeline.scrollHeight;
        const previousScrollTop = timeline.scrollTop;

        try {
            const url = new URL(timeline.dataset.pollUrl, window.location.origin);
            url.searchParams.set('before_id', String(beforeId));
            url.searchParams.set('limit', '10');

            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            const messages = Array.isArray(payload.data) ? payload.data : [];

            messages.forEach(prependMessage);

            const meta = payload.meta || {};
            const oldestId = Number(meta.oldest_id || messages[0]?.id || beforeId);
            timeline.dataset.oldestMessageId = String(oldestId || beforeId);
            timeline.dataset.hasOlderMessages = meta.has_more ? '1' : '0';
            timeline.scrollTop = timeline.scrollHeight - previousScrollHeight + previousScrollTop;
        } finally {
            olderMessagesLoading = false;
        }
    }

    let messageSocket = null;
    let streamSource = null;
    let inboxStreamSource = null;
    let streamReconnectTimer = null;
    let inboxStreamReconnectTimer = null;
    let pollingTimer = null;
    let fallbackTimer = null;
    let pollingInFlight = false;
    let realtimeMode = 'none';
    let reconnectTimer = null;

    function parsePusherData(data) {
        return typeof data === 'string' ? JSON.parse(data) : data;
    }

    async function subscribeToChannels(socket, socketId, channels) {
        for (const channel of [...new Set(channels)]) {
            const response = await fetch(realtimeRoot.dataset.broadcastAuthUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify({
                    socket_id: socketId,
                    channel_name: channel,
                }),
            });

            if (!response.ok) {
                const details = await response.text().catch(() => '');
                throw new Error(`Broadcast authentication failed (${response.status}): ${details.slice(0, 160)}`);
            }

            const auth = await response.json();
            socket.send(JSON.stringify({
                event: 'pusher:subscribe',
                data: {
                    auth: auth.auth,
                    channel,
                },
            }));
        }
    }

    function handleRealtimeMessage(message) {
        upsertThread(message);

        if (String(message.conversation_id) === String(timeline?.dataset.conversationId)) {
            const chatPhone = document.querySelector('[data-chat-customer-phone]');

            if (chatPhone && message.conversation_customer_phone) {
                chatPhone.textContent = message.conversation_customer_phone;
            }

            updateProfilePanel(message);
            appendMessage(message);

            if (message.sender_type === 'customer') {
                markActiveConversationRead();
            }
        }
    }

    function removeMessageRows(messageIds) {
        (messageIds || []).forEach(function (messageId) {
            timeline?.querySelector(`[data-message-id="${messageId}"]`)?.remove();
        });
    }

    async function deleteConversation(button) {
        const url = button?.dataset.deleteConversationUrl;

        if (!url || !window.confirm('Xoa hoi thoai nay khoi CRM?')) {
            return;
        }

        button.disabled = true;

        try {
            const response = await fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
            throw new Error(payload.message || 'Không xóa được hội thoại.');
            }

            handleDeletedMessages(payload.data || {});
        } catch (error) {
            window.alert(error.message || 'Không xóa được hội thoại.');
        } finally {
            button.disabled = false;
        }
    }

    function handleDeletedMessages(payload) {
        if (payload.delete_conversation) {
            removeConversationThread(payload.conversation_id);
            return;
        }

        if (String(payload?.conversation_id) !== String(timeline?.dataset.conversationId)) {
            return;
        }

        if (payload.clear_all) {
            timeline?.querySelectorAll('.message-row').forEach((row) => row.remove());
            timeline.dataset.oldestMessageId = '0';
            timeline.dataset.lastMessageId = '0';
            timeline.dataset.hasOlderMessages = '0';
            updateThreadPreview({
                conversation_id: payload.conversation_id,
                content: 'Da xoa toan bo tin nhan',
            });
            markThreadRead(payload.conversation_id);
            return;
        }

        removeMessageRows(payload.message_ids || []);
        updateThreadPreview({
            conversation_id: payload.conversation_id,
            content: 'Da xoa tin nhan',
        });
    }

    function handleRealtimeConversation(payload) {
        const conversation = payload.conversation || payload;

        if (!conversation?.id) {
            return;
        }

        refreshThreadList();
    }

    function removeConversationThread(conversationId) {
        const thread = document.querySelector(`[data-thread-conversation-id="${conversationId}"]`);
        const wasActive = String(conversationId) === String(timeline?.dataset.conversationId);
        const fallbackThread = thread?.nextElementSibling?.matches?.('.messenger-thread')
            ? thread.nextElementSibling
            : thread?.previousElementSibling?.matches?.('.messenger-thread')
            ? thread.previousElementSibling
            : document.querySelector(`.messenger-thread:not([data-thread-conversation-id="${conversationId}"])`);

        thread?.remove();

        if (!wasActive) {
            return;
        }

        if (fallbackThread) {
            loadConversation(fallbackThread.dataset.conversationUrl || fallbackThread.href);
            return;
        }

        window.location.href = realtimeRoot?.dataset.conversationsUrl || '/conversations';
    }

    async function runPollingLoop() {
        if (document.hidden || realtimeMode === 'websocket') {
            stopPolling();
            return;
        }

        if (!pollingInFlight) {
            pollingInFlight = true;

            try {
                await fetchNewMessages();
                await refreshThreadList();
            } finally {
                pollingInFlight = false;
            }
        }

        pollingTimer = window.setTimeout(runPollingLoop, 30000);
    }

    function startPolling() {
        if (!pollingTimer && realtimeMode !== 'websocket') {
            console.info('CRM messenger realtime fallback: polling every 30s');
            pollingTimer = window.setTimeout(runPollingLoop, 0);
        }
    }

    function stopPolling() {
        if (pollingTimer) {
            window.clearTimeout(pollingTimer);
            pollingTimer = null;
        }
    }

    function stopFallbackTimer() {
        if (fallbackTimer) {
            window.clearTimeout(fallbackTimer);
            fallbackTimer = null;
        }
    }

    function stopReconnectTimer() {
        if (reconnectTimer) {
            window.clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
    }

    function stopStreamReconnectTimer() {
        if (streamReconnectTimer) {
            window.clearTimeout(streamReconnectTimer);
            streamReconnectTimer = null;
        }
    }

    function stopInboxStreamReconnectTimer() {
        if (inboxStreamReconnectTimer) {
            window.clearTimeout(inboxStreamReconnectTimer);
            inboxStreamReconnectTimer = null;
        }
    }

    function closeMessageStream() {
        stopStreamReconnectTimer();

        if (streamSource) {
            streamSource.close();
            streamSource = null;
        }
    }

    function closeInboxMessageStream() {
        stopInboxStreamReconnectTimer();

        if (inboxStreamSource) {
            inboxStreamSource.close();
            inboxStreamSource = null;
        }
    }

    function closeMessageSocket() {
        if (messageSocket) {
            messageSocket.manualClose = true;
            messageSocket.close();
            messageSocket = null;
        }
    }

    function startMessageStream() {
        if (
            !timeline?.dataset.streamUrl ||
            !window.EventSource ||
            realtimeMode === 'websocket'
        ) {
            return false;
        }

        if (streamSource) {
            return true;
        }

        closeMessageStream();

        const url = new URL(timeline.dataset.streamUrl, window.location.origin);
        url.searchParams.set('after_id', timeline.dataset.lastMessageId || '0');

        streamSource = new EventSource(url);

        streamSource.addEventListener('open', function () {
            console.info('CRM messenger realtime: stream connected');
            realtimeMode = realtimeMode === 'websocket' ? 'websocket' : 'stream';
        });

        streamSource.addEventListener('message', function (event) {
            try {
                const message = JSON.parse(event.data);
                handleRealtimeMessage(message);
            } catch (error) {
                console.warn('CRM messenger stream message failed:', error);
            }
        });

        streamSource.addEventListener('error', function () {
            closeMessageStream();

            if (document.hidden || realtimeMode === 'websocket') {
                return;
            }

            realtimeMode = 'polling';
            startPolling();
            streamReconnectTimer = window.setTimeout(function () {
                if (!document.hidden && realtimeMode !== 'websocket') {
                    startMessageStream();
                }
            }, 2500);
        });

        return true;
    }

    function startInboxMessageStream() {
        if (
            !realtimeRoot?.dataset.inboxStreamUrl ||
            !window.EventSource ||
            realtimeMode === 'websocket'
        ) {
            return false;
        }

        if (inboxStreamSource) {
            return true;
        }

        closeInboxMessageStream();

        const url = new URL(realtimeRoot.dataset.inboxStreamUrl, window.location.origin);
        url.searchParams.set('after_id', realtimeRoot.dataset.inboxLastMessageId || '0');

        inboxStreamSource = new EventSource(url);

        inboxStreamSource.addEventListener('open', function () {
            console.info('CRM messenger realtime: inbox stream connected');
            realtimeMode = realtimeMode === 'websocket' ? 'websocket' : 'stream';
        });

        inboxStreamSource.addEventListener('message', function (event) {
            try {
                const message = JSON.parse(event.data);
                realtimeRoot.dataset.inboxLastMessageId = String(Math.max(
                    Number(realtimeRoot.dataset.inboxLastMessageId || 0),
                    Number(message.id || 0),
                ));
                handleRealtimeMessage(message);
            } catch (error) {
                console.warn('CRM messenger inbox stream message failed:', error);
            }
        });

        inboxStreamSource.addEventListener('error', function () {
            closeInboxMessageStream();

            if (document.hidden || realtimeMode === 'websocket') {
                return;
            }

            realtimeMode = 'polling';
            startPolling();
            inboxStreamReconnectTimer = window.setTimeout(function () {
                if (!document.hidden && realtimeMode !== 'websocket') {
                    startInboxMessageStream();
                }
            }, 2500);
        });

        return true;
    }

    function startBroadcastSocket() {
        if (
            !realtimeRoot?.dataset.reverbKey ||
            !realtimeRoot?.dataset.reverbHost ||
            !window.WebSocket
        ) {
            return false;
        }

        closeMessageSocket();

        const port = realtimeRoot.dataset.reverbPort ? `:${realtimeRoot.dataset.reverbPort}` : '';
        const socketUrl = `${realtimeRoot.dataset.reverbScheme}://${realtimeRoot.dataset.reverbHost}${port}/app/${encodeURIComponent(realtimeRoot.dataset.reverbKey)}?protocol=7&client=crm-web&version=1.0&flash=false`;
        console.info('CRM messenger websocket connecting:', socketUrl);
        messageSocket = new WebSocket(socketUrl);
        const socket = messageSocket;

        socket.addEventListener('message', async function (event) {
            try {
                const payload = JSON.parse(event.data);

                if (payload.event === 'pusher:connection_established') {
                    const connection = parsePusherData(payload.data);
                    await subscribeToChannels(socket, connection.socket_id, [
                        timeline?.dataset.broadcastChannel,
                        realtimeRoot.dataset.inboxBroadcastChannel,
                    ].filter(Boolean));

                    return;
                }

                if (payload.event === 'pusher_internal:subscription_succeeded') {
                    console.info('CRM messenger realtime: websocket connected');
                    realtimeMode = 'websocket';
                    stopFallbackTimer();
                    stopPolling();
                    closeMessageStream();
                    closeInboxMessageStream();
                    return;
                }

                if (payload.event === 'message.created' || payload.event === 'message.updated') {
                    const data = parsePusherData(payload.data);
                    handleRealtimeMessage(data.message || data);
                }

                if (payload.event === 'message.deleted') {
                    handleDeletedMessages(parsePusherData(payload.data));
                }

                if (String(payload.event || '').startsWith('conversation.')) {
                    handleRealtimeConversation(parsePusherData(payload.data));
                }
            } catch (error) {
                console.warn('CRM messenger websocket message failed:', error);
                closeMessageSocket();
                realtimeMode = 'polling';
                startPolling();
            }
        });

        socket.addEventListener('close', function (event) {
            if (socket.manualClose) {
                return;
            }

            if (messageSocket === socket) {
                messageSocket = null;
            }

            console.warn('CRM messenger websocket closed:', {
                code: event.code,
                reason: event.reason,
                wasClean: event.wasClean,
            });

            if (!document.hidden) {
                realtimeMode = 'polling';
                stopFallbackTimer();
                startInboxMessageStream();
                startMessageStream();
                startPolling();
                stopReconnectTimer();
                reconnectTimer = window.setTimeout(startRealtime, 10000);
            }
        });

        socket.addEventListener('error', function (event) {
            console.warn('CRM messenger websocket error:', event);
            closeMessageSocket();
            realtimeMode = 'polling';
            stopFallbackTimer();
            startInboxMessageStream();
            startMessageStream();
            startPolling();
        });

        return true;
    }

    function startRealtime() {
        if (
            messageSocket &&
            (messageSocket.readyState === WebSocket.CONNECTING || messageSocket.readyState === WebSocket.OPEN)
        ) {
            return;
        }

        closeMessageSocket();
        closeMessageStream();
        closeInboxMessageStream();
        stopReconnectTimer();
        stopFallbackTimer();
        stopPolling();
        realtimeMode = 'connecting';

        const socketStarted = startBroadcastSocket();
        const inboxStreamStarted = startInboxMessageStream();
        const messageStreamStarted = startMessageStream();

        if (socketStarted) {
            fallbackTimer = window.setTimeout(function () {
                if (realtimeMode !== 'websocket') {
                    realtimeMode = inboxStreamStarted || messageStreamStarted ? 'stream' : 'polling';
                    startInboxMessageStream();
                    startMessageStream();
                    startPolling();
                }
            }, 3000);
            return;
        }

        realtimeMode = inboxStreamStarted || messageStreamStarted ? 'stream' : 'polling';
        startPolling();
    }

    if (timeline) {
        timeline.scrollTop = timeline.scrollHeight;
        timeline.addEventListener('scroll', function () {
            if (timeline.scrollTop <= 80) {
                loadOlderMessages();
            }
        });
    }

    startRealtime();
    renderCustomerTagOptions('');

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            Promise.all([
                fetchNewMessages(),
                refreshThreadList(),
            ]).finally(function () {
                if (realtimeMode === 'none' || realtimeMode === 'polling') {
                    startRealtime();
                }
            });

            return;
        }

        closeMessageSocket();
        closeMessageStream();
        closeInboxMessageStream();
        stopFallbackTimer();
        stopReconnectTimer();
        stopPolling();
        realtimeMode = 'none';
    });

    composer?.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (composer.dataset.canReply !== '1') {
            document.querySelector('[data-claim-status]')?.scrollIntoView({block: 'center'});
            return;
        }

        const input = composer.querySelector('[name="content"]');
        const button = composer.querySelector('button[type="submit"]');
        const fileInput = composer.querySelector('[data-composer-files]');
        const fileList = composer.querySelector('[data-composer-file-list]');
        const content = String(input?.value || '').trim();
        const channel = String(composer.querySelector('input[name="channel"]')?.value || '');
        const messageMode = String(composer.querySelector('[name="message_mode"]')?.value || 'message');
        const files = fileInput?.files || [];

        if (!content && files.length === 0) {
            return;
        }

        const clientMessageId = createClientMessageId();
        const pendingId = appendPendingMessage(content, messageMode === 'whisper' ? 'internal' : channel, files, clientMessageId, messageMode);

        button.disabled = true;
        input.value = '';
        if (fileList) {
            fileList.textContent = '';
        }
        input.focus();

        try {
            const uploadedAttachments = files.length ? await attachmentUpload.promise : [];
            const formData = new FormData();
            formData.set('channel', channel);
            formData.set('message_mode', messageMode);
            formData.set('client_message_id', clientMessageId);

            if (content) {
                formData.set('content', content);
            }

            uploadedAttachments.forEach(function (attachment, index) {
                Object.entries(attachment).forEach(function ([key, value]) {
                    if (value !== null && value !== undefined) {
                        formData.append(`uploaded_attachments[${index}][${key}]`, String(value));
                    }
                });
            });

            const response = await fetch(composer.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: formData,
            });

            if (!response.ok) {
                const payload = await response.json().catch(function () {
                    return {};
                });

                throw new Error(payload.message || 'Không gửi được tin nhắn.');
            }

            const payload = await response.json();
            replacePendingMessage(pendingId, payload.data);
            if (
                Array.isArray(payload.data?.conversation_tags)
                && String(payload.data?.conversation_id) === String(timeline?.dataset.conversationId)
            ) {
                updateConversationTags(document.querySelector('[data-conversation-tags]')?.dataset.tagsUrl || '', payload.data.conversation_tags);
                updateThreadTags(payload.data.conversation_id, payload.data.conversation_tags);
            }
            if (fileInput) {
                fileInput.value = '';
            }
            attachmentUpload = {
                files: [],
                promise: Promise.resolve([]),
                uploaded: [],
            };
        } catch (error) {
            markPendingFailed(pendingId, error.message);
        } finally {
            button.disabled = false;
        }
    });

    document.querySelector('[data-refresh-suggestions]')?.addEventListener('click', function (event) {
        event.preventDefault();
        renderReplySuggestions({refresh: true});
    });

    document.querySelector('[data-reply-suggestion-list]')?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-reply-suggestion]');
        const list = event.currentTarget;

        if (!button) {
            return;
        }

        if (list?.dataset.dragged === '1') {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        applyReplySuggestion(button.dataset.replySuggestion || button.textContent || '');
    });

    composer?.querySelector('[name="content"]')?.addEventListener('focus', function () {
        renderReplySuggestions();
    });

    composer?.querySelector('[data-composer-files]')?.addEventListener('change', function (event) {
        const fileList = composer.querySelector('[data-composer-file-list]');
        const files = Array.from(event.target.files || []);

        if (fileList) {
            fileList.textContent = files.length ? 'Đang tải: ' + files.map((file) => file.name).join(', ') : '';
        }

        attachmentUpload.files = files;
        attachmentUpload.uploaded = [];
        attachmentUpload.promise = uploadComposerAttachments(files)
            .then(function (attachments) {
                attachmentUpload.uploaded = attachments;

                if (fileList) {
                    fileList.textContent = attachments.map((attachment) => attachment.name).join(', ');
                }

                return attachments;
            })
            .catch(function (error) {
                if (fileList) {
                    fileList.textContent = `Tai file loi: ${error.message}`;
                }

                throw error;
            });
    });

    composer?.querySelector('[name="content"]')?.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
            return;
        }

        event.preventDefault();
        composer.requestSubmit();
    });

    composer?.querySelectorAll('[data-composer-mode]')?.forEach(function (button) {
        button.addEventListener('click', function () {
            const mode = button.dataset.composerMode || 'message';
            const modeInput = composer.querySelector('[name="message_mode"]');
            const textInput = composer.querySelector('[name="content"]');

            composer.querySelectorAll('[data-composer-mode]').forEach(function (modeButton) {
                modeButton.classList.toggle('active', modeButton === button);
            });

            if (modeInput) {
                modeInput.value = mode;
            }

            if (textInput) {
                textInput.placeholder = mode === 'whisper'
                    ? 'Nhập ghi chú nội bộ, chỉ nhân viên thấy'
                    : 'Nhập nội dung tin nhắn và nhấn Enter để gửi';
                textInput.focus();
            }
        });
    });

    document.querySelectorAll('[data-conversation-tags]').forEach(function (tabs) {
        let dragging = false;
        let startX = 0;
        let startScrollLeft = 0;
        let moved = false;

        tabs.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || event.target.closest('button, a, input, label, select, textarea')) {
                return;
            }

            dragging = true;
            moved = false;
            startX = event.clientX;
            startScrollLeft = tabs.scrollLeft;
            tabs.classList.add('is-dragging');
            tabs.setPointerCapture?.(event.pointerId);
        });

        tabs.addEventListener('pointermove', function (event) {
            if (!dragging) {
                return;
            }

            const delta = event.clientX - startX;

            if (Math.abs(delta) > 4) {
                moved = true;
                tabs.dataset.dragged = '1';
                event.preventDefault();
            }

            tabs.scrollLeft = startScrollLeft - delta;
        });

        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (eventName) {
            tabs.addEventListener(eventName, function (event) {
                if (!dragging) {
                    return;
                }

                dragging = false;
                tabs.classList.remove('is-dragging');
                tabs.releasePointerCapture?.(event.pointerId);

                if (moved) {
                    window.setTimeout(function () {
                        delete tabs.dataset.dragged;
                    }, 0);
                }
            });
        });

        tabs.addEventListener('wheel', function (event) {
            if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
                return;
            }

            event.preventDefault();
            tabs.scrollLeft += event.deltaY;
        }, {passive: false});
    });

    document.querySelectorAll('[data-reply-suggestion-list]').forEach(function (list) {
        let dragging = false;
        let startX = 0;
        let startScrollLeft = 0;
        let moved = false;

        list.addEventListener('pointerdown', function (event) {
            if (event.button !== 0) {
                return;
            }

            dragging = true;
            moved = false;
            startX = event.clientX;
            startScrollLeft = list.scrollLeft;
            list.classList.add('is-dragging');
            list.setPointerCapture?.(event.pointerId);
        });

        list.addEventListener('pointermove', function (event) {
            if (!dragging) {
                return;
            }

            const delta = event.clientX - startX;

            if (Math.abs(delta) > 4) {
                moved = true;
                list.dataset.dragged = '1';
                event.preventDefault();
            }

            list.scrollLeft = startScrollLeft - delta;
        });

        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (eventName) {
            list.addEventListener(eventName, function (event) {
                if (!dragging) {
                    return;
                }

                dragging = false;
                list.classList.remove('is-dragging');
                list.releasePointerCapture?.(event.pointerId);

                if (moved) {
                    window.setTimeout(function () {
                        delete list.dataset.dragged;
                    }, 0);
                }
            });
        });

        list.addEventListener('wheel', function (event) {
            if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
                return;
            }

            event.preventDefault();
            list.scrollLeft += event.deltaY;
        }, {passive: false});
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-open-tag-manager]')) {
            event.preventDefault();
            openTagManager();
            return;
        }

        if (event.target.closest('[data-close-tag-manager]')) {
            event.preventDefault();
            closeTagManager();
            return;
        }

        if (event.target === tagManagerModal()) {
            closeTagManager();
            return;
        }

        const editButton = event.target.closest('[data-edit-tag]');

        if (editButton) {
            event.preventDefault();
            const row = editButton.closest('[data-tag-manager-row]');
            const form = tagManagerForm();

            if (!row || !form) {
                return;
            }

            form.querySelector('[data-tag-manager-id]').value = row.dataset.tagId || '';
            form.querySelector('[data-tag-manager-name]').value = row.dataset.tagName || '';
            form.querySelector('[data-tag-manager-name]').readOnly = row.dataset.tagDefault === '1';
            form.querySelector('[data-tag-manager-color]').value = row.dataset.tagColor || '#2563eb';
            form.querySelector('[data-tag-manager-submit]').textContent = 'Cap nhat';
            form.querySelector('[data-tag-manager-color]').focus();
            return;
        }

        const deleteButton = event.target.closest('[data-delete-tag]');

        if (deleteButton) {
            event.preventDefault();
            const row = deleteButton.closest('[data-tag-manager-row]');
            const deleteUrl = row?.dataset.tagDeleteUrl;

            if (!row || deleteButton.disabled) {
                return;
            }

            const tag = tagPayloadFromButton({
                dataset: {
                    tagId: row.dataset.tagId,
                    tagName: row.dataset.tagName,
                    tagColor: row.dataset.tagColor,
                    tagDefault: row.dataset.tagDefault,
                },
                textContent: row.dataset.tagName || '',
            });

            if (tag.is_default) {
                setTagManagerStatus('Tag mac dinh khong duoc xoa.', true);
                return;
            }

            const url = deleteUrl || row.dataset.tagDeleteUrl;

            if (!url) {
                setTagManagerStatus('Thieu URL xoa tag.', true);
                return;
            }

            fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
            })
                .then(function (response) {
                    if (!response.ok) {
            throw new Error('Không xóa được tag.');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    applyTagCatalog(payload.data?.tags || []);
                    resetTagManagerForm();
                    setTagManagerStatus('Da xoa tag.');
                    refreshThreadList();
                })
                .catch(function (error) {
            setTagManagerStatus(error.message || 'Không xóa được tag.', true);
                });
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !tagManagerModal()?.hidden) {
            closeTagManager();
        }
    });

    tagManagerForm()?.addEventListener('submit', async function (event) {
        event.preventDefault();

        const form = event.currentTarget;
        const endpoints = tagManagerEndpoints();
        const id = form.querySelector('[data-tag-manager-id]')?.value || '';
        const name = form.querySelector('[data-tag-manager-name]')?.value.trim() || '';
        const color = form.querySelector('[data-tag-manager-color]')?.value || '#2563eb';
        const row = id ? document.querySelector(`[data-tag-manager-row][data-tag-id="${CSS.escape(id)}"]`) : null;
        const url = id ? row?.dataset.tagUpdateUrl : endpoints.store;

        if (!name || !url) {
            setTagManagerStatus('Vui long nhap ten tag.', true);
            return;
        }

        try {
            const response = await fetch(url, {
                method: id ? 'PATCH' : 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify({name, color}),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
            throw new Error(payload.message || 'Không lưu được tag.');
            }

            applyTagCatalog(payload.data?.tags || []);
            resetTagManagerForm();
            setTagManagerStatus('Da luu tag.');
            await refreshThreadList();
        } catch (error) {
            setTagManagerStatus(error.message || 'Không lưu được tag.', true);
        }
    });

    document.querySelector('[data-tag-manager-reset]')?.addEventListener('click', function () {
        resetTagManagerForm();
        setTagManagerStatus('');
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-tag-name]');
        const tabs = button?.closest('[data-conversation-tags]');

        if (!button || !tabs) {
            return;
        }

        if (tabs.dataset.dragged === '1') {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        const wasActive = button.classList.contains('is-active');
        const conversationId = timeline?.dataset.conversationId;
        const previousTags = selectedConversationTags();

        tabs.querySelectorAll('[data-tag-name].is-active').forEach(function (activeButton) {
            activeButton.classList.remove('is-active');
        });

        if (!wasActive) {
            button.classList.add('is-active');
        }

        updateThreadTags(conversationId, selectedConversationTags());

        try {
            const response = await fetch(tabs.dataset.tagsUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
                },
                body: JSON.stringify({
                    tags: selectedConversationTags(),
                }),
            });

            if (!response.ok) {
            throw new Error('Không lưu được tag.');
            }

            const payload = await response.json();
            updateConversationTags(tabs.dataset.tagsUrl, payload.data?.tags || []);
            updateThreadTags(payload.data?.id || timeline?.dataset.conversationId, payload.data?.tags || []);
            await refreshThreadList();
        } catch (error) {
            updateConversationTags(tabs.dataset.tagsUrl, previousTags);
            updateThreadTags(conversationId, previousTags);
            console.warn('CRM conversation tag update failed:', error);
        }
    });

    async function uploadComposerAttachments(files) {
        if (!files.length) {
            return [];
        }

        const uploadUrl = composer?.dataset.uploadUrl;

        if (!uploadUrl) {
            throw new Error('Upload endpoint is missing.');
        }

        const formData = new FormData();

        files.forEach(function (file) {
            formData.append('attachments[]', file);
        });

        const response = await fetch(uploadUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? {'X-CSRF-TOKEN': csrfToken} : {}),
            },
            body: formData,
        });

        if (!response.ok) {
            const payload = await response.json().catch(function () {
                return {};
            });

            throw new Error(payload.message || 'Không tải được file.');
        }

        const payload = await response.json();

        return Array.isArray(payload.data) ? payload.data : [];
    }

    renderReplySuggestions();
