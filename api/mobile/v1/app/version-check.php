<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$platform = strtolower(trim((string) ($_GET['platform'] ?? 'android')));
$currentVersion = trim((string) ($_GET['version'] ?? '1.0.0'));

$allowed = ['android', 'ios', 'huawei'];
if (!in_array($platform, $allowed, true)) {
    $platform = 'android';
}

$db = app_db();
$stmt = $db->prepare(
    "SELECT * FROM mobile_app_config WHERE platform = ? LIMIT 1"
);
$stmt->execute([$platform]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    $config = [
        'platform' => $platform,
        'min_supported_version' => '1.0.0',
        'latest_version' => '1.0.0',
        'force_update' => 0,
        'store_url' => 'https://superoptic.net/downloads/app',
        'release_notes' => 'İlkin reliz',
    ];
}

$minVer = (string) $config['min_supported_version'];
$latestVer = (string) $config['latest_version'];
$forceFlag = (int) $config['force_update'] === 1;

// Version comparison
$isDeprecated = version_compare($currentVersion, $minVer, '<');
$hasNewUpdate = version_compare($currentVersion, $latestVer, '<');
$forceUpdate = $forceFlag || $isDeprecated;

app_mobile_json_response([
    'platform' => $platform,
    'current_client_version' => $currentVersion,
    'min_supported_version' => $minVer,
    'latest_version' => $latestVer,
    'update_available' => $hasNewUpdate,
    'force_update' => $forceUpdate,
    'store_url' => (string) $config['store_url'],
    'release_notes' => (string) ($config['release_notes'] ?? ''),
], 200);
