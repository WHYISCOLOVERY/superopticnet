<?php
declare(strict_types=1);

/**
 * Stage 4 primary WhatsApp monitor view (audit section 7.1).
 *
 * Read-only phone-style cards: each company number is a phone showing its
 * online status and recent chats. Clicking a card enlarges it into an in-page
 * modal with the full searchable chat list and message history. No
 * send/reply/delete controls are rendered here.
 */
$statusCards = (array) ($context['number_status_cards'] ?? []);
$monitorLang = (string) ($context['lang'] ?? 'az');
$settings = (array) ($context['settings'] ?? []);
$refreshSeconds = max(5, (int) ($settings['stream_refresh_seconds']['setting_value'] ?? 10));

$waStatusMeta = static function (string $status): array {
    return match ($status) {
        'online' => ['label' => 'Onlayn', 'tone' => 'success', 'icon' => 'fa-circle-check'],
        'sync_delayed' => ['label' => 'Sinxron gecikir', 'tone' => 'warning', 'icon' => 'fa-clock-rotate-left'],
        'offline' => ['label' => 'Oflayn', 'tone' => 'danger', 'icon' => 'fa-circle-xmark'],
        default => ['label' => 'Deaktiv', 'tone' => 'neutral', 'icon' => 'fa-ban'],
    };
};
$waRelative = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return e($value);
    }
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'İndicə';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' dəq';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' saat';
    }
    return date('d.m H:i', $timestamp);
};
$waChatShort = static function (string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    return $text === '' ? 'Media / boş mesaj' : mb_substr($text, 0, 60);
};
?>
<section class="wa-lm" data-wa-lm data-refresh-endpoint="api/whatsapp-monitor/number-status.php" data-refresh-seconds="<?= e((string) $refreshSeconds); ?>">
    <div class="wa-lm-head">
        <div>
            <h3>Canlı Monitor</h3>
            <small>Hər telefon şirkət nömrəsidir — statusu və çatları burada görünür. Böyütmək üçün kartın üstünə basın. Bu görünüş yalnız izləyir, mesaj göndərmir.</small>
        </div>
        <span class="portal-pill" data-wa-lm-updated>Yenilənir…</span>
    </div>

    <?php if ($statusCards === []): ?>
        <?php if (function_exists('app_design_system_state')): ?>
            <?= app_design_system_state('empty', 'Nömrə yoxdur', 'Nəzarətə götürülmüş şirkət WhatsApp nömrəsi tapılmadı. Nömrə əlavə edib QR ilə qoşun.', 'Nömrələr', whatsapp_monitor_url('numbers', [], $monitorLang)); ?>
        <?php else: ?>
            <div class="mgs-empty-state"><i class="fa-solid fa-inbox"></i><strong>Nömrə yoxdur</strong><p>Nəzarətə götürülmüş şirkət nömrəsi tapılmadı.</p></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="wa-lm-grid" data-wa-lm-grid>
            <?php foreach ($statusCards as $card): ?>
                <?php
                $meta = $waStatusMeta((string) ($card['status'] ?? 'disabled'));
                $numberId = (int) ($card['number_id'] ?? 0);
                $unread = (int) ($card['unread_count'] ?? 0);
                $recentChats = (array) ($card['recent_chats'] ?? []);
                ?>
                <article
                    class="wa-phone wa-lm-tone-<?= e($meta['tone']); ?>"
                    data-wa-lm-card="<?= e((string) $numberId); ?>"
                    data-wa-phone
                    data-number-id="<?= e((string) $numberId); ?>"
                    data-company-number="<?= e((string) ($card['company_number'] ?? '')); ?>"
                    data-employee-name="<?= e((string) ($card['employee_name'] ?? '')); ?>"
                    tabindex="0"
                    role="button"
                    aria-label="<?= e((string) ($card['company_number'] ?? '')); ?> nömrəsini böyüt"
                >
                    <header class="wa-phone-top">
                        <span class="wa-phone-dot wa-lm-badge-<?= e($meta['tone']); ?>" data-wa-lm-status>
                            <i class="fa-solid <?= e($meta['icon']); ?>"></i>
                            <span data-wa-lm-status-label><?= e($meta['label']); ?></span>
                        </span>
                        <button type="button" class="wa-phone-expand" data-wa-expand aria-label="Böyüt"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
                    </header>
                    <div class="wa-phone-id">
                        <strong class="wa-lm-number"><?= e((string) ($card['company_number'] ?? '—')); ?></strong>
                        <small class="wa-lm-employee"><?= e((string) ($card['employee_name'] ?? 'Təyin olunmayıb')); ?></small>
                    </div>
                    <p class="wa-phone-reason" data-wa-lm-reason><?= e((string) ($card['status_reason'] ?? '')); ?></p>
                    <div class="wa-phone-stats">
                        <span><i class="fa-solid fa-comments"></i> <?= e((string) ($card['chat_count'] ?? 0)); ?> çat</span>
                        <span class="wa-phone-unread<?= $unread > 0 ? ' is-active' : ''; ?>"><i class="fa-solid fa-bell"></i> <span data-wa-lm-unread><?= e((string) $unread); ?></span> cavabsız</span>
                    </div>
                    <div class="wa-phone-screen" aria-label="Son çatlar">
                        <?php if ($recentChats === []): ?>
                            <div class="wa-phone-empty">Çat qeydi yoxdur</div>
                        <?php else: ?>
                            <?php foreach ($recentChats as $chat): ?>
                                <div class="wa-phone-chat">
                                    <div class="wa-phone-chat-top">
                                        <span class="wa-phone-contact"><?= e((string) ($chat['contact_name'] ?? $chat['chat_id'] ?? 'Naməlum')); ?></span>
                                        <span class="wa-phone-time"><?= e($waRelative($chat['last_message_at'] ?? null)); ?></span>
                                    </div>
                                    <div class="wa-phone-chat-last"><?= e($waChatShort((string) ($chat['last_message'] ?? ''))); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <footer class="wa-phone-foot">
                        <span class="wa-phone-hint"><i class="fa-solid fa-expand"></i> Böyütmək üçün bas</span>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Enlarge modal: shared, populated per number by JS -->
    <div class="wa-modal" data-wa-modal hidden>
        <div class="wa-modal-backdrop" data-wa-modal-close></div>
        <div class="wa-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="wa-modal-title" tabindex="-1">
            <header class="wa-modal-head">
                <div>
                    <h3 id="wa-modal-title">Nömrə</h3>
                    <small data-wa-modal-sub></small>
                </div>
                <button type="button" class="wa-modal-close portal-ghost-btn" data-wa-modal-close aria-label="Bağla"><i class="fa-solid fa-xmark"></i></button>
            </header>
            <div class="wa-modal-body">
                <aside class="wa-modal-list">
                    <div class="wa-modal-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" data-wa-chat-search placeholder="Kontakt / nömrə axtar" aria-label="Çat axtar">
                    </div>
                    <div class="wa-modal-chats" data-wa-modal-chats aria-live="polite"></div>
                </aside>
                <section class="wa-modal-thread">
                    <div class="wa-modal-thread-head" data-wa-thread-head>
                        <span class="wa-modal-thread-title">Çat seçin</span>
                    </div>
                    <div class="wa-modal-search wa-modal-search-thread">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" data-wa-message-search placeholder="Mesajlarda axtar" aria-label="Mesaj axtar">
                    </div>
                    <div class="wa-modal-messages" data-wa-modal-messages aria-live="polite">
                        <div class="wa-modal-placeholder">Soldan çat seçin.</div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</section>
<script>
(() => {
    const root = document.querySelector('[data-wa-lm]');
    if (!root || root.dataset.waLmReady === '1') return;
    root.dataset.waLmReady = '1';

    const endpoint = root.getAttribute('data-refresh-endpoint') || 'api/whatsapp-monitor/number-status.php';
    const seconds = Math.max(5, parseInt(root.getAttribute('data-refresh-seconds') || '10', 10));
    const updated = root.querySelector('[data-wa-lm-updated]');

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    const statusMeta = {
        online: { label: 'Onlayn', tone: 'success', icon: 'fa-circle-check' },
        sync_delayed: { label: 'Sinxron gecikir', tone: 'warning', icon: 'fa-clock-rotate-left' },
        offline: { label: 'Oflayn', tone: 'danger', icon: 'fa-circle-xmark' },
        disabled: { label: 'Deaktiv', tone: 'neutral', icon: 'fa-ban' },
    };
    const relative = (value) => {
        if (!value) return '';
        const ts = Date.parse(String(value).replace(' ', 'T'));
        if (Number.isNaN(ts)) return value;
        const diff = Math.floor((Date.now() - ts) / 1000);
        if (diff < 60) return 'İndicə';
        if (diff < 3600) return Math.floor(diff / 60) + ' dəq';
        if (diff < 86400) return Math.floor(diff / 3600) + ' saat';
        return new Date(ts).toLocaleString('az-AZ', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    };

    /* ---- live status polling (read-only) ---- */
    const applyCard = (card) => {
        const el = root.querySelector('[data-wa-lm-card="' + card.number_id + '"]');
        if (!el) return;
        const meta = statusMeta[card.status] || statusMeta.disabled;
        el.className = 'wa-phone wa-lm-tone-' + meta.tone;
        const badge = el.querySelector('[data-wa-lm-status]');
        if (badge) {
            badge.className = 'wa-phone-dot wa-lm-badge-' + meta.tone;
            badge.querySelector('i').className = 'fa-solid ' + meta.icon;
            const label = badge.querySelector('[data-wa-lm-status-label]');
            if (label) label.textContent = meta.label;
        }
        const reason = el.querySelector('[data-wa-lm-reason]');
        if (reason) reason.textContent = card.status_reason || '';
        const unread = el.querySelector('[data-wa-lm-unread]');
        if (unread) {
            const count = parseInt(card.unread_count || 0, 10);
            unread.textContent = String(count);
            unread.closest('.wa-phone-unread')?.classList.toggle('is-active', count > 0);
        }
    };
    const refresh = async () => {
        try {
            const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('status ' + response.status);
            const data = await response.json();
            if (!data || data.ok !== true || !Array.isArray(data.cards)) return;
            data.cards.forEach(applyCard);
            if (updated) updated.textContent = 'Yeniləndi: ' + new Date().toLocaleTimeString('az-AZ');
        } catch (error) {
            if (updated) updated.textContent = 'Yenilənmə dayandı';
        }
    };
    refresh();
    window.setInterval(refresh, seconds * 1000);

    /* ---- enlarge modal ---- */
    const modal = root.querySelector('[data-wa-modal]');
    const dialog = modal.querySelector('.wa-modal-dialog');
    const titleEl = modal.querySelector('#wa-modal-title');
    const subEl = modal.querySelector('[data-wa-modal-sub]');
    const chatsEl = modal.querySelector('[data-wa-modal-chats]');
    const messagesEl = modal.querySelector('[data-wa-modal-messages]');
    const threadHead = modal.querySelector('[data-wa-thread-head]');
    const chatSearch = modal.querySelector('[data-wa-chat-search]');
    const messageSearch = modal.querySelector('[data-wa-message-search]');
    let opener = null;
    let currentNumberId = 0;
    let chatCache = [];
    let messageCache = [];

    const closeModal = () => {
        modal.hidden = true;
        document.body.classList.remove('wa-modal-open');
        if (opener && opener.isConnected) opener.focus();
        opener = null;
    };

    const renderChats = () => {
        const term = (chatSearch.value || '').trim().toLowerCase();
        const rows = chatCache.filter((c) => {
            if (!term) return true;
            return (String(c.contact_name || '') + ' ' + String(c.chat_id || '')).toLowerCase().includes(term);
        });
        if (rows.length === 0) {
            chatsEl.innerHTML = '<div class="wa-modal-placeholder">Çat tapılmadı.</div>';
            return;
        }
        chatsEl.innerHTML = rows.map((c) => (
            '<button type="button" class="wa-modal-chat" data-chat-id="' + esc(c.chat_id) + '">' +
                '<span class="wa-modal-chat-top"><span class="wa-modal-chat-name">' + esc(c.contact_name || c.chat_id || 'Naməlum') + '</span>' +
                '<span class="wa-modal-chat-time">' + esc(relative(c.last_message_at)) + '</span></span>' +
                '<span class="wa-modal-chat-last">' + esc((c.last_message || '').slice(0, 80) || 'Media / boş mesaj') + '</span>' +
            '</button>'
        )).join('');
    };

    const renderMessages = () => {
        const term = (messageSearch.value || '').trim().toLowerCase();
        const rows = messageCache.filter((m) => !term || String(m.message_body || '').toLowerCase().includes(term));
        if (rows.length === 0) {
            messagesEl.innerHTML = '<div class="wa-modal-placeholder">' + (term ? 'Uyğun mesaj yoxdur.' : 'Mesaj yoxdur.') + '</div>';
            return;
        }
        messagesEl.innerHTML = rows.map((m) => {
            const dir = m.direction === 'outbound' ? 'out' : 'in';
            const body = m.message_body ? esc(m.message_body) : '<em>[' + esc(m.message_type || 'media') + ']</em>';
            return '<div class="wa-msg wa-msg-' + dir + '">' +
                '<div class="wa-msg-body">' + body + '</div>' +
                '<div class="wa-msg-time">' + esc(relative(m.occurred_at)) + '</div>' +
            '</div>';
        }).join('');
        messagesEl.scrollTop = messagesEl.scrollHeight;
    };

    const loadMessages = async (chatId, contactName) => {
        threadHead.innerHTML = '<span class="wa-modal-thread-title">' + esc(contactName || chatId) + '</span>';
        messagesEl.innerHTML = '<div class="wa-modal-placeholder">Yüklənir…</div>';
        messageCache = [];
        try {
            const url = 'api/whatsapp-monitor/chat-messages.php?number_id=' + currentNumberId + '&chat_id=' + encodeURIComponent(chatId);
            const data = await (await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
            if (data && data.ok && Array.isArray(data.rows)) {
                messageCache = data.rows;
                renderMessages();
            } else {
                messagesEl.innerHTML = '<div class="wa-modal-placeholder">Mesajlar yüklənmədi.</div>';
            }
        } catch (error) {
            messagesEl.innerHTML = '<div class="wa-modal-placeholder">Şəbəkə xətası.</div>';
        }
    };

    const loadChats = async (numberId) => {
        chatsEl.innerHTML = '<div class="wa-modal-placeholder">Yüklənir…</div>';
        chatCache = [];
        try {
            const url = 'api/whatsapp-monitor/chat-list.php?number_id=' + numberId;
            const data = await (await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
            if (data && data.ok && Array.isArray(data.rows)) {
                chatCache = data.rows;
                renderChats();
                if (chatCache.length > 0) {
                    loadMessages(chatCache[0].chat_id, chatCache[0].contact_name);
                }
            } else {
                chatsEl.innerHTML = '<div class="wa-modal-placeholder">Çat yüklənmədi.</div>';
            }
        } catch (error) {
            chatsEl.innerHTML = '<div class="wa-modal-placeholder">Şəbəkə xətası.</div>';
        }
    };

    const openModal = (phone) => {
        currentNumberId = parseInt(phone.getAttribute('data-number-id') || '0', 10);
        if (currentNumberId <= 0) return;
        opener = phone;
        titleEl.textContent = phone.getAttribute('data-company-number') || 'Nömrə';
        subEl.textContent = phone.getAttribute('data-employee-name') || '';
        chatSearch.value = '';
        messageSearch.value = '';
        messagesEl.innerHTML = '<div class="wa-modal-placeholder">Soldan çat seçin.</div>';
        threadHead.innerHTML = '<span class="wa-modal-thread-title">Çat seçin</span>';
        modal.hidden = false;
        document.body.classList.add('wa-modal-open');
        window.requestAnimationFrame(() => dialog.focus());
        loadChats(currentNumberId);
    };

    root.querySelectorAll('[data-wa-phone]').forEach((phone) => {
        phone.addEventListener('click', (event) => {
            if (event.target.closest('a')) return;
            openModal(phone);
        });
        phone.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openModal(phone);
            }
        });
    });
    chatsEl.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-chat-id]');
        if (!btn) return;
        chatsEl.querySelectorAll('.wa-modal-chat').forEach((c) => c.classList.remove('is-active'));
        btn.classList.add('is-active');
        loadMessages(btn.getAttribute('data-chat-id'), btn.querySelector('.wa-modal-chat-name')?.textContent || '');
    });
    chatSearch.addEventListener('input', renderChats);
    messageSearch.addEventListener('input', renderMessages);
    modal.querySelectorAll('[data-wa-modal-close]').forEach((el) => el.addEventListener('click', closeModal));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });
})();
</script>
