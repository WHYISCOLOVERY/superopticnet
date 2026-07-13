<?php
declare(strict_types=1);

require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/finance-operations-lib.php';
require_once __DIR__ . '/finance-bonus-lib.php';

function app_finance_tabs(): array
{
    return [
        'overview' => 'Umumi baxis',
        'invoices' => 'Qaimeler',
        'payments' => 'Odenisler',
        'income' => 'Gelirler',
        'companies' => 'Sirketler',
        'expenses' => 'Xercler',
        'cash-bank' => 'Kassa ve bank',
        'reconciliation' => 'Uzlasdirma',
        'assets' => 'Esas vesaitler',
        'employee-bonuses' => 'Emekdas Bonuslari',
        'statistics' => 'Statistika',
    ];
}

function app_finance_tab(array $query): string
{
    $tab = clean_text((string) ($query['finance_tab'] ?? $query['tab'] ?? 'overview'));
    return array_key_exists($tab, app_finance_tabs()) ? $tab : 'overview';
}

function app_finance_pagination(array $query, string $pageKey = 'page'): array
{
    $page = max(1, (int) ($query[$pageKey] ?? 1));
    $limit = max(1, min(50, (int) ($query['limit'] ?? 50)));

    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => ($page - 1) * $limit,
    ];
}

function app_finance_date_value(mixed $value, string $fallback): string
{
    $date = clean_text((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $fallback;
    }

    $timestamp = strtotime($date);
    return $timestamp === false ? $fallback : date('Y-m-d', $timestamp);
}

function app_finance_date_range(array $query): array
{
    $today = new DateTimeImmutable('today');
    $range = clean_text((string) ($query['range'] ?? 'month'));
    if (!in_array($range, ['today', 'yesterday', 'week', 'month', 'year', 'custom'], true)) {
        $range = 'month';
    }

    $start = $today->modify('-29 days')->format('Y-m-d');
    $end = $today->format('Y-m-d');
    if ($range === 'today') {
        $start = $end;
    } elseif ($range === 'yesterday') {
        $start = $today->modify('-1 day')->format('Y-m-d');
        $end = $start;
    } elseif ($range === 'week') {
        $start = $today->modify('-6 days')->format('Y-m-d');
    } elseif ($range === 'year') {
        $start = $today->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
    } elseif ($range === 'custom') {
        $start = app_finance_date_value($query['start_date'] ?? '', $start);
        $end = app_finance_date_value($query['end_date'] ?? '', $end);
    }

    if (strtotime($start) > strtotime($end)) {
        [$start, $end] = [$end, $start];
    }

    return ['range' => $range, 'start_date' => $start, 'end_date' => $end];
}

function app_finance_query_page(
    PDO $db,
    string $countSql,
    string $rowsSql,
    array $params,
    array $query,
    string $pageKey = 'page'
): array {
    $pagination = app_finance_pagination($query, $pageKey);
    try {
        $countStatement = $db->prepare($countSql);
        $countStatement->execute($params);
        $total = max(0, (int) $countStatement->fetchColumn());
        $pages = max(1, (int) ceil($total / $pagination['limit']));
        $page = min($pagination['page'], $pages);
        $offset = ($page - 1) * $pagination['limit'];

        $rowsStatement = $db->prepare(
            $rowsSql . ' LIMIT ' . $pagination['limit'] . ' OFFSET ' . $offset
        );
        $rowsStatement->execute($params);
        $rows = $rowsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $start = $total > 0 ? $offset + 1 : 0;
        $end = $total > 0 ? min($total, $offset + count($rows)) : 0;

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $pagination['limit'],
            'offset' => $offset,
            'start' => $start,
            'end' => $end,
        ];
    } catch (Throwable $exception) {
        return [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'pages' => 1,
            'limit' => $pagination['limit'],
            'offset' => 0,
            'start' => 0,
            'end' => 0,
            'error' => $exception->getMessage(),
        ];
    }
}

function app_finance_search_filter(array $query, array $columns, array &$params): string
{
    $search = clean_text((string) ($query['search'] ?? ''));
    if ($search === '' || $columns === []) {
        return '';
    }

    $params['search'] = '%' . $search . '%';
    return ' AND (' . implode(' OR ', array_map(
        static fn (string $column): string => $column . ' LIKE :search',
        $columns
    )) . ')';
}

function app_finance_payment_page(PDO $db, array $query): array
{
    $where = ' WHERE 1=1';
    $params = [];
    $where .= app_finance_search_filter($query, ['p.subscriber_code', 'p.transaction_id', 'p.payment_method', 'p.note'], $params);

    $method = clean_text((string) ($query['payment_method'] ?? ''));
    if ($method !== '') {
        $where .= ' AND p.payment_method = :payment_method';
        $params['payment_method'] = $method;
    }
    if (!empty($query['start_date'])) {
        $where .= ' AND p.created_at >= :start_at';
        $params['start_at'] = app_finance_date_value($query['start_date'], date('Y-m-d')) . ' 00:00:00';
    }
    if (!empty($query['end_date'])) {
        $endDate = app_finance_date_value($query['end_date'], date('Y-m-d'));
        $where .= ' AND p.created_at < :end_at';
        $params['end_at'] = date('Y-m-d 00:00:00', strtotime($endDate . ' +1 day'));
    }

    return app_finance_query_page(
        $db,
        'SELECT COUNT(*) FROM billing_payments p' . $where,
        'SELECT p.id, p.subscriber_code, p.amount, p.payment_method, p.transaction_id, p.note, p.created_at,
                a.full_name AS customer_name
           FROM billing_payments p
           LEFT JOIN portal_accounts a ON a.id = p.account_id' . $where . '
          ORDER BY p.created_at DESC, p.id DESC',
        $params,
        $query
    );
}

function app_finance_invoice_page(PDO $db, array $query): array
{
    $where = ' WHERE 1=1';
    $params = [];
    $where .= app_finance_search_filter($query, ['i.document_no', 'i.counterparty_name', 'i.import_batch'], $params);
    foreach (['ledger_type', 'status'] as $filter) {
        $value = clean_text((string) ($query[$filter] ?? ''));
        if ($value !== '') {
            $where .= ' AND i.' . $filter . ' = :' . $filter;
            $params[$filter] = $value;
        }
    }

    return app_finance_query_page(
        $db,
        'SELECT COUNT(*) FROM finance_e_invoice_imports i' . $where,
        'SELECT i.*, d.amount_open, d.status AS debt_status
           FROM finance_e_invoice_imports i
           LEFT JOIN finance_debt_ledger d ON d.id = i.debt_id' . $where . '
          ORDER BY i.created_at DESC, i.id DESC',
        $params,
        $query
    );
}

function app_finance_company_page(PDO $db, array $query): array
{
    if (function_exists('app_finance_counterparty_page')) {
        return app_finance_counterparty_page($db, $query);
    }
    $params = [];
    $where = ' WHERE counterparty_name <> ""';
    $where .= app_finance_search_filter($query, ['counterparty_name', 'counterparty_type'], $params);
    $groupSql = ' FROM finance_debt_ledger' . $where . ' GROUP BY counterparty_name, counterparty_type';

    return app_finance_query_page(
        $db,
        'SELECT COUNT(*) FROM (SELECT counterparty_name' . $groupSql . ') finance_companies',
        'SELECT MIN(id) AS id, counterparty_name, counterparty_type,
                COUNT(*) AS document_count,
                COALESCE(SUM(amount_original),0) AS amount_total,
                COALESCE(SUM(amount_open),0) AS amount_open,
                MAX(updated_at) AS updated_at' . $groupSql . '
          ORDER BY amount_open DESC, counterparty_name',
        $params,
        $query
    );
}

function app_finance_expense_page(PDO $db, array $query): array
{
    $where = " WHERE entity_type = 'finance'
                 AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.entryType')), ''))
                     IN ('expense','xerc','cost','outcome')";
    $params = [];
    $where .= app_finance_search_filter($query, [
        'summary',
        "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.title'))",
        "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category'))",
    ], $params);

    return app_finance_query_page(
        $db,
        'SELECT COUNT(*) FROM mgs_operation_actions' . $where,
        "SELECT id, action_type, status, actor_name, summary, created_at,
                JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.category')) AS category,
                JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.title')) AS title,
                CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.amount')), '0') AS DECIMAL(12,2)) AS amount,
                JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.branchId')) AS branch_id,
                JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.noteText')) AS note_text
           FROM mgs_operation_actions" . $where . '
          ORDER BY created_at DESC, id DESC',
        $params,
        $query
    );
}

function app_finance_debt_page(PDO $db, array $query): array
{
    $where = ' WHERE 1=1';
    $params = [];
    $where .= app_finance_search_filter($query, ['counterparty_name', 'document_no', 'note'], $params);
    foreach (['ledger_type', 'status'] as $filter) {
        $value = clean_text((string) ($query[$filter] ?? ''));
        if ($value !== '' && $value !== 'all') {
            $where .= ' AND ' . $filter . ' = :' . $filter;
            $params[$filter] = $value;
        }
    }

    return app_finance_query_page(
        $db,
        'SELECT COUNT(*) FROM finance_debt_ledger' . $where,
        'SELECT * FROM finance_debt_ledger' . $where . '
          ORDER BY FIELD(status, "open", "partial", "closed", "void"), COALESCE(due_date, created_at), id DESC',
        $params,
        $query
    );
}

function app_finance_debt_payment_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE 1=1';
    $where .= app_finance_search_filter($query, ['d.counterparty_name', 'd.document_no', 'p.reference_no'], $params);

    return app_finance_query_page(
        $db,
        'SELECT COUNT(*) FROM finance_debt_payments p LEFT JOIN finance_debt_ledger d ON d.id = p.debt_id' . $where,
        'SELECT p.*, d.ledger_type, d.counterparty_name, d.counterparty_type, d.document_no
           FROM finance_debt_payments p
           LEFT JOIN finance_debt_ledger d ON d.id = p.debt_id' . $where . '
          ORDER BY p.created_at DESC, p.id DESC',
        $params,
        $query
    );
}

function app_finance_depreciation_page(PDO $db, array $query): array
{
    $params = [];
    $where = ' WHERE 1=1';
    $where .= app_finance_search_filter($query, ['asset_label', 'writeoff_status'], $params);

    return app_finance_query_page(
        $db,
        'SELECT COUNT(*) FROM finance_asset_depreciations' . $where,
        'SELECT * FROM finance_asset_depreciations' . $where . '
          ORDER BY fiscal_year DESC, created_at DESC, id DESC',
        $params,
        $query
    );
}

function app_finance_asset_page(PDO $db, array $query): array
{
    $view = clean_text((string) ($query['asset_view'] ?? 'depreciation'));
    if ($view === 'depreciation') {
        return app_finance_depreciation_page($db, $query) + ['view' => 'depreciation'];
    }

    $params = [];
    $where = ' WHERE 1=1';
    if ($view === 'employees') {
        $where .= ' AND ia.current_location_type IN ("technician","employee")';
    } else {
        $view = 'inventory';
    }
    $where .= app_finance_search_filter($query, ['ia.asset_code', 'ia.serial_number', 'ia.mac_address', 'isi.sku', 'isi.item_name'], $params);
    $from = ' FROM inventory_assets ia
              LEFT JOIN inventory_stock_items isi ON isi.id = ia.product_id
              LEFT JOIN inventory_asset_models iam ON iam.id = ia.model_id
              LEFT JOIN finance_asset_depreciations fd ON fd.id = (
                  SELECT MAX(fd2.id) FROM finance_asset_depreciations fd2 WHERE fd2.asset_id = ia.id
              )' . $where;

    return app_finance_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT ia.id, ia.asset_code, ia.serial_number, ia.mac_address, ia.ownership_type,
                ia.lifecycle_status, ia.condition_status, ia.current_location_type, ia.current_location_id,
                isi.sku, isi.item_name, isi.category, iam.vendor, iam.model,
                COALESCE(fd.net_book_value,0) AS net_book_value,
                COALESCE(fd.writeoff_status,"") AS writeoff_status' . $from . '
          ORDER BY ia.updated_at DESC, ia.id DESC',
        $params,
        $query
    ) + ['view' => $view];
}

function app_finance_operational_employee_rows(array $rows): array
{
    return array_values(array_filter($rows, static function (mixed $row): bool {
        if (!is_array($row)) {
            return false;
        }
        $haystack = strtolower(implode(' ', [
            (string) ($row['full_name'] ?? ''),
            (string) ($row['login'] ?? ''),
            (string) ($row['employee_role'] ?? ''),
        ]));
        foreach (['role agent', 'copilot texnik', 'telegram smoke', 'smoke password', 'agent.', 'cp-tech-', 'tg-operator-'] as $marker) {
            if (str_contains($haystack, $marker)) {
                return false;
            }
        }
        return true;
    }));
}

function app_finance_dataset(PDO $db, string $sql, array $params, string $labelKey, string $valueKey, string $countKey = ''): array
{
    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $rows = [];
    }

    return array_map(static function (array $row) use ($labelKey, $valueKey, $countKey): array {
        return [
            'label' => (string) ($row[$labelKey] ?? '-'),
            'value' => max(0.0, (float) ($row[$valueKey] ?? 0)),
            'count' => $countKey !== '' ? max(0, (int) ($row[$countKey] ?? 0)) : 0,
        ];
    }, $rows);
}

function app_finance_statistics_context(PDO $db, array $query): array
{
    $dates = app_finance_date_range($query);
    $params = [
        'start_at' => $dates['start_date'] . ' 00:00:00',
        'end_at' => date('Y-m-d 00:00:00', strtotime($dates['end_date'] . ' +1 day')),
    ];
    $reportType = clean_text((string) ($query['report_type'] ?? 'overview')) ?: 'overview';
    $dateBucket = $dates['range'] === 'year'
        ? 'DATE_FORMAT(created_at, "%Y-%m")'
        : 'DATE(created_at)';

    $income = app_finance_dataset(
        $db,
        'SELECT ' . $dateBucket . ' AS label, COALESCE(SUM(amount),0) AS amount_total, COUNT(*) AS row_count
           FROM billing_payments
          WHERE created_at >= :start_at AND created_at < :end_at
          GROUP BY ' . $dateBucket . '
          ORDER BY label',
        $params,
        'label',
        'amount_total',
        'row_count'
    );
    $manualIncome = app_finance_dataset(
        $db,
        'SELECT ' . ($dates['range'] === 'year' ? 'DATE_FORMAT(transaction_date, "%Y-%m")' : 'transaction_date') . ' AS label, COALESCE(SUM(amount),0) AS amount_total, COUNT(*) AS row_count
           FROM finance_entries
          WHERE entry_type = "income" AND status = "posted"
            AND currency = "AZN"
            AND transaction_date >= :start_date AND transaction_date <= :end_date
          GROUP BY label ORDER BY label',
        ['start_date' => $dates['start_date'], 'end_date' => $dates['end_date']],
        'label', 'amount_total', 'row_count'
    );
    foreach ($manualIncome as $row) {
        $row['label'] = 'Manual ' . (string) ($row['label'] ?? '');
        $income[] = $row;
    }
    $expenses = app_finance_dataset(
        $db,
        "SELECT " . $dateBucket . " AS label,
                COALESCE(SUM(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.amount')), '0') AS DECIMAL(12,2))),0) AS amount_total,
                COUNT(*) AS row_count
           FROM mgs_operation_actions
          WHERE entity_type = 'finance'
            AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.entryType')), '')) IN ('expense','xerc','cost','outcome')
            AND created_at >= :start_at AND created_at < :end_at
          GROUP BY " . $dateBucket . "
          ORDER BY label",
        $params,
        'label',
        'amount_total',
        'row_count'
    );
    $invoices = app_finance_dataset(
        $db,
        'SELECT CONCAT(ledger_type, ":", status) AS label, COALESCE(SUM(amount),0) AS amount_total, COUNT(*) AS row_count
           FROM finance_e_invoice_imports
          WHERE created_at >= :start_at AND created_at < :end_at
          GROUP BY ledger_type, status
          ORDER BY amount_total DESC',
        $params,
        'label',
        'amount_total',
        'row_count'
    );
    $debts = app_finance_dataset(
        $db,
        'SELECT ledger_type AS label, COALESCE(SUM(amount_open),0) AS amount_total, COUNT(*) AS row_count
           FROM finance_debt_ledger
          WHERE status IN ("open","partial")
          GROUP BY ledger_type
          ORDER BY amount_total DESC',
        [],
        'label',
        'amount_total',
        'row_count'
    );
    $methods = app_finance_dataset(
        $db,
        'SELECT COALESCE(NULLIF(payment_method,""),"manual") AS label,
                COALESCE(SUM(amount),0) AS amount_total, COUNT(*) AS row_count
           FROM billing_payments
          WHERE created_at >= :start_at AND created_at < :end_at
          GROUP BY COALESCE(NULLIF(payment_method,""),"manual")
          ORDER BY amount_total DESC
          LIMIT 12',
        $params,
        'label',
        'amount_total',
        'row_count'
    );
    $counterparties = app_finance_dataset(
        $db,
        'SELECT counterparty_name AS label, COALESCE(SUM(amount_open),0) AS amount_total, COUNT(*) AS row_count
           FROM finance_debt_ledger
          WHERE status IN ("open","partial")
          GROUP BY counterparty_name
          ORDER BY amount_total DESC
          LIMIT 12',
        [],
        'label',
        'amount_total',
        'row_count'
    );

    $sum = static fn (array $rows): float => array_sum(array_map(
        static fn (array $row): float => (float) ($row['value'] ?? 0),
        $rows
    ));
    $incomeTotal = $sum($income);
    $expenseTotal = $sum($expenses);
    $receivable = 0.0;
    $payable = 0.0;
    foreach ($debts as $row) {
        if (($row['label'] ?? '') === 'receivable') {
            $receivable = (float) $row['value'];
        } elseif (($row['label'] ?? '') === 'payable') {
            $payable = (float) $row['value'];
        }
    }

    return $dates + [
        'report_type' => $reportType,
        'kpis' => [
            ['label' => 'Gelir', 'value' => $incomeTotal, 'kind' => 'money'],
            ['label' => 'Xerc', 'value' => $expenseTotal, 'kind' => 'money'],
            ['label' => 'Net pul axini', 'value' => $incomeTotal - $expenseTotal, 'kind' => 'money'],
            ['label' => 'Alacaq', 'value' => $receivable, 'kind' => 'money'],
            ['label' => 'Borc', 'value' => $payable, 'kind' => 'money'],
            ['label' => 'Qaime', 'value' => array_sum(array_column($invoices, 'count')), 'kind' => 'count'],
        ],
        'datasets' => [
            'income' => ['title' => 'Gelir', 'kind' => 'money', 'rows' => $income],
            'expenses' => ['title' => 'Xercler', 'kind' => 'money', 'rows' => $expenses],
            'invoices' => ['title' => 'Qaimeler', 'kind' => 'money', 'rows' => $invoices],
            'debts' => ['title' => 'Borc ve alacaq', 'kind' => 'money', 'rows' => $debts],
            'payment_methods' => ['title' => 'Kassa ve bank menbeleri', 'kind' => 'money', 'rows' => $methods],
            'counterparties' => ['title' => 'Qarsi terefler', 'kind' => 'money', 'rows' => $counterparties],
        ],
    ];
}

function app_finance_overview_kpis(PDO $db, array $query): array
{
    $dates = app_finance_date_range($query);
    $params = [
        'start_at' => $dates['start_date'] . ' 00:00:00',
        'end_at' => date('Y-m-d 00:00:00', strtotime($dates['end_date'] . ' +1 day')),
    ];
    $scalar = static function (string $sql, array $bindings = []) use ($db): float {
        try {
            $statement = $db->prepare($sql);
            $statement->execute($bindings);
            return (float) $statement->fetchColumn();
        } catch (Throwable) {
            return 0.0;
        }
    };

    $income = $scalar(
        'SELECT COALESCE(SUM(amount),0) FROM billing_payments WHERE created_at >= :start_at AND created_at < :end_at',
        $params
    );
    $income += app_finance_manual_income_total($db, $dates['start_date'], $dates['end_date']);
    $expenses = $scalar(
        "SELECT COALESCE(SUM(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.amount')), '0') AS DECIMAL(12,2))),0)
           FROM mgs_operation_actions
          WHERE entity_type = 'finance'
            AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.entryType')), '')) IN ('expense','xerc','cost','outcome')
            AND created_at >= :start_at AND created_at < :end_at",
        $params
    );
    $receivable = $scalar('SELECT COALESCE(SUM(amount_open),0) FROM finance_debt_ledger WHERE ledger_type = "receivable" AND status IN ("open","partial")');
    $payable = $scalar('SELECT COALESCE(SUM(amount_open),0) FROM finance_debt_ledger WHERE ledger_type = "payable" AND status IN ("open","partial")');
    $invoiceCount = $scalar(
        'SELECT COUNT(*) FROM finance_e_invoice_imports WHERE created_at >= :start_at AND created_at < :end_at',
        $params
    );

    return [
        ['label' => 'Gelir', 'value' => $income, 'kind' => 'money'],
        ['label' => 'Xerc', 'value' => $expenses, 'kind' => 'money'],
        ['label' => 'Net pul axini', 'value' => $income - $expenses, 'kind' => 'money'],
        ['label' => 'Alacaq', 'value' => $receivable, 'kind' => 'money'],
        ['label' => 'Borc', 'value' => $payable, 'kind' => 'money'],
        ['label' => 'Qaime', 'value' => $invoiceCount, 'kind' => 'count'],
    ];
}

function app_finance_url(string $tab, array $params = [], string $lang = 'az'): string
{
    $query = ['section' => 'finance', 'finance_tab' => $tab] + $params;
    if ($lang !== 'az') {
        $query['lang'] = $lang;
    }

    return 'operations-center.php?' . http_build_query($query);
}

function app_finance_build_context(PDO $db, array $user, array $query): array
{
    app_mgs_ensure_schema($db);
    app_finance_ensure_operational_schema($db);
    app_bonus_ensure_schema($db);
    $tab = app_finance_tab($query);
    $context = [
        'tab' => $tab,
        'tabs' => app_finance_tabs(),
        'query' => $query,
        'user' => $user,
        'lang' => app_lang(),
        'data' => [],
    ];

    if ($tab === 'overview') {
        $context['data'] = [
            'kpis' => app_finance_overview_kpis($db, $query),
            'recent_payments' => app_finance_payment_page($db, ['page' => 1, 'limit' => 5]),
            'overdue_debts' => app_finance_debt_page($db, ['page' => 1, 'limit' => 5, 'status' => 'open']),
        ];
    } elseif ($tab === 'invoices') {
        $context['data']['page'] = app_finance_invoice_page($db, $query);
    } elseif ($tab === 'payments') {
        $context['data']['page'] = app_finance_payment_page($db, $query);
    } elseif ($tab === 'income') {
        $context['data']['page'] = app_finance_income_page($db, $query);
        $context['data']['branches'] = function_exists('app_db_branches') ? app_db_branches($db) : [];
        $context['data']['counterparties'] = app_finance_active_counterparties($db);
    } elseif ($tab === 'companies') {
        $context['data']['page'] = app_finance_company_page($db, $query);
        $context['data']['registry_counterparties'] = app_finance_active_counterparties($db);
    } elseif ($tab === 'expenses') {
        $context['data']['page'] = app_finance_expense_page($db, $query);
        $context['data']['branches'] = function_exists('app_db_branches') ? app_db_branches($db) : [];
        $employeePage = app_finance_query_page(
            $db,
            'SELECT COUNT(*) FROM portal_accounts WHERE role = "employee" AND is_active = 1',
            'SELECT id, full_name, login, employee_role FROM portal_accounts WHERE role = "employee" AND is_active = 1 ORDER BY full_name, id',
            [],
            ['page' => 1, 'limit' => 50]
        );
        $context['data']['employees'] = app_finance_operational_employee_rows((array) ($employeePage['rows'] ?? []));
    } elseif ($tab === 'cash-bank') {
        $context['data']['statistics'] = app_finance_statistics_context($db, $query);
    } elseif ($tab === 'reconciliation') {
        $context['data']['page'] = app_finance_debt_page($db, $query);
        $context['data']['open_debts'] = app_mgs_finance_debt_rows($db, 'all', 50);
        $context['data']['payments'] = app_finance_debt_payment_page($db, ['page' => 1, 'limit' => 8]);
    } elseif ($tab === 'assets') {
        $context['data']['page'] = app_finance_asset_page($db, $query);
        $context['data']['assets'] = app_mgs_finance_printable_inventory_rows($db, 50);
        $context['data']['stock_items'] = app_finance_query_page(
            $db,
            'SELECT COUNT(*) FROM inventory_stock_items WHERE status = "active"',
            'SELECT id, sku, item_name, category, unit FROM inventory_stock_items WHERE status = "active" ORDER BY item_name, id',
            [],
            ['page' => 1, 'limit' => 50]
        )['rows'];
    } elseif ($tab === 'employee-bonuses') {
        $context['data']['bonus'] = app_bonus_context($db, $user, $query);
    } elseif ($tab === 'statistics') {
        $context['data']['statistics'] = app_finance_statistics_context($db, $query);
    }

    $context['data']['can_write'] = app_finance_actor_can_write($user);

    return $context;
}
