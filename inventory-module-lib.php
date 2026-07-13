<?php
declare(strict_types=1);

require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/inventory-operations-lib.php';

function app_inventory_tabs(): array
{
    return [
        'overview' => 'Umumi baxis',
        'catalog' => 'Mehsul kataloqu',
        'devices' => 'Cihazlar',
        'materials' => 'Serfiyyat materiallari',
        'cable-drums' => 'Kabel barabanlari',
        'technicians' => 'Texniklerde olan mallar',
        'customers' => 'Musterilerde olan cihazlar',
        'handover' => 'Tehvil-teslim',
        'returns-inspection' => 'Qaytarma ve inspection',
        'repair-rma' => 'Temir / RMA',
        'movements' => 'Medaxil ve transfer',
        'approvals' => 'Transfer ve qaytarma',
        'statistics' => 'Statistika',
    ];
}

function app_inventory_tab(array $query): string
{
    $tab = clean_text((string) ($query['inventory_tab'] ?? $query['tab'] ?? ''));
    $tab = match ($tab) {
        '', 'overview' => 'overview',
        'stock' => 'catalog',
        'lifecycle' => 'overview',
        'assets' => 'devices',
        default => $tab,
    };
    return array_key_exists($tab, app_inventory_tabs()) ? $tab : 'overview';
}

function app_inventory_pagination(array $query, string $pageKey = 'page'): array
{
    $page = max(1, (int) ($query[$pageKey] ?? 1));
    $limit = max(1, min(50, (int) ($query['limit'] ?? 50)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

function app_inventory_date_value(mixed $value, string $fallback): string
{
    $date = clean_text((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $fallback;
    }
    $timestamp = strtotime($date);
    return $timestamp === false ? $fallback : date('Y-m-d', $timestamp);
}

function app_inventory_date_range(array $query): array
{
    $today = new DateTimeImmutable('today');
    $range = clean_text((string) ($query['range'] ?? 'month'));
    if (!in_array($range, ['today', 'week', 'month', 'year', 'custom'], true)) {
        $range = 'month';
    }
    $start = $today->modify('-29 days')->format('Y-m-d');
    $end = $today->format('Y-m-d');
    if ($range === 'today') {
        $start = $end;
    } elseif ($range === 'week') {
        $start = $today->modify('-6 days')->format('Y-m-d');
    } elseif ($range === 'year') {
        $start = $today->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
    } elseif ($range === 'custom') {
        $start = app_inventory_date_value($query['start_date'] ?? '', $start);
        $end = app_inventory_date_value($query['end_date'] ?? '', $end);
    }
    if (strtotime($start) > strtotime($end)) {
        [$start, $end] = [$end, $start];
    }
    return ['range' => $range, 'start_date' => $start, 'end_date' => $end];
}

function app_inventory_query_page(PDO $db, string $countSql, string $rowsSql, array $params, array $query, string $pageKey = 'page'): array
{
    $pagination = app_inventory_pagination($query, $pageKey);
    try {
        $count = $db->prepare($countSql);
        $count->execute($params);
        $total = max(0, (int) $count->fetchColumn());
        $pages = max(1, (int) ceil($total / $pagination['limit']));
        $page = min($pagination['page'], $pages);
        $offset = ($page - 1) * $pagination['limit'];
        $statement = $db->prepare($rowsSql . ' LIMIT ' . $pagination['limit'] . ' OFFSET ' . $offset);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $pagination['limit'],
            'offset' => $offset,
            'start' => $total > 0 ? $offset + 1 : 0,
            'end' => $total > 0 ? min($total, $offset + count($rows)) : 0,
        ];
    } catch (Throwable $exception) {
        return [
            'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1,
            'limit' => $pagination['limit'], 'offset' => 0, 'start' => 0, 'end' => 0,
            'error' => $exception->getMessage(),
        ];
    }
}

function app_inventory_search_filter(array $query, array $columns, array &$params): string
{
    $search = clean_text((string) ($query['search'] ?? ''));
    if ($search === '' || $columns === []) {
        return '';
    }
    $params['search'] = '%' . $search . '%';
    return ' AND (' . implode(' OR ', array_map(static fn (string $column): string => $column . ' LIKE :search', $columns)) . ')';
}

function app_inventory_operational_employee_sql(string $alias = 'a'): string
{
    $haystack = 'LOWER(CONCAT_WS(" ", COALESCE(' . $alias . '.full_name,""), COALESCE(' . $alias . '.login,""), COALESCE(' . $alias . '.employee_role,"")))';
    $parts = [];
    foreach (['role agent', 'copilot texnik', 'telegram smoke', 'smoke password', 'agent.', 'cp-tech-', 'tg-operator-'] as $marker) {
        $parts[] = $haystack . ' NOT LIKE ' . "'%" . $marker . "%'";
    }
    return implode(' AND ', $parts);
}

function app_inventory_operational_stock_sql(string $alias = 'i'): string
{
    $haystack = 'LOWER(CONCAT_WS(" ", COALESCE(' . $alias . '.sku,""), COALESCE(' . $alias . '.item_name,""), COALESCE(' . $alias . '.metadata_json,"")))';
    $parts = [];
    foreach (['smoke', 'test-', 'cp-modem-', 'flow-sku-', 'flow gpon modem'] as $marker) {
        $parts[] = $haystack . ' NOT LIKE ' . "'%" . $marker . "%'";
    }
    return implode(' AND ', $parts);
}

function app_inventory_catalog_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE ' . app_inventory_operational_stock_sql('i');
    $where .= app_inventory_search_filter($query, ['i.sku', 'i.item_name', 'i.category', 'c.category_name'], $params);
    $category = clean_text((string) ($query['category'] ?? ''));
    if ($category !== '') {
        $where .= ' AND i.category = :category';
        $params['category'] = $category;
    }
    $status = clean_text((string) ($query['status'] ?? ''));
    if ($status !== '') {
        $where .= ' AND i.status = :status';
        $params['status'] = $status;
    }
    if (!empty($query['critical'])) {
        $where .= ' AND i.quantity_on_hand <= i.minimum_quantity';
    }
    $from = ' FROM inventory_stock_items i LEFT JOIN inventory_categories c ON c.category_code = i.category' . $where;
    return app_inventory_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT i.id, i.sku, i.item_name, i.category, COALESCE(c.category_name,i.category) AS category_name,
                i.unit, i.quantity_on_hand, i.minimum_quantity, i.status, i.updated_at' . $from . '
          ORDER BY i.item_name, i.id',
        $params,
        $query
    );
}

function app_inventory_asset_base(array $query, array &$params, string $extraWhere = ''): array
{
    $where = ' WHERE ' . app_inventory_operational_stock_sql('i') . $extraWhere;
    $where .= app_inventory_search_filter($query, ['a.asset_code', 'a.serial_number', 'a.mac_address', 'a.current_location_id', 'm.vendor', 'm.model', 'i.item_name'], $params);
    $condition = clean_text((string) ($query['condition'] ?? ''));
    if ($condition !== '') {
        $where .= ' AND a.condition_status = :condition';
        $params['condition'] = $condition;
    }
    $lifecycle = clean_text((string) ($query['lifecycle'] ?? ''));
    if ($lifecycle !== '') {
        $where .= ' AND a.lifecycle_status = :lifecycle';
        $params['lifecycle'] = $lifecycle;
    }
    $from = ' FROM inventory_assets a
              LEFT JOIN inventory_stock_items i ON i.id = a.product_id
              LEFT JOIN inventory_asset_models m ON m.id = a.model_id' . $where;
    $select = 'SELECT a.id, a.asset_code, a.serial_number, a.mac_address, a.ownership_type,
                      a.lifecycle_status, a.condition_status, a.current_location_type, a.current_location_id,
                      a.installation_count, a.failure_count, a.return_count, a.updated_at,
                      i.sku, i.item_name, m.vendor, m.model, m.device_type' . $from;
    return [$where, $from, $select];
}

function app_inventory_asset_page(PDO $db, array $query): array
{
    $params = [];
    [, $from, $select] = app_inventory_asset_base($query, $params);
    return app_inventory_query_page($db, 'SELECT COUNT(*)' . $from, $select . ' ORDER BY a.updated_at DESC, a.id DESC', $params, $query);
}

function app_inventory_material_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE i.category NOT IN ("ont_modem","router","switch","sfp") AND ' . app_inventory_operational_stock_sql('i');
    $where .= app_inventory_search_filter($query, ['i.sku', 'i.item_name', 'i.category', 'c.category_name'], $params);
    $from = ' FROM inventory_stock_items i LEFT JOIN inventory_categories c ON c.category_code = i.category' . $where;
    return app_inventory_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT i.id, i.sku, i.item_name, i.category, COALESCE(c.category_name,i.category) AS category_name,
                i.unit, i.quantity_on_hand, i.minimum_quantity, i.status, i.updated_at' . $from . ' ORDER BY i.item_name, i.id',
        $params,
        $query
    );
}

function app_inventory_cable_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE ' . app_inventory_operational_stock_sql('i');
    $where .= app_inventory_search_filter($query, ['d.drum_number', 'd.location_type', 'd.location_id', 'd.condition_status', 'i.sku', 'i.item_name'], $params);
    $from = ' FROM inventory_cable_drums d LEFT JOIN inventory_stock_items i ON i.id = d.product_id' . $where;
    return app_inventory_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT d.*, i.sku, i.item_name' . $from . ' ORDER BY d.updated_at DESC, d.id DESC',
        $params,
        $query
    );
}

function app_inventory_technician_page(PDO $db, array $query): array
{
    $params = [];
    [, $from, $select] = app_inventory_asset_base($query, $params, ' AND a.current_location_type = "technician"');
    return app_inventory_query_page($db, 'SELECT COUNT(*)' . $from, $select . ' ORDER BY a.current_location_id, a.updated_at DESC', $params, $query);
}

function app_inventory_customer_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE e.assignment_status = "active" AND ' . app_inventory_operational_stock_sql('i');
    $where .= app_inventory_search_filter($query, ['e.customer_code', 'e.serial_number', 'e.mac_address', 'e.installed_by', 'e.installed_ticket_no', 'a.asset_code', 'i.item_name'], $params);
    $from = ' FROM customer_equipment_assignments e
              LEFT JOIN inventory_assets a ON a.id = e.asset_id
              LEFT JOIN inventory_stock_items i ON i.id = a.product_id
              LEFT JOIN inventory_asset_models m ON m.id = a.model_id' . $where;
    return app_inventory_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT e.*, a.asset_code, a.lifecycle_status, a.condition_status, i.sku, i.item_name, m.vendor, m.model' . $from . '
          ORDER BY e.installed_at DESC, e.id DESC',
        $params,
        $query
    );
}

function app_inventory_handover_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE (l.movement_type IN ("assign_to_technician","handover") OR l.to_location_type IN ("technician","employee")) AND ' . app_inventory_operational_stock_sql('i');
    $where .= app_inventory_search_filter($query, ['l.movement_no', 'l.technician_code', 'l.ticket_no', 'l.to_location_id', 'i.item_name', 'a.asset_code'], $params);
    $from = ' FROM inventory_lifecycle_movements l
              LEFT JOIN inventory_stock_items i ON i.id = l.product_id
              LEFT JOIN inventory_assets a ON a.id = l.asset_id' . $where;
    return app_inventory_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT l.*, i.sku, i.item_name, a.asset_code, a.serial_number' . $from . ' ORDER BY l.created_at DESC, l.id DESC',
        $params,
        $query
    );
}

function app_inventory_return_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE 1=1';
    $where .= app_inventory_search_filter($query, ['r.customer_code', 'r.technician_code', 'r.return_ticket_no', 'r.return_reason', 'r.return_status', 'a.asset_code', 'a.serial_number'], $params);
    $status = clean_text((string) ($query['status'] ?? ''));
    if ($status !== '') {
        $where .= ' AND r.return_status = :status';
        $params['status'] = $status;
    }
    $from = ' FROM inventory_asset_returns r LEFT JOIN inventory_assets a ON a.id = r.asset_id' . $where;
    return app_inventory_query_page($db, 'SELECT COUNT(*)' . $from, 'SELECT r.*, a.asset_code, a.serial_number, a.mac_address, a.condition_status' . $from . ' ORDER BY r.created_at DESC, r.id DESC', $params, $query);
}

function app_inventory_inspection_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE 1=1';
    $where .= app_inventory_search_filter($query, ['n.inspection_status', 'n.inspected_by', 'n.notes', 'a.asset_code', 'a.serial_number', 'r.return_ticket_no'], $params);
    $from = ' FROM inventory_asset_inspections n
              LEFT JOIN inventory_assets a ON a.id = n.asset_id
              LEFT JOIN inventory_asset_returns r ON r.id = n.return_id' . $where;
    return app_inventory_query_page($db, 'SELECT COUNT(*)' . $from, 'SELECT n.*, a.asset_code, a.serial_number, a.mac_address, r.return_ticket_no, r.customer_code' . $from . ' ORDER BY n.inspected_at DESC, n.id DESC', $params, $query);
}

function app_inventory_rma_page(PDO $db, array $query): array
{
    $params = [];
    $extra = ' AND (LOWER(a.condition_status) LIKE "%defect%" OR LOWER(a.condition_status) LIKE "%repair%" OR LOWER(a.condition_status) LIKE "%rma%" OR LOWER(a.condition_status) LIKE "%fault%" OR LOWER(a.lifecycle_status) LIKE "%repair%" OR LOWER(a.lifecycle_status) LIKE "%rma%")';
    [, $from, $select] = app_inventory_asset_base($query, $params, $extra);
    return app_inventory_query_page($db, 'SELECT COUNT(*)' . $from, $select . ' ORDER BY a.failure_count DESC, a.updated_at DESC, a.id DESC', $params, $query);
}

function app_inventory_stock_movement_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE ' . app_inventory_operational_stock_sql('i') . ' AND (m.technician_account_id IS NULL OR ' . app_inventory_operational_employee_sql('a') . ')';
    $where .= app_inventory_search_filter($query, ['m.ticket_no', 'm.actor_name', 'm.note', 'm.movement_type', 'i.sku', 'i.item_name', 'a.full_name'], $params);
    $type = clean_text((string) ($query['movement_type'] ?? ''));
    if ($type !== '') {
        $where .= ' AND m.movement_type = :movement_type';
        $params['movement_type'] = $type;
    }
    $from = ' FROM inventory_stock_movements m
              LEFT JOIN inventory_stock_items i ON i.id = m.item_id
              LEFT JOIN portal_accounts a ON a.id = m.technician_account_id' . $where;
    return app_inventory_query_page($db, 'SELECT COUNT(*)' . $from, 'SELECT m.*, i.sku, i.item_name, i.unit, a.full_name AS technician_name' . $from . ' ORDER BY m.created_at DESC, m.id DESC', $params, $query);
}

function app_inventory_lifecycle_movement_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE ' . app_inventory_operational_stock_sql('i');
    $where .= app_inventory_search_filter($query, ['l.movement_no', 'l.movement_type', 'l.customer_code', 'l.technician_code', 'l.ticket_no', 'l.reason', 'i.item_name', 'a.asset_code', 'd.drum_number'], $params);
    $from = ' FROM inventory_lifecycle_movements l
              LEFT JOIN inventory_stock_items i ON i.id = l.product_id
              LEFT JOIN inventory_assets a ON a.id = l.asset_id
              LEFT JOIN inventory_cable_drums d ON d.id = l.cable_drum_id' . $where;
    return app_inventory_query_page($db, 'SELECT COUNT(*)' . $from, 'SELECT l.*, i.sku, i.item_name, a.asset_code, a.serial_number, d.drum_number' . $from . ' ORDER BY l.created_at DESC, l.id DESC', $params, $query);
}

function app_inventory_dataset(PDO $db, string $sql, array $params = []): array
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $rows = [];
    }
    return array_map(static fn (array $row): array => [
        'label' => (string) ($row['label'] ?? '-'),
        'value' => (float) ($row['value_total'] ?? 0),
        'count' => (int) ($row['row_count'] ?? 0),
    ], $rows);
}

function app_inventory_scalar(PDO $db, string $sql, array $params = []): float
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return (float) $statement->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

function app_inventory_statistics_context(PDO $db, array $query): array
{
    $dates = app_inventory_date_range($query);
    $params = [
        'start_at' => $dates['start_date'] . ' 00:00:00',
        'end_at' => date('Y-m-d 00:00:00', strtotime($dates['end_date'] . ' +1 day')),
    ];
    $bucket = $dates['range'] === 'year' ? 'DATE_FORMAT(m.created_at, "%Y-%m")' : 'DATE(m.created_at)';
    $stockFilter = app_inventory_operational_stock_sql('i');
    $stock = app_inventory_dataset($db, 'SELECT i.category AS label, COALESCE(SUM(i.quantity_on_hand),0) AS value_total, COUNT(*) AS row_count FROM inventory_stock_items i WHERE ' . $stockFilter . ' GROUP BY i.category ORDER BY value_total DESC LIMIT 12');
    $lifecycle = app_inventory_dataset($db, 'SELECT lifecycle_status AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM inventory_assets GROUP BY lifecycle_status ORDER BY value_total DESC LIMIT 12');
    $condition = app_inventory_dataset($db, 'SELECT condition_status AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM inventory_assets GROUP BY condition_status ORDER BY value_total DESC LIMIT 12');
    $cables = app_inventory_dataset($db, 'SELECT location_type AS label, COALESCE(SUM(remaining_length_meters),0) AS value_total, COUNT(*) AS row_count FROM inventory_cable_drums GROUP BY location_type ORDER BY value_total DESC LIMIT 12');
    $movementFrom = ' FROM inventory_stock_movements m LEFT JOIN inventory_stock_items i ON i.id = m.item_id WHERE ' . $stockFilter . ' AND m.created_at >= :start_at AND m.created_at < :end_at';
    $movementTypes = app_inventory_dataset($db, 'SELECT m.movement_type AS label, COALESCE(SUM(ABS(m.quantity)),0) AS value_total, COUNT(*) AS row_count' . $movementFrom . ' GROUP BY m.movement_type ORDER BY value_total DESC LIMIT 12', $params);
    $movementTrend = app_inventory_dataset($db, 'SELECT ' . $bucket . ' AS label, COUNT(*) AS value_total, COUNT(*) AS row_count' . $movementFrom . ' GROUP BY ' . $bucket . ' ORDER BY label LIMIT 12', $params);
    $locations = app_inventory_dataset($db, 'SELECT current_location_type AS label, COUNT(*) AS value_total, COUNT(*) AS row_count FROM inventory_assets GROUP BY current_location_type ORDER BY value_total DESC LIMIT 12');

    return $dates + [
        'kpis' => [
            ['label' => 'Aktiv mehsul', 'value' => app_inventory_scalar($db, 'SELECT COUNT(*) FROM inventory_stock_items i WHERE i.status = "active" AND ' . $stockFilter), 'kind' => 'count'],
            ['label' => 'Stok vahidi', 'value' => app_inventory_scalar($db, 'SELECT COALESCE(SUM(i.quantity_on_hand),0) FROM inventory_stock_items i WHERE i.status = "active" AND ' . $stockFilter), 'kind' => 'quantity'],
            ['label' => 'Kritik stok', 'value' => app_inventory_scalar($db, 'SELECT COUNT(*) FROM inventory_stock_items i WHERE i.status = "active" AND i.quantity_on_hand <= i.minimum_quantity AND ' . $stockFilter), 'kind' => 'count'],
            ['label' => 'Serialized cihaz', 'value' => app_inventory_scalar($db, 'SELECT COUNT(*) FROM inventory_assets'), 'kind' => 'count'],
            ['label' => 'Musteride cihaz', 'value' => app_inventory_scalar($db, 'SELECT COUNT(*) FROM customer_equipment_assignments WHERE assignment_status = "active"'), 'kind' => 'count'],
            ['label' => 'Qalan kabel', 'value' => app_inventory_scalar($db, 'SELECT COALESCE(SUM(remaining_length_meters),0) FROM inventory_cable_drums'), 'kind' => 'meters'],
        ],
        'datasets' => [
            'stock_categories' => ['title' => 'Kateqoriya uzre stok', 'kind' => 'quantity', 'rows' => $stock],
            'asset_lifecycle' => ['title' => 'Cihaz lifecycle statusu', 'kind' => 'count', 'rows' => $lifecycle],
            'asset_condition' => ['title' => 'Cihaz veziyyeti', 'kind' => 'count', 'rows' => $condition],
            'cable_locations' => ['title' => 'Lokasiya uzre qalan kabel', 'kind' => 'meters', 'rows' => $cables],
            'movement_types' => ['title' => 'Hereket tipleri', 'kind' => 'quantity', 'rows' => $movementTypes],
            'movement_trend' => ['title' => 'Hereket trendi', 'kind' => 'count', 'rows' => $movementTrend],
            'asset_locations' => ['title' => 'Cihaz lokasiyalari', 'kind' => 'count', 'rows' => $locations],
        ],
    ];
}

function app_inventory_product_options(PDO $db): array
{
    try {
        $statement = $db->query('SELECT i.id, i.sku, i.item_name, i.category, i.unit, i.quantity_on_hand FROM inventory_stock_items i WHERE i.status = "active" AND ' . app_inventory_operational_stock_sql('i') . ' ORDER BY i.item_name LIMIT 250');
        return $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable) {
        return [];
    }
}

function app_inventory_employee_options(PDO $db): array
{
    try {
        $sql = 'SELECT a.id, a.full_name, a.login, a.employee_role, a.area FROM portal_accounts a WHERE a.role = "employee" AND a.is_active = 1 AND ' . app_inventory_operational_employee_sql('a') . ' ORDER BY a.full_name LIMIT 250';
        $statement = $db->query($sql);
        return $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable) {
        return [];
    }
}

function app_inventory_pending_handovers(PDO $db, array $user, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    $params = [];
    $where = ' WHERE d.document_type = "handover" AND d.status = "pending"';
    if (!app_portal_is_admin($user) && !in_array(strtolower((string) ($user['employee_role'] ?? '')), ['warehouse', 'warehouse_manager'], true)) {
        $where .= ' AND d.destination_id = :employee_id';
        $params['employee_id'] = (string) ((int) ($user['id'] ?? 0));
    }
    $statement = $db->prepare('SELECT d.*,a.full_name AS employee_name,COUNT(i.id) AS item_count,GROUP_CONCAT(CONCAT(i.item_name," x",i.quantity) ORDER BY i.line_no SEPARATOR ", ") AS item_summary FROM inventory_documents d LEFT JOIN inventory_document_items i ON i.document_id=d.id LEFT JOIN portal_accounts a ON a.id=CAST(d.destination_id AS UNSIGNED)' . $where . ' GROUP BY d.id,a.full_name ORDER BY d.created_at DESC LIMIT ' . $limit);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_inventory_url(string $tab, array $params = [], string $lang = 'az'): string
{
    $query = ['section' => 'inventory', 'inventory_tab' => $tab] + $params;
    if ($lang !== 'az') {
        $query['lang'] = $lang;
    }
    return 'operations-center.php?' . http_build_query($query);
}

function app_inventory_build_context(PDO $db, array $user, array $query): array
{
    app_mgs_ensure_schema($db);
    app_inventory_ensure_operational_schema($db);
    $tab = app_inventory_tab($query);
    $context = ['tab' => $tab, 'tabs' => app_inventory_tabs(), 'query' => $query, 'user' => $user, 'lang' => app_lang(), 'data' => ['can_write' => app_inventory_actor_can_write($user)]];
    if ($tab === 'overview') {
        $context['data']['statistics'] = app_inventory_statistics_context($db, $query);
        $context['data']['critical'] = (array) (app_inventory_catalog_page($db, ['page' => 1, 'limit' => 5, 'status' => 'active', 'critical' => 1])['rows'] ?? []);
        $context['data']['recent'] = (array) (app_inventory_stock_movement_page($db, ['page' => 1, 'limit' => 5])['rows'] ?? []);
    } elseif ($tab === 'catalog') {
        $context['data']['page'] = app_inventory_catalog_page($db, $query);
        $context['data']['categories'] = app_mgs_inventory_categories($db);
    } elseif ($tab === 'devices') {
        $context['data']['page'] = app_inventory_asset_page($db, $query);
    } elseif ($tab === 'materials') {
        $context['data']['page'] = app_inventory_material_page($db, $query);
    } elseif ($tab === 'cable-drums') {
        $context['data']['page'] = app_inventory_cable_page($db, $query);
    } elseif ($tab === 'technicians') {
        $context['data']['page'] = app_inventory_technician_page($db, $query);
        $context['data']['balances'] = app_inventory_employee_balances($db);
        $context['data']['products'] = app_inventory_product_options($db);
        $context['data']['employees'] = app_inventory_employee_options($db);
        $context['data']['branches'] = function_exists('app_db_branches') ? app_db_branches($db) : [];
    } elseif ($tab === 'customers') {
        $context['data']['page'] = app_inventory_customer_page($db, $query);
    } elseif ($tab === 'handover') {
        $context['data']['page'] = app_inventory_handover_page($db, $query);
        $context['data']['pending_handovers'] = app_inventory_pending_handovers($db, $user);
        $context['data']['products'] = app_inventory_product_options($db);
        $context['data']['employees'] = app_inventory_employee_options($db);
        $context['data']['branches'] = function_exists('app_db_branches') ? app_db_branches($db) : [];
    } elseif ($tab === 'returns-inspection') {
        $view = clean_text((string) ($query['return_view'] ?? 'returns'));
        $view = in_array($view, ['returns', 'inspection'], true) ? $view : 'returns';
        $context['data']['view'] = $view;
        $context['data']['page'] = $view === 'inspection' ? app_inventory_inspection_page($db, $query) : app_inventory_return_page($db, $query);
    } elseif ($tab === 'repair-rma') {
        $context['data']['page'] = app_inventory_rma_page($db, $query);
    } elseif ($tab === 'movements') {
        $view = clean_text((string) ($query['movement_view'] ?? 'stock'));
        $view = in_array($view, ['stock', 'lifecycle'], true) ? $view : 'stock';
        $context['data']['view'] = $view;
        $context['data']['page'] = $view === 'lifecycle' ? app_inventory_lifecycle_movement_page($db, $query) : app_inventory_stock_movement_page($db, $query);
        $context['data']['products'] = app_inventory_product_options($db);
        $context['data']['employees'] = app_inventory_employee_options($db);
        $context['data']['categories'] = app_mgs_inventory_categories($db);
        $context['data']['branches'] = function_exists('app_db_branches') ? app_db_branches($db) : [];
        $context['data']['can_approve'] = app_inventory_actor_can_approve($user);
    } elseif ($tab === 'approvals') {
        $context['data']['page'] = app_inventory_document_page($db, $query);
        $context['data']['products'] = app_inventory_product_options($db);
        $context['data']['branches'] = function_exists('app_db_branches') ? app_db_branches($db) : [];
        $context['data']['can_approve'] = app_inventory_actor_can_approve($user);
    } elseif ($tab === 'statistics') {
        $context['data']['statistics'] = app_inventory_statistics_context($db, $query);
    }
    return $context;
}
