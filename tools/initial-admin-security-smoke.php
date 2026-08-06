<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$db = (string) @file_get_contents($root . '/db.php');
$config = (string) @file_get_contents($root . '/config.php');
$actions = (string) @file_get_contents($root . '/portal-actions.php');
$changePage = (string) @file_get_contents($root . '/change-password.php');
$bootstrap = (string) @file_get_contents($root . '/tools/create-initial-super-admin.php');
$installer = (string) @file_get_contents($root . '/deploy/ubuntu-install-superoptic-mgs.sh');

$checks = [
    'files_present' => $db !== '' && $config !== '' && $actions !== ''
        && $changePage !== '' && $bootstrap !== '' && $installer !== '',
    'known_password_removed' => !str_contains($db, '!Asan!1312')
        && !str_contains($bootstrap, '!Asan!1312')
        && !str_contains($installer, '!Asan!1312'),
    'demo_seed_environment_guard' => str_contains($db, 'function app_db_demo_seed_enabled')
        && str_contains($db, 'return app_demo_fixtures_enabled();')
        && str_contains($config, "['development', 'dev', 'local', 'test']")
        && str_contains($db, 'if (!app_db_demo_seed_enabled())'),
    'development_admin_password_random' => str_contains($db, "'password' => bin2hex(random_bytes(32))"),
    'runtime_migrations_disabled_after_deploy' => str_contains($db, "app_setting('db_bootstrap_mode', 'cached')")
        && str_contains($installer, '"db_bootstrap_mode" "off"'),
    'designated_admin_login' => str_contains($bootstrap, 's.abdullayev@superoptic.net')
        && str_contains($bootstrap, 'WHERE LOWER(login) = :login'),
    'strong_initial_password' => str_contains($bootstrap, 'strlen($password) >= 14')
        && str_contains($bootstrap, "preg_match('/[^A-Za-z0-9]/', \$password)"),
    'forced_change_recorded' => str_contains($bootstrap, "'must_change_password' => true")
        && str_contains($changePage, 'minlength="14"'),
    'backend_employee_policy' => str_contains($config, '$requiresStrongPassword')
        && str_contains($config, 'strlen($newPassword) >= 14')
        && str_contains($config, "preg_match('/[^A-Za-z0-9]/', \$newPassword)"),
    'forced_change_route_guard' => str_contains($actions, "if (!empty(\$user['must_change_password']) && \$action !== 'update_password')")
        && str_contains($config, "return 'change-password.php';"),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode(['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 1);
