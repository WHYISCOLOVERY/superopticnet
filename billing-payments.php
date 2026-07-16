<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-module-lib.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();
$query = clean_text((string) ($_GET['q'] ?? ''));
$method = clean_text((string) ($_GET['method'] ?? ''));
$startDate = clean_text((string) ($_GET['start_date'] ?? ''));
$endDate = clean_text((string) ($_GET['end_date'] ?? ''));
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = in_array($perPage, [25, 50], true) ? $perPage : 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$importResult = null;
$importError = '';
$openImportPanel = (string) ($_GET['import'] ?? '') === '1';

function billing_payments_import_csv(string $file, bool $apply, string $method, int $days, string $delimiter = ','): array
{
    $normalizeKey = static function (string $key): string {
        $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
        $key = strtolower(trim($key));
        $key = str_replace([' ', '-', '.', '/', '\\'], '_', $key);
        return preg_replace('/_+/', '_', $key) ?? $key;
    };

    $pick = static function (array $row, array $keys): string {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    };

    $parseAmount = static function (mixed $value): float {
        if (is_numeric($value)) {
            return (float) $value;
        }
        $text = trim((string) $value);
        $text = str_replace(['AZN', 'azn', '₼', ' '], '', $text);
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace(',', '', $text);
        } else {
            $text = str_replace(',', '.', $text);
        }
        return is_numeric($text) ? (float) $text : 0.0;
    };

    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return ['error' => 'CSV faylı oxunmadı.'];
    }

    $header = fgetcsv($handle, 0, $delimiter);
    if (!is_array($header) || $header === []) {
        fclose($handle);
        return ['error' => 'CSV başlığı boşdur.'];
    }

    $columns = array_map(static fn (string $key): string => $normalizeKey($key), $header);
    $result = [
        'mode' => $apply ? 'apply' : 'dry-run',
        'processed' => 0,
        'would_apply' => 0,
        'applied' => 0,
        'duplicates' => 0,
        'missing_customer' => 0,
        'invalid_amount' => 0,
        'failed' => 0,
        'rows' => [],
    ];

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($data === [null] || $data === []) {
            continue;
        }
        $row = [];
        foreach ($columns as $index => $column) {
            $row[$column] = $data[$index] ?? '';
        }

        $result['processed']++;
        $subscriberCode = $pick($row, ['subscriber_code', 'customer_code', 'abonent_kodu', 'abonent_kod', 'username', 'user_name', 'code', 'kod']);
        $amount = $parseAmount($pick($row, ['amount', 'mebleg', 'payment_amount', 'odenis', 'odeme', 'sum']));
        $transactionId = $pick($row, ['transaction_id', 'transaction', 'receipt_no', 'receipt', 'provider_tx', 'tx']);
        $externalId = $pick($row, ['external_id', 'provider_id']);
        $paidAt = $pick($row, ['paid_at', 'created_at', 'date', 'tarix', 'payment_date']);
        $createdAt = $paidAt !== '' && strtotime($paidAt) !== false ? date('Y-m-d H:i:s', strtotime($paidAt)) : null;
        if ($externalId === '') {
            $externalId = hash('sha256', implode('|', [$method, $transactionId, $subscriberCode, number_format($amount, 2, '.', ''), $createdAt ?? '']));
        }

        $rowResult = [
            'line' => $result['processed'] + 1,
            'subscriber_code' => $subscriberCode,
            'amount' => $amount,
            'transaction_id' => $transactionId,
            'external_id' => $externalId,
            'status' => 'pending',
        ];

        if ($subscriberCode === '' || app_db_account_by_subscriber_code($subscriberCode) === null) {
            $result['missing_customer']++;
            $rowResult['status'] = 'missing_customer';
            $result['rows'][] = $rowResult;
            continue;
        }
        if ($amount <= 0) {
            $result['invalid_amount']++;
            $rowResult['status'] = 'invalid_amount';
            $result['rows'][] = $rowResult;
            continue;
        }
        if (app_billing_payment_external_exists($externalId)) {
            $result['duplicates']++;
            $rowResult['status'] = 'duplicate';
            $result['rows'][] = $rowResult;
            continue;
        }
        if (!$apply) {
            $result['would_apply']++;
            $rowResult['status'] = 'would_apply';
            $result['rows'][] = $rowResult;
            continue;
        }

        $ok = app_billing_apply_customer_payment($subscriberCode, $amount, $method, $days, $transactionId, $externalId, $createdAt);
        if ($ok) {
            $result['applied']++;
            $rowResult['status'] = 'applied';
        } else {
            $result['failed']++;
            $rowResult['status'] = 'failed';
        }
        $result['rows'][] = $rowResult;
    }

    fclose($handle);
    return $result;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && clean_text((string) ($_POST['action'] ?? '')) === 'provider_import') {
    if (!$db instanceof PDO) {
        $importError = 'MySQL bağlantısı hazır deyil.';
    } elseif (!isset($_FILES['provider_csv']) || (int) ($_FILES['provider_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $importError = 'CSV faylı seçilməyib və ya yüklənmə xətası var.';
    } else {
        $importMethod = clean_text((string) ($_POST['import_method'] ?? 'provider'));
        $importMethod = $importMethod !== '' ? $importMethod : 'provider';
        $importDays = max(0, min(366, (int) ($_POST['import_days'] ?? 0)));
        $importApply = clean_text((string) ($_POST['import_mode'] ?? 'dry-run')) === 'apply';
        $tmpName = (string) ($_FILES['provider_csv']['tmp_name'] ?? '');
        $importResult = billing_payments_import_csv($tmpName, $importApply, $importMethod, $importDays);
        if (isset($importResult['error'])) {
            $importError = (string) $importResult['error'];
            $importResult = null;
        }
    }
    $openImportPanel = true;
}

$summary = ['total' => 0.0, 'terminal' => 0.0, 'cash' => 0.0, 'today' => 0.0, 'month' => 0.0, 'count' => 0, 'filtered_count' => 0, 'filtered_total' => 0.0];
$payments = [];
$methods = [];
$totalPages = 1;

if ($db instanceof PDO) {
    $summaryRow = $db->query(
        "SELECT
            COALESCE(SUM(amount),0) AS total_amount,
            COALESCE(SUM(CASE WHEN payment_method NOT IN ('manual','cash','nagd') THEN amount ELSE 0 END),0) AS terminal_amount,
            COALESCE(SUM(CASE WHEN payment_method IN ('manual','cash','nagd') THEN amount ELSE 0 END),0) AS cash_amount,
            COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN amount ELSE 0 END),0) AS today_amount,
            COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount ELSE 0 END),0) AS month_amount,
            COUNT(*) AS payment_count
         FROM billing_payments"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['total'] = (float) ($summaryRow['total_amount'] ?? 0);
    $summary['terminal'] = (float) ($summaryRow['terminal_amount'] ?? 0);
    $summary['cash'] = (float) ($summaryRow['cash_amount'] ?? 0);
    $summary['today'] = (float) ($summaryRow['today_amount'] ?? 0);
    $summary['month'] = (float) ($summaryRow['month_amount'] ?? 0);
    $summary['count'] = (int) ($summaryRow['payment_count'] ?? 0);
    $methods = $db->query(
        "SELECT payment_method, COUNT(*) AS payment_count
         FROM billing_payments
         WHERE payment_method IS NOT NULL AND payment_method <> ''
         GROUP BY payment_method
         ORDER BY payment_count DESC, payment_method
         LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $baseSql = 'FROM billing_payments p WHERE 1=1';
    $selectBaseSql = "FROM billing_payments p
            LEFT JOIN portal_accounts a ON a.id = p.account_id
            WHERE 1=1";
    $whereSql = '';
    $params = [];
    if ($query !== '') {
        $accountSearch = $db->prepare(
            'SELECT id
             FROM portal_accounts
             WHERE role = "customer"
               AND (full_name LIKE :q_like OR phone LIKE :q_prefix OR contract_no LIKE :q_prefix OR subscriber_code LIKE :q_prefix)
             LIMIT 1000'
        );
        $accountSearch->execute([
            'q_like' => '%' . $query . '%',
            'q_prefix' => $query . '%',
        ]);
        $accountIds = array_map('intval', $accountSearch->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $searchParts = [
            'p.subscriber_code = :q_exact',
            'p.subscriber_code LIKE :q_prefix',
            'p.transaction_id = :q_exact',
            'p.transaction_id LIKE :q_prefix',
        ];
        $params['q_exact'] = $query;
        $params['q_prefix'] = $query . '%';
        if ($accountIds !== []) {
            $accountPlaceholders = [];
            foreach ($accountIds as $index => $accountId) {
                $key = 'account_id_' . $index;
                $accountPlaceholders[] = ':' . $key;
                $params[$key] = $accountId;
            }
            $searchParts[] = 'p.account_id IN (' . implode(',', $accountPlaceholders) . ')';
        }
        $whereSql .= ' AND (' . implode(' OR ', $searchParts) . ')';
    }
    if ($method !== '') {
        $whereSql .= ' AND p.payment_method = :method';
        $params['method'] = $method;
    }
    if ($startDate !== '') {
        $whereSql .= ' AND p.created_at >= :start_date';
        $params['start_date'] = $startDate . ' 00:00:00';
    }
    if ($endDate !== '') {
        $whereSql .= ' AND p.created_at <= :end_date';
        $params['end_date'] = $endDate . ' 23:59:59';
    }

    if ($whereSql === '') {
        $summary['filtered_count'] = $summary['count'];
        $summary['filtered_total'] = $summary['total'];
    } else {
        $countStatement = $db->prepare('SELECT COUNT(*), COALESCE(SUM(p.amount),0) ' . $baseSql . $whereSql);
        $countStatement->execute($params);
        $countRow = $countStatement->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $summary['filtered_count'] = (int) ($countRow[0] ?? 0);
        $summary['filtered_total'] = (float) ($countRow[1] ?? 0);
    }
    $totalPages = max(1, (int) ceil(($summary['filtered_count'] > 0 ? $summary['filtered_count'] : 1) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT p.*, a.full_name, a.phone, a.area, a.contract_no
            " . $selectBaseSql . $whereSql . '
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT :limit OFFSET :offset';
    $statement = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value);
    }
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $payments = $statement->fetchAll() ?: [];
} else {
    $mockPayments = billing_mock_payment_rows();
    $summary['total'] = array_sum(array_map(static fn (array $row): float => (float) ($row['amount'] ?? 0), $mockPayments));
    $summary['terminal'] = array_sum(array_map(static function (array $row): float {
        $methodName = strtolower((string) ($row['payment_method'] ?? ''));
        return in_array($methodName, ['manual', 'cash', 'nagd'], true) ? 0.0 : (float) ($row['amount'] ?? 0);
    }, $mockPayments));
    $summary['cash'] = $summary['total'] - $summary['terminal'];
    $summary['today'] = 0.0;
    $summary['month'] = $summary['total'];
    $summary['count'] = count($mockPayments);

    foreach ($mockPayments as $payment) {
        $methodName = (string) (($payment['payment_method'] ?? '') ?: 'Mock');
        $methods[$methodName] = [
            'payment_method' => $methodName,
            'payment_count' => (int) ($methods[$methodName]['payment_count'] ?? 0) + 1,
        ];
    }
    $methods = array_values($methods);

    $filtered = array_values(array_filter($mockPayments, static function (array $row) use ($query, $method, $startDate, $endDate): bool {
        if ($query !== '') {
            $haystack = strtolower(implode(' ', [
                (string) ($row['subscriber_code'] ?? ''),
                (string) ($row['contract_no'] ?? ''),
                (string) ($row['full_name'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['transaction_id'] ?? ''),
            ]));
            if (!str_contains($haystack, strtolower($query))) {
                return false;
            }
        }
        if ($method !== '' && (string) ($row['payment_method'] ?? '') !== $method) {
            return false;
        }
        $day = date('Y-m-d', strtotime((string) ($row['created_at'] ?? 'now')));
        if ($startDate !== '' && $day < $startDate) {
            return false;
        }
        if ($endDate !== '' && $day > $endDate) {
            return false;
        }

        return true;
    }));

    $summary['filtered_count'] = count($filtered);
    $summary['filtered_total'] = array_sum(array_map(static fn (array $row): float => (float) ($row['amount'] ?? 0), $filtered));
    $totalPages = max(1, (int) ceil(($summary['filtered_count'] > 0 ? $summary['filtered_count'] : 1) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $payments = array_slice($filtered, $offset, $perPage);
}
$queryBase = $_GET;
unset($queryBase['page']);
$importPanelParams = ['import' => '1'];
if ($lang !== 'az') {
    $importPanelParams['lang'] = $lang;
}
$importPanelUrl = 'billing-payments.php?' . http_build_query($importPanelParams);
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Odenisler</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('Odenisler', 'payments', $lang); ?>

    <main class="portal-layout">
        <?php
        $billingLegacySection = 'payments';
        $billingLegacyTitle = 'Odenisler';
        $billingLegacyDescription = 'Odenis qebulu, provider importu, filter ve cixaris eyni Billing is axininda saxlanir.';
        $billingLegacyActions = [
            ['href' => 'billing-cash-payment.php', 'label' => 'Nagd odenis', 'icon' => 'fa-cash-register', 'class' => 'portal-primary-btn portal-inline-link'],
            ['href' => 'billing-customers.php', 'label' => 'Musteriler', 'icon' => 'fa-users'],
            ['href' => $importPanelUrl, 'label' => 'Provider import', 'icon' => 'fa-file-import'],
        ];
        require __DIR__ . '/views/billing/_legacy-module-intro.php';
        ?>
        <section class="billing-payment-kpis portal-reveal">
            <article class="billing-kpi" data-payment-kpi><span>Bu gun</span><strong><?= e(billing_money($summary['today'])); ?></strong><small>gunluk odenis</small></article>
            <article class="billing-kpi" data-payment-kpi><span>Bu ay</span><strong><?= e(billing_money($summary['month'])); ?></strong><small>ayliq odenis</small></article>
            <article class="billing-kpi" data-payment-kpi><span>Toplam</span><strong><?= e(billing_money($summary['total'])); ?></strong><small><?= e((string) $summary['count']); ?> qeyd</small></article>
            <article class="billing-kpi" data-payment-kpi><span>Filtr neticesi</span><strong><?= e(billing_money($summary['filtered_total'])); ?></strong><small><?= e((string) $summary['filtered_count']); ?> qeyd</small></article>
        </section>

        <?php if (false): // Legacy duplicate hero and six-card summary retained only for rollback reference. ?>
        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">Maliyyə control center</span>
                <h1>Ödəniş siyahısı və provider analitikası</h1>
                <p>Əvvəl yekun KPI və trend, sonra filterli çıxarış. Cədvəl yalnız detallı baxış üçün aşağı hissədə qalır.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn" href="billing-cash-payment.php"><i class="fa-solid fa-cash-register"></i> Nağd ödəniş</a>
                    <a class="portal-ghost-btn portal-inline-link" href="billing-customers.php"><i class="fa-solid fa-users"></i> Müştərilər</a>
                    <a class="portal-ghost-btn portal-inline-link<?= $openImportPanel ? ' is-active' : ''; ?>" href="<?= e($importPanelUrl); ?>"><i class="fa-solid fa-file-import"></i> Provider import</a>
                    <a class="portal-ghost-btn portal-inline-link" href="billing-statistics.php#revenue"><i class="fa-solid fa-chart-line"></i> Analitika</a>
                </div>
            </div>
            <aside class="mgs-command-panel">
                <span>Yekun</span>
                <strong><?= e(billing_money($summary['total'])); ?></strong>
                <small><?= e((string) $summary['count']); ?> ödəniş qeydi</small>
                <span class="mgs-status-badge is-online">Reconciliation üçün hazır data</span>
            </aside>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Bu gün', billing_money($summary['today']), 'günlük ödəniş', 'fa-calendar-day', 'orange'); ?>
            <?php billing_render_stat_card('Bu ay', billing_money($summary['month']), 'aylıq ödəniş', 'fa-chart-simple', 'green'); ?>
            <?php billing_render_stat_card('Terminal', billing_money($summary['terminal']), 'provider/webhook', 'fa-building-columns', 'blue'); ?>
            <?php billing_render_stat_card('Nağd/manual', billing_money($summary['cash']), 'əməkdaş ödənişi', 'fa-cash-register', 'navy'); ?>
            <?php billing_render_stat_card('Toplam', billing_money($summary['total']), (string) $summary['count'] . ' qeyd', 'fa-coins', 'orange'); ?>
            <?php billing_render_stat_card('Filtr nəticəsi', (string) $summary['filtered_count'], billing_money($summary['filtered_total']), 'fa-filter', 'blue'); ?>
        </section>

        <?php endif; ?>

        <?php if ($openImportPanel): ?>
        <section class="mgs-filter-panel portal-reveal" id="provider-import">
            <div class="mgs-section-head">
                <div>
                    <h2>Provider/terminal ödəniş importu</h2>
                    <p>CSV üzrə dry-run yoxlama et, sonra təsdiqləyib müştərilərə tətbiq et. <code>external_id</code> təkrar ödənişin ikinci dəfə yazılmasını bloklayır.</p>
                </div>
            </div>
            <?php if ($importError !== ''): ?>
                <div class="portal-alert portal-alert-danger"><?= e($importError); ?></div>
            <?php endif; ?>
            <?php if (is_array($importResult)): ?>
                <div class="mgs-stat-grid">
                    <?php billing_render_stat_card('Rejim', (string) ($importResult['mode'] ?? '-'), 'CSV yoxlama', 'fa-file-import', 'blue'); ?>
                    <?php billing_render_stat_card('Oxundu', (string) ($importResult['processed'] ?? 0), 'sətir', 'fa-list-check', 'navy'); ?>
                    <?php billing_render_stat_card('Tətbiq', (string) ($importResult['applied'] ?? 0), 'real yazılan', 'fa-circle-check', 'green'); ?>
                    <?php billing_render_stat_card('Hazır', (string) ($importResult['would_apply'] ?? 0), 'dry-run', 'fa-eye', 'orange'); ?>
                    <?php billing_render_stat_card('Təkrar', (string) ($importResult['duplicates'] ?? 0), 'skip', 'fa-copy', 'blue'); ?>
                    <?php billing_render_stat_card('Tapılmadı', (string) ($importResult['missing_customer'] ?? 0), 'müştəri yoxdur', 'fa-triangle-exclamation', 'orange'); ?>
                </div>
                <?php $previewRows = array_slice(is_array($importResult['rows'] ?? null) ? $importResult['rows'] : [], 0, 12); ?>
                <?php if ($previewRows !== []): ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead><tr><th>Sətir</th><th>Müştəri</th><th>Məbləğ</th><th>Transaction</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($previewRows as $row): ?>
                                    <tr>
                                        <td><?= e((string) ($row['line'] ?? '-')); ?></td>
                                        <td><?= e((string) ($row['subscriber_code'] ?? '-')); ?></td>
                                        <td><?= e(billing_money($row['amount'] ?? 0)); ?></td>
                                        <td><?= e((string) ($row['transaction_id'] ?? '-')); ?><br><small><?= e((string) ($row['external_id'] ?? '-')); ?></small></td>
                                        <td><span class="portal-pill"><?= e((string) ($row['status'] ?? '-')); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="portal-stacked-form">
                <input type="hidden" name="action" value="provider_import">
                <div class="billing-filter-grid">
                    <div class="portal-form-row">
                        <label>CSV fayl</label>
                        <input type="file" name="provider_csv" accept=".csv,text/csv" required>
                    </div>
                    <div class="portal-form-row">
                        <label>Metod/provider</label>
                        <input type="text" name="import_method" value="provider" placeholder="emanat / million / kapital">
                    </div>
                    <div class="portal-form-row">
                        <label>Gün</label>
                        <input type="number" name="import_days" min="0" max="366" value="0">
                        <small>0 = tarifdəki gün, yoxdursa 30 gün.</small>
                    </div>
                    <div class="portal-form-row">
                        <label>Rejim</label>
                        <select name="import_mode">
                            <option value="dry-run">Dry-run / yalnız yoxla</option>
                            <option value="apply">Tətbiq et</option>
                        </select>
                    </div>
                </div>
                <div class="billing-filter-actions">
                    <button type="submit" class="portal-primary-btn"><i class="fa-solid fa-file-import"></i> CSV yoxla / import et</button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <?php if (false): // Analytics belongs to billing-statistics.php. ?>
        <section class="mgs-dashboard-grid portal-reveal">
            <article class="mgs-chart-card">
                <div class="mgs-chart-head">
                    <div>
                        <h2>Günlük gəlir chart</h2>
                        <p>Son 14 gün üzrə ödəniş həcmi.</p>
                    </div>
                </div>
                <div class="mgs-bars">
                    <?php foreach ($dailyRevenue as $row): ?>
                        <?php $amount = (float) ($row['total_amount'] ?? 0); ?>
                        <div class="mgs-bar-row">
                            <span><?= e(date('d.m', strtotime((string) ($row['report_day'] ?? 'now')))); ?></span>
                            <span class="mgs-bar-track"><span class="mgs-bar-fill is-orange" style="width: <?= e((string) billing_percent($amount, $dailyMax)); ?>%"></span></span>
                            <strong><?= e(billing_money($amount)); ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($dailyRevenue === []): ?>
                        <?php billing_render_empty_state('Ödəniş trendi yoxdur', 'Tarix aralığında ödəniş tapılmadı.', 'fa-chart-column'); ?>
                    <?php endif; ?>
                </div>
            </article>
            <article class="mgs-chart-card">
                <div class="mgs-chart-head">
                    <div>
                        <h2>Provider paylanması</h2>
                        <p>Metod üzrə ən çox istifadə olunan kanallar.</p>
                    </div>
                </div>
                <div class="mgs-bars">
                    <?php foreach (array_slice($methods, 0, 8) as $methodRow): ?>
                        <?php $methodCount = (int) ($methodRow['payment_count'] ?? 0); ?>
                        <div class="mgs-bar-row">
                            <span><?= e((string) ($methodRow['payment_method'] ?? '-')); ?></span>
                            <span class="mgs-bar-track"><span class="mgs-bar-fill" style="width: <?= e((string) billing_percent($methodCount, $methodMax)); ?>%"></span></span>
                            <strong><?= e((string) $methodCount); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <?php endif; ?>

        <section class="mgs-filter-panel portal-reveal">
            <div class="mgs-section-head"><div><h2>Ödəniş üzrə filtrasiya</h2><p>Kod, müqavilə, ad, telefon, transaction, tarix və metod üzrə axtar.</p></div></div>
            <form method="get" class="portal-stacked-form" id="billing-payments-filter" data-auto-filter data-auto-filter-targets="billing-payments-results">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row"><label>Axtarış</label><input type="text" name="q" value="<?= e($query); ?>" placeholder="müştəri kodu / ad / telefon / transaction"></div>
                    <div class="portal-form-row">
                        <label>Metod</label>
                        <select name="method">
                            <option value="">Bütün metodlar</option>
                            <?php foreach ($methods as $methodRow): ?>
                                <?php $methodName = (string) ($methodRow['payment_method'] ?? ''); ?>
                                <option value="<?= e($methodName); ?>" <?= $method === $methodName ? 'selected' : ''; ?>><?= e($methodName); ?> (<?= e((string) ($methodRow['payment_count'] ?? 0)); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row"><label>İlkin tarix</label><input type="date" name="start_date" value="<?= e($startDate); ?>"></div>
                    <div class="portal-form-row"><label>Son tarix</label><input type="date" name="end_date" value="<?= e($endDate); ?>"></div>
                    <div class="portal-form-row">
                        <label>Göstər</label>
                        <select name="per_page">
                            <?php foreach ([25, 50] as $size): ?>
                                <option value="<?= e((string) $size); ?>" <?= $perPage === $size ? 'selected' : ''; ?>><?= e((string) $size); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="billing-filter-actions">
                    <button type="submit" class="portal-primary-btn">Axtar</button>
                    <a class="portal-ghost-btn portal-inline-link" href="billing-payments.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Sıfırla</a>
                </div>
                <div class="billing-export-actions">
                    <button type="button" class="portal-ghost-btn">Kopyala</button>
                    <button type="button" class="portal-ghost-btn">Excel</button>
                    <button type="button" class="portal-ghost-btn">CSV</button>
                    <button type="button" class="portal-ghost-btn">Çap et</button>
                </div>
                <small data-auto-filter-status role="status" aria-live="polite"></small>
            </form>
        </section>

        <section class="mgs-data-card portal-reveal" data-auto-filter-results="billing-payments-results" data-auto-filter-form="billing-payments-filter">
            <div class="mgs-section-head"><div><h2>Ödəniş çıxarışı</h2><p><?= e((string) $summary['filtered_count']); ?> nəticədən <?= e((string) count($payments)); ?> qeyd göstərilir.</p></div></div>
            <?php if ($payments === []): ?>
                <?php billing_render_empty_state('Ödəniş tapılmadı', 'Axtarış və filtr şərtlərini dəyişib yenidən yoxlayın.', 'fa-receipt'); ?>
            <?php else: ?>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>№</th><th>Müştəri</th><th>Terminal/metod</th><th>Ödəniş</th><th>Transaction</th><th>Tarix</th><th>Profil</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= e((string) ($payment['id'] ?? '-')); ?></td>
                                <td><?= e((string) ($payment['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($payment['full_name'] ?? '-')); ?></small><br><small><?= e((string) ($payment['phone'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($payment['payment_method'] ?? '-')); ?></td>
                                <td><?= e(billing_money($payment['amount'] ?? 0)); ?></td>
                                <td><?= e((string) ($payment['transaction_id'] ?? '-')); ?></td>
                                <td><?= e(billing_datetime($payment['created_at'] ?? null)); ?></td>
                                <td><a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?id=<?= e((string) ($payment['account_id'] ?? 0)); ?>#profile-payments">Aç</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
                <nav class="portal-pagination" aria-label="Ödəniş səhifələri">
                    <?php
                    $prevParams = $queryBase + ['page' => max(1, $page - 1)];
                    $nextParams = $queryBase + ['page' => min($totalPages, $page + 1)];
                    ?>
                    <a class="portal-ghost-btn portal-inline-link<?= $page <= 1 ? ' portal-disabled-link' : ''; ?>" href="billing-payments.php?<?= e(http_build_query($prevParams)); ?>">Əvvəlki</a>
                    <span><?= e((string) $page); ?> / <?= e((string) $totalPages); ?></span>
                    <a class="portal-ghost-btn portal-inline-link<?= $page >= $totalPages ? ' portal-disabled-link' : ''; ?>" href="billing-payments.php?<?= e(http_build_query($nextParams)); ?>">Növbəti</a>
                </nav>
            <?php endif; ?>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
