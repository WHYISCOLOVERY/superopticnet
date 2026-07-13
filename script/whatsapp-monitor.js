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
    const thread = document.querySelector('[data-wa-chat-thread]');
    const messages = thread?.querySelector('[data-wa-chat-messages]');
    const numberId = thread?.dataset.numberId || '';
    const chatId = thread?.dataset.chatId || '';
    if (thread && messages && numberId && chatId) {
        messages.scrollTop = messages.scrollHeight;
        const newBadge = chatMonitor?.querySelector('[data-wa-new-badge]');
        let newBadgeTimer = null;

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
                if (!response.ok) return;
                const payload = await response.json();
                const rows = payload.rows || [];
                rows.forEach(appendMessage);
                if (rows.length > 0) {
                    messages.scrollTop = messages.scrollHeight;
                    flashNewBadge();
                }
            } catch {
                // Keep the monitor open and retry on the next interval.
            }
        };
        window.setInterval(refreshMessages, 2500);
    }

    const searchInput = document.querySelector('[data-wa-chat-search]');
    const chatList = document.querySelector('[data-wa-chat-list]');
    if (searchInput && chatList) {
        const items = Array.from(chatList.querySelectorAll('[data-wa-chat-search-item]'));
        const emptyFilter = chatList.querySelector('[data-wa-chat-empty-filter]');
        searchInput.addEventListener('input', () => {
            const term = searchInput.value.trim().toLowerCase();
            let visibleCount = 0;
            items.forEach(item => {
                const haystack = item.dataset.waChatSearchText || '';
                const matches = term === '' || haystack.includes(term);
                item.hidden = !matches;
                if (matches) visibleCount += 1;
            });
            if (emptyFilter) emptyFilter.hidden = visibleCount !== 0;
        });
    }
})();
