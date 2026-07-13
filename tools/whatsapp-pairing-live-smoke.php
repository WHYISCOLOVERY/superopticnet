<?php
declare(strict_types=1);

require dirname(__DIR__) . '/whatsapp-monitor-lib.php';
require_once dirname(__DIR__) . '/billing-ui.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}
whatsapp_monitor_ensure_schema($db);

$actorStatement = $db->prepare('SELECT * FROM portal_accounts WHERE login = :login ORDER BY id DESC LIMIT 1');
$actorStatement->execute(['login' => 's.abdullayev@superoptic.net']);
$actor = $actorStatement->fetch(PDO::FETCH_ASSOC);
if (!is_array($actor)) {
    $actorStatement = $db->prepare('SELECT * FROM portal_accounts WHERE full_name = :full_name ORDER BY id DESC LIMIT 1');
    $actorStatement->execute(['full_name' => 'Serdar Abdullayev']);
    $actor = $actorStatement->fetch(PDO::FETCH_ASSOC);
}
if (!is_array($actor)) {
    fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => 'actor_missing']) . PHP_EOL);
    exit(0);
}

$context = whatsapp_monitor_context($db, $actor, 'pairing', 'az');
$pairings = (array) ($context['pairings'] ?? []);
$latest = (array) ($pairings[0] ?? []);
$errors = [];
if (($latest['pairing_status'] ?? '') !== 'qr_ready') {
    $errors[] = 'latest_pairing_not_qr_ready';
}
if (!str_starts_with((string) ($latest['qr_image_data_uri'] ?? ''), 'data:image/')) {
    $errors[] = 'qr_image_missing';
}
if (trim((string) ($latest['worker_id'] ?? '')) === '') {
    $errors[] = 'worker_id_missing';
}

$monitorLang = 'az';
ob_start();
require dirname(__DIR__) . '/views/whatsapp-monitor/pairing.php';
$html = (string) ob_get_clean();
foreach (['data-wa-pairing-live', 'data-wa-qr-box', '<img src="data:image/'] as $marker) {
    if (!str_contains($html, $marker)) {
        $errors[] = 'render_missing:' . $marker;
    }
}

$health = @file_get_contents('http://127.0.0.1:4001/health');
$healthPayload = is_string($health) ? json_decode($health, true) : null;
if (!is_array($healthPayload) || empty($healthPayload['ok'])) {
    $errors[] = 'bridge_health_failed';
}

fwrite(STDOUT, json_encode([
    'ok' => $errors === [],
    'pairing_id' => (int) ($latest['id'] ?? 0),
    'status' => (string) ($latest['pairing_status'] ?? ''),
    'qr_size' => strlen((string) ($latest['qr_image_data_uri'] ?? '')),
    'worker_id' => (string) ($latest['worker_id'] ?? ''),
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($errors === [] ? 0 : 1);
