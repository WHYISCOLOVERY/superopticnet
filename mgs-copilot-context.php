<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';

$user = billing_require_employee();
$db = app_db();
$lang = app_lang();
$active = 'copilot-context';
$accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsJson = (string) ($_GET['format'] ?? '') === 'json'
    || (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'));

if (!$wantsJson) {
    $params = [];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    $target = 'ai-network-analytics.php' . ($params !== [] ? '?' . http_build_query($params) : '');
    header('Location: ' . $target, true, 302);
    exit;
}

if (!$db instanceof PDO) {
    http_response_code(503);
    $payload = [
        'ok' => false,
        'reason' => 'database_unavailable',
        'generated_at' => date(DATE_ATOM),
    ];
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        ?>
        <!doctype html>
        <html lang="<?= e($lang); ?>">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Superpilot konteksti</title>
            <link rel="stylesheet" href="css/fontawesome-lite.css">
            <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
        </head>
        <body class="portal-body">
            <?php billing_render_header('Superpilot konteksti', $active, $lang); ?>
            <main class="portal-layout"><section class="portal-card"><h1>Database baglantisi yoxdur</h1><p>Superpilot konteksti DB olmadan hazirlanmir.</p></section></main>
        </body>
        </html>
        <?php
    }
    exit;
}

if ($wantsJson) {
    $context = app_mgs_copilot_context($db, $user);
    $context['requested_by'] = [
        'account_id' => (int) ($user['id'] ?? 0),
        'role' => (string) ($user['role'] ?? ''),
        'account_type' => (string) ($user['account_type'] ?? ''),
    ];
    $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $json;
    exit;
}

$summary = app_mgs_summary($db, false, $user);
$bridge = app_mgs_bridge_status();
$managementPathReady = app_mgs_management_path_ready();
$context = [
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'summary' => $summary,
    'management_path' => [
        'monitor_management_vlan_connected' => $managementPathReady,
        'state' => $managementPathReady ? 'ready_for_probe' : 'internal_offline_alarms_suppressed',
    ],
    'bridge' => [
        'online' => (bool) ($bridge['online'] ?? false),
        'url' => (string) ($bridge['url'] ?? ''),
        'message' => (string) ($bridge['message'] ?? ''),
    ],
];
?>
<!doctype html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Superpilot konteksti</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .copilot-context-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
        .copilot-context-lazy{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center}
        .copilot-context-lazy code{display:block;padding:12px 14px;border-radius:16px;background:#f1f6f8;color:#10233f;white-space:normal;word-break:break-word;font-weight:800}
        @media (max-width: 900px){.copilot-context-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width: 560px){.copilot-context-grid,.copilot-context-lazy{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('Superpilot konteksti', $active, $lang); ?>
    <main class="portal-layout">
        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">AI / Superpilot</span>
                <h1>Superpilot kontekst snapshot</h1>
                <p>Bu sehife operator ucun oxunaqli xulase verir. API ucun JSON lazimdirsa `format=json` ile acilir.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="mgs-copilot-context.php?format=json"><i class="fa-solid fa-code"></i> JSON ac</a>
                    <a class="portal-ghost-btn portal-inline-link" href="mgs-integration.php"><i class="fa-solid fa-gears"></i> Servisler</a>
                </div>
            </div>
            <div class="portal-hero-card">
                <span>Status</span>
                <strong><?= !empty($context['ok']) ? 'Hazir' : 'Yoxlanmalidir'; ?></strong>
                <small><?= e((string) ($context['generated_at'] ?? date(DATE_ATOM))); ?></small>
            </div>
        </section>
        <section class="copilot-context-grid portal-reveal">
            <article class="portal-muted-card"><strong>Alarm</strong><p><?= e((string) ($context['summary']['alarms_open'] ?? 0)); ?></p><small>open</small></article>
            <article class="portal-muted-card"><strong>Device</strong><p><?= e((string) ($context['summary']['device_profiles'] ?? 0)); ?></p><small>profiles</small></article>
            <article class="portal-muted-card"><strong>Reachable</strong><p><?= e((string) ($context['summary']['devices_reachable'] ?? 0)); ?></p><small>management path</small></article>
            <article class="portal-muted-card"><strong>Inventory</strong><p><?= e((string) ($context['summary']['inventory_items'] ?? 0)); ?></p><small>items</small></article>
        </section>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Tam context lazy yuklenir</h2>
                <small>UI defaultda yalniz xulase yukleyir. Tam snapshot API ve Superpilot agenti ucun ayrica acilir.</small>
            </div>
            <div class="copilot-context-lazy" data-full-context-lazy>
                <code>mgs-copilot-context.php?format=json</code>
                <a class="portal-primary-btn portal-inline-link" href="mgs-copilot-context.php?format=json" target="_blank" rel="noopener">
                    <i class="fa-solid fa-code"></i> Tam JSON ac
                </a>
            </div>
        </section>
    </main>
    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
