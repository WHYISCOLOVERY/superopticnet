<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function customer_portal_flow_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function customer_portal_flow_render(array $customerSession): string
{
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/superopticnet/portal.php';
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/portal.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    app_session_boot();
    $_SESSION['portal_user'] = $customerSession;

    chdir(dirname(__DIR__));
    ob_start();
    include dirname(__DIR__) . '/portal.php';

    return (string) ob_get_clean();
}

$db = app_db();
if (!$db instanceof PDO) {
    customer_portal_flow_fail('Database unavailable', 2);
}

$suffix = date('His') . '-' . strtolower(bin2hex(random_bytes(2)));
$subscriberCode = 'SMOKE-PORTAL-' . $suffix;
$password = 'PortalSmoke!42';
$accountId = 0;
$ticketNo = 'SMK-' . date('His') . random_int(100, 999);

try {
    $payload = [
        'profile' => [
            'full_name' => 'Smoke Portal Customer',
            'category' => 'Ev',
            'subscriber_code' => $subscriberCode,
            'contract_no' => 'SO-MQ-' . $subscriberCode,
            'plan' => 'Smoke 100',
            'status' => 'Aktiv',
            'area' => 'Kurdexani',
            'address' => 'Smoke portal test unvani',
            'email' => 'smoke.portal@example.test',
            'phone' => '+994501234567',
        ],
        'traffic' => [
            'download_speed' => '100 Mbps',
            'upload_speed' => '25 Mbps',
            'download_used' => '12.50 GB',
            'upload_used' => '3.40 GB',
            'period' => 'Son 24 saat',
            'last_online' => '2026-05-29 07:05',
        ],
        'billing' => [
            'status' => 'Aktiv',
            'radius_status' => 'Aktiv',
            'tariff' => 'Smoke 100',
            'balance' => '0 AZN',
            'expires_at' => '2026-06-29 07:05:00',
            'days_left' => 31,
            'remaining_days_raw' => '31 gun',
            'monthly_total' => '25.00 AZN',
            'internet_monthly_fee' => '25.00 AZN',
        ],
        'payments' => [
            [
                'date' => '29.05.2026 07:05',
                'amount' => '25.00 AZN',
                'method' => 'manual-smoke',
                'status' => 'Tesdiqlendi',
            ],
        ],
        'statements' => [
            [
                'period' => '2026-05',
                'monthly_charge' => '25.00 AZN',
                'paid' => '25.00 AZN',
                'balance' => '0 AZN',
            ],
        ],
        'internet_services' => [
            [
                'service_type' => 'pppoe',
                'username' => $subscriberCode,
                'tariff' => 'Smoke 100',
                'status' => 'active',
                'ip_address' => '10.250.99.10',
                'expires_at' => '2026-06-29 07:05:00',
            ],
        ],
        'messages' => [],
    ];

    $account = app_db_upsert_account([
        'login' => $subscriberCode,
        'password' => $password,
        'role' => 'customer',
        'account_type' => 'customer',
        'full_name' => 'Smoke Portal Customer',
        'subscriber_code' => $subscriberCode,
        'contract_no' => 'SO-MQ-' . $subscriberCode,
        'email' => 'smoke.portal@example.test',
        'phone' => '+994501234567',
        'category' => 'Ev',
        'area' => 'Kurdexani',
        'address' => 'Smoke portal test unvani',
        'plan' => 'Smoke 100',
        'status' => 'Aktiv',
        'payload' => $payload,
        'is_active' => 1,
    ], false);

    if (!is_array($account) || (int) ($account['id'] ?? 0) <= 0) {
        customer_portal_flow_fail('Unable to create smoke customer', 3);
    }
    $accountId = (int) $account['id'];

    $loggedIn = app_portal_login('customer', $subscriberCode, $password);
    if (!is_array($loggedIn) || (string) ($loggedIn['subscriber_code'] ?? '') !== $subscriberCode) {
        customer_portal_flow_fail('Customer login failed', 4);
    }

    $newPassword = 'PortalSmoke!43';
    $passwordUpdated = app_portal_update_customer_password($loggedIn, $password, $newPassword, $newPassword);
    $oldPasswordLogin = app_portal_login('customer', $subscriberCode, $password);
    $newPasswordLogin = app_portal_login('customer', $subscriberCode, $newPassword);
    if (!$passwordUpdated || is_array($oldPasswordLogin) || !is_array($newPasswordLogin)) {
        customer_portal_flow_fail('Customer password change flow failed', 5);
    }

    $ticketInserted = app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'customer_account_id' => $accountId,
        'subscriber_code' => $subscriberCode,
        'contract_no' => 'SO-MQ-' . $subscriberCode,
        'customer_name' => 'Smoke Portal Customer',
        'phone' => '+994501234567',
        'address' => 'Smoke portal test unvani',
        'area' => 'Kurdexani',
        'subject' => 'Smoke portal muraciet',
        'message' => 'Smoke portal muraciet axini test edildi.',
        'status' => 'Aciqdir',
        'opened_at' => app_db_now(),
        'updated_at' => app_db_now(),
        'created_by_account_id' => $accountId,
        'created_by_role' => 'customer',
        'created_by_name' => 'Smoke Portal Customer',
        'telegram_message_ids_json' => '[]',
        'metadata_json' => app_db_json_encode(['smoke' => true]),
    ]);

    if (!$ticketInserted) {
        customer_portal_flow_fail('Unable to create smoke customer ticket', 5);
    }

    $html = customer_portal_flow_render(app_db_user_session_payload($account));
    $markers = [
        'Musteri paneli',
        $subscriberCode,
        'Smoke Portal Customer',
        'Download istifadesi',
        '12.50 GB',
        'Upload istifadesi',
        '3.40 GB',
        'Odenisler',
        '25.00 AZN',
        'Hesab cixarislari',
        'Muracietler',
        'Smoke portal muraciet',
        'Desteye yaz',
        'name="action" value="create_ticket"',
        'name="action" value="update_password"',
    ];

    $missing = [];
    foreach ($markers as $marker) {
        if (!str_contains($html, $marker)) {
            $missing[] = $marker;
        }
    }

    if ($missing !== []) {
        customer_portal_flow_fail('Customer portal missing markers: ' . implode(', ', $missing), 6);
    }

    echo json_encode([
        'status' => 'ok',
        'subscriber_code' => $subscriberCode,
        'checks' => [
            'customer_login' => true,
            'password_change_action' => true,
            'payments_visible' => true,
            'traffic_visible' => true,
            'statements_visible' => true,
            'tickets_visible' => true,
            'request_form_visible' => true,
            'password_change_form_visible' => true,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    if ($accountId > 0) {
        $ticketId = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
        $ticketId->execute(['ticket_no' => $ticketNo]);
        $id = (int) ($ticketId->fetchColumn() ?: 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id')->execute(['ticket_id' => $id]);
            $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => $id]);
        }
        $db->prepare('DELETE FROM billing_radius_services WHERE account_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM customer_ip_assignments WHERE customer_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM ip_assignment_history WHERE customer_id = :id')->execute(['id' => $accountId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}
