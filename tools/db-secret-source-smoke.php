<?php
declare(strict_types=1);

$original = getenv('DB_PASS');
putenv('DB_PASS');

// config.php loads a root .env file as a legitimate environment secret source
// (app_load_environment_file(), SUPEROPTIC_SECRET_ENV_FILE override). If a real
// .env with DB_PASS exists in this workspace, that load would silently repopulate
// DB_PASS right after we unset it above, making the "no environment secret at
// all" baseline below impossible to observe. Point the loader at a throwaway
// empty file for this probe only; the real .env is never opened or read.
$originalEnvFileOverride = getenv('SUPEROPTIC_SECRET_ENV_FILE');
$emptyEnvFile = tempnam(sys_get_temp_dir(), 'superoptic-empty-env-');
putenv('SUPEROPTIC_SECRET_ENV_FILE=' . $emptyEnvFile);

require dirname(__DIR__) . '/config.php';

$withoutEnvironment = app_db_settings();
$probe = 'db-env-smoke-' . bin2hex(random_bytes(8));
putenv('DB_PASS=' . $probe);
$withEnvironment = app_db_settings();

if ($original === false) {
    putenv('DB_PASS');
} else {
    putenv('DB_PASS=' . $original);
}
if ($originalEnvFileOverride === false) {
    putenv('SUPEROPTIC_SECRET_ENV_FILE');
} else {
    putenv('SUPEROPTIC_SECRET_ENV_FILE=' . $originalEnvFileOverride);
}
if (is_string($emptyEnvFile) && is_file($emptyEnvFile)) {
    unlink($emptyEnvFile);
}

$checks = [
    'local_config_password_ignored' => (string) ($withoutEnvironment['pass'] ?? '') === '',
    'environment_password_used' => hash_equals($probe, (string) ($withEnvironment['pass'] ?? '')),
];
$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
