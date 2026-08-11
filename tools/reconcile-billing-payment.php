<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';

$options = getopt('', ['retry-action::', 'reverse-operation::', 'actor-account-id:', 'reason:', 'correlation-id::']);
$actorId = (int) ($options['actor-account-id'] ?? 0);
$reason = trim((string) ($options['reason'] ?? ''));
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$statement = $db->prepare('SELECT * FROM portal_accounts WHERE id = :id AND is_active = 1 LIMIT 1');
$statement->execute(['id' => $actorId]);
$actor = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($actor) || !app_portal_is_admin($actor)) {
    fwrite(STDERR, "An active admin actor is required.\n");
    exit(3);
}

if (array_key_exists('retry-action', $options)) {
    $result = app_billing_payment_retry_action($db, (int) $options['retry-action'], $actor, $reason);
} elseif (array_key_exists('reverse-operation', $options)) {
    $result = app_billing_payment_reverse_operation(
        $db,
        (int) $options['reverse-operation'],
        $actor,
        $reason,
        (string) ($options['correlation-id'] ?? '')
    );
} else {
    fwrite(STDERR, "Use --retry-action=<id> or --reverse-operation=<id>.\n");
    exit(4);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
