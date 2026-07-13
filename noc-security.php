<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/security-anomaly-lib.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();
$flash = null;
$scanOptions = [];

if ($db instanceof PDO) {
    app_security_anomaly_ensure_schema($db);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!app_verify_csrf($csrfToken)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO) {
        $flash = ['type' => 'error', 'message' => 'Database baglantisi aktiv deyil.'];
    } else {
        $record = (string) ($_POST['record'] ?? '0') === '1';
        $live = (string) ($_POST['live'] ?? '0') === '1' && app_portal_is_admin($user);
        $scanOptions = [
            'record' => $record,
            'test_mode' => !$live,
            'traffic_window_hours' => max(1, min(168, (int) ($_POST['traffic_window_hours'] ?? 24))),
            'nat_window_minutes' => max(1, min(1440, (int) ($_POST['nat_window_minutes'] ?? 15))),
        ];
        $scan = app_security_anomaly_run($db, $scanOptions);
        $flash = [
            'type' => !empty($scan['ok']) ? 'success' : 'error',
            'message' => !empty($scan['ok'])
                ? 'Security scan tamamlandi: ' . (int) ($scan['summary']['findings'] ?? 0) . ' risk tapildi. Mode: ' . (string) ($scan['mode'] ?? 'detect_only')
                : 'Security scan xetasi: ' . (string) ($scan['reason'] ?? 'unknown'),
        ];
    }
}

$scanResult = $db instanceof PDO ? app_security_anomaly_run($db, $scanOptions) : ['ok' => false, 'findings' => [], 'summary' => []];
$findings = array_values(array_filter((array) ($scanResult['findings'] ?? []), 'is_array'));
$summary = is_array($scanResult['summary'] ?? null) ? $scanResult['summary'] : [];
$events = [];
if ($db instanceof PDO) {
    $events = $db->query(
        'SELECT * FROM security_anomaly_events
         ORDER BY last_seen_at DESC, id DESC
         LIMIT 30'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function noc_security_tone(string $severity): string
{
    return match ($severity) {
        'emergency', 'critical' => 'danger',
        'warning' => 'orange',
        'info' => 'blue',
        default => 'navy',
    };
}

function noc_security_event_evidence(array $event): array
{
    return app_db_json_decode((string) ($event['evidence_json'] ?? ''), []);
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security / abuse monitor</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .security-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(340px,.9fr);gap:18px;align-items:start}
        .security-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .security-risk-card{display:grid;gap:12px;padding:18px;border-radius:24px;background:rgba(255,255,255,.94);border:1px solid rgba(148,163,184,.22);box-shadow:0 18px 40px rgba(15,23,42,.08)}
        .security-risk-card.is-danger{border-color:rgba(244,63,94,.35);background:linear-gradient(180deg,#fff,#fff1f2)}
        .security-risk-card.is-orange{border-color:rgba(251,146,60,.35);background:linear-gradient(180deg,#fff,#fff7ed)}
        .security-risk-card.is-blue{border-color:rgba(14,165,233,.3);background:linear-gradient(180deg,#fff,#f0f9ff)}
        .security-risk-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
        .security-risk-head h3{margin:0;color:#10233f;font-size:1.05rem}
        .security-score{display:inline-grid;place-items:center;min-width:58px;height:58px;border-radius:18px;background:#10233f;color:#fff;font-weight:1000}
        .security-evidence{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .security-evidence span{display:grid;gap:4px;padding:10px;border-radius:14px;background:rgba(248,250,252,.9);font-size:.78rem;color:#64748b;font-weight:800}
        .security-evidence b{font-size:.95rem;color:#10233f;overflow-wrap:anywhere}
        .security-action-list{display:grid;gap:7px;margin:0;padding:0;list-style:none}
        .security-action-list li{display:flex;gap:8px;align-items:flex-start;color:#475569;font-size:.86rem}
        .security-action-list i{margin-top:3px;color:#0f8b92}
        .security-scan-form{display:grid;gap:12px}
        .security-scan-form label{display:grid;gap:6px;color:#64748b;font-weight:900}
        .security-scan-form input,.security-scan-form select{min-height:44px;border:1px solid rgba(148,163,184,.32);border-radius:14px;padding:0 12px;background:#fff;color:#10233f;font-weight:900}
        .security-timeline{display:grid;gap:10px}
        .security-event{display:grid;gap:7px;padding:14px;border-radius:18px;background:rgba(248,250,252,.94);border:1px solid rgba(148,163,184,.2)}
        .security-event-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
        .security-event strong{color:#10233f}
        .security-event small{color:#64748b}
        .security-pill{display:inline-flex;width:max-content;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:.76rem;font-weight:1000}
        .security-pill.is-danger{background:#ffe4e6;color:#9f1239}
        .security-pill.is-orange{background:#ffedd5;color:#9a3412}
        .security-pill.is-blue{background:#e0f2fe;color:#075985}
        @media (max-width:1050px){.security-grid,.security-card-grid{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('Security / abuse monitor', 'security-anomaly', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e((string) $flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e((string) $flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">NOC security engine</span>
                <h1>Virus, botnet, top-talker ve abuse monitorinqi</h1>
                <p>RADIUS accounting ve NAT loglarindan anormal trafik, cox sessiya, destination fanout ve upload abuse siqnallarini tapir. Bloklama avtomatik deyil; CoA/rate-limit/quarantine yalniz tesdiqle icra olunmalidir.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="radius-logs.php">RADIUS loglari</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-natlog.php">NAT log axtarisi</a>
                    <a class="portal-ghost-btn portal-inline-link" href="noc-devices.php">NOC cihaz paneli</a>
                    <a class="portal-ghost-btn portal-inline-link" href="docs/security-anomaly-engine.md">Runbook</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Max risk</span>
                <strong><?= e((string) ($summary['max_risk_score'] ?? 0)); ?></strong>
                <small><?= e((string) ($summary['findings'] ?? 0)); ?> aktiv finding / <?= e((string) ($summary['critical_or_higher'] ?? 0)); ?> critical+</small>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Finding', (string) ($summary['findings'] ?? 0), 'detect-only cari scan', 'fa-shield-virus', 'blue'); ?>
            <?php billing_render_stat_card('Critical+', (string) ($summary['critical_or_higher'] ?? 0), 'tez baxilmalidir', 'fa-triangle-exclamation', ((int) ($summary['critical_or_higher'] ?? 0) > 0 ? 'red' : 'green')); ?>
            <?php billing_render_stat_card('Max risk', (string) ($summary['max_risk_score'] ?? 0), '0-100 risk score', 'fa-gauge-high', ((int) ($summary['max_risk_score'] ?? 0) >= 75 ? 'red' : 'green')); ?>
            <?php billing_render_stat_card('Event tarixi', (string) count($events), 'son 30 security event', 'fa-clock-rotate-left', 'navy'); ?>
        </section>

        <section class="security-grid portal-reveal">
            <div class="portal-card">
                <div class="portal-card-head">
                    <h2>Aktiv risk kartlari</h2>
                    <small>Hazirki scan neticesi. Threshold ve NetFlow/IPFIX qosulduqca deqiqlik artacaq.</small>
                </div>
                <?php if ($findings === []): ?>
                    <?php billing_render_empty_state('Aktiv abuse siqnali yoxdur', 'Cari radacct/NAT loglara gore kritik virus, botnet, top-talker ve fanout tapilmadi.', 'fa-shield-heart'); ?>
                <?php else: ?>
                    <div class="security-card-grid">
                        <?php foreach (array_slice($findings, 0, 8) as $finding): ?>
                            <?php
                            $severity = (string) ($finding['severity'] ?? 'info');
                            $tone = noc_security_tone($severity);
                            $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
                            $actions = array_slice(array_values(array_filter((array) ($finding['recommended_actions'] ?? []), 'is_array')), 0, 3);
                            ?>
                            <article class="security-risk-card is-<?= e($tone); ?>">
                                <div class="security-risk-head">
                                    <div>
                                        <span class="security-pill is-<?= e($tone); ?>"><?= e(strtoupper($severity)); ?> / <?= e((string) ($finding['signal_type'] ?? '-')); ?></span>
                                        <h3><?= e((string) ($finding['subject_id'] ?? '-')); ?></h3>
                                        <small><?= e((string) ($finding['title'] ?? '-')); ?></small>
                                    </div>
                                    <span class="security-score"><?= e((string) ($finding['risk_score'] ?? 0)); ?></span>
                                </div>
                                <div class="security-evidence">
                                    <span>Total<b><?= e((string) ($evidence['total_gb'] ?? '-')); ?> GB</b></span>
                                    <span>Upload<b><?= e((string) ($evidence['upload_gb'] ?? '-')); ?> GB</b></span>
                                    <span>Destinations<b><?= e((string) ($evidence['destination_count'] ?? '-')); ?></b></span>
                                    <span>Ports<b><?= e((string) ($evidence['destination_port_count'] ?? '-')); ?></b></span>
                                </div>
                                <ul class="security-action-list">
                                    <?php foreach ($actions as $action): ?>
                                        <li><i class="fa-solid fa-circle-check"></i><span><?= e((string) ($action['action'] ?? '-')); ?>: <?= e((string) ($action['reason'] ?? '')); ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="portal-card">
                <div class="portal-card-head">
                    <h2>Scan idarəsi</h2>
                    <small>Record test mode alarm/event yazır. Live status yalnız admin üçündür.</small>
                </div>
                <form class="security-scan-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <label>
                        Traffic window
                        <input type="number" name="traffic_window_hours" min="1" max="168" value="24">
                    </label>
                    <label>
                        NAT window
                        <input type="number" name="nat_window_minutes" min="1" max="1440" value="15">
                    </label>
                    <label>
                        Event yazılsın?
                        <select name="record">
                            <option value="0">Yox, detect-only</option>
                            <option value="1">Bəli, event/alarm yaz</option>
                        </select>
                    </label>
                    <?php if (app_portal_is_admin($user)): ?>
                        <label>
                            Status
                            <select name="live">
                                <option value="0">Test statusu</option>
                                <option value="1">Live open statusu</option>
                            </select>
                        </label>
                    <?php endif; ?>
                    <button class="portal-primary-btn" type="submit"><i class="fa-solid fa-radar"></i> Scan et</button>
                </form>
            </aside>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Security event tarixçəsi</h2>
                <small>Son yazılmış event-lər. Test event-lər retention ilə avtomatik təmizlənir.</small>
            </div>
            <?php if ($events === []): ?>
                <?php billing_render_empty_state('Event yoxdur', 'Hələ security anomaly event yazılmayıb. Detect-only scan event yaratmır.', 'fa-clock'); ?>
            <?php else: ?>
                <div class="security-timeline">
                    <?php foreach ($events as $event): ?>
                        <?php
                        $severity = (string) ($event['severity'] ?? 'info');
                        $tone = noc_security_tone($severity);
                        $evidence = noc_security_event_evidence($event);
                        ?>
                        <article class="security-event">
                            <div class="security-event-head">
                                <div>
                                    <span class="security-pill is-<?= e($tone); ?>"><?= e(strtoupper($severity)); ?> / <?= e((string) ($event['status'] ?? '-')); ?></span>
                                    <strong><?= e((string) ($event['subject_id'] ?? '-')); ?> - <?= e((string) ($event['signal_type'] ?? '-')); ?></strong>
                                </div>
                                <small><?= e((string) ($event['last_seen_at'] ?? '-')); ?></small>
                            </div>
                            <small><?= e((string) ($event['title'] ?? '-')); ?> / risk <?= e((string) ($event['risk_score'] ?? 0)); ?> / seen <?= e((string) ($event['seen_count'] ?? 1)); ?></small>
                            <?php if ($evidence !== []): ?>
                                <small>Sübut: total <?= e((string) ($evidence['total_gb'] ?? '-')); ?>GB, upload <?= e((string) ($evidence['upload_gb'] ?? '-')); ?>GB, destinations <?= e((string) ($evidence['destination_count'] ?? '-')); ?></small>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
