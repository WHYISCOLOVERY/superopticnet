<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/config.php';

$options = getopt('', ['run-id:', 'apply']);
$runId = trim((string) ($options['run-id'] ?? ''));
$apply = array_key_exists('apply', $options);
if (!preg_match('/^SIM26-[0-9]{12}-[a-f0-9]{6}$/D', $runId)) {
    fwrite(STDERR, "invalid_run_id\n");
    exit(2);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "db_unavailable\n");
    exit(2);
}

$runStatement = $db->prepare('SELECT * FROM system_test_runs WHERE run_id=:run_id LIMIT 1');
$runStatement->execute(['run_id' => $runId]);
$run = $runStatement->fetch(PDO::FETCH_ASSOC) ?: [];
if ($run === []) {
    fwrite(STDERR, "run_not_found\n");
    exit(2);
}

$report = json_decode((string) ($run['report_json'] ?? '{}'), true);
$report = is_array($report) ? $report : [];
$ids = is_array($report['entity_ids'] ?? null) ? $report['entity_ids'] : [];
$prefix = strtoupper(str_replace('-', '', $runId));

$employeeQuery = $db->prepare('SELECT id,full_name FROM portal_accounts WHERE role="employee" AND (JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.simulation.run_id"))=:run_id OR login LIKE :login_prefix)');
$employeeQuery->execute(['run_id' => $runId, 'login_prefix' => '%.' . strtolower(str_replace('-', '', $runId)) . '@local']);
$employeeRows = $employeeQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
$employeeIds = array_values(array_unique(array_map('intval', array_merge((array) ($ids['employees'] ?? []), array_column($employeeRows, 'id')))));
$employeeNames = array_values(array_unique(array_filter(array_map('strval', array_column($employeeRows, 'full_name')))));

$customerQuery = $db->prepare('SELECT id,subscriber_code FROM portal_accounts WHERE role="customer" AND (JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.simulation.run_id"))=:run_id OR subscriber_code LIKE :prefix)');
$customerQuery->execute(['run_id' => $runId, 'prefix' => $prefix . 'C%']);
$customerRows = $customerQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
$customerIds = array_values(array_unique(array_map('intval', array_merge((array) ($ids['customers'] ?? []), array_column($customerRows, 'id')))));
$subscriberCodes = array_values(array_unique(array_filter(array_map('strval', array_column($customerRows, 'subscriber_code')))));

$summary = ['run_id' => $runId, 'employees' => count($employeeIds), 'customers' => count($customerIds), 'subscriber_codes' => count($subscriberCodes), 'apply' => $apply, 'deleted_rows' => []];
$documentIds = array_values(array_filter(array_map('intval', (array) ($ids['documents'] ?? [])), static fn(int $id): bool => $id > 0));
$documentNumbers = [];
if ($documentIds !== []) {
    $documentLookup = $db->query('SELECT document_no FROM inventory_documents WHERE id IN (' . implode(',', $documentIds) . ')');
    $documentNumbers = array_values(array_filter(array_map('strval', $documentLookup ? ($documentLookup->fetchAll(PDO::FETCH_COLUMN) ?: []) : [])));
}
$summary['document_numbers'] = count($documentNumbers);
if (!$apply) {
    echo json_encode(['status' => 'preview'] + $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

function cleanup_delete(PDO $db, array &$summary, string $label, string $sql, array $params = []): void
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        $summary['deleted_rows'][$label] = ($summary['deleted_rows'][$label] ?? 0) + $statement->rowCount();
    } catch (Throwable $exception) {
        $summary['deleted_rows'][$label . '_error'] = $exception->getMessage();
    }
}

function cleanup_in(array $values): array
{
    $values = array_values(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0));
    return [$values, $values === [] ? 'NULL' : implode(',', $values)];
}

[$employeeIds, $employeeIn] = cleanup_in($employeeIds);
[$customerIds, $customerIn] = cleanup_in($customerIds);
$entityIds = array_merge($employeeIds, $customerIds);
[, $entityIn] = cleanup_in($entityIds);

$db->beginTransaction();
try {
    if ($customerIds !== []) {
        cleanup_delete($db, $summary, 'ticket_events', 'DELETE e FROM portal_ticket_events e INNER JOIN portal_tickets t ON t.id=e.ticket_id WHERE t.customer_account_id IN (' . $customerIn . ') OR t.ticket_no LIKE :run_like', ['run_like' => $runId . '%']);
        cleanup_delete($db, $summary, 'tickets', 'DELETE FROM portal_tickets WHERE customer_account_id IN (' . $customerIn . ') OR ticket_no LIKE :run_like', ['run_like' => $runId . '%']);
        cleanup_delete($db, $summary, 'equipment_assignments', 'DELETE FROM customer_equipment_assignments WHERE customer_code LIKE :prefix', ['prefix' => $prefix . 'C%']);
        cleanup_delete($db, $summary, 'bonus_assignments', 'DELETE FROM customer_employee_assignments WHERE customer_account_id IN (' . $customerIn . ')');
        cleanup_delete($db, $summary, 'bonus_items', 'DELETE FROM employee_bonus_items WHERE customer_account_id IN (' . $customerIn . ')');
        cleanup_delete($db, $summary, 'payments', 'DELETE FROM billing_payments WHERE account_id IN (' . $customerIn . ') OR external_id LIKE :run_like', ['run_like' => $runId . '%']);
        cleanup_delete($db, $summary, 'radius_services', 'DELETE FROM billing_radius_services WHERE account_id IN (' . $customerIn . ')');
        cleanup_delete($db, $summary, 'ip_assignment_history', 'DELETE FROM ip_assignment_history WHERE customer_id IN (' . $customerIn . ')');
        cleanup_delete($db, $summary, 'customer_ip_assignments', 'DELETE FROM customer_ip_assignments WHERE customer_id IN (' . $customerIn . ')');
        cleanup_delete($db, $summary, 'fin_claims', 'DELETE FROM billing_customer_fin_claims WHERE account_id IN (' . $customerIn . ')');
    }
    if ($subscriberCodes !== []) {
        $quotedCodes = implode(',', array_map([$db, 'quote'], $subscriberCodes));
        foreach (['radcheck', 'radreply', 'radusergroup', 'radacct', 'radius_session_actions'] as $table) {
            cleanup_delete($db, $summary, $table, 'DELETE FROM ' . $table . ' WHERE username IN (' . $quotedCodes . ')');
        }
    }

    $ticketIds = (array) ($ids['tickets'] ?? []);[, $ticketIn] = cleanup_in($ticketIds);
    if ($ticketIds !== []) {
        cleanup_delete($db, $summary, 'ticket_events_by_id', 'DELETE FROM portal_ticket_events WHERE ticket_id IN (' . $ticketIn . ')');
        cleanup_delete($db, $summary, 'tickets_by_id', 'DELETE FROM portal_tickets WHERE id IN (' . $ticketIn . ')');
    }

    $periodIds = (array) ($ids['bonus_periods'] ?? []);[, $periodIn] = cleanup_in($periodIds);
    if ($periodIds !== []) {
        foreach (['employee_bonus_notifications', 'employee_bonus_adjustments', 'employee_bonus_items', 'employee_bonus_summaries'] as $table) cleanup_delete($db, $summary, $table, 'DELETE FROM ' . $table . ' WHERE period_id IN (' . $periodIn . ')');
        cleanup_delete($db, $summary, 'bonus_periods', 'DELETE FROM bonus_periods WHERE id IN (' . $periodIn . ')');
    }

    if ($employeeIds !== []) {
        cleanup_delete($db, $summary, 'attendance', 'DELETE FROM hr_attendance_events WHERE employee_account_id IN (' . $employeeIn . ')');
        cleanup_delete($db, $summary, 'hr_score', 'DELETE FROM hr_score_events WHERE employee_account_id IN (' . $employeeIn . ')');
        cleanup_delete($db, $summary, 'hr_payroll', 'DELETE FROM hr_payroll_records WHERE employee_account_id IN (' . $employeeIn . ')');
        cleanup_delete($db, $summary, 'hr_compensation', 'DELETE FROM hr_employee_compensations WHERE employee_account_id IN (' . $employeeIn . ')');
        cleanup_delete($db, $summary, 'work_snapshots', 'DELETE FROM employee_work_snapshots WHERE employee_account_id IN (' . $employeeIn . ')');
        cleanup_delete($db, $summary, 'portal_account_areas', 'DELETE FROM portal_account_areas WHERE account_id IN (' . $employeeIn . ')');
        cleanup_delete($db, $summary, 'employee_balances', 'DELETE FROM inventory_location_balances WHERE location_type="employee" AND CAST(location_id AS UNSIGNED) IN (' . $employeeIn . ')');
    }

    [, $documentIn] = cleanup_in($documentIds);
    if ($documentIds !== []) {
        cleanup_delete($db, $summary, 'document_items', 'DELETE FROM inventory_document_items WHERE document_id IN (' . $documentIn . ')');
        cleanup_delete($db, $summary, 'documents', 'DELETE FROM inventory_documents WHERE id IN (' . $documentIn . ')');
    }
    cleanup_delete($db, $summary, 'document_items_by_run', 'DELETE i FROM inventory_document_items i INNER JOIN inventory_documents d ON d.id=i.document_id WHERE d.note LIKE :run_like', ['run_like' => '%' . $runId . '%']);
    cleanup_delete($db, $summary, 'documents_by_run', 'DELETE FROM inventory_documents WHERE note LIKE :run_like OR document_no LIKE :prefix', ['run_like' => '%' . $runId . '%', 'prefix' => $prefix . '%']);
    cleanup_delete($db, $summary, 'stock_movements', 'DELETE FROM inventory_stock_movements WHERE metadata_json LIKE :run_like OR note LIKE :run_like OR ticket_no LIKE :run_prefix', ['run_like' => '%' . $runId . '%', 'run_prefix' => $runId . '%']);
    cleanup_delete($db, $summary, 'lifecycle_movements', 'DELETE FROM inventory_lifecycle_movements WHERE movement_no LIKE :prefix OR reason LIKE :run_like OR ticket_no LIKE :run_prefix OR customer_code LIKE :customer_prefix', ['prefix' => $prefix . '%', 'run_like' => '%' . $runId . '%', 'run_prefix' => $runId . '%', 'customer_prefix' => $prefix . 'C%']);
    if ($employeeNames !== []) {
        $quotedEmployeeNames = implode(',', array_map([$db, 'quote'], $employeeNames));
        cleanup_delete($db, $summary, 'lifecycle_movements_by_employee', 'DELETE FROM inventory_lifecycle_movements WHERE technician_code IN (' . $quotedEmployeeNames . ') OR performed_by IN (' . $quotedEmployeeNames . ')');
    }
    if ($documentNumbers !== []) {
        $movementWhere = implode(' OR ', array_fill(0, count($documentNumbers), 'movement_no LIKE ?'));
        cleanup_delete($db, $summary, 'lifecycle_movements_by_document', 'DELETE FROM inventory_lifecycle_movements WHERE ' . $movementWhere, array_map(static fn(string $number): string => $number . '%', $documentNumbers));
    }

    $assetIds = (array) ($ids['assets'] ?? []);[, $assetIn] = cleanup_in($assetIds);
    if ($assetIds !== []) cleanup_delete($db, $summary, 'assets', 'DELETE FROM inventory_assets WHERE id IN (' . $assetIn . ')');
    cleanup_delete($db, $summary, 'assets_by_prefix', 'DELETE FROM inventory_assets WHERE asset_code LIKE :prefix OR metadata_json LIKE :run_like', ['prefix' => $prefix . '%', 'run_like' => '%' . $runId . '%']);
    cleanup_delete($db, $summary, 'asset_models', 'DELETE FROM inventory_asset_models WHERE model LIKE :run_like', ['run_like' => '%' . $runId . '%']);

    $itemIds = (array) ($ids['items'] ?? []);[, $itemIn] = cleanup_in($itemIds);
    if ($itemIds !== []) {
        cleanup_delete($db, $summary, 'item_balances', 'DELETE FROM inventory_location_balances WHERE item_id IN (' . $itemIn . ')');
        cleanup_delete($db, $summary, 'items', 'DELETE FROM inventory_stock_items WHERE id IN (' . $itemIn . ')');
    }
    cleanup_delete($db, $summary, 'items_by_prefix', 'DELETE FROM inventory_stock_items WHERE sku LIKE :prefix', ['prefix' => $prefix . '%']);

    $tariffIds = (array) ($ids['tariffs'] ?? []);[, $tariffIn] = cleanup_in($tariffIds);
    if ($tariffIds !== []) cleanup_delete($db, $summary, 'tariffs', 'DELETE FROM billing_tariffs WHERE id IN (' . $tariffIn . ')');
    $enginePoolIds = (array) ($ids['engine_pools'] ?? []);[, $enginePoolIn] = cleanup_in($enginePoolIds);
    if ($enginePoolIds !== []) {
        cleanup_delete($db, $summary, 'ip_addresses', 'DELETE FROM ip_addresses WHERE pool_id IN (' . $enginePoolIn . ')');
        cleanup_delete($db, $summary, 'engine_pools', 'DELETE FROM ip_pools WHERE id IN (' . $enginePoolIn . ')');
    }
    $billingPoolIds = (array) ($ids['billing_pools'] ?? []);[, $billingPoolIn] = cleanup_in($billingPoolIds);
    if ($billingPoolIds !== []) cleanup_delete($db, $summary, 'billing_pools', 'DELETE FROM billing_ip_pools WHERE id IN (' . $billingPoolIn . ')');

    cleanup_delete($db, $summary, 'operation_actions', 'DELETE FROM mgs_operation_actions WHERE payload_json LIKE :run_like OR summary LIKE :run_like OR entity_id LIKE :prefix', ['run_like' => '%' . $runId . '%', 'prefix' => $prefix . '%']);
    if ($employeeIds !== []) {
        cleanup_delete($db, $summary, 'operation_actions_by_employee', 'DELETE FROM mgs_operation_actions WHERE actor_account_id IN (' . $employeeIn . ') OR (entity_type="employee" AND entity_id IN (' . $employeeIn . '))');
    }
    if ($documentNumbers !== []) {
        cleanup_delete($db, $summary, 'operation_actions_by_document', 'DELETE FROM mgs_operation_actions WHERE entity_id IN (' . implode(',', array_map([$db, 'quote'], $documentNumbers)) . ')');
    }
    $ledgerCount = $db->prepare('SELECT COUNT(*) FROM security_audit_ledger WHERE payload_json LIKE :run_like OR summary LIKE :run_like' . ($entityIds !== [] ? ' OR entity_id IN (' . $entityIn . ')' : ''));
    $ledgerCount->execute(['run_like' => '%' . $runId . '%']);
    $summary['security_audit_ledger_retained'] = (int) $ledgerCount->fetchColumn();
    if ($customerIds !== []) cleanup_delete($db, $summary, 'customer_accounts', 'DELETE FROM portal_accounts WHERE id IN (' . $customerIn . ')');
    if ($employeeIds !== []) cleanup_delete($db, $summary, 'employee_accounts', 'DELETE FROM portal_accounts WHERE id IN (' . $employeeIn . ')');

    $db->prepare('UPDATE system_test_runs SET status="cleaned",cleaned_at=NOW() WHERE run_id=:run_id')->execute(['run_id' => $runId]);
    $db->commit();
    $summary['status'] = 'cleaned';
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    $summary['status'] = 'failed';
    $summary['error'] = $exception->getMessage();
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($summary['status'] ?? '') === 'cleaned' ? 0 : 1);
