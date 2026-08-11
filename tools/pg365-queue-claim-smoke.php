<?php
declare(strict_types=1);

require dirname(__DIR__) . '/communication-queue-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "MySQL/PDO connection is not ready.\n");
    exit(3);
}

$settings = app_db_settings();
$secondDb = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $settings['host'],
        $settings['port'],
        $settings['name'],
        $settings['charset']
    ),
    $settings['user'],
    $settings['pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$messageId = 2147483000;
$claimed = app_communication_queue_claim($db, $messageId);
$blockedWhileHeld = !app_communication_queue_claim($secondDb, $messageId);
app_communication_queue_release_claim($db, $messageId);
$reclaimed = app_communication_queue_claim($secondDb, $messageId);
app_communication_queue_release_claim($secondDb, $messageId);

$passed = $claimed && $blockedWhileHeld && $reclaimed;
echo json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'claim_acquired' => $claimed,
    'second_worker_blocked_while_held' => $blockedWhileHeld,
    'claim_reacquired_after_release' => $reclaimed,
    'real_provider_requests' => 0,
], JSON_PRETTY_PRINT) . PHP_EOL;

exit($passed ? 0 : 1);
