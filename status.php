<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

app_send_security_headers();

$db = app_db();
$checks = [];
$summary = [
    'ok' => 0,
    'degraded' => 0,
    'down' => 0,
];
$clientIp = app_client_ip();
$detailAllowed = in_array($clientIp, ['127.0.0.1', '::1', 'localhost'], true)
    || str_starts_with($clientIp, '10.')
    || str_starts_with($clientIp, '192.168.')
    || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $clientIp) === 1;

function status_add_check(array &$checks, string $name, string $status, string $details = '', string $icon = 'fa-circle-info'): void
{
    $checks[] = [
        'name' => $name,
        'status' => in_array($status, ['ok', 'degraded', 'down'], true) ? $status : 'degraded',
        'details' => $details,
        'icon' => $icon,
    ];
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'ok' => 'portal-pill-success',
        'down' => 'portal-pill-danger',
        default => '',
    };
}

if ($db instanceof PDO) {
    try {
        $databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        status_add_check(
            $checks,
            'Database',
            $databaseName !== '' ? 'ok' : 'degraded',
            $detailAllowed
                ? ($databaseName !== '' ? 'Connected: ' . $databaseName : 'Database name missing')
                : ($databaseName !== '' ? 'Connected' : 'Database check degraded'),
            'fa-database'
        );
    } catch (Throwable) {
        status_add_check($checks, 'Database', 'down', $detailAllowed ? 'MySQL query failed' : 'Database unavailable', 'fa-database');
    }

    $tableChecks = [
        'Billing customers' => ['portal_accounts', "SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer'", 'fa-users'],
        'Radius services' => ['billing_radius_services', 'SELECT COUNT(*) FROM billing_radius_services', 'fa-satellite-dish'],
        'Radius accounting' => ['radacct', 'SELECT COUNT(*) FROM radacct', 'fa-list-check'],
        'Message queue' => ['communication_messages', 'SELECT COUNT(*) FROM communication_messages WHERE status = "queued"', 'fa-message'],
        'Open tickets' => ['portal_tickets', 'SELECT COUNT(*) FROM portal_tickets WHERE status NOT IN ("closed", "resolved")', 'fa-ticket'],
    ];
    foreach ($tableChecks as $name => [$table, $sql, $icon]) {
        try {
            $count = (int) $db->query($sql)->fetchColumn();
            status_add_check($checks, $name, 'ok', $detailAllowed ? ($count . ($name === 'Message queue' ? ' queued' : ' records')) : 'Ready', $icon);
        } catch (Throwable) {
            status_add_check($checks, $name, 'degraded', $detailAllowed ? ('Table/query unavailable: ' . $table) : 'Check degraded', $icon);
        }
    }
} else {
    status_add_check($checks, 'Database', 'down', $detailAllowed ? 'MySQL connection unavailable' : 'Database unavailable', 'fa-database');
}

$requiredGatewayFiles = [
    'Nginx gateway config' => __DIR__ . '/deploy/nginx/sites-available/superoptic-gateway.conf',
    'Gateway validator' => __DIR__ . '/tools/validate-nginx-gateway.php',
    'Health API' => __DIR__ . '/api/health.php',
];
foreach ($requiredGatewayFiles as $name => $path) {
    status_add_check($checks, $name, is_file($path) ? 'ok' : 'degraded', is_file($path) ? 'Ready' : 'Missing file', 'fa-shield-halved');
}

foreach ($checks as $check) {
    $summary[$check['status']]++;
}

$overallStatus = $summary['down'] > 0 ? 'down' : ($summary['degraded'] > 0 ? 'degraded' : 'ok');
$appUrl = rtrim(app_config('app_url', ''), '/');
$operatorUrl = $appUrl !== '' ? $appUrl . '/portal-login.php?role=employee' : 'portal-login.php?role=employee';
http_response_code($overallStatus === 'down' ? 503 : 200);
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SuperOptic Status</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .status-shell{max-width:1120px;margin:0 auto;padding:34px 16px 56px}
        .status-hero{display:grid;grid-template-columns:minmax(0,1fr) 260px;gap:18px;align-items:stretch}
        .status-card{background:rgba(255,255,255,.92);border:1px solid var(--portal-line);box-shadow:var(--portal-shadow);border-radius:28px;padding:24px}
        .status-kicker{font-size:.78rem;text-transform:uppercase;letter-spacing:.16em;color:var(--portal-accent);font-weight:800}
        .status-hero h1{margin:8px 0 10px;font-size:clamp(2rem,4vw,3.4rem);line-height:1}
        .status-hero p{color:var(--portal-muted);max-width:720px}
        .status-overall{display:flex;flex-direction:column;gap:10px;justify-content:center}
        .status-overall strong{font-size:2.4rem;text-transform:uppercase}
        .status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:18px}
        .status-check{display:flex;gap:14px;align-items:flex-start}
        .status-check i{width:42px;height:42px;border-radius:16px;display:grid;place-items:center;background:rgba(10,132,153,.1);color:#0a8499}
        .status-check h2{margin:0 0 6px;font-size:1rem}
        .status-check p{margin:0;color:var(--portal-muted);font-size:.9rem}
        .status-footer{margin-top:18px;color:var(--portal-muted);font-size:.9rem}
        @media (max-width:760px){.status-hero{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
    <main class="status-shell">
        <section class="status-hero">
            <article class="status-card">
                <span class="status-kicker">SuperOptic status</span>
                <h1>Platforma veziyyeti</h1>
                <p>Bu sehife public status domeni ucun read-only gorunusdur. DB, RADIUS cedvelleri, mesaj novbesi, ticket ve gateway hazirligi yoxlanilir; hec bir idareetme emeliyyati icra edilmir.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="api/health.php">JSON health</a>
                    <a class="portal-ghost-btn portal-inline-link" href="<?= e($operatorUrl); ?>">Operator paneli</a>
                </div>
            </article>
            <article class="status-card status-overall">
                <span>Umumi status</span>
                <strong><?= e($overallStatus); ?></strong>
                <div class="portal-badge-list">
                    <span class="portal-pill portal-pill-success"><?= e((string) $summary['ok']); ?> OK</span>
                    <span class="portal-pill"><?= e((string) $summary['degraded']); ?> Degraded</span>
                    <span class="portal-pill portal-pill-danger"><?= e((string) $summary['down']); ?> Down</span>
                </div>
                <small>Yenilənmə: <?= e(date('d.m.Y H:i:s')); ?></small>
            </article>
        </section>

        <section class="status-grid">
            <?php foreach ($checks as $check): ?>
                <article class="status-card status-check">
                    <i class="fa-solid <?= e($check['icon']); ?>"></i>
                    <div>
                        <h2><?= e($check['name']); ?></h2>
                        <span class="portal-pill <?= e(status_badge_class($check['status'])); ?>"><?= e(strtoupper($check['status'])); ?></span>
                        <p><?= e($check['details']); ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <p class="status-footer">Qeyd: SNMP/SSH cihaz monitorinqi ayrica NOC panelinde qiymetlendirilir. Bu sehife public gateway ve tetbiq bazasinin minimal saglamliq gostericisidir.</p>
    </main>
</body>
</html>
