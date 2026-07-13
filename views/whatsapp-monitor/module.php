<?php
declare(strict_types=1);

$monitorView = (string) ($context['view'] ?? 'overview');
$monitorTabs = (array) ($context['tabs'] ?? whatsapp_monitor_tabs());
$monitorLang = (string) ($context['lang'] ?? 'az');
$monitorTitle = (string) ($monitorTabs[$monitorView] ?? 'WhatsApp Monitorinq');
$monitorLink = static fn (string $key): array => [
    'key' => $key,
    'label' => (string) ($monitorTabs[$key] ?? $key),
    'href' => whatsapp_monitor_url($key, [], $monitorLang),
];
?>
<section class="portal-card portal-reveal whatsapp-monitor-module" data-whatsapp-monitor-module data-monitor-view="<?= e($monitorView); ?>">
    <style>
        .whatsapp-monitor-module{position:relative;min-width:0}.wa-monitor-head{align-items:flex-start}.wa-breadcrumb{display:flex;gap:7px;align-items:center;color:var(--portal-muted);font-size:.78rem;margin-bottom:6px}.wa-command-strip{display:grid;grid-template-columns:minmax(190px,.8fr) repeat(6,minmax(0,1fr));gap:10px;align-items:stretch;margin:10px 0 14px;padding:10px;border:1px solid rgba(14,132,141,.18);border-radius:8px;background:linear-gradient(135deg,rgba(238,248,247,.94),rgba(255,248,242,.94))}.wa-command-title{display:grid;gap:3px;align-content:center;padding:0 4px}.wa-command-title small{color:var(--portal-muted);line-height:1.35}.wa-command-strip .portal-inline-link{min-width:0;justify-content:center;text-align:center;white-space:normal;line-height:1.25}.wa-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}.wa-kpi{min-width:0;border:1px solid rgba(148,163,184,.28);border-radius:8px;background:#fff;padding:14px}.wa-kpi span{display:block;color:var(--portal-muted);font-size:.78rem}.wa-kpi strong{display:block;margin-top:6px;font-size:clamp(1.05rem,1.8vw,1.35rem);line-height:1.15;overflow-wrap:anywhere}.wa-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.wa-card{border:1px solid rgba(148,163,184,.28);border-radius:8px;background:#fff;padding:14px;min-width:0}.wa-card strong,.wa-card small{display:block}.wa-card small{color:var(--portal-muted);margin-top:4px}.wa-card.is-online{border-color:rgba(14,132,141,.35)}.wa-card.is-offline{border-color:rgba(239,68,68,.36)}.wa-card.is-warning{border-color:rgba(255,148,77,.48);box-shadow:0 12px 30px rgba(255,148,77,.12)}.wa-card.is-disabled{opacity:.68}.wa-status{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 9px;font-weight:900;font-size:.76rem;background:#e2e8f0;color:#334155}.wa-status.online,.wa-status.connected{background:#dff7ef;color:#07745f}.wa-status.offline,.wa-status.failed{background:#fee2e2;color:#b91c1c}.wa-status.warning,.wa-status.qr_ready,.wa-status.scanned,.wa-status.qr_requested,.wa-status.requested{background:#ffedd5;color:#c2410c}.wa-status.disabled,.wa-status.revoked,.wa-status.expired,.wa-status.not_connected{background:#e5e7eb;color:#4b5563}.wa-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;align-items:end}.wa-form-grid textarea,.wa-form-grid .wide{grid-column:1/-1}.wa-table-actions{display:flex;gap:6px;flex-wrap:wrap}.wa-screen-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin:12px 0}.wa-screen-grid{display:grid;grid-template-columns:repeat(var(--wa-cols,4),minmax(0,1fr));gap:12px}.wa-screen-card{min-height:260px;display:flex;flex-direction:column}.wa-screen-frame{margin-top:10px;min-height:180px;border-radius:8px;background:#0f172a;overflow:hidden;display:grid;place-items:center;color:#cbd5e1}.wa-screen-frame iframe{width:100%;height:220px;border:0;background:#111827}.wa-muted{color:var(--portal-muted)}.wa-inline-form{display:inline}.wa-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.wa-audit-pre{max-width:360px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.76rem}.wa-qr-panel{display:grid;grid-template-columns:minmax(220px,320px) minmax(0,1fr);gap:14px;align-items:start}.wa-qr-box{min-height:260px;border:1px dashed rgba(14,132,141,.35);border-radius:8px;background:#fff;display:grid;place-items:center;padding:14px;text-align:center}.wa-qr-box img{max-width:240px;width:100%;height:auto}.wa-session-key{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-all;background:#f1f5f9;border-radius:8px;padding:10px}.wa-chat-layout{display:grid;grid-template-columns:minmax(240px,340px) minmax(0,1fr);gap:12px;min-height:520px}.wa-chat-list{border:1px solid rgba(148,163,184,.28);border-radius:8px;overflow:auto;max-height:620px;background:#fff}.wa-chat-link{display:grid;gap:3px;padding:12px;border-bottom:1px solid rgba(148,163,184,.2);color:var(--portal-text)}.wa-chat-link.is-active{background:#e7f5f6;border-left:4px solid #0e848d}.wa-chat-link small,.wa-chat-link span,.wa-chat-link time{color:var(--portal-muted);font-size:.76rem}.wa-chat-thread{display:flex;flex-direction:column;border:1px solid rgba(148,163,184,.28);border-radius:8px;overflow:hidden;background:#eef3f5;min-width:0}.wa-chat-thread-head{display:grid;gap:3px;padding:13px;background:#fff;border-bottom:1px solid rgba(148,163,184,.25)}.wa-chat-thread-head small{color:var(--portal-muted)}.wa-chat-messages{display:flex;flex-direction:column;gap:8px;padding:14px;overflow:auto;max-height:560px}.wa-message{align-self:flex-start;max-width:min(78%,680px);padding:9px 11px;border-radius:8px;background:#fff;box-shadow:0 3px 10px rgba(15,23,42,.06);overflow-wrap:anywhere}.wa-message.is-outbound{align-self:flex-end;background:#dff7ef}.wa-message div>small,.wa-message time{display:block;margin-top:4px;color:var(--portal-muted);font-size:.7rem}
        @media(max-width:1100px){.wa-command-strip,.wa-grid,.wa-screen-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wa-form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wa-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:680px){.wa-command-strip,.wa-grid,.wa-screen-grid,.wa-form-grid,.wa-kpis,.wa-settings-grid,.wa-qr-panel,.wa-chat-layout{grid-template-columns:1fr}.wa-chat-list{max-height:260px}.whatsapp-monitor-module .portal-table thead{display:none}.whatsapp-monitor-module .portal-table,.whatsapp-monitor-module .portal-table tbody,.whatsapp-monitor-module .portal-table tr,.whatsapp-monitor-module .portal-table td{display:block;width:100%}.whatsapp-monitor-module .portal-table tr{border:1px solid rgba(148,163,184,.28);border-radius:8px;margin-bottom:10px;padding:8px}.whatsapp-monitor-module .portal-table td{border:0;padding:6px 8px}}
    </style>
    <div class="portal-card-head wa-monitor-head">
        <div>
            <div class="wa-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>CRM</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($monitorTitle); ?></strong></div>
            <h2><?= e($monitorTitle); ?></h2>
            <small>Sirket nomreleri ve cihazlari ucun raziliqli, auditli ve permission-lu monitorinq.</small>
        </div>
        <div class="portal-badge-list">
            <a class="portal-ghost-btn portal-inline-link" href="portal.php"><i class="fa-solid fa-arrow-left"></i> Esas portal</a>
        </div>
    </div>
    <div class="wa-command-strip" aria-label="WhatsApp Monitor emeliyyatlari">
        <div class="wa-command-title">
            <strong>Tez emeliyyatlar</strong>
            <small>Cihaz, nomre, canli monitor ve case axinlari ayridir.</small>
        </div>
        <a class="portal-primary-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('screen-wall', [], $monitorLang)); ?>"><i class="fa-solid fa-table-cells-large"></i> Canli monitor</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('chats', [], $monitorLang)); ?>"><i class="fa-solid fa-comments"></i> Catlar</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('cases', [], $monitorLang)); ?>"><i class="fa-solid fa-triangle-exclamation"></i> Case yarat</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('pairing', [], $monitorLang)); ?>"><i class="fa-solid fa-qrcode"></i> QR bagla</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('devices', [], $monitorLang)); ?>"><i class="fa-solid fa-mobile-screen"></i> Cihazlar</a>
        <a class="portal-ghost-btn portal-inline-link" href="<?= e(whatsapp_monitor_url('numbers', [], $monitorLang)); ?>"><i class="fa-brands fa-whatsapp"></i> Nomreler</a>
    </div>
    <?php
    $moduleNavGroups = [
        ['label' => 'Umumi baxis', 'icon' => 'fa-gauge-high', 'links' => [$monitorLink('overview')]],
        ['label' => 'Monitorinq', 'icon' => 'fa-display', 'links' => [$monitorLink('screen-wall'), $monitorLink('chats'), $monitorLink('cases')]],
        ['label' => 'Reyestr', 'icon' => 'fa-list-check', 'links' => [$monitorLink('numbers'), $monitorLink('pairing'), $monitorLink('devices')]],
        ['label' => 'Hesabat ve nezaret', 'icon' => 'fa-chart-line', 'links' => [$monitorLink('performance'), $monitorLink('settings'), $monitorLink('audit')]],
    ];
    $moduleNavActive = $monitorView;
    $moduleNavAria = 'WhatsApp Monitor bolmeleri';
    $moduleNavName = 'whatsapp-monitor-nav';
    $moduleNavLinkAttribute = '';
    require dirname(__DIR__) . '/_module-group-nav.php';
    require __DIR__ . '/' . $monitorView . '.php';
    ?>
</section>
