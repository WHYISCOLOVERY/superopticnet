<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/finance-module-lib.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$user = [
    'id' => 1,
    'role' => 'employee',
    'account_type' => 'admin',
    'employee_role' => 'admin',
    'full_name' => 'Finance read-only smoke',
    'is_active' => 1,
];
$tabs = array_keys(app_finance_tabs());
$checks = [];

try {
    foreach ($tabs as $tab) {
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            $context = app_finance_build_context($db, $user, ['finance_tab' => $tab, 'page' => 1, 'limit' => 1]);
            $checks[$tab] = (string) ($context['tab'] ?? '') === $tab;
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'finance_readonly_failed:' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
