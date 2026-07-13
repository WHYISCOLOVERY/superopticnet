<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['status' => 'failed', 'reason' => 'db_unavailable'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(2);
}

$statement = $db->prepare(
    'UPDATE communication_messages
     SET status = "failed",
         metadata_json = JSON_SET(COALESCE(NULLIF(metadata_json, ""), "{}"), "$.strict_isolation_cleanup", true)
     WHERE status = "queued"
       AND template_code = "real_queue_provider_test"
       AND message_body LIKE "%REAL TEST - % strict routing%"'
);
$statement->execute();

echo json_encode([
    'status' => 'ok',
    'updated' => $statement->rowCount(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
