<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/billing-operations-check-lib.php';

$lang = app_lang();
$user = billing_require_employee();
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya tokeni yanlisdir. Emeliyyat icra olunmadi.'];
    } elseif ($action === 'sync_all_radius') {
        $startCode = clean_text((string) ($_POST['start_code'] ?? ''));
        $endCode = clean_text((string) ($_POST['end_code'] ?? ''));
        $sync = app_billing_sync_radius_accounts($startCode !== '' ? $startCode : null, $endCode !== '' ? $endCode : null);
        $flash = [
            'type' => ((int) ($sync['failed'] ?? 0) > 0) ? 'warning' : 'success',
            'message' => 'RADIUS sync yekunlasdi: ' . (int) ($sync['synced'] ?? 0) . ' ugurlu, ' . (int) ($sync['failed'] ?? 0) . ' xeta.',
        ];
    } elseif ($action === 'refresh_countdowns') {
        $refresh = app_billing_refresh_countdowns(5000, true);
        $suspend = app_billing_process_due_suspensions();
        $flash = [
            'type' => ((int) ($refresh['failed'] ?? 0) > 0 || (int) ($suspend['failed'] ?? 0) > 0) ? 'warning' : 'success',
            'message' => 'Gun hesabi yenilendi: ' . (int) ($refresh['updated'] ?? 0) . ' yenilendi, ' . (int) ($suspend['suspended'] ?? 0) . ' servis dayandirildi.',
        ];
    }
}
$result = billing_ops_run_checks(app_db());
$summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
$checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
$criticalFailed = (int) ($result['critical_failed'] ?? 0);
$warningsFailed = (int) ($result['warnings_failed'] ?? 0);
$status = (string) ($result['status'] ?? 'failed');
$groups = [];
foreach ($checks as $check) {
    if (!is_array($check)) {
        continue;
    }
    $group = (string) ($check['group'] ?? 'other');
    $groups[$group][] = $check;
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing operational check</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .billing-check-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .billing-check-list{display:grid;gap:10px}
        .billing-check-row{display:grid;grid-template-columns:28px 1fr auto;gap:12px;align-items:center;padding:12px;border:1px solid var(--portal-line);border-radius:16px;background:#fff}
        .billing-check-row i{font-size:1rem}
        .billing-check-row.is-ok i{color:#16a34a}
        .billing-check-row.is-fail i{color:#ef4444}
        .billing-check-row.is-warning i{color:#f97316}
        .billing-check-row strong{display:block}
        .billing-check-row small{display:block;margin-top:3px}
        .billing-check-code{display:block;white-space:normal;word-break:break-word;padding:12px;border-radius:14px;background:#0f172a;color:#dbeafe;font-size:.78rem;line-height:1.5}
        @media (max-width: 960px){.billing-check-grid{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('Billing yoxlama paneli', 'billing-check', $lang); ?>

    <main class="portal-layout">
        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">Billing operations readiness</span>
                <h1>Billing funksiyalarının işlək statusu</h1>
                <p>Müştəri, tarif, IP pool, RADIUS SQL, ödəniş, mesaj və maintenance axınları eyni paneldə yoxlanır.</p>
                <div class="mgs-command-actions">
            <a class="portal-primary-btn" href="billing-customers.php?create=1"><i class="fa-solid fa-user-plus"></i> Yeni müştəri</a>
                    <a class="portal-ghost-btn portal-inline-link" href="billing-cash-payment.php"><i class="fa-solid fa-cash-register"></i> Ödəniş al</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-active.php"><i class="fa-solid fa-satellite-dish"></i> RADIUS</a>
                </div>
            </div>
            <aside class="mgs-command-panel">
                <span>Status</span>
                <strong><?= e(strtoupper($status)); ?></strong>
                <small>Critical failed: <?= e((string) $criticalFailed); ?> / warning failed: <?= e((string) $warningsFailed); ?></small>
                <span class="mgs-status-badge <?= $criticalFailed === 0 ? 'is-online' : ''; ?>"><?= e((string) ($result['generated_at'] ?? '')); ?></span>
            </aside>
        </section>

        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>" data-autohide-alert>
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Operativ emeliyyatlar</h2>
                <small>Bu duymeler billing bazasini RADIUS SQL ile hazir veziyyete getirir.</small>
            </div>
            <div class="billing-filter-actions">
                <form method="post" class="portal-inline-inputs">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="sync_all_radius">
                    <input type="text" name="start_code" placeholder="Baslangic kod (optional)">
                    <input type="text" name="end_code" placeholder="Son kod (optional)">
                    <button type="submit" class="portal-primary-btn">Butun musterileri RADIUS-a yaz</button>
                </form>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="refresh_countdowns">
                    <button type="submit" class="portal-ghost-btn portal-inline-link">Gun hesabi ve avtomatik baglama yoxla</button>
                </form>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Müştəri', (string) ($summary['customers'] ?? 0), 'aktiv: ' . (string) ($summary['active_customers'] ?? 0), 'fa-users', 'blue', 'billing-customers.php'); ?>
            <?php billing_render_stat_card('Tarif', (string) ($summary['tariffs'] ?? 0), 'active price plans', 'fa-layer-group', 'orange', 'radius-billing.php#tariffs'); ?>
            <?php billing_render_stat_card('RADIUS servis', (string) ($summary['radius_services'] ?? 0), 'billing_radius_services', 'fa-satellite-dish', 'green', 'radius-active.php'); ?>
            <?php billing_render_stat_card('Ödəniş', (string) ($summary['payments'] ?? 0), 'billing_payments', 'fa-receipt', 'navy', 'billing-payments.php'); ?>
            <?php billing_render_stat_card('Ticket', (string) ($summary['open_tickets'] ?? 0), 'açıq ticket', 'fa-ticket', 'red', 'crm.php'); ?>
            <?php billing_render_stat_card('Message queue', (string) ($summary['queued_messages'] ?? 0), 'queued', 'fa-message', 'blue', 'message-center.php'); ?>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>CLI verification</h2>
                <small>Serverdə eyni yoxlamanı terminaldan da işlətmək üçün.</small>
            </div>
            <code class="billing-check-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-operation-check.php</code>
            <code class="billing-check-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\optimize-billing-performance.php --apply</code>
            <code class="billing-check-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-flow-smoke.php</code>
            <code class="billing-check-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-ui-smoke.php</code>
            <code class="billing-check-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-maintenance.php --apply --sync-radius --queue-notices --channel=auto</code>
        </section>

        <section class="billing-check-grid portal-reveal">
            <?php foreach ($groups as $group => $items): ?>
                <article class="portal-card">
                    <div class="portal-card-head">
                        <h2><?= e(ucfirst(str_replace('_', ' ', $group))); ?></h2>
                        <small><?= e((string) count($items)); ?> yoxlama</small>
                    </div>
                    <div class="billing-check-list">
                        <?php foreach ($items as $check): ?>
                            <?php
                            $ok = !empty($check['ok']);
                            $level = (string) ($check['level'] ?? 'info');
                            $rowClass = $ok ? 'is-ok' : ($level === 'critical' ? 'is-fail' : 'is-warning');
                            ?>
                            <div class="billing-check-row <?= e($rowClass); ?>">
                                <i class="fa-solid <?= $ok ? 'fa-circle-check' : ($level === 'critical' ? 'fa-circle-xmark' : 'fa-triangle-exclamation'); ?>"></i>
                                <div>
                                    <strong><?= e((string) ($check['name'] ?? '-')); ?></strong>
                                    <small><?= e((string) ($check['message'] ?? '-')); ?></small>
                                </div>
                                <span class="portal-pill"><?= e($level); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
