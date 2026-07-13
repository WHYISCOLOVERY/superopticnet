<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/audit-log-lib.php';

$user = billing_require_employee(true);
if (!app_audit_actor_can_view($user)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'forbidden';
    exit;
}

$db = app_db();
app_audit_ensure_schema($db);

function operation_logs_export_filters(array $source): array
{
    $filters = [];
    foreach (['module', 'action', 'status', 'date_from', 'date_to', 'actor', 'entity', 'search'] as $key) {
        $value = trim((string) ($source[$key] ?? ''));
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }
    return $filters;
}

$filters = operation_logs_export_filters($_GET);
$tab = strtolower(trim((string) ($_GET['tab'] ?? 'operational'))) === 'ledger' ? 'ledger' : 'operational';
$filename = 'superoptic-audit-' . $tab . '-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'wb');
if (!is_resource($output)) {
    exit;
}

fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'source',
    'id',
    'action_id',
    'created_at',
    'module_key',
    'operation_class',
    'action_type',
    'status',
    'actor_account_id',
    'actor_name',
    'entity_type',
    'entity_id',
    'summary',
    'ticket_no',
    'reason',
    'request_ip',
    'request_id',
    'sensitivity',
    'is_visible',
    'before_json',
    'after_json',
    'payload_json',
    'previous_hash',
    'event_hash',
]);

$page = 1;
do {
    $pageData = $tab === 'ledger'
        ? app_audit_ledger_page($db, $filters, $page, 50)
        : app_audit_operation_page($db, $filters, $page, 50);
    foreach (($pageData['rows'] ?? []) as $row) {
        fputcsv($output, [
            $tab,
            $row['id'] ?? '',
            $row['action_id'] ?? ($row['id'] ?? ''),
            $row['created_at'] ?? '',
            $row['module_key'] ?? '',
            $row['operation_class'] ?? '',
            $row['action_type'] ?? '',
            $row['status'] ?? '',
            $row['actor_account_id'] ?? '',
            $row['actor_name'] ?? '',
            $row['entity_type'] ?? '',
            $row['entity_id'] ?? '',
            $row['summary'] ?? '',
            $row['ticket_no'] ?? '',
            $row['reason'] ?? '',
            $row['request_ip'] ?? '',
            $row['request_id'] ?? '',
            $row['sensitivity'] ?? '',
            $row['is_visible'] ?? '',
            $row['before_json'] ?? '',
            $row['after_json'] ?? '',
            $row['payload_json'] ?? '',
            $row['previous_hash'] ?? '',
            $row['event_hash'] ?? '',
        ]);
    }
    $page++;
} while ($page <= (int) ($pageData['pages'] ?? 1));

fclose($output);
