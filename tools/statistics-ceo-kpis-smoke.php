<?php
declare(strict_types=1);

require dirname(__DIR__) . '/statistics-center-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// NOTE: PHP does not run try{}finally{} blocks after exit()/die() inside the
// try body -- every failure path below throws instead of exiting directly.
class StatisticsCeoKpisSmokeFailure extends RuntimeException
{
}

function statistics_ceo_kpis_smoke_fail(string $message, int $code = 1): never
{
    throw new StatisticsCeoKpisSmokeFailure($message, $code);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, 'db_unavailable' . PHP_EOL);
    exit(2);
}

$run = date('His') . random_int(100, 999);
$tariffIds = [];
$accountIds = [];
$serviceIds = [];
$paymentIds = [];

$cleanup = function () use ($db, &$tariffIds, &$accountIds, &$serviceIds, &$paymentIds): void {
    foreach ($paymentIds as $id) {
        $db->prepare('DELETE FROM billing_payments WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($serviceIds as $id) {
        $db->prepare('DELETE FROM billing_radius_services WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($accountIds as $id) {
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($tariffIds as $id) {
        $db->prepare('DELETE FROM billing_tariffs WHERE id = :id')->execute(['id' => $id]);
    }
};

try {
    // --- Set up two tariffs with known monthly fees ---
    $db->prepare(
        'INSERT INTO billing_tariffs (name, service_type, download_mbps, upload_mbps, monthly_fee, radius_group, status)
         VALUES (:name, "pppoe", 100, 100, :fee, :radius_group, "active")'
    )->execute(['name' => 'Smoke Tariff A ' . $run, 'fee' => 25.00, 'radius_group' => 'smoke-a-' . $run]);
    $tariffAId = (int) $db->lastInsertId();
    $tariffIds[] = $tariffAId;

    $db->prepare(
        'INSERT INTO billing_tariffs (name, service_type, download_mbps, upload_mbps, monthly_fee, radius_group, status)
         VALUES (:name, "pppoe", 200, 200, :fee, :radius_group, "active")'
    )->execute(['name' => 'Smoke Tariff B ' . $run, 'fee' => 40.00, 'radius_group' => 'smoke-b-' . $run]);
    $tariffBId = (int) $db->lastInsertId();
    $tariffIds[] = $tariffBId;

    // --- Baseline MRR/ARPU BEFORE our inserts (real dev-DB data may already
    //     have active services; we assert on the DELTA our own data causes,
    //     not an absolute value). ---
    $before = app_statistics_ceo_kpis($db, ['start_at' => '2001-01-01 00:00:00', 'operational_end_at_exclusive' => '2001-02-01 00:00:00']);
    $mrrBefore = (float) $before['mrr'];
    $payingBefore = (int) $before['paying_customers'];

    // --- Three customers: two with an ACTIVE service (one per tariff), one
    //     whose service EXPIRED inside our test window (churn signal). ---
    $makeCustomer = function (string $suffix, string $area) use ($db, &$accountIds, $run): int {
        $saved = app_db_upsert_account([
            'login' => 'smoke-ceo-kpi-' . strtolower($run) . '-' . $suffix . '@qa.local',
            'password' => 'SmokePass123',
            'role' => 'customer',
            'account_type' => 'customer',
            'full_name' => 'CEO KPI Smoke Customer ' . $suffix,
            'subscriber_code' => 'CEOKPI-' . strtoupper($run) . '-' . $suffix,
            'area' => $area,
            'status' => 'Aktiv',
            'is_active' => 1,
        ], false);
        if (!is_array($saved)) {
            statistics_ceo_kpis_smoke_fail('customer_seed_failed_' . $suffix);
        }
        $id = (int) $saved['id'];
        $accountIds[] = $id;
        return $id;
    };

    $customerA = $makeCustomer('a', 'SmokeAreaNorth');
    $customerB = $makeCustomer('b', 'SmokeAreaSouth');
    $customerChurned = $makeCustomer('c', 'SmokeAreaNorth');

    $insertService = function (int $accountId, int $tariffId, string $status, string $updatedAt) use ($db, $run, &$serviceIds): int {
        $db->prepare(
            'INSERT INTO billing_radius_services (account_id, username, service_type, tariff_id, status, created_at, updated_at)
             VALUES (:account_id, :username, "pppoe", :tariff_id, :status, :updated_at, :updated_at)'
        )->execute([
            'account_id' => $accountId,
            'username' => 'smoke-ceo-kpi-' . $run . '-' . $accountId,
            'tariff_id' => $tariffId,
            'status' => $status,
            'updated_at' => $updatedAt,
        ]);
        $id = (int) $db->lastInsertId();
        $serviceIds[] = $id;
        return $id;
    };

    $insertService($customerA, $tariffAId, 'active', date('Y-m-d H:i:s'));
    $insertService($customerB, $tariffBId, 'active', date('Y-m-d H:i:s'));
    // This one was active before our test window and expired INSIDE it --
    // counts toward "active at period start" AND toward "churned this period".
    $insertService($customerChurned, $tariffAId, 'expired', '2001-01-15 10:00:00');

    // --- Revenue-by-area: two payments in the 2001 test window on two
    //     different areas. ---
    $insertPayment = function (int $accountId, float $amount, string $createdAt) use ($db, &$paymentIds): int {
        $db->prepare(
            'INSERT INTO billing_payments (account_id, amount, payment_method, created_at)
             VALUES (:account_id, :amount, "smoke", :created_at)'
        )->execute(['account_id' => $accountId, 'amount' => $amount, 'created_at' => $createdAt]);
        $id = (int) $db->lastInsertId();
        $paymentIds[] = $id;
        return $id;
    };
    $insertPayment($customerA, 25.00, '2001-01-10 09:00:00');
    $insertPayment($customerB, 40.00, '2001-01-12 09:00:00');

    // --- Assertions ---
    $after = app_statistics_ceo_kpis($db, ['start_at' => '2001-01-01 00:00:00', 'operational_end_at_exclusive' => '2001-02-01 00:00:00']);

    $mrrDelta = round((float) $after['mrr'] - $mrrBefore, 2);
    if ($mrrDelta !== 65.00) {
        statistics_ceo_kpis_smoke_fail('mrr_delta_wrong:' . $mrrDelta . ' (expected 65.00 = 25+40 active services)');
    }
    $payingDelta = (int) $after['paying_customers'] - $payingBefore;
    if ($payingDelta !== 2) {
        statistics_ceo_kpis_smoke_fail('paying_customers_delta_wrong:' . $payingDelta . ' (expected 2, the churned one is not active)');
    }

    // Churn: 3 accounts were "active at period start" (2 active + 1 that
    // expired inside the window), 1 of those churned inside the window.
    if ((int) $after['churned_customers'] < 1) {
        statistics_ceo_kpis_smoke_fail('churned_customers_missing_our_test_row');
    }

    $revenueByArea = (array) ($after['revenue_by_area'] ?? []);
    $northRow = null;
    $southRow = null;
    foreach ($revenueByArea as $row) {
        if ((string) ($row['label'] ?? '') === 'SmokeAreaNorth') {
            $northRow = $row;
        }
        if ((string) ($row['label'] ?? '') === 'SmokeAreaSouth') {
            $southRow = $row;
        }
    }
    if (!is_array($northRow) || abs((float) $northRow['value'] - 25.00) > 0.01) {
        statistics_ceo_kpis_smoke_fail('revenue_by_area_north_wrong:' . json_encode($northRow, JSON_UNESCAPED_SLASHES));
    }
    if (!is_array($southRow) || abs((float) $southRow['value'] - 40.00) > 0.01) {
        statistics_ceo_kpis_smoke_fail('revenue_by_area_south_wrong:' . json_encode($southRow, JSON_UNESCAPED_SLASHES));
    }

    // ARPU sanity: must equal mrr / paying_customers on the full (not delta)
    // figures returned by the function itself.
    $expectedArpu = (float) $after['paying_customers'] > 0 ? (float) $after['mrr'] / (float) $after['paying_customers'] : 0.0;
    if (abs((float) $after['arpu'] - $expectedArpu) > 0.01) {
        statistics_ceo_kpis_smoke_fail('arpu_does_not_match_mrr_over_paying_customers');
    }

    // Non-admin context builder must never call this (context-level check,
    // not this function directly) -- verified via source marker instead of a
    // live HTTP request, matching the smoke-test pattern used elsewhere.
    $source = (string) file_get_contents(__DIR__ . '/../statistics-center-lib.php');
    if (!str_contains($source, "'ceo_kpis' => \$isAdmin ? app_statistics_ceo_kpis(\$db, \$dates) : []")) {
        statistics_ceo_kpis_smoke_fail('ceo_kpis_not_admin_gated_in_context_builder');
    }

    $cleanup();

    echo json_encode([
        'status' => 'passed',
        'mrr_delta' => $mrrDelta,
        'paying_delta' => $payingDelta,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $cleanup();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() ?: 1);
}
