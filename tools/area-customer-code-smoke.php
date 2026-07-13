<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function area_code_smoke_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    area_code_smoke_fail('db_unavailable', 2);
}

$createdCodes = ['7790000'];

try {
    app_db_seed_area_code_prefixes($db);

    foreach ($createdCodes as $code) {
        app_db_upsert_account([
            'login' => 'area-smoke-' . $code,
            'password' => 'AreaSmoke123',
            'role' => 'customer',
            'account_type' => 'customer',
            'full_name' => 'Area Code Smoke ' . $code,
            'subscriber_code' => $code,
            'contract_no' => $code,
            'area' => 'Smoke Prefix Area',
            'plan' => 'Smoke',
            'status' => 'Aktiv',
            'is_active' => 1,
            'payload' => ['profile' => ['subscriber_code' => $code]],
        ], false);
    }

    $qaracuxur = app_db_next_customer_code_for_area($db, 'Qaracuxur');
    if (!str_starts_with($qaracuxur, '100') || !ctype_digit($qaracuxur)) {
        area_code_smoke_fail('qaracuxur_next_failed:' . $qaracuxur, 3);
    }

    $kurdexani = app_db_next_customer_code_for_area($db, 'Kurdexani');
    if (!str_starts_with($kurdexani, '232') || !ctype_digit($kurdexani)) {
        area_code_smoke_fail('kurdexani_next_failed:' . $kurdexani, 4);
    }

    app_db_upsert_area_code_prefix($db, 'Smoke Prefix Area', '779');
    $custom = app_db_next_customer_code_for_area($db, 'Smoke Prefix Area');
    if ($custom !== '7790001') {
        area_code_smoke_fail('custom_prefix_next_failed:' . $custom, 5);
    }

    $resolvedManual = app_db_resolve_customer_code_for_area($db, 'Smoke Prefix Area', '7790000', false);
    if ($resolvedManual !== '7790000') {
        area_code_smoke_fail('manual_code_should_not_recompute:' . $resolvedManual, 6);
    }

    $resolvedAuto = app_db_resolve_customer_code_for_area($db, 'Smoke Prefix Area', '7790000', true);
    if ($resolvedAuto !== '7790001') {
        area_code_smoke_fail('stale_auto_code_should_recompute:' . $resolvedAuto, 7);
    }

    echo json_encode([
        'status' => 'ok',
        'qaracuxur_next' => $qaracuxur,
        'kurdexani_next' => $kurdexani,
        'custom_next' => $custom,
        'stale_auto_resolved' => $resolvedAuto,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    foreach ($createdCodes as $code) {
        $account = app_db_account_by_subscriber_code($code);
        if (is_array($account) && str_starts_with((string) ($account['login'] ?? ''), 'area-smoke-')) {
            $id = (int) ($account['id'] ?? 0);
            if ($id > 0) {
                $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $id]);
            }
        }
    }
    try {
        $db->prepare('DELETE FROM customer_area_code_prefixes WHERE area_name = :area')->execute(['area' => 'Smoke Prefix Area']);
    } catch (Throwable) {
        // Table intentionally does not exist during the RED phase.
    }
}
