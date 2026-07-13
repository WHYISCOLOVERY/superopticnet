<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$query = (string) ($db->query('SELECT username FROM billing_radius_services WHERE username <> "" ORDER BY id LIMIT 1')->fetchColumn() ?: '');
if ($query === '') {
    fwrite(STDERR, "no_radius_service_username\n");
    exit(3);
}

$actor = ['id' => 0, 'full_name' => 'modem_search_smoke'];
$originalPathSetting = app_mgs_runtime_setting('monitor_management_vlan_connected', '0');
app_mgs_set_runtime_setting('monitor_management_vlan_connected', '0', $actor, $db);

$reply = app_mgs_build_modem_search_reply($query, true, $db);
app_mgs_set_runtime_setting('monitor_management_vlan_connected', $originalPathSetting, $actor, $db);

if (empty($reply['ok'])) {
    fwrite(STDERR, 'reply_failed:' . (string) ($reply['reason'] ?? 'unknown') . "\n");
    exit(4);
}

$text = (string) ($reply['text'] ?? '');
if ($text === '') {
    fwrite(STDERR, "empty_reply_text\n");
    exit(5);
}

foreach (['OLT / PON / ONT', 'ONT RX / TX', 'OLT RX / TX', 'Temperatur', 'RADIUS', 'Ticket'] as $marker) {
    if (!str_contains($text, $marker)) {
        fwrite(STDERR, 'missing_marker:' . $marker . "\n");
        exit(6);
    }
}

if (!str_contains($text, 'Live OLT query: management path down')) {
    fwrite(STDERR, "missing_path_down_fallback\n");
    exit(7);
}

echo json_encode([
    'status' => 'ok',
    'query' => $query,
    'preview' => mb_substr($text, 0, 300),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
