<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
$apply = in_array('--apply', $argv, true);
$json = in_array('--json', $argv, true);
$startedAt = microtime(true);

function maintenance_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $statement->execute(['table' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function maintenance_columns_exist(PDO $db, string $table, array $columns): bool
{
    if ($columns === []) {
        return true;
    }
    $placeholders = [];
    $params = ['table' => $table];
    foreach (array_values($columns) as $index => $column) {
        $key = 'c' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $column;
    }
    $statement = $db->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME IN (' . implode(',', $placeholders) . ')'
    );
    $statement->execute($params);
    $found = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return count(array_intersect($columns, $found)) === count($columns);
}

function maintenance_index_exists(PDO $db, string $table, string $index): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND INDEX_NAME = :idx'
    );
    $statement->execute(['table' => $table, 'idx' => $index]);
    return (int) $statement->fetchColumn() > 0;
}

function maintenance_retention_rule(PDO $db, array $rule, bool $apply): array
{
    $table = (string) ($rule['table'] ?? '');
    $where = (string) ($rule['where'] ?? '1=0');
    if ($table === '' || !maintenance_table_exists($db, $table)) {
        return [
            'table' => $table,
            'status' => 'skipped',
            'candidate_count' => 0,
            'deleted_count' => 0,
            'reason' => 'table_missing',
        ];
    }

    $candidate = (int) $db->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn();
    $deleted = 0;
    if ($apply && $candidate > 0) {
        $deleted = (int) $db->exec('DELETE FROM ' . $table . ' WHERE ' . $where);
    }

    return [
        'table' => $table,
        'status' => $apply ? 'applied' : 'dry-run',
        'candidate_count' => $candidate,
        'deleted_count' => $deleted,
        'reason' => (string) ($rule['reason'] ?? ''),
    ];
}

function maintenance_index_check(PDO $db, array $index, bool $apply): array
{
    $table = (string) ($index['table'] ?? '');
    $name = (string) ($index['name'] ?? '');
    $columns = (array) ($index['columns'] ?? []);
    $sql = (string) ($index['sql'] ?? '');

    if ($table === '' || $name === '' || !maintenance_table_exists($db, $table)) {
        return ['table' => $table, 'index' => $name, 'status' => 'skipped', 'reason' => 'table_missing'];
    }
    if (!maintenance_columns_exist($db, $table, array_map('strval', $columns))) {
        return ['table' => $table, 'index' => $name, 'status' => 'skipped', 'reason' => 'columns_missing'];
    }
    if (maintenance_index_exists($db, $table, $name)) {
        return ['table' => $table, 'index' => $name, 'status' => 'exists', 'reason' => ''];
    }
    if ($apply && $sql !== '') {
        $db->exec($sql);
        return ['table' => $table, 'index' => $name, 'status' => 'created', 'reason' => ''];
    }

    return ['table' => $table, 'index' => $name, 'status' => 'planned', 'reason' => 'run_with_apply'];
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    $payload = ['status' => 'failed', 'reason' => 'db_unavailable'];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

$retentionRules = [
    [
        'table' => 'communication_messages',
        'where' => 'status = "test" AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)',
        'reason' => 'test messages older than 90 days',
    ],
    [
        'table' => 'communication_messages',
        'where' => 'status IN ("sent","failed") AND created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)',
        'reason' => 'delivered/failed messages older than 365 days',
    ],
    [
        'table' => 'communication_delivery_attempts',
        'where' => 'attempted_at < DATE_SUB(NOW(), INTERVAL 365 DAY)',
        'reason' => 'old provider delivery attempts',
    ],
    [
        'table' => 'communication_worker_runs',
        'where' => 'finished_at < DATE_SUB(NOW(), INTERVAL 180 DAY)',
        'reason' => 'old communication worker run summaries',
    ],
    [
        'table' => 'mgs_operation_actions',
        'where' => 'status IN ("dry_run","skipped","executed","failed") AND created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)',
        'reason' => 'old completed/dry-run operation actions',
    ],
    [
        'table' => 'radius_session_actions',
        'where' => 'status IN ("executed","cancelled","rejected") AND requested_at < DATE_SUB(NOW(), INTERVAL 180 DAY)',
        'reason' => 'old completed RADIUS action requests',
    ],
    [
        'table' => 'radius_nat_logs',
        'where' => 'logged_at < DATE_SUB(NOW(), INTERVAL 365 DAY)',
        'reason' => 'raw CGNAT/NAT logs older than 365 days',
    ],
    [
        'table' => 'radacct',
        'where' => 'acctstoptime IS NOT NULL AND acctstoptime < DATE_SUB(NOW(), INTERVAL 730 DAY)',
        'reason' => 'closed RADIUS accounting sessions older than 730 days',
    ],
    [
        'table' => 'mgs_alarm_events',
        'where' => 'status IN ("resolved","suppressed","test") AND last_seen_at < DATE_SUB(NOW(), INTERVAL 180 DAY)',
        'reason' => 'old resolved alarm events',
    ],
    [
        'table' => 'security_anomaly_events',
        'where' => 'status IN ("resolved","false_positive","test") AND last_seen_at < DATE_SUB(NOW(), INTERVAL 180 DAY)',
        'reason' => 'old resolved/test security anomaly events',
    ],
    [
        'table' => 'billing_maintenance_runs',
        'where' => 'finished_at < DATE_SUB(NOW(), INTERVAL 365 DAY)',
        'reason' => 'old maintenance run summaries',
    ],
];

$indexRules = [
    ['table' => 'portal_accounts', 'name' => 'idx_portal_accounts_role_updated_id', 'columns' => ['role', 'updated_at', 'id'], 'sql' => 'CREATE INDEX idx_portal_accounts_role_updated_id ON portal_accounts (role, updated_at, id)'],
    ['table' => 'portal_accounts', 'name' => 'idx_portal_accounts_role_active_status', 'columns' => ['role', 'is_active', 'status'], 'sql' => 'CREATE INDEX idx_portal_accounts_role_active_status ON portal_accounts (role, is_active, status)'],
    ['table' => 'billing_radius_services', 'name' => 'idx_billing_radius_services_account_status_id', 'columns' => ['account_id', 'status', 'id'], 'sql' => 'CREATE INDEX idx_billing_radius_services_account_status_id ON billing_radius_services (account_id, status, id)'],
    ['table' => 'billing_radius_services', 'name' => 'idx_billing_radius_services_updated', 'columns' => ['updated_at', 'id'], 'sql' => 'CREATE INDEX idx_billing_radius_services_updated ON billing_radius_services (updated_at, id)'],
    ['table' => 'billing_payments', 'name' => 'idx_billing_payments_created_id', 'columns' => ['created_at', 'id'], 'sql' => 'CREATE INDEX idx_billing_payments_created_id ON billing_payments (created_at, id)'],
    ['table' => 'billing_payments', 'name' => 'idx_billing_payments_method_created', 'columns' => ['payment_method', 'created_at'], 'sql' => 'CREATE INDEX idx_billing_payments_method_created ON billing_payments (payment_method, created_at)'],
    ['table' => 'communication_messages', 'name' => 'idx_communication_messages_status_created', 'columns' => ['status', 'created_at', 'id'], 'sql' => 'CREATE INDEX idx_communication_messages_status_created ON communication_messages (status, created_at, id)'],
    ['table' => 'portal_tickets', 'name' => 'idx_portal_tickets_status_opened', 'columns' => ['status', 'opened_at', 'id'], 'sql' => 'CREATE INDEX idx_portal_tickets_status_opened ON portal_tickets (status, opened_at, id)'],
    ['table' => 'radacct', 'name' => 'idx_radacct_online_recent', 'columns' => ['acctstoptime', 'acctstarttime', 'username'], 'sql' => 'CREATE INDEX idx_radacct_online_recent ON radacct (acctstoptime, acctstarttime, username)'],
    ['table' => 'radius_nat_logs', 'name' => 'idx_radius_nat_logs_logged_id', 'columns' => ['logged_at', 'id'], 'sql' => 'CREATE INDEX idx_radius_nat_logs_logged_id ON radius_nat_logs (logged_at, id)'],
    ['table' => 'radius_nat_logs', 'name' => 'idx_radius_nat_logs_subscriber_logged', 'columns' => ['subscriber_code', 'logged_at'], 'sql' => 'CREATE INDEX idx_radius_nat_logs_subscriber_logged ON radius_nat_logs (subscriber_code, logged_at)'],
    ['table' => 'mgs_operation_actions', 'name' => 'idx_mgs_operation_status_created', 'columns' => ['status', 'created_at', 'id'], 'sql' => 'CREATE INDEX idx_mgs_operation_status_created ON mgs_operation_actions (status, created_at, id)'],
    ['table' => 'security_anomaly_events', 'name' => 'idx_security_anomaly_status', 'columns' => ['status', 'severity', 'last_seen_at'], 'sql' => 'CREATE INDEX idx_security_anomaly_status ON security_anomaly_events (status, severity, last_seen_at)'],
];

$retention = [];
foreach ($retentionRules as $rule) {
    $retention[] = maintenance_retention_rule($db, $rule, $apply);
}

$indexes = [];
foreach ($indexRules as $index) {
    $indexes[] = maintenance_index_check($db, $index, $apply);
}

$summary = [
    'retention_candidates' => array_sum(array_map(static fn (array $row): int => (int) ($row['candidate_count'] ?? 0), $retention)),
    'retention_deleted' => array_sum(array_map(static fn (array $row): int => (int) ($row['deleted_count'] ?? 0), $retention)),
    'index_checks' => count($indexes),
    'indexes_created' => count(array_filter($indexes, static fn (array $row): bool => ($row['status'] ?? '') === 'created')),
    'indexes_planned' => count(array_filter($indexes, static fn (array $row): bool => ($row['status'] ?? '') === 'planned')),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
];

app_mgs_record_operation_action([
    'action_type' => 'system_maintenance_optimizer',
    'entity_type' => 'system',
    'entity_id' => date('Y-m-d'),
    'status' => $apply ? 'executed' : 'dry_run',
    'summary' => 'System maintenance optimizer ' . ($apply ? 'applied' : 'dry-run') . ': deleted ' . $summary['retention_deleted'],
    'payload' => ['summary' => $summary, 'retention' => $retention, 'indexes' => $indexes],
]);

$payload = [
    'status' => 'ok',
    'mode' => $apply ? 'apply' : 'dry-run',
    'summary' => $summary,
    'retention' => $retention,
    'indexes' => $indexes,
    'generated_at' => date(DATE_ATOM),
];

if ($json || true) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
