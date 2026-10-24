<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();

$accountId = (int) $user['id'];
$db = app_db();

// Fetch subscriber account details
$stmt = $db->prepare(
    "SELECT id, subscriber_code, contract_no, full_name, phone, plan, status, address, area, billing_debt_amount, created_at
     FROM portal_accounts
     WHERE id = ? LIMIT 1"
);
$stmt->execute([$accountId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    app_mobile_json_response(null, 404, 'Müştəri hesabı tapılmadı.');
}

// Fetch active RADIUS internet service
$stmtRad = $db->prepare(
    "SELECT username, ip_mode, framed_ip_address, status, mac_address, olt_name, pon_port
     FROM billing_radius_services
     WHERE customer_account_id = ?
     ORDER BY id DESC LIMIT 1"
);
$stmtRad->execute([$accountId]);
$radiusService = $stmtRad->fetch(PDO::FETCH_ASSOC);

// Check active online session in radacct
$isOnline = false;
$sessionIp = null;
if ($radiusService && !empty($radiusService['username'])) {
    $stmtAcct = $db->prepare(
        "SELECT framedipaddress, acctstarttime
         FROM radacct
         WHERE username = ? AND acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)
         ORDER BY radacctid DESC LIMIT 1"
    );
    $stmtAcct->execute([$radiusService['username']]);
    $onlineSession = $stmtAcct->fetch(PDO::FETCH_ASSOC);
    if ($onlineSession) {
        $isOnline = true;
        $sessionIp = $onlineSession['framedipaddress'];
    }
}

// Fetch open tickets count
$stmtTickets = $db->prepare(
    "SELECT COUNT(*) FROM portal_tickets
     WHERE customer_account_id = ? AND status IN ('open', 'assigned', 'in_progress')"
);
$stmtTickets->execute([$accountId]);
$openTicketsCount = (int) $stmtTickets->fetchColumn();

// Fetch latest payment
$stmtPay = $db->prepare(
    "SELECT amount, payment_method, created_at
     FROM billing_payments
     WHERE customer_account_id = ?
     ORDER BY id DESC LIMIT 1"
);
$stmtPay->execute([$accountId]);
$latestPayment = $stmtPay->fetch(PDO::FETCH_ASSOC);

$debt = (float) ($account['billing_debt_amount'] ?? 0.0);
$serviceStatus = $account['status'] ?? 'active';

app_mobile_json_response([
    'customer' => [
        'id' => (int) $account['id'],
        'subscriber_code' => $account['subscriber_code'],
        'contract_no' => $account['contract_no'],
        'name' => $account['full_name'],
        'phone' => $account['phone'],
        'address' => $account['address'],
        'area' => $account['area'],
    ],
    'service' => [
        'plan' => $account['plan'] ?? 'Fiber 100 Mbps',
        'status' => $serviceStatus, // active, suspended, blocked
        'is_online' => $isOnline,
        'session_ip' => $sessionIp,
        'has_debt' => $debt > 0,
        'debt_azn' => $debt,
    ],
    'billing' => [
        'latest_payment' => $latestPayment ? [
            'amount_azn' => (float) $latestPayment['amount'],
            'method' => $latestPayment['payment_method'],
            'date' => $latestPayment['created_at'],
        ] : null,
    ],
    'support' => [
        'open_tickets_count' => $openTicketsCount,
    ],
], 200);
