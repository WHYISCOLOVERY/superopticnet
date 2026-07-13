<?php
declare(strict_types=1);

require_once __DIR__ . '/../customer-intelligence-lib.php';

$account = [
    'id' => 77,
    'subscriber_code' => '110TEST',
    'full_name' => 'Smoke Customer',
    'status' => 'Aktiv',
    'is_active' => 1,
    'plan' => 'Smoke 30',
    'area' => 'Smoke area',
    'created_at' => date('Y-m-d H:i:s', strtotime('-390 days')),
    'payload_json' => json_encode([
        'billing' => [
            'monthly_fee' => '30 AZN',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ],
        'profile' => ['risk_score' => 2],
    ]),
];

$payments = [
    ['amount' => 30, 'created_at' => date('Y-m-d H:i:s', strtotime('-355 days'))],
    ['amount' => 30, 'created_at' => date('Y-m-d H:i:s', strtotime('-325 days'))],
    ['amount' => 30, 'created_at' => date('Y-m-d H:i:s', strtotime('-287 days'))],
    ['amount' => 30, 'created_at' => date('Y-m-d H:i:s', strtotime('-250 days'))],
];

$sessions = [];
for ($day = 0; $day < 120; $day++) {
    if ($day % 5 === 0) {
        continue;
    }
    $sessions[] = [
        'acctstarttime' => date('Y-m-d 10:00:00', strtotime('-' . $day . ' days')),
        'acctstoptime' => date('Y-m-d 12:00:00', strtotime('-' . $day . ' days')),
        'acctinputoctets' => 512 * 1024 * 1024,
        'acctoutputoctets' => 2 * 1024 * 1024 * 1024,
    ];
}

$tickets = [
    ['status' => 'Baglandi', 'opened_at' => date('Y-m-d H:i:s', strtotime('-20 days'))],
    ['status' => 'Aciqdir', 'opened_at' => date('Y-m-d H:i:s', strtotime('-2 days'))],
];

$result = app_customer_activity_intelligence_from_rows($account, $payments, $sessions, $tickets);

$errors = [];
foreach (['activity_score', 'payment_score', 'active_days_year', 'inactive_days_year', 'current_inactive_days', 'revenue_loss', 'churn_risk', 'ai_summary', 'monthly_timeline'] as $key) {
    if (!array_key_exists($key, $result)) {
        $errors[] = 'missing_' . $key;
    }
}
if (($result['activity_score'] ?? -1) < 0 || ($result['activity_score'] ?? 101) > 100) {
    $errors[] = 'activity_score_range';
}
if (($result['payment_score'] ?? -1) < 0 || ($result['payment_score'] ?? 101) > 100) {
    $errors[] = 'payment_score_range';
}
if (($result['current_inactive_days'] ?? -1) !== 0) {
    $errors[] = 'active_customer_current_inactive_not_zero';
}
if (count($result['monthly_timeline'] ?? []) !== 12) {
    $errors[] = 'timeline_not_12_months';
}

$traffic = app_traffic_monetization_from_rows($sessions, 1000.0);
foreach (['average_mbps', 'peak_mbps', 'unused_capacity_percent', 'recommended_packages', 'projected_monthly_revenue', 'executive_insight'] as $key) {
    if (!array_key_exists($key, $traffic)) {
        $errors[] = 'traffic_missing_' . $key;
    }
}
if (($traffic['unused_capacity_percent'] ?? -1) < 0 || ($traffic['unused_capacity_percent'] ?? 101) > 100) {
    $errors[] = 'traffic_unused_range';
}
if (($traffic['recommended_packages'] ?? []) === []) {
    $errors[] = 'traffic_no_recommendations';
}

if ($errors !== []) {
    fwrite(STDERR, "customer intelligence smoke failed: " . implode(', ', $errors) . PHP_EOL);
    exit(1);
}

echo "customer intelligence smoke passed\n";
echo json_encode([
    'activity_score' => $result['activity_score'],
    'payment_score' => $result['payment_score'],
    'current_inactive_days' => $result['current_inactive_days'],
    'revenue_loss' => $result['revenue_loss'],
    'churn_risk' => $result['churn_risk'],
    'unused_capacity_percent' => $traffic['unused_capacity_percent'],
    'projected_monthly_revenue' => $traffic['projected_monthly_revenue'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
