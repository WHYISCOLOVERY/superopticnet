<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';

$page = (string) file_get_contents(__DIR__ . '/../message-center.php');
$errors = [];

foreach ([
    'message-channel-chart',
    'message-channel-ring',
    'message-channel-bars',
    'data-message-channel',
    '$messageChannelUsage',
    'message_center_status_label',
    'message_center_mode_label',
    'message_center_reason_label',
    'Novbe worker ve provider hazirligi',
    'Namelum nomre gunluk limit',
    'data-message-shell',
    'data-message-content',
    'data-message-command-strip',
    'data-message-status',
    'history.pushState',
    'popstate',
    'new DOMParser()',
    'String(form.method || \'get\').toLowerCase() !== \'get\'',
    'Mesaj emeliyyatlari',
    'Ferdi mesaj',
    'SMS ayari',
    'sms-provider-settings',
    'Provider test',
] as $marker) {
    if (!str_contains($page, $marker)) {
        $errors[] = $marker . '_missing';
    }
}

$db = app_db();
if ($db instanceof PDO) {
    try {
        $statement = $db->query(
            'SELECT channel, COUNT(*) AS total
             FROM communication_messages
             GROUP BY channel
             LIMIT 10'
        );
        $rows = $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $exception) {
        $errors[] = 'channel_usage_query_failed:' . $exception->getMessage();
    }
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
