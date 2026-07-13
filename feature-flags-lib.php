<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_feature_flags_ensure_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS app_feature_flags (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flag_key VARCHAR(96) NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        rollout_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        role_scope VARCHAR(255) DEFAULT NULL,
        user_scope_json LONGTEXT DEFAULT NULL,
        source VARCHAR(32) NOT NULL DEFAULT 'local',
        note VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_feature_flags_key (flag_key),
        KEY idx_app_feature_flags_enabled (is_enabled, flag_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $seed = [
        ['mgs_new_module_shell', 'New unified module shell and grouped navigation'],
        ['mgs_bonus_dashboard_v2', 'Enhanced finance employee bonus dashboard'],
        ['mgs_noc_scan_selectable_results', 'Selectable NOC scan results before adding devices'],
        ['mgs_statistics_ajax_charts', 'Partial-refresh statistics charts'],
        ['mgs_data_analytics_report_embed', 'Embedded Data Analytics report surface'],
    ];
    $statement = $db->prepare('INSERT IGNORE INTO app_feature_flags (flag_key, description, is_enabled, rollout_percent, source) VALUES (:flag_key, :description, 0, 0, "local")');
    foreach ($seed as [$key, $description]) {
        $statement->execute(['flag_key' => $key, 'description' => $description]);
    }
}

function app_feature_flag_save(PDO $db, string $key, bool $enabled, float $rolloutPercent = 0.0, string $roleScope = '', array $userScope = [], string $note = ''): bool
{
    app_feature_flags_ensure_schema($db);
    $key = clean_text($key);
    if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
        return false;
    }
    $rolloutPercent = max(0.0, min(100.0, round($rolloutPercent, 2)));
    $json = json_encode(array_values(array_unique(array_map('intval', $userScope))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $statement = $db->prepare('INSERT INTO app_feature_flags (flag_key, is_enabled, rollout_percent, role_scope, user_scope_json, note, source)
        VALUES (:flag_key, :is_enabled, :rollout_percent, :role_scope, :user_scope_json, :note, "local")
        ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), rollout_percent = VALUES(rollout_percent), role_scope = VALUES(role_scope), user_scope_json = VALUES(user_scope_json), note = VALUES(note), source = "local"');
    return $statement->execute([
        'flag_key' => $key,
        'is_enabled' => $enabled ? 1 : 0,
        'rollout_percent' => $rolloutPercent,
        'role_scope' => clean_text($roleScope) ?: null,
        'user_scope_json' => is_string($json) ? $json : '[]',
        'note' => clean_text($note) ?: null,
    ]);
}

function app_feature_flag_enabled(PDO $db, string $key, array $user = [], bool $default = false): bool
{
    app_feature_flags_ensure_schema($db);
    $statement = $db->prepare('SELECT * FROM app_feature_flags WHERE flag_key = :flag_key LIMIT 1');
    $statement->execute(['flag_key' => clean_text($key)]);
    $flag = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($flag)) {
        return $default;
    }
    if ((int) ($flag['is_enabled'] ?? 0) !== 1) {
        return false;
    }

    $userId = (int) ($user['id'] ?? 0);
    $userScope = json_decode((string) ($flag['user_scope_json'] ?? '[]'), true);
    if (is_array($userScope) && $userId > 0 && in_array($userId, array_map('intval', $userScope), true)) {
        return true;
    }

    $roleScope = array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($flag['role_scope'] ?? ''))))));
    if ($roleScope !== []) {
        $roles = [
            strtolower(trim((string) ($user['employee_role'] ?? ''))),
            strtolower(trim((string) ($user['account_type'] ?? ''))),
            strtolower(trim((string) ($user['role'] ?? ''))),
        ];
        if (array_intersect($roleScope, array_filter($roles)) === []) {
            return false;
        }
    }

    $percent = (float) ($flag['rollout_percent'] ?? 0);
    if ($percent >= 100.0) {
        return true;
    }
    if ($percent <= 0.0 || $userId <= 0) {
        return false;
    }

    $bucket = hexdec(substr(hash('sha256', $key . ':' . $userId), 0, 8)) % 10000;
    return $bucket < (int) round($percent * 100);
}

function app_feature_flags_for_user(PDO $db, array $user): array
{
    app_feature_flags_ensure_schema($db);
    $rows = $db->query('SELECT flag_key FROM app_feature_flags ORDER BY flag_key')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $enabled = [];
    foreach ($rows as $key) {
        $enabled[(string) $key] = app_feature_flag_enabled($db, (string) $key, $user, false);
    }
    return $enabled;
}
