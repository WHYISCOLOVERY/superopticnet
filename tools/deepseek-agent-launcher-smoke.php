<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$setup = (string) @file_get_contents($root . '/deploy/windows/Set-DeepSeek-AgentCredential.ps1');
$launcher = (string) @file_get_contents($root . '/tools/invoke-deepseek-agent.ps1');

$checks = [
    'files_present' => $setup !== '' && $launcher !== '',
    'dpapi_storage' => str_contains($setup, 'ConvertFrom-SecureString')
        && str_contains($launcher, 'ConvertTo-SecureString'),
    'credential_outside_repo' => str_contains($setup, '$env:APPDATA')
        && str_contains($launcher, '$env:APPDATA'),
    'official_anthropic_endpoint' => str_contains($launcher, 'https://api.deepseek.com/anthropic'),
    'current_models' => str_contains($launcher, 'deepseek-v4-pro')
        && str_contains($launcher, 'deepseek-v4-flash'),
    'read_only_default' => str_contains($launcher, '"plan"')
        && str_contains($launcher, 'Read,Grep,Glob')
        && str_contains($launcher, '[switch]$AllowEdits'),
    'no_permission_bypass' => !str_contains($launcher, 'dangerously-skip-permissions')
        && !str_contains($launcher, 'bypassPermissions'),
    'edit_mode_is_allowlisted' => str_contains($launcher, 'Read,Grep,Glob,Edit,Write')
        && str_contains($launcher, '$arguments += @("--allowedTools", $allowedTools)'),
    'process_secret_cleanup' => str_contains($launcher, 'ZeroFreeBSTR')
        && str_contains($launcher, 'SetEnvironmentVariable($name, $previous[$name], "Process")'),
    'no_literal_deepseek_key' => preg_match('/sk-[A-Za-z0-9_-]{20,}/', $setup . $launcher) !== 1,
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode(['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 1);
