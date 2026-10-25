<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/deploy/ubuntu-rollback-superoptic.sh';
$source = is_file($path) ? (string) file_get_contents($path) : '';
$checks = [
    'script_present' => $source !== '',
    'root_required' => str_contains($source, '[[ "${EUID}" -eq 0 ]]'),
    'apply_required' => str_contains($source, 'if [[ "${APPLY}" != "1" ]]'),
    'database_restore_separate' => str_contains($source, '--restore-db')
        && str_contains($source, '[[ "${RESTORE_DB}" == "1" ]]'),
    'backup_root_constrained' => str_contains($source, '"${resolved_root}"/preinstall-*'),
    'checksums_required' => str_contains($source, 'sha256sum -c SHA256SUMS'),
    'safety_snapshot_created' => str_contains($source, 'rollback-safety-'),
    'remote_password_not_in_cli' => str_contains($source, 'MYSQL_PWD="${DB_ADMIN_PASS}"')
        && !str_contains($source, '--password='),
    'manifest_not_sourced' => !preg_match('/\bsource\s+[^\n]*manifest/i', $source),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
