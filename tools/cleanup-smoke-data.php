<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

function smoke_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $statement->execute(['table' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function smoke_column_exists(PDO $db, string $table, string $column): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $statement->execute(['table' => $table, 'column' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function smoke_placeholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

function smoke_count_or_delete(PDO $db, string $sql, array $params, bool $apply): int
{
    $statement = $db->prepare($apply ? preg_replace('/^SELECT COUNT\(\*\)/', 'DELETE', $sql, 1) : $sql);
    $statement->execute($params);
    return $apply ? $statement->rowCount() : (int) $statement->fetchColumn();
}

$accountWhere = "(subscriber_code LIKE 'SMOKE-%' OR subscriber_code LIKE 'CP-SMOKE-%' OR subscriber_code LIKE 'UP-SMOKE-%' OR subscriber_code LIKE 'SEC-SMOKE-%' OR login LIKE 'smoke-%' OR login LIKE 'SMOKE-%')";
$accounts = $db->query(
    'SELECT id, subscriber_code, login
     FROM portal_accounts
     WHERE ' . $accountWhere
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$accountIds = [];
$usernames = [];
foreach ($accounts as $account) {
    $accountIds[] = (int) $account['id'];
    foreach (['subscriber_code', 'login'] as $field) {
        $value = trim((string) ($account[$field] ?? ''));
        if ($value !== '') {
            $usernames[$value] = true;
        }
    }
}

$standaloneUsernameWhere = "username LIKE 'SMOKE-%' OR username LIKE 'CP-SMOKE-%' OR username LIKE 'UP-SMOKE-%' OR username LIKE 'SEC-SMOKE-%'";
foreach (['radcheck', 'radreply', 'radusergroup', 'billing_radius_services', 'radius_session_actions'] as $table) {
    if (!smoke_table_exists($db, $table) || !smoke_column_exists($db, $table, 'username')) {
        continue;
    }
    foreach ($db->query('SELECT DISTINCT username FROM ' . $table . ' WHERE ' . $standaloneUsernameWhere)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $username) {
        $username = trim((string) $username);
        if ($username !== '') {
            $usernames[$username] = true;
        }
    }
}
$usernames = array_keys($usernames);

$counts = [];
$db->beginTransaction();
try {
    $accountIdTables = [
        'customer_ip_assignments' => 'customer_id',
        'ip_assignment_history' => 'customer_id',
        'billing_radius_services' => 'account_id',
        'portal_tickets' => 'customer_id',
        'communication_messages' => 'account_id',
    ];
    if ($accountIds !== []) {
        $idSql = smoke_placeholders($accountIds);
        foreach ($accountIdTables as $table => $column) {
            if (!smoke_table_exists($db, $table) || !smoke_column_exists($db, $table, $column)) {
                continue;
            }
            $counts[$table] = smoke_count_or_delete(
                $db,
                "SELECT COUNT(*) FROM {$table} WHERE {$column} IN ({$idSql})",
                $accountIds,
                $apply
            );
        }
    }

    if ($usernames !== []) {
        $usernameSql = smoke_placeholders($usernames);
        foreach (['radcheck', 'radreply', 'radusergroup', 'radius_session_actions'] as $table) {
            if (!smoke_table_exists($db, $table) || !smoke_column_exists($db, $table, 'username')) {
                continue;
            }
            $counts[$table] = smoke_count_or_delete(
                $db,
                "SELECT COUNT(*) FROM {$table} WHERE username IN ({$usernameSql})",
                $usernames,
                $apply
            );
        }

        if (smoke_table_exists($db, 'billing_payments')) {
            $paymentWhere = [];
            foreach (['customer_code', 'subscriber_code', 'transaction_id', 'external_id'] as $column) {
                if (smoke_column_exists($db, 'billing_payments', $column)) {
                    $paymentWhere[] = "{$column} LIKE 'SMOKE-%'";
                    $paymentWhere[] = "{$column} LIKE 'UP-SMOKE-%'";
                    $paymentWhere[] = "{$column} LIKE 'SEC-SMOKE-%'";
                    if (in_array($column, ['customer_code', 'subscriber_code'], true)) {
                        $paymentWhere[] = "{$column} IN ({$usernameSql})";
                    }
                }
            }
            if ($paymentWhere !== []) {
                $counts['billing_payments'] = smoke_count_or_delete(
                    $db,
                    'SELECT COUNT(*) FROM billing_payments WHERE ' . implode(' OR ', $paymentWhere),
                    $usernames,
                    $apply
                );
            }
        }
    }

    $smokePoolWhere = "name LIKE 'Smoke %' OR name LIKE 'SMOKE %'";
    foreach (['ip_pools', 'billing_ip_pools'] as $table) {
        if (!smoke_table_exists($db, $table) || !smoke_column_exists($db, $table, 'name')) {
            continue;
        }
        $counts[$table] = smoke_count_or_delete(
            $db,
            "SELECT COUNT(*) FROM {$table} WHERE {$smokePoolWhere}",
            [],
            $apply
        );
    }

    if ($accountIds !== []) {
        $idSql = smoke_placeholders($accountIds);
        $counts['portal_accounts'] = smoke_count_or_delete(
            $db,
            "SELECT COUNT(*) FROM portal_accounts WHERE id IN ({$idSql})",
            $accountIds,
            $apply
        );
    } else {
        $counts['portal_accounts'] = 0;
    }

    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(3);
}

echo json_encode([
    'status' => 'ok',
    'mode' => $apply ? 'apply' : 'dry-run',
    'accounts_matched' => count($accountIds),
    'usernames_matched' => count($usernames),
    'counts' => $counts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
