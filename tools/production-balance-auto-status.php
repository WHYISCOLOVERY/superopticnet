<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/config.php';
$db = app_db();
$account = app_db_account_by_subscriber_code('3001455');
$payload = is_array($account) ? app_db_account_payload($account) : [];
$billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
$latest = $db instanceof PDO ? $db->query('SELECT summary_json,finished_at FROM billing_maintenance_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) : [];
echo json_encode([
 'account' => ['status' => $account['status'] ?? null, 'is_active' => $account['is_active'] ?? null, 'balance' => $billing['balance'] ?? null, 'expires_at' => $billing['expires_at'] ?? null],
 'latest_run' => $latest,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
