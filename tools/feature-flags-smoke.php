<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/feature-flags-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode(['status' => 'failed', 'errors' => ['db_unavailable']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$errors = [];
$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$flagKey = 'smoke_feature_flag_' . date('YmdHis') . random_int(100, 999);

try {
    app_feature_flags_ensure_schema($db);
    $seedCount = (int) $db->query("SELECT COUNT(*) FROM app_feature_flags WHERE flag_key IN ('mgs_new_module_shell','mgs_bonus_dashboard_v2','mgs_noc_scan_selectable_results','mgs_statistics_ajax_charts','mgs_data_analytics_report_embed')")->fetchColumn();
    $expect($seedCount === 5, 'seed_flags_missing');

    $adminUser = ['id' => 101, 'role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'super_admin'];
    $financeUser = ['id' => 202, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'finance'];
    $supportUser = ['id' => 303, 'role' => 'employee', 'account_type' => 'operator', 'employee_role' => 'support'];

    $expect(app_feature_flag_save($db, $flagKey, true, 100, 'finance,super_admin', [], 'smoke role scope'), 'flag_save_failed');
    $expect(app_feature_flag_enabled($db, $flagKey, $adminUser) === true, 'admin_role_should_pass');
    $expect(app_feature_flag_enabled($db, $flagKey, $financeUser) === true, 'finance_role_should_pass');
    $expect(app_feature_flag_enabled($db, $flagKey, $supportUser) === false, 'support_role_should_fail');

    $expect(app_feature_flag_save($db, $flagKey, true, 0, '', [303], 'smoke user override'), 'flag_user_override_save_failed');
    $expect(app_feature_flag_enabled($db, $flagKey, $supportUser) === true, 'user_override_should_pass');
    $expect(app_feature_flag_enabled($db, $flagKey, $financeUser) === false, 'zero_rollout_without_override_should_fail');

    $expect(app_feature_flag_save($db, $flagKey, false, 100, '', [303], 'smoke disabled'), 'flag_disable_save_failed');
    $expect(app_feature_flag_enabled($db, $flagKey, $supportUser) === false, 'disabled_flag_should_fail');

    $expect(app_feature_flag_enabled($db, 'missing_flag_' . $flagKey, $supportUser, true) === true, 'missing_flag_default_true_failed');
    $flags = app_feature_flags_for_user($db, $adminUser);
    $expect(array_key_exists('mgs_new_module_shell', $flags), 'flags_for_user_missing_seed');
} finally {
    $db->prepare('DELETE FROM app_feature_flags WHERE flag_key = :flag_key')->execute(['flag_key' => $flagKey]);
}

echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
