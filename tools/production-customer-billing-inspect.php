<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/config.php';
$code = trim((string) ($argv[1] ?? ''));
if ($code === '') exit(2);
$account = app_db_account_by_subscriber_code($code);
if (!$account) exit(3);
$payload = app_db_account_payload($account);
$billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
$db = app_db();
$tariff = $db instanceof PDO ? app_billing_account_tariff($db, $account, $billing) : null;
echo json_encode([
    'subscriber_code' => $code,
    'account_status' => $account['status'] ?? null,
    'is_active' => (int) ($account['is_active'] ?? 0),
    'plan' => $account['plan'] ?? null,
    'billing' => array_intersect_key($billing, array_flip(['balance','balance_amount','monthly_total','monthly_fee','internet_monthly_fee','debt_raw','status','expires_at','days_left','remaining_days_raw','default_days'])),
    'tariff' => is_array($tariff) ? array_intersect_key($tariff, array_flip(['id','name','monthly_fee','default_days'])) : null,
    'services' => array_map(static fn($s) => array_intersect_key((array) $s, array_flip(['username','status','expires_at','tariff'])), (array) ($payload['internet_services'] ?? [])),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
