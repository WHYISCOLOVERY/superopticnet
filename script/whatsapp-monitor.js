(() => {
    document.addEventListener('click', event => {
        const full = event.target.closest('[data-screen-wall-fullscreen]');
        if (full) {
            const wall = document.querySelector('[data-screen-wall]');
            if (wall && wall.requestFullscreen) wall.requestFullscreen();
        }
    });

    const live = document.querySelector('[data-wa-pairing-live]');
    if (live) {
        const sessionKey = live.dataset.sessionKey || '';
        if (sessionKey) {
            let timer = null;

            const renderPairing = (payload) => {
                const status = String(payload.status || 'requested');
                live.dataset.pairingStatus = status;
                const badge = live.querySelector('[data-wa-pairing-status]');
                if (badge) {
                    badge.className = `wa-status ${status}`;
                    badge.textContent = payload.status_label || status;
                }
                const worker = live.querySelector('[data-wa-worker]');
                if (worker) worker.textContent = payload.worker_id || '-';
                const box = live.querySelector('[data-wa-qr-box]');
                if (box) {
                    box.classList.remove('is-loading');
                    if (String(payload.qr_image_data_uri || '').startsWith('data:image/')) {
                        let image = box.querySelector('img');
                        if (!image) {
                            box.replaceChildren();
                            image = document.createElement('img');
                            image.alt = 'WhatsApp QR';
                            box.appendChild(image);
                        }
                        image.src = payload.qr_image_data_uri;
                    } else if (['requested', 'qr_requested'].includes(status)) {
                        box.classList.add('is-loading');
                    }
                }
                if (['connected', 'expired', 'failed', 'revoked'].includes(status) && timer) {
                    window.clearInterval(timer);
                    timer = null;
                }
            };

            const refreshPairing = async () => {
                try {
                    const endpoint = new URL('api/whatsapp-monitor/pairing-status.php', window.location.href);
                    endpoint.searchParams.set('session_key', sessionKey);
                    const response = await fetch(endpoint, {headers: {'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store'});
                    if (!response.ok) return;
                    renderPairing(await response.json());
                } catch {
                    // The next poll retries without interrupting the operator.
                }
            };
            refreshPairing();
            timer = window.setInterval(refreshPairing, 2000);
        }
    }

    const chatMonitor = document.querySelector('[data-wa-chat-monitor]');
    const monitorLang = chatMonitor?.dataset.waMonitorLang || 'az';
    const thread = document.querySelector('[data-wa-chat-thread]');
    const messages = thread?.querySelector('[data-wa-chat-messages]');
    const numberId = thread?.dataset.numberId || '';
    const chatId = thread?.dataset.chatId || '';
    const liveStatus = chatMonitor?.querySelector('[data-wa-live-status]');
    const LIVE_STATUS_LABELS = {live: 'Canli', refreshing: 'Yenilenir', weak: 'Baglanti zeifdir'};

    const setLiveStatus = state => {
        if (!liveStatus) return;
        liveStatus.dataset.state = state;
        const icon = liveStatus.querySelector('i');
        liveStatus.textContent = '';
        if (icon) liveStatus.appendChild(icon);
        liveStatus.append(' ' + (LIVE_STATUS_LABELS[state] || LIVE_STATUS_LABELS.live));
    };

    let messageTimer = null;
    const scheduleMessageRefresh = (refreshFn, interval) => {
        if (messageTimer) window.clearInterval(messageTimer);
        messageTimer = window.setInterval(refreshFn, interval);
    };

    if (thread && messages && numberId && chatId) {
        messages.scrollTop = messages.scrollHeight;
        const newBadge = chatMonitor?.querySelector('[data-wa-new-badge]');
        let newBadgeTimer = null;
        let messageFailCount = 0;

        const flashNewBadge = () => {
            if (!newBadge) return;
            newBadge.hidden = false;
            if (newBadgeTimer) window.clearTimeout(newBadgeTimer);
            newBadgeTimer = window.setTimeout(() => {
                newBadge.hidden = true;
            }, 4000);
        };

        const appendMessage = row => {
            if (messages.querySelector(`[data-message-id="${Number(row.id || 0)}"]`)) return;
            const emptyState = messages.querySelector('.mgs-empty-state');
            if (emptyState) emptyState.remove();
            const article = document.createElement('article');
            article.className = `wa-message is-new is-${row.direction === 'outbound' ? 'outbound' : 'inbound'}`;
            article.dataset.messageId = String(row.id || 0);
            const body = document.createElement('div');
            body.textContent = row.message_body || '';
            if (Number(row.has_media || 0) === 1) {
                const media = document.createElement('small');
                media.textContent = `[Media: ${row.message_type || 'file'}]`;
                body.appendChild(media);
            }
            const time = document.createElement('time');
            time.textContent = row.occurred_at || '';
            article.append(body, time);
            messages.appendChild(article);
            thread.dataset.lastMessageId = String(row.id || 0);
        };

        const refreshMessages = async () => {
            try {
                const endpoint = new URL('api/whatsapp-monitor/chat-messages.php', window.location.href);
                endpoint.searchParams.set('number_id', numberId);
                endpoint.searchParams.set('chat_id', chatId);
                endpoint.searchParams.set('after_id', thread.dataset.lastMessageId || '0');
                const response = await fetch(endpoint, {headers: {'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store'});
                if (!response.ok) throw new Error('bad_response');
                const payload = await response.json();
                const rows = payload.rows || [];
                rows.forEach(appendMessage);
                if (rows.length > 0) {
                    messages.scrollTop = messages.scrollHeight;
                    flashNewBadge();
                }
                messageFailCount = 0;
                setLiveStatus('live');
            } catch {
                messageFailCount += 1;
                if (messageFailCount >= 2) setLiveStatus('weak');
            }
        };

        refreshMessages();
        scheduleMessageRefresh(refreshMessages, 2500);

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                scheduleMessageRefresh(refreshMessages, 20000);
            } else {
                setLiveStatus('refreshing');
                refreshMessages();
                scheduleMessageRefresh(refreshMessages, 2500);
            }
        });
    }

    const searchInput = document.querySelector('[data-wa-chat-search]');
    const chatList = document.querySelector('[data-wa-chat-list]');
    const emptyFilter = chatList?.querySelector('[data-wa-chat-empty-filter]');

    const applyChatSearchFilter = () => {
        if (!chatList) return;
        const term = (searchInput?.value || '').trim().toLocaleLowerCase('az');
        const items = Array.from(chatList.querySelectorAll('[data-wa-chat-search-item]'));
        let visibleCount = 0;
        items.forEach(item => {
            const haystack = (item.dataset.waChatSearchText || '').toLocaleLowerCase('az');
            const matches = term === '' || haystack.includes(term);
            item.hidden = !matches;
            if (matches) visibleCount += 1;
        });
        if (emptyFilter) emptyFilter.hidden = visibleCount !== 0;
    };

    if (searchInput && chatList) {
        searchInput.addEventListener('input', applyChatSearchFilter);
    }

    let listTimer = null;
    const scheduleListRefresh = (refreshFn, interval) => {
        if (listTimer) window.clearInterval(listTimer);
        listTimer = window.setInterval(refreshFn, interval);
    };

    if (chatList) {
        const buildChatHref = (rowNumberId, rowChatId) => {
            const url = new URL('whatsapp-monitor.php', window.location.href);
            url.searchParams.set('view', 'chats');
            url.searchParams.set('number_id', String(rowNumberId));
            url.searchParams.set('chat_id', rowChatId);
            if (monitorLang !== 'az') url.searchParams.set('lang', monitorLang);
            return `${url.pathname}${url.search}`;
        };

        const buildChatItem = row => {
            const link = document.createElement('a');
            link.className = 'wa-chat-link';
            link.dataset.waChatSearchItem = '';
            link.dataset.numberId = String(row.number_id || 0);
            link.dataset.chatId = String(row.chat_id || '');
            link.href = buildChatHref(row.number_id || 0, row.chat_id || '');

            const top = document.createElement('span');
            top.className = 'wa-chat-link-top';
            const name = document.createElement('strong');
            name.dataset.waChatName = '';
            const time = document.createElement('time');
            time.dataset.waChatTime = '';
            top.append(name, time);

            const meta = document.createElement('small');

            const preview = document.createElement('span');
            preview.className = 'wa-chat-link-preview';
            preview.dataset.waChatPreview = '';

            const count = document.createElement('span');
            count.className = 'wa-chat-link-count';
            count.dataset.waChatCount = '';

            link.append(top, meta, preview, count);
            return link;
        };

        const updateChatItem = (item, row) => {
            const name = item.querySelector('[data-wa-chat-name]');
            if (name) name.textContent = row.contact_name || row.chat_id || '';
            const time = item.querySelector('[data-wa-chat-time]');
            if (time) time.textContent = row.last_message_at || '';
            const meta = item.querySelector('small');
            if (meta) meta.textContent = `${row.company_number || '-'} · ${row.employee_name || '-'}`;
            const preview = item.querySelector('[data-wa-chat-preview]');
            if (preview) preview.textContent = String(row.last_message || '').slice(0, 80);
            const count = item.querySelector('[data-wa-chat-count]');
            if (count) count.textContent = `${row.message_count || 0} mesaj`;
            item.dataset.waChatSearchText = `${row.contact_name || ''} ${row.company_number || ''} ${row.employee_name || ''}`;
            const isActive = numberId !== '' && chatId !== ''
                && String(row.number_id || '') === numberId && String(row.chat_id || '') === chatId;
            item.classList.toggle('is-active', isActive);
            if (isActive) {
                item.setAttribute('aria-current', 'true');
            } else {
                item.removeAttribute('aria-current');
            }
        };

        const refreshChatList = async () => {
            try {
                const endpoint = new URL('api/whatsapp-monitor/chat-list.php', window.location.href);
                const response = await fetch(endpoint, {headers: {'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store'});
                if (!response.ok) return;
                const payload = await response.json();
                const rows = payload.rows || [];
                rows.forEach(row => {
                    const key = `[data-number-id="${row.number_id}"][data-chat-id="${CSS.escape(String(row.chat_id || ''))}"]`;
                    let item = chatList.querySelector(key);
                    if (!item) item = buildChatItem(row);
                    updateChatItem(item, row);
                    chatList.appendChild(item);
                });
                if (emptyFilter) chatList.appendChild(emptyFilter);
                applyChatSearchFilter();
            } catch {
                // Keep the current list visible and retry on the next interval.
            }
        };

        scheduleListRefresh(refreshChatList, 5000);

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                scheduleListRefresh(refreshChatList, 30000);
            } else {
                refreshChatList();
                scheduleListRefresh(refreshChatList, 5000);
            }
        });
    }
})();
