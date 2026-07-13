<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$apply = in_array('--apply', array_slice($_SERVER['argv'] ?? [], 1), true);
$db = app_db();
if (!$db instanceof PDO) {
    echo json_encode([
        'status' => 'failed',
        'message' => 'MySQL/PDO connection is not ready.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(2);
}

function perf_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $statement->execute(['table' => $table]);

    return (int) $statement->fetchColumn() > 0;
}

function perf_columns_exist(PDO $db, string $table, array $columns): bool
{
    if ($columns === []) {
        return true;
    }

    $placeholders = [];
    $params = ['table_name' => $table];
    foreach ($columns as $index => $column) {
        $key = 'column_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $column;
    }

    $statement = $db->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME IN (' . implode(',', $placeholders) . ')'
    );
    $statement->execute($params);
    $found = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);

    return count(array_intersect($columns, $found)) === count($columns);
}

function perf_index_exists(PDO $db, string $table, string $index): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name'
    );
    $statement->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

$indexes = [
    [
        'table' => 'portal_accounts',
        'name' => 'idx_portal_accounts_role_updated_id',
        'columns' => ['role', 'updated_at', 'id'],
        'sql' => 'CREATE INDEX idx_portal_accounts_role_updated_id ON portal_accounts (role, updated_at, id)',
        'reason' => 'Müştəri siyahısı role + updated_at sıralamasını filesort olmadan oxusun.',
    ],
    [
        'table' => 'portal_accounts',
        'name' => 'idx_portal_accounts_role_active_status',
        'columns' => ['role', 'is_active', 'status'],
        'sql' => 'CREATE INDEX idx_portal_accounts_role_active_status ON portal_accounts (role, is_active, status)',
        'reason' => 'Dashboard və müştəri filtrində aktiv/deaktiv sayımları sürətlənsin.',
    ],
    [
        'table' => 'billing_radius_services',
        'name' => 'idx_billing_radius_services_account_status_id',
        'columns' => ['account_id', 'status', 'id'],
        'sql' => 'CREATE INDEX idx_billing_radius_services_account_status_id ON billing_radius_services (account_id, status, id)',
        'reason' => 'Müştəri siyahısında son/aktiv servis seçimi daha az filesort etsin.',
    ],
    [
        'table' => 'billing_radius_services',
        'name' => 'idx_billing_radius_services_updated',
        'columns' => ['updated_at', 'id'],
        'sql' => 'CREATE INDEX idx_billing_radius_services_updated ON billing_radius_services (updated_at, id)',
        'reason' => 'RADIUS aktiv list və son dəyişiklik sıralamaları sürətlənsin.',
    ],
    [
        'table' => 'billing_payments',
        'name' => 'idx_billing_payments_created_id',
        'columns' => ['created_at', 'id'],
        'sql' => 'CREATE INDEX idx_billing_payments_created_id ON billing_payments (created_at, id)',
        'reason' => 'Ödəniş siyahısı created_at + id sıralamasını sürətləndirsin.',
    ],
    [
        'table' => 'billing_payments',
        'name' => 'idx_billing_payments_method_created',
        'columns' => ['payment_method', 'created_at'],
        'sql' => 'CREATE INDEX idx_billing_payments_method_created ON billing_payments (payment_method, created_at)',
        'reason' => 'Ödəniş methodu, terminal/nağd və tarix filtrləri sürətlənsin.',
    ],
    [
        'table' => 'communication_messages',
        'name' => 'idx_communication_messages_status_created',
        'columns' => ['status', 'created_at', 'id'],
        'sql' => 'CREATE INDEX idx_communication_messages_status_created ON communication_messages (status, created_at, id)',
        'reason' => 'Mesaj mərkəzi queue/status filtrini sürətləndirsin.',
    ],
    [
        'table' => 'portal_tickets',
        'name' => 'idx_portal_tickets_status_opened',
        'columns' => ['status', 'opened_at', 'id'],
        'sql' => 'CREATE INDEX idx_portal_tickets_status_opened ON portal_tickets (status, opened_at, id)',
        'reason' => 'Dashboard və ticket sayımları üçün status/opened_at indekslənsin.',
    ],
    [
        'table' => 'billing_maintenance_runs',
        'name' => 'idx_billing_maintenance_finished',
        'columns' => ['finished_at', 'id'],
        'sql' => 'CREATE INDEX idx_billing_maintenance_finished ON billing_maintenance_runs (finished_at, id)',
        'reason' => 'Son maintenance run-ları dashboardda sürətli oxunsun.',
    ],
    [
        'table' => 'radacct',
        'name' => 'idx_radacct_online_recent',
        'columns' => ['acctstoptime', 'acctstarttime', 'username'],
        'sql' => 'CREATE INDEX idx_radacct_online_recent ON radacct (acctstoptime, acctstarttime, username)',
        'reason' => 'Online sessiya və son accounting yoxlamaları indekslənsin.',
    ],
    [
        'table' => 'radacct',
        'name' => 'idx_radacct_update_recent',
        'columns' => ['acctupdatetime', 'radacctid'],
        'sql' => 'CREATE INDEX idx_radacct_update_recent ON radacct (acctupdatetime, radacctid)',
        'reason' => 'RADIUS aktiv list son update sıralamasını sürətləndirsin.',
    ],
    [
        'table' => 'radpostauth',
        'name' => 'idx_radpostauth_reply_authdate',
        'columns' => ['reply', 'authdate'],
        'sql' => 'CREATE INDEX idx_radpostauth_reply_authdate ON radpostauth (reply, authdate)',
        'reason' => 'RADIUS auth/reject statistikası reply + tarix üzrə sürətlənsin.',
    ],
];

$results = [];
foreach ($indexes as $index) {
    $table = (string) $index['table'];
    $name = (string) $index['name'];
    $exists = perf_table_exists($db, $table);
    $columnsOk = $exists && perf_columns_exist($db, $table, $index['columns']);
    $indexExists = $exists && perf_index_exists($db, $table, $name);
    $status = 'skipped';
    $message = 'Already exists.';

    if (!$exists) {
        $message = 'Table does not exist.';
    } elseif (!$columnsOk) {
        $message = 'Required columns are missing.';
    } elseif (!$indexExists && $apply) {
        $db->exec((string) $index['sql']);
        $status = 'created';
        $message = 'Index created.';
    } elseif (!$indexExists) {
        $status = 'planned';
        $message = 'Index can be created with --apply.';
    }

    $results[] = [
        'table' => $table,
        'index' => $name,
        'status' => $indexExists ? 'exists' : $status,
        'message' => $message,
        'reason' => (string) $index['reason'],
    ];
}

$created = count(array_filter($results, static fn (array $row): bool => ($row['status'] ?? '') === 'created'));
$planned = count(array_filter($results, static fn (array $row): bool => ($row['status'] ?? '') === 'planned'));

echo json_encode([
    'status' => 'ok',
    'mode' => $apply ? 'apply' : 'dry-run',
    'created' => $created,
    'planned' => $planned,
    'results' => $results,
    'generated_at' => date(DATE_ATOM),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
