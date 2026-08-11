<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_render_copilot_widget(string $lang = 'az'): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    $csrf = app_csrf_token();
    ?>
    <section class="billing-copilot-widget" data-copilot-widget aria-label="Superpilot">
        <button class="billing-copilot-toggle" type="button" data-copilot-toggle>
            <span>Superpilot</span>
            <small>Analiz et</small>
        </button>
        <div class="billing-copilot-panel" data-copilot-panel hidden>
            <div class="billing-copilot-head">
                <div>
                    <strong>Superpilot</strong>
                    <small>Musteri, RADIUS, ticket, anbar, ONT ve emekdas sorgulari</small>
                </div>
                <button type="button" data-copilot-close aria-label="Bagla">x</button>
            </div>
            <div class="billing-copilot-log" data-copilot-log>
                <div class="billing-copilot-msg is-bot">
                    Numune: "hansi suallara cavab verirsen", "2320025 niye qirilir?", "2320025 odenisi neye cixib?",
                    "bu modem kimdedir?", "Samir adinda hansi mallar var?", "NOC-da hansi cihazlar problemlidir?",
                    "bos trafik saatlari ve traffic monetization teklifleri hansilardir?"
                </div>
            </div>
            <form class="billing-copilot-form" data-copilot-form>
                <input type="hidden" name="csrf_token" value="<?= e($csrf); ?>">
                <textarea name="question" rows="4" maxlength="1200" placeholder="Musteri kodu, SN/MAC, emekdas adi ve ya problem yazin..." required></textarea>
                <button type="submit">Superpilotdan sorus</button>
            </form>
        </div>
    </section>
    <style>
        .billing-copilot-widget{position:fixed;right:18px;bottom:18px;z-index:2147483000;font-family:inherit;pointer-events:none}
        .billing-copilot-toggle{pointer-events:auto;border:0;border-radius:999px;background:#10233f;color:#fff;padding:12px 16px;box-shadow:0 18px 44px rgba(16,35,63,.24);font-weight:900;cursor:pointer;display:flex;gap:8px;align-items:center}
        .billing-copilot-toggle small{color:#bde7ef;font-weight:700}
        .billing-copilot-panel{pointer-events:auto;position:fixed;right:18px;top:96px;bottom:18px;width:min(390px,calc(100vw - 24px));max-height:calc(100vh - 114px);display:grid;grid-template-rows:auto 1fr auto;background:#fff;border:1px solid rgba(42,182,165,.24);border-radius:22px;box-shadow:0 24px 70px rgba(16,35,63,.28);overflow:hidden}
        .billing-copilot-panel[hidden]{display:none!important}
        .billing-copilot-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:14px 16px;background:linear-gradient(135deg,#10233f,#0f8a95);color:#fff}
        .billing-copilot-head small{display:block;color:#d7f6fa;margin-top:4px}
        .billing-copilot-head button{border:0;background:rgba(255,255,255,.14);color:#fff;border-radius:999px;width:30px;height:30px;cursor:pointer}
        .billing-copilot-log{display:grid;gap:10px;align-content:start;min-height:0;overflow:auto;padding:14px;background:#f6fbfe}
        .billing-copilot-msg{border-radius:15px;padding:11px 13px;line-height:1.42;white-space:pre-wrap;font-size:.91rem}
        .billing-copilot-msg.is-bot{background:#fff;border:1px solid rgba(148,163,184,.28);color:#10233f}
        .billing-copilot-msg.is-user{background:#e6f8f7;color:#063b42;margin-left:28px}
        .billing-copilot-msg.is-error{background:#fff1f2;color:#991b1b;border:1px solid #fecdd3}
        .billing-copilot-form{display:grid;gap:9px;padding:12px;background:#fff;border-top:1px solid rgba(148,163,184,.2)}
        .billing-copilot-form textarea{width:100%;resize:vertical;min-height:78px;max-height:150px;border:1px solid rgba(148,163,184,.35);border-radius:15px;padding:11px;font:inherit}
        .billing-copilot-form button{justify-self:end;border:0;border-radius:999px;background:#ff8a4c;color:#111827;padding:10px 16px;font-weight:900;cursor:pointer}
        @media (max-width:680px){.billing-copilot-widget{position:static;display:flex;justify-content:flex-end;padding:10px 12px 16px}.billing-copilot-toggle{margin-left:auto;padding:10px 13px}.billing-copilot-panel{left:8px;right:8px;top:auto;bottom:8px;width:auto;max-height:min(78vh,640px);border-radius:20px}.billing-copilot-head{padding:12px 14px}.billing-copilot-log{padding:12px}.billing-copilot-form textarea{min-height:72px;max-height:120px}.billing-copilot-form button{width:100%;justify-self:stretch}}
    </style>
    <script>
    (() => {
        const root = document.querySelector('[data-copilot-widget]');
        if (!root || root.dataset.ready === '1') return;
        root.dataset.ready = '1';
        const panel = root.querySelector('[data-copilot-panel]');
        const log = root.querySelector('[data-copilot-log]');
        const form = root.querySelector('[data-copilot-form]');
        const toggle = root.querySelector('[data-copilot-toggle]');
        const close = root.querySelector('[data-copilot-close]');
        const append = (text, type) => {
            const item = document.createElement('div');
            item.className = `billing-copilot-msg is-${type}`;
            item.textContent = text;
            log.appendChild(item);
            log.scrollTop = log.scrollHeight;
        };
        toggle.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
        });
        close.addEventListener('click', () => {
            panel.hidden = true;
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const data = new FormData(form);
            const question = String(data.get('question') || '').trim();
            if (!question) return;
            append(question, 'user');
            form.question.value = '';
            try {
                const response = await fetch('api/copilot/agent-chat.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        csrf_token: String(data.get('csrf_token') || ''),
                        question,
                    }),
                });
                const payload = await response.json();
                append(String(payload.text || payload.reason || 'Cavab alina bilmedi.'), payload.ok ? 'bot' : 'error');
            } catch (error) {
                append('Superpilot API cavab vermedi: ' + (error && error.message ? error.message : 'network error'), 'error');
            }
        });
    })();
    </script>
    <?php
}
