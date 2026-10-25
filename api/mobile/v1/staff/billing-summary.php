<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, [
    'super_admin', 'admin', 'manager', 'director',
    'operator', 'engineer', 'noc', 'network_engineer',
]);

$db = app_db();

$scalar = static function (PDO $db, string $sql): int {
    try {
        return (int) $db->query($sql)->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
};

$totalCustomers = $scalar($db,
    "SELECT COUNT(*) FROM portal_accounts WHERE role='customer'"
);
$deactivatedCustomers = $scalar($db,
    "SELECT COUNT(*)
       FROM portal_accounts
      WHERE role='customer'
        AND (
            is_active=0
            OR LOWER(TRIM(COALESCE(status,''))) IN (
                'inactive','deactivated','disabled','blocked','suspended',
                'deaktiv','bloklanib','bloklanıb'
            )
        )"
);
$paidCustomers = $scalar($db,
    "SELECT COUNT(DISTINCT account_id)
       FROM billing_payments
      WHERE account_id IS NOT NULL
        AND amount > 0
        AND created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
        AND created_at < DATE_ADD(LAST_DAY(CURRENT_DATE()), INTERVAL 1 DAY)"
);
$onlineCustomers = $scalar($db,
    "SELECT COUNT(DISTINCT r.username)
       FROM radacct r
      WHERE r.acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)
        AND COALESCE(r.acctupdatetime, r.acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)"
);

app_mobile_json_response([
    'metrics' => [
        'total_customers' => $totalCustomers,
        'paid_customers' => $paidCustomers,
        'deactivated_customers' => $deactivatedCustomers,
        'online_customers' => $onlineCustomers,
    ],
    'period' => [
        'key' => 'current_calendar_month',
        'starts_at' => date('Y-m-01 00:00:00'),
        'ends_at' => date('Y-m-t 23:59:59'),
    ],
    'definitions' => [
        'paid_customers' => 'Cari təqvim ayında ən azı bir müsbət ödənişi olan unikal müştərilər.',
        'deactivated_customers' => 'Hesabı deaktiv, bloklanmış və ya dayandırılmış müştərilər.',
        'online_customers' => 'Son 20 dəqiqədə RADIUS accounting yeniləməsi alınan unikal müştərilər. Aktiv abunə sayı ilə eyni göstərici deyil.',
    ],
    'generated_at' => date(DATE_ATOM),
], 200);
