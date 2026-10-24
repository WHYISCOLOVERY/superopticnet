<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$config = (string) @file_get_contents($root . '/config.php');
$mgs = (string) @file_get_contents($root . '/mgs-integration-lib.php');
$actions = (string) @file_get_contents($root . '/portal-actions.php');
$errors = [];

$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$expect(str_contains($config, 'function app_telegram_runtime_chat_id'), 'runtime_chat_fallback_missing');
$expect(str_contains($config, "'telegram_customer_chat_id'"), 'customer_chat_runtime_key_missing');
$expect(str_contains($mgs, "'Bilet: ' . \$ticketNo"), 'alarm_ticket_number_missing');
$expect(str_contains($mgs, "'Bagla: ' . \$closeLink"), 'alarm_close_link_missing');
$expect(str_contains($actions, "app_portal_can_access_module(\$user, 'crm')"), 'crm_post_module_guard_missing');

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
