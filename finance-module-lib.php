<?php
declare(strict_types=1);

require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/finance-operations-lib.php';
require_once __DIR__ . '/finance-bonus-lib.php';
require_once __DIR__ . '/reporting-period-lib.php';
// Payroll/tax was moved from HR to Finance; reuse the HR payroll data loaders.
require_once __DIR__ . '/hr-module-lib.php';
// Revenue-for-period must match Billing's own dashboard exactly; reuse its
// canonical sum instead of keeping a second copy of the same query here.
require_once __DIR__ . '/billing-module-lib.php';
// Finance views call app_ui_status()/app_ui_term(); ensure available on both the
// full-page and AJAX-partial render paths.
require_once __DIR__ . '/ui-language-lib.php';

function app_finance_tabs(): array
{
    return [
        'overview' => 'Ümumi baxış',
        'invoices' => 'Qaimələr',
        'payments' => 'Ödənişlər',
        'income' => 'Gəlirlər',
        'companies' => 'Şirkətlər',
        'expenses' => 'Xərclər',
        'cash-bank' => 'Kassa və bank',
        'reconciliation' => 'Uzlaşdırma',
        'assets' => 'Əsas vəsaitlər',
        'employee-bonuses' => 'Əməkdaş bonusları',
        'payroll' => 'Maaş və vergilər',
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
    $timezone = new DateTimeZone(app_timezone());
    $date = app_reporting_strict_local_date($value, $timezone);
    return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : $fallback;
}

function app_finance_date_range(array $query): array
{
    return app_reporting_period($query);
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
    $where = ' WHERE e.entry_type = "expense"';
    $params = [];
    $where .= app_finance_search_filter($query, [
        'e.title',
        'e.category',
        'e.reference_no',
        'e.created_by_name',
    ], $params);

    $from = ' FROM finance_entries e
              LEFT JOIN portal_accounts a ON a.id = e.employee_account_id' . $where;
    return app_finance_query_page(
        $db,
        'SELECT COUNT(*)' . $from,
        'SELECT e.id,e.entry_type,e.title,e.category,e.amount,e.currency,e.transaction_date,
                e.branch_id,e.employee_account_id,e.payment_source,e.reference_no,e.note AS note_text,
                e.status,e.source_type,e.source_id,e.correlation_id,e.created_by_name AS actor_name,
                e.created_at,a.full_name AS responsible_employee_name' . $from . '
          ORDER BY e.transaction_date DESC,e.id DESC',
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
    if (empty($dates['valid'])) {
        return $dates + [
            'report_type' => clean_text((string) ($query['report_type'] ?? 'overview')) ?: 'overview',
            'kpis' => [],
            'datasets' => [],
            'data_quality' => [],
        ];
    }
    $dateContractEnabled = app_stage1_feature_enabled($db, 'stage1_statistics_date_contract');
    $params = [
        'start_at' => $dates['start_at'],
        'end_at' => $dateContractEnabled ? $dates['operational_end_at_exclusive'] : $dates['end_at_exclusive'],
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
    $incomeByLabel = [];
    foreach (array_merge($income, $manualIncome) as $row) {
        $label = (string) ($row['label'] ?? '-');
        if (!isset($incomeByLabel[$label])) {
            $incomeByLabel[$label] = ['label' => $label, 'value' => 0.0, 'count' => 0];
        }
        $incomeByLabel[$label]['value'] += (float) ($row['value'] ?? 0);
        $incomeByLabel[$label]['count'] += (int) ($row['count'] ?? 0);
    }
    ksort($incomeByLabel);
    $income = array_values($incomeByLabel);
    $expenses = app_finance_dataset(
        $db,
        'SELECT ' . ($dates['bucket'] === 'month' ? 'DATE_FORMAT(transaction_date, "%Y-%m")' : 'transaction_date') . ' AS label,
                COALESCE(SUM(amount),0) AS amount_total,COUNT(*) AS row_count
           FROM finance_entries
          WHERE entry_type = "expense" AND status = "posted" AND currency = "AZN"
            AND transaction_date >= :start_date AND transaction_date <= :end_date
          GROUP BY label ORDER BY label',
        ['start_date' => $dates['start_date'], 'end_date' => $dates['end_date']],
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
          ORDER BY amount_total DESC',
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
          ORDER BY amount_total DESC',
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

    $futurePayments = 0;
    try {
        $future = $db->prepare('SELECT COUNT(*) FROM billing_payments WHERE created_at >= :future_cutoff_at');
        $future->execute(['future_cutoff_at' => $dates['future_cutoff_at']]);
        $futurePayments = (int) $future->fetchColumn();
    } catch (Throwable) {
        $futurePayments = 0;
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
        'data_quality' => [
            'future_billing_payment_rows' => $futurePayments,
            'future_rows_excluded' => $dateContractEnabled,
        ],
    ];
}

function app_finance_overview_kpis(PDO $db, array $query): array
{
    $dates = app_finance_date_range($query);
    if (empty($dates['valid'])) {
        return [];
    }
    $dateContractEnabled = app_stage1_feature_enabled($db, 'stage1_statistics_date_contract');
    $params = [
        'start_at' => $dates['start_at'],
        'end_at' => $dateContractEnabled ? $dates['operational_end_at_exclusive'] : $dates['end_at_exclusive'],
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

    $income = app_billing_period_revenue_total($db, (string) $params['start_at'], (string) $params['end_at']);
    $income += app_finance_manual_income_total($db, $dates['start_date'], $dates['end_date']);
    $expenses = $scalar(
        'SELECT COALESCE(SUM(amount),0) FROM finance_entries
          WHERE entry_type = "expense" AND status = "posted" AND currency = "AZN"
            AND transaction_date >= :start_date AND transaction_date <= :end_date',
        ['start_date' => $dates['start_date'], 'end_date' => $dates['end_date']]
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
    } elseif ($tab === 'payroll') {
        // Payroll/tax is a finance responsibility (moved from HR). Reuse the HR
        // payroll data loaders; the finance payroll view mirrors the HR one.
        $legalProfile = function_exists('app_mgs_hr_default_legal_profile') ? app_mgs_hr_default_legal_profile() : [];
        $employees = $db->query('SELECT id, full_name, login, employee_role, job_title, is_active FROM portal_accounts a WHERE a.role = "employee" AND ' . app_hr_operational_sql('a') . ' ORDER BY a.full_name, a.id LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $context['data']['page'] = app_hr_payroll_page($db, $query);
        $context['data']['employees'] = $employees;
        $context['data']['legal_profile'] = $legalProfile;
        $context['data']['preview'] = function_exists('app_mgs_hr_payroll_preview') ? app_mgs_hr_payroll_preview([
            'gross_salary' => max(0, (float) ($query['payroll_gross_salary'] ?? 1000)),
            'leave_days' => max(0, (float) ($query['payroll_leave_days'] ?? 0)),
            'leave_daily_rate' => max(0, (float) ($query['payroll_leave_daily_rate'] ?? 0)),
            'travel_days' => max(0, (float) ($query['payroll_travel_days'] ?? 0)),
            'travel_daily_rate' => max(0, (float) ($query['payroll_travel_daily_rate'] ?? 0)),
            'taxable_travel_amount' => max(0, (float) ($query['payroll_taxable_travel_amount'] ?? 0)),
        ], $legalProfile) : [];
        $context['data']['cost_rows'] = function_exists('app_mgs_hr_cost_report_rows') ? app_mgs_hr_cost_report_rows($db, 12) : [];
    }

    $context['data']['can_write'] = app_finance_actor_can_write($user);
    $context['data']['can_confirm_maker_checker'] = app_portal_is_admin($user);

    return $context;
}
