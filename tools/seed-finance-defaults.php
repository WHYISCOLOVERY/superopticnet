<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/finance-bonus-lib.php';

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

try {
    app_bonus_require_schema($db);
    app_bonus_seed_defaults($db);
    echo "finance_defaults_seeded=1\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'finance_seed_failed:' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
