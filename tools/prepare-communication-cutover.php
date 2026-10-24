<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';

$apply = in_array('--apply', $argv, true);
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$queued = $db->query(
    "SELECT channel, COUNT(*) total
       FROM communication_messages
      WHERE status='queued'
      GROUP BY channel ORDER BY channel"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$staleExternal = (int) $db->query(
    "SELECT COUNT(*) FROM communication_messages
      WHERE status='queued' AND channel IN ('telegram','whatsapp')
        AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
)->fetchColumn();

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'queued_by_channel' => array_map('intval', $queued),
    'stale_external_to_archive' => $staleExternal,
    'archived' => 0,
    'sms_enabled' => false,
];

if ($apply) {
    $db->beginTransaction();
    try {
        $archive = $db->prepare(
            "UPDATE communication_messages
                SET status='failed', delivery_state='final_failed', provider_status_final=1,
                    provider_status_description='pre_cutover_stale_queue',
                    final_failed_at=NOW(), delivery_updated_at=NOW(),
                    metadata_json=JSON_SET(
                        CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE JSON_OBJECT() END,
                        '$.cutover_archive_reason', 'pre_cutover_stale_queue',
                        '$.cutover_archived_at', DATE_FORMAT(NOW(), '%Y-%m-%dT%H:%i:%s%z')
                    )
              WHERE status='queued' AND channel IN ('telegram','whatsapp')
                AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $archive->execute();
        $result['archived'] = $archive->rowCount();

        $setting = $db->prepare(
            'INSERT INTO mgs_runtime_settings (setting_key,setting_value,updated_by_name)
             VALUES ("channel_sms_enabled","1","legacy-cutover")
             ON DUPLICATE KEY UPDATE setting_value="1",updated_by_name="legacy-cutover"'
        );
        $setting->execute();
        $db->commit();
        $result['sms_enabled'] = true;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fwrite(STDERR, "Communication cutover preparation failed.\n");
        exit(3);
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
