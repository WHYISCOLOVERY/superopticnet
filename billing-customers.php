<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-module-lib.php';
require_once __DIR__ . '/customer-intelligence-lib.php';
require_once __DIR__ . '/billing-customer-identity-lib.php';

$lang = app_lang();
$user = billing_require_employee();

$db = app_db();
app_billing_ensure_identity_schema($db);
$flash = null;
$billingCustomerPerfEnabled = PHP_SAPI === 'cli' && isset($_GET['perf']);
$billingCustomerPerfStart = microtime(true);
$billingCustomerPerfMarks = [];

function billing_customer_perf_mark(bool $enabled, array &$marks, string $label, float $startedAt): void
{
    if (!$enabled) {
        return;
    }

    $marks[] = [
        'label' => $label,
        'ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ];
}

billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'boot', $billingCustomerPerfStart);

function billing_customer_random_password(int $length = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

function billing_customer_service_map(PDO $db, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
    if ($accountIds === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($accountIds as $index => $accountId) {
        $key = 'account_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $accountId;
    }

    $statement = $db->prepare(
        'SELECT s.account_id,
                s.username,
                s.service_type,
                s.assigned_ip,
                s.nat_public_ip,
                s.static_ip,
                s.status AS radius_status,
                s.expires_at,
                t.name AS tariff_name
         FROM billing_radius_services s
         LEFT JOIN billing_tariffs t ON t.id = s.tariff_id
         WHERE s.account_id IN (' . implode(',', $placeholders) . ')
         ORDER BY s.account_id,
                  FIELD(s.status, "active", "suspended", "expired", "disabled"),
                  s.id DESC'
    );
    $statement->execute($params);

    $serviceMap = [];
    foreach (($statement->fetchAll() ?: []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $accountId = (int) ($row['account_id'] ?? 0);
        if ($accountId <= 0 || isset($serviceMap[$accountId])) {
            continue;
        }

        $serviceMap[$accountId] = $row;
    }

    return $serviceMap;
}

function billing_customer_online_usernames(PDO $db, array $usernames): array
{
    $usernames = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $usernames)))));
    if ($usernames === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($usernames as $index => $username) {
        $key = 'username_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $username;
    }

    $statement = $db->prepare(
        'SELECT DISTINCT username
         FROM radacct
         WHERE username IN (' . implode(',', $placeholders) . ')
           AND acctstoptime IS NULL'
    );
    $statement->execute($params);

    $online = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $username) {
        $online[strtolower((string) $username)] = true;
    }

    return $online;
}

function billing_customer_sync_legacy_pools_if_needed(PDO $db): void
{
    $legacyPoolCount = (int) $db->query('SELECT COUNT(*) FROM billing_ip_pools WHERE status = "active"')->fetchColumn();
    $bridgePoolCount = (int) $db->query('SELECT COUNT(*) FROM ip_pools WHERE legacy_billing_pool_id IS NOT NULL')->fetchColumn();
    if ($legacyPoolCount > $bridgePoolCount) {
        app_ip_sync_legacy_pools($db);
    }
}

function billing_customer_pool_next_ip_map(PDO $db): array
{
    $statement = $db->query(
        'SELECT p.legacy_billing_pool_id, a.ip_address
         FROM ip_addresses a
         INNER JOIN ip_pools p ON p.id = a.pool_id
         WHERE a.status = "free"
           AND p.status = "active"
           AND p.legacy_billing_pool_id IS NOT NULL
         ORDER BY p.legacy_billing_pool_id, a.ip_num
         LIMIT 1000'
    );

    $nextIps = [];
    foreach (($statement !== false ? $statement->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $legacyPoolId = (int) ($row['legacy_billing_pool_id'] ?? 0);
        $ip = trim((string) ($row['ip_address'] ?? ''));
        if ($legacyPoolId > 0 && $ip !== '' && !isset($nextIps[$legacyPoolId])) {
            $nextIps[$legacyPoolId] = $ip;
        }
    }

    return $nextIps;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($db instanceof PDO && clean_text((string) ($_POST['action'] ?? '')) === 'refresh_customer_radius_session') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $username = clean_text((string) ($_POST['username'] ?? ''));
        $accountForRefresh = $accountId > 0 ? app_db_account_by_id($accountId) : null;
        if ($accountForRefresh === null || (string) ($accountForRefresh['role'] ?? '') !== 'customer') {
            $flash = ['type' => 'error', 'message' => 'Musteri tapilmadi. Yenileme icra olunmadi.'];
        } else {
            if ($username === '') {
                $payloadForRefresh = app_db_account_payload($accountForRefresh);
                $billingForRefresh = is_array($payloadForRefresh['billing'] ?? null) ? $payloadForRefresh['billing'] : [];
                $servicesForRefresh = is_array($payloadForRefresh['internet_services'] ?? null)
                    ? array_values(array_filter($payloadForRefresh['internet_services'], 'is_array'))
                    : [];
                $username = trim((string) ($servicesForRefresh[0]['username'] ?? $billingForRefresh['pppoe_username'] ?? $accountForRefresh['subscriber_code'] ?? ''));
            }

            $synced = app_billing_sync_radius_account($accountForRefresh);
            $session = ['account_id' => $accountId, 'username' => $username];
            if ($username !== '') {
                $sessionStatement = $db->prepare(
                    'SELECT username, framedipaddress, nasipaddress, nasportid, callingstationid
                     FROM radacct
                     WHERE username = :username AND acctstoptime IS NULL
                     ORDER BY COALESCE(acctupdatetime, acctstarttime) DESC, radacctid DESC
                     LIMIT 1'
                );
                $sessionStatement->execute(['username' => $username]);
                $latestSession = $sessionStatement->fetch(PDO::FETCH_ASSOC);
                if (is_array($latestSession)) {
                    $session += $latestSession;
                }
            }

            $requestId = $username !== ''
                ? app_radius_request_disconnect($db, $session, $user, 'Customer refresh from billing list')
                : 0;
            $flash = $synced && $requestId > 0
                ? ['type' => 'success', 'message' => 'Yenileme hazirdir: RADIUS sync edildi ve live disconnect queue #' . $requestId . ' yaradildi.']
                : ['type' => 'warning', 'message' => 'RADIUS sync yoxlandi, amma online sessiya/username tapilmadi.'];
        }
    } elseif ($db instanceof PDO && clean_text((string) ($_POST['action'] ?? '')) === 'create_billing_customer') {
        $selectedArea = clean_text((string) ($_POST['area'] ?? ''));
        $subscriberCode = clean_text((string) ($_POST['subscriber_code'] ?? ''));
        $subscriberCodeAuto = clean_text((string) ($_POST['subscriber_code_auto'] ?? '1')) === '1';
        if (($subscriberCode === '' || $subscriberCodeAuto) && $selectedArea !== '') {
            $subscriberCode = app_db_resolve_customer_code_for_area($db, $selectedArea, $subscriberCode, $subscriberCodeAuto);
        }
        $fullName = clean_text((string) ($_POST['full_name'] ?? ''));
        $serviceType = clean_text((string) ($_POST['service_type'] ?? 'pppoe'));
        $serviceType = $serviceType === 'ipoe' ? 'ipoe' : 'pppoe';
        $tariffId = (int) ($_POST['tariff_id'] ?? 0);
        $poolId = (int) ($_POST['pool_id'] ?? 0);
        $password = trim((string) ($_POST['pppoe_password'] ?? ''));
        if ($password === '' && $serviceType === 'pppoe') {
            $password = billing_customer_random_password();
        }

        $tariffStatement = $db->prepare('SELECT * FROM billing_tariffs WHERE id = :id LIMIT 1');
        $tariffStatement->execute(['id' => $tariffId]);
        $tariff = $tariffStatement->fetch();
        $tariffValidationError = null;
        if (is_array($tariff) && strtolower((string) ($tariff['service_type'] ?? '')) !== $serviceType) {
            $tariffValidationError = 'Secilen tarif ' . strtoupper($serviceType) . ' xidmetine aid deyil.';
        }
        $billingDays = app_billing_account_default_days([], [], is_array($tariff) ? $tariff : null);
        $postedBillingDays = (int) ($_POST['billing_days'] ?? 0);
        if ($postedBillingDays > 0) {
            $billingDays = max(1, min(366, $postedBillingDays));
        }
        $postedStableDueDay = max(0, (int) ($_POST['stable_due_day'] ?? 0));
        $stableDueDay = $postedStableDueDay >= 1 && $postedStableDueDay <= 31 ? $postedStableDueDay : 0;

        $poolStatement = $db->prepare('SELECT * FROM billing_ip_pools WHERE id = :id LIMIT 1');
        $poolStatement->execute(['id' => $poolId]);
        $pool = $poolStatement->fetch();
        app_ip_sync_legacy_pools($db);
        $poolValidationError = null;
        if (is_array($pool)) {
            $poolServiceType = strtolower((string) ($pool['service_type'] ?? ''));
            $poolKind = strtolower((string) ($pool['pool_type'] ?? 'dynamic'));
            if ($poolServiceType !== $serviceType) {
                $poolValidationError = 'Secilen IP pool ' . strtoupper($serviceType) . ' xidmetine aid deyil.';
            } elseif ($poolKind === 'static') {
                $poolValidationError = 'Esas IP pool dinamik/local olmalidir. Static IP ucun ayrica Static IP pool ve Static IP xanasindan istifade edin.';
            }
        }

        $assignedIp = clean_text((string) ($_POST['assigned_ip'] ?? ''));
        $natPublicIp = clean_text((string) ($_POST['nat_public_ip'] ?? ''));
        $staticIp = clean_text((string) ($_POST['static_ip'] ?? ''));
        $autoAssignedIp = false;
        if ($assignedIp === '' && $staticIp === '' && $poolId > 0) {
            $assignedIp = app_billing_next_available_ip($poolId) ?? '';
            $autoAssignedIp = $assignedIp !== '';
        }
        $ipAllocationError = null;
        if ($assignedIp === '' && $staticIp === '' && $poolValidationError === null && is_array($pool)) {
            $ipAllocationError = 'Secilen IP poolda bos IP tapilmadi. Basqa pool secin, IP poolu genisledin ve ya static IP secin.';
        }

        $expiresAt = clean_text((string) ($_POST['expires_at'] ?? ''));
        if ($expiresAt === '') {
            $expiresAt = app_billing_resolve_next_expires_at($billingDays, $stableDueDay, null, time());
        } else {
            $expiresTimestamp = strtotime($expiresAt);
            $expiresAt = $expiresTimestamp === false
                ? app_billing_resolve_next_expires_at($billingDays, $stableDueDay, null, time())
                : date('Y-m-d H:i:s', $expiresTimestamp);
        }

        $ipValidationError = app_ip_validate_customer_ips($db, $assignedIp, $natPublicIp, $staticIp, 0);

        $existingCustomer = $subscriberCode !== '' ? app_db_account_by_subscriber_code($subscriberCode) : null;
        $identityValidation = app_billing_validate_customer_identity($db, $_POST);

        if (empty($identityValidation['ok'])) {
            $flash = ['type' => 'error', 'message' => ($identityValidation['error'] ?? '') === 'duplicate_fin' ? 'Bu FIN kod aktiv musteride movcuddur.' : 'FIN, sexsiyyet vesiqesi, mobil nomre ve unvan mutleq doldurulmalidir.'];
        } elseif ($subscriberCode === '' || $fullName === '' || !is_array($tariff) || !is_array($pool)) {
            $flash = ['type' => 'error', 'message' => 'Musteri kodu, ad, tarif ve pool mutleq doldurulmalidir. Erazi secilibse kod avtomatik verilmelidir.'];
        } elseif (is_array($existingCustomer)) {
            $flash = ['type' => 'error', 'message' => 'Bu musteri kodu artiq movcuddur: ' . $subscriberCode . '. Erazi kod prefixini ve novbeti kodu yoxlayin.'];
        } elseif ($tariffValidationError !== null) {
            $flash = ['type' => 'error', 'message' => $tariffValidationError];
        } elseif ($poolValidationError !== null) {
            $flash = ['type' => 'error', 'message' => $poolValidationError];
        } elseif ($ipAllocationError !== null) {
            $flash = ['type' => 'error', 'message' => $ipAllocationError];
        } elseif (is_array($ipValidationError)) {
            $flash = ['type' => 'error', 'message' => (string) ($ipValidationError['message'] ?? 'IP validation xetasi')];
        } else {
            $mac = strtoupper(clean_text((string) ($_POST['mac_address'] ?? '')));
            $oltName = clean_text((string) ($_POST['olt_name'] ?? ''));
            $pon = clean_text((string) ($_POST['pon_port'] ?? ''));
            $ontId = clean_text((string) ($_POST['ont_id'] ?? ''));
            $vlan = clean_text((string) ($_POST['vlan'] ?? ''));
            $priorityScoreRaw = trim((string) ($_POST['priority_score'] ?? ''));
            $riskScoreRaw = trim((string) ($_POST['risk_score'] ?? ''));
            $priorityScore = $priorityScoreRaw === '' ? 5 : max(1, min(10, (int) $priorityScoreRaw));
            $riskScore = $riskScoreRaw === '' ? 5 : max(1, min(10, (int) $riskScoreRaw));

            $payload = [
                'profile' => [
                    'full_name' => $fullName,
                    'subscriber_code' => $subscriberCode,
                    'contract_no' => clean_text((string) ($_POST['contract_no'] ?? '')) ?: $subscriberCode,
                    'category' => clean_text((string) ($_POST['category'] ?? 'Residential')),
                    'area' => $selectedArea,
                    'address' => clean_text((string) ($_POST['address'] ?? '')),
                    'phone' => clean_text((string) ($_POST['phone'] ?? '')),
                    'fin_code' => (string) ($identityValidation['data']['fin_code'] ?? ''),
                    'document_no' => (string) ($identityValidation['data']['document_no'] ?? ''),
                    'email' => clean_text((string) ($_POST['email'] ?? '')),
                    'plan' => (string) ($tariff['name'] ?? ''),
                    'status' => 'Aktiv',
                    'priority_score' => $priorityScore,
                    'risk_score' => $riskScore,
                ],
                'billing' => [
                    'service_type' => $serviceType,
                    'pppoe_username' => clean_text((string) ($_POST['radius_username'] ?? $subscriberCode)),
                    'pppoe_password' => $password,
                    'customer_code' => $subscriberCode,
                    'tariff' => (string) ($tariff['name'] ?? ''),
                    'internet_monthly_fee' => number_format((float) ($tariff['monthly_fee'] ?? 0), 2, '.', '') . ' AZN',
                    'extra_services_total' => '0.00 AZN',
                    'monthly_total' => number_format((float) ($tariff['monthly_fee'] ?? 0), 2, '.', '') . ' AZN',
                    'monthly_fee' => number_format((float) ($tariff['monthly_fee'] ?? 0), 2, '.', '') . ' AZN',
                    'balance' => '0 AZN',
                    'status' => 'Aktiv',
                    'radius_status' => 'Aktiv',
                    'pool' => (string) ($pool['name'] ?? ''),
                    'zone' => $selectedArea,
                    'ip_address' => $assignedIp,
                    'assigned_ip' => $assignedIp,
                    'nat_public_ip' => $natPublicIp,
                    'static_ip' => $staticIp,
                    'ip_assignment_mode' => $autoAssignedIp ? 'pool' : (($assignedIp === '' && $natPublicIp === '' && $staticIp === '') ? 'pool' : 'manual'),
                    'expires_at' => $expiresAt,
                    'default_days' => $billingDays,
                    'billing_days' => $billingDays,
                    'days_left' => app_billing_days_left($expiresAt),
                    'remaining_days_raw' => app_billing_remaining_days_text($expiresAt),
                ],
                'internet_services' => [[
                    'service_type' => $serviceType,
                    'username' => clean_text((string) ($_POST['radius_username'] ?? $subscriberCode)),
                    'password' => $password,
                    'tariff' => (string) ($tariff['name'] ?? ''),
                    'pool' => (string) ($pool['name'] ?? ''),
                    'ip_address' => $staticIp !== '' ? $staticIp : $assignedIp,
                    'assigned_ip' => $assignedIp,
                    'nat_public_ip' => $natPublicIp,
                    'static_ip' => $staticIp,
                    'ip_assignment_mode' => $autoAssignedIp ? 'pool' : (($assignedIp === '' && $natPublicIp === '' && $staticIp === '') ? 'pool' : 'manual'),
                    'vlan' => $vlan,
                    'mac' => $mac,
                    'status' => 'active',
                    'expires_at' => $expiresAt,
                    'default_days' => $billingDays,
                ]],
                'ont_profiles' => [[
                    'olt' => $oltName,
                    'pon' => $pon,
                    'ont_id' => $ontId,
                    'serial' => clean_text((string) ($_POST['ont_serial'] ?? '')),
                    'mac' => $mac,
                    'temperature' => '-',
                    'status' => 'unknown',
                ]],
                'traffic' => [
                    'download_speed' => (string) ($tariff['download_mbps'] ?? 0) . ' Mb/s',
                    'upload_speed' => (string) ($tariff['upload_mbps'] ?? 0) . ' Mb/s',
                    'download_used' => '0 GB',
                    'upload_used' => '0 GB',
                    'period' => 'Aktiv sessiya',
                    'last_online' => '-',
                ],
                'payments' => [],
                'statements' => [],
                'tickets' => [],
                'extra_services' => [],
            ];

            if ($stableDueDay > 0) {
                $payload['profile']['stable_due_day'] = $stableDueDay;
                $payload['billing']['stable_due_day'] = $stableDueDay;
                $payload['internet_services'][0]['stable_due_day'] = $stableDueDay;
            }

            $saveResult = app_billing_upsert_customer_with_identity($db, [
                'login' => $subscriberCode,
                'password' => trim((string) ($_POST['portal_password'] ?? '')) ?: billing_customer_random_password(10),
                'role' => 'customer',
                'account_type' => 'customer',
                'full_name' => $fullName,
                'subscriber_code' => $subscriberCode,
                'contract_no' => clean_text((string) ($_POST['contract_no'] ?? '')) ?: $subscriberCode,
                'email' => $_POST['email'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'category' => $_POST['category'] ?? 'Residential',
                'area' => $selectedArea,
                'address' => $_POST['address'] ?? '',
                'plan' => (string) ($tariff['name'] ?? ''),
                'status' => 'Aktiv',
                'is_active' => 1,
                'payload' => $payload,
            ], $_POST, $user, $password);
            $saved = !empty($saveResult['ok']) && is_array($saveResult['account'] ?? null) ? $saveResult['account'] : null;
            $synced = $saved !== null && !empty($saveResult['radius_synced']);
            $flash = [
                'type' => $synced ? 'success' : 'error',
                'message' => $synced
                    ? 'Musteri yaradildi ve Radius SQL cedvellerine yazildi. PPPoE sifre: ' . ($password !== '' ? $password : 'IPoE')
                    : 'Musteri saxlanmadi ve ya Radius sync alinmadi.',
            ];
        }
    }
}

$openCreateModal = clean_text((string) ($_GET['create'] ?? '')) === '1'
    || ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
        && clean_text((string) ($_POST['action'] ?? '')) === 'create_billing_customer'
        && is_array($flash)
        && (string) ($flash['type'] ?? '') !== 'success');

$tariffs = [];
$pools = [];
if ($openCreateModal) {
    $tariffs = $db instanceof PDO ? ($db->query('SELECT * FROM billing_tariffs WHERE status = "active" ORDER BY service_type, download_mbps')->fetchAll() ?: []) : [
        ['id' => 0, 'name' => 'Demo PPPoE 500 Mb/s', 'service_type' => 'pppoe', 'download_mbps' => 500, 'upload_mbps' => 250, 'monthly_fee' => 46],
        ['id' => 0, 'name' => 'Demo IPoE 1000 Mb/s', 'service_type' => 'ipoe', 'download_mbps' => 1000, 'upload_mbps' => 1000, 'monthly_fee' => 120],
    ];
    $pools = $db instanceof PDO ? ($db->query('SELECT * FROM billing_ip_pools WHERE status = "active" ORDER BY service_type, pool_type, name')->fetchAll() ?: []) : [
        ['id' => 0, 'name' => 'Demo Local PPPoE Pool', 'service_type' => 'pppoe', 'pool_type' => 'dynamic', 'range_start' => '10.11.20.10', 'range_end' => '10.11.20.254'],
        ['id' => 0, 'name' => 'Demo IPoE Pool', 'service_type' => 'ipoe', 'pool_type' => 'dynamic', 'range_start' => '10.110.0.10', 'range_end' => '10.110.0.254'],
    ];
    if ($db instanceof PDO) {
        billing_customer_sync_legacy_pools_if_needed($db);
    }
}
$poolNextIps = ($openCreateModal && $db instanceof PDO) ? billing_customer_pool_next_ip_map($db) : [];
$staticPools = array_values(array_filter($pools, static fn (array $poolRow): bool => strtolower((string) ($poolRow['pool_type'] ?? '')) === 'static'));
$freeLocalIps = ($openCreateModal && $db instanceof PDO) ? app_ip_available_addresses($db, 'local', 25) : [];
$freeNatIps = ($openCreateModal && $db instanceof PDO) ? app_ip_available_addresses($db, 'nat_public', 25) : [];
$freeStaticIps = ($openCreateModal && $db instanceof PDO) ? app_ip_available_addresses($db, 'static_public', 25) : [];
$natPoolChoices = [];
foreach ($freeNatIps as $ipRow) {
    $poolKey = (string) ($ipRow['pool_id'] ?? $ipRow['pool_name'] ?? '');
    if ($poolKey !== '' && !isset($natPoolChoices[$poolKey])) {
        $natPoolChoices[$poolKey] = [
            'name' => (string) ($ipRow['pool_name'] ?? 'NAT pool'),
            'next_ip' => (string) ($ipRow['ip_address'] ?? ''),
        ];
    }
}
billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'lookups', $billingCustomerPerfStart);
$areas = app_service_areas();
$areaCodeOptions = [];
$query = clean_text((string) ($_GET['q'] ?? ''));
$statusFilter = clean_text((string) ($_GET['status_filter'] ?? ''));
$areaFilter = clean_text((string) ($_GET['area_filter'] ?? ''));
$viewMode = clean_text((string) ($_GET['view'] ?? 'compact'));
$viewMode = in_array($viewMode, ['table', 'card', 'compact'], true) ? $viewMode : 'compact';
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = in_array($perPage, [25, 50], true) ? $perPage : 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$skipCustomerDataForCreate = $openCreateModal && $query === '' && $statusFilter === '' && $areaFilter === '';
$customers = [];
$customerIntelligence = [];
$summary = ['total' => 0, 'active' => 0, 'inactive' => 0, 'online' => 0, 'filtered' => 0];
if ($db instanceof PDO) {
    $summary['total'] = (int) $db->query("SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer'")->fetchColumn();
    $summary['active'] = (int) $db->query("SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer' AND is_active = 1 AND (status IS NULL OR status NOT LIKE '%Deaktiv%')")->fetchColumn();
    $summary['inactive'] = max(0, $summary['total'] - $summary['active']);
    $summary['online'] = (int) $db->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
    $dbAreas = $db->query("SELECT DISTINCT area FROM portal_accounts WHERE role = 'customer' AND area IS NOT NULL AND area <> '' ORDER BY area LIMIT 300")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $areas = array_values(array_unique(array_filter(array_merge($areas, array_map('strval', $dbAreas)))));
    $areaCodeOptions = app_db_area_code_options($db, $areas);
billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'summary_and_areas', $billingCustomerPerfStart);

    if ($skipCustomerDataForCreate) {
        $summary['filtered'] = 0;
        billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'count_skipped_for_create', $billingCustomerPerfStart);
        billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'data_skipped_for_create', $billingCustomerPerfStart);
        billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'service_map', $billingCustomerPerfStart);
    } else {
    $baseSql = "FROM portal_accounts a
            LEFT JOIN (
                SELECT s1.*
                FROM billing_radius_services s1
                INNER JOIN (
                    SELECT account_id,
                           MAX(CASE WHEN status = 'active' THEN id ELSE 0 END) AS active_id,
                           MAX(id) AS latest_id
                    FROM billing_radius_services
                    GROUP BY account_id
                ) service_pick
                  ON service_pick.account_id = s1.account_id
                 AND s1.id = COALESCE(NULLIF(service_pick.active_id, 0), service_pick.latest_id)
            ) s ON s.account_id = a.id
            LEFT JOIN billing_tariffs t ON t.id = s.tariff_id
            WHERE a.role = 'customer'";
    $whereSql = '';
    $params = [];
    $accountOnlyWhereSql = '';
    $accountOnlyParams = [];
    $accountOnlyExactWhereSql = '';
    $accountOnlyExactParams = [];
    $accountOnlySearchAllowed = true;
    $forceAccountOnlyData = false;
    if ($query !== '') {
        $whereSql .= ' AND (a.subscriber_code LIKE :q OR a.contract_no LIKE :q OR a.full_name LIKE :q OR a.phone LIKE :q OR a.fin_code LIKE :q OR a.document_no LIKE :q OR a.address LIKE :q OR s.username LIKE :q OR s.assigned_ip LIKE :q OR s.nat_public_ip LIKE :q OR s.static_ip LIKE :q)';
        $params['q'] = '%' . $query . '%';
        $accountOnlyWhereSql .= ' AND (a.subscriber_code LIKE :q OR a.contract_no LIKE :q OR a.full_name LIKE :q OR a.phone LIKE :q OR a.fin_code LIKE :q OR a.document_no LIKE :q OR a.address LIKE :q)';
        $accountOnlyParams['q'] = '%' . $query . '%';
        $accountOnlyExactWhereSql .= ' AND (a.subscriber_code = :q_exact OR a.contract_no = :q_exact OR a.phone = :q_exact)';
        $accountOnlyExactParams['q_exact'] = $query;
    }
    if ($statusFilter !== '') {
        if ($statusFilter === 'billing_active') {
            $whereSql .= " AND a.is_active = 1 AND (a.status IS NULL OR a.status NOT LIKE '%Deaktiv%')";
            $accountOnlyWhereSql .= " AND a.is_active = 1 AND (a.status IS NULL OR a.status NOT LIKE '%Deaktiv%')";
            $accountOnlyExactWhereSql .= " AND a.is_active = 1 AND (a.status IS NULL OR a.status NOT LIKE '%Deaktiv%')";
        } elseif ($statusFilter === 'billing_inactive') {
            $whereSql .= " AND (a.is_active = 0 OR a.status LIKE '%Deaktiv%')";
            $accountOnlyWhereSql .= " AND (a.is_active = 0 OR a.status LIKE '%Deaktiv%')";
            $accountOnlyExactWhereSql .= " AND (a.is_active = 0 OR a.status LIKE '%Deaktiv%')";
        } elseif ($statusFilter === 'radius_active') {
            $whereSql .= ' AND s.status = "active"';
            $accountOnlySearchAllowed = false;
        } elseif ($statusFilter === 'radius_inactive') {
            $whereSql .= ' AND (s.status <> "active" OR s.status IS NULL)';
            $accountOnlySearchAllowed = false;
        }
    }
    if ($areaFilter !== '') {
        $whereSql .= ' AND a.area = :area_filter';
        $params['area_filter'] = $areaFilter;
        $accountOnlyWhereSql .= ' AND a.area = :area_filter';
        $accountOnlyParams['area_filter'] = $areaFilter;
        $accountOnlyExactWhereSql .= ' AND a.area = :area_filter';
        $accountOnlyExactParams['area_filter'] = $areaFilter;
    }

    if ($query !== '' && $accountOnlySearchAllowed && preg_match('/^[A-Za-z0-9@._:+-]{3,80}$/', $query) === 1) {
        $accountOnlyExactCount = $db->prepare("SELECT COUNT(*) FROM portal_accounts a WHERE a.role = 'customer'" . $accountOnlyExactWhereSql);
        $accountOnlyExactCount->execute($accountOnlyExactParams);
        if ((int) $accountOnlyExactCount->fetchColumn() > 0) {
            $whereSql = $accountOnlyExactWhereSql;
            $params = $accountOnlyExactParams;
            $forceAccountOnlyData = true;
        }
    }

    if (!$forceAccountOnlyData && $query !== '' && $accountOnlySearchAllowed) {
        $accountOnlyCount = $db->prepare("SELECT COUNT(*) FROM portal_accounts a WHERE a.role = 'customer'" . $accountOnlyWhereSql);
        $accountOnlyCount->execute($accountOnlyParams);
        if ((int) $accountOnlyCount->fetchColumn() > 0) {
            $whereSql = $accountOnlyWhereSql;
            $params = $accountOnlyParams;
            $forceAccountOnlyData = true;
        }
    }

    $countNeedsServiceJoin = !$forceAccountOnlyData && ($query !== '' || in_array($statusFilter, ['radius_active', 'radius_inactive'], true));
    $countSql = $countNeedsServiceJoin
        ? ('SELECT COUNT(*) ' . $baseSql . $whereSql)
        : ("SELECT COUNT(*) FROM portal_accounts a WHERE a.role = 'customer'" . $whereSql);
    $countStatement = $db->prepare($countSql);
    $countStatement->execute($params);
    $summary['filtered'] = (int) $countStatement->fetchColumn();
billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'count', $billingCustomerPerfStart);
    $totalPagesForQuery = max(1, (int) ceil(($summary['filtered'] > 0 ? $summary['filtered'] : 1) / $perPage));
    $page = min($page, $totalPagesForQuery);
    $offset = ($page - 1) * $perPage;

    $dataNeedsServiceJoin = !$forceAccountOnlyData && ($query !== '' || in_array($statusFilter, ['radius_active', 'radius_inactive'], true));
    $sql = $dataNeedsServiceJoin
        ? "SELECT a.*,
                   s.username,
                   s.service_type,
                   s.assigned_ip,
                   s.nat_public_ip,
                   s.static_ip,
                   s.status AS radius_status,
                   s.expires_at,
                   t.name AS tariff_name,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.expires_at')) AS billing_expires_at,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.remaining_days_raw')) AS remaining_days_raw,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.debt_raw')) AS debt_raw,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.last_payment_at')) AS last_payment_at,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.payment_count')) AS payment_count
            " . $baseSql . $whereSql . '
            ORDER BY (a.subscriber_code LIKE "SMOKE-%") ASC,
                     (a.login LIKE "smoke.%") ASC,
                     a.updated_at DESC,
                     a.id DESC
            LIMIT :limit OFFSET :offset'
        : "SELECT a.*,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.expires_at')) AS billing_expires_at,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.remaining_days_raw')) AS remaining_days_raw,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.debt_raw')) AS debt_raw,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.last_payment_at')) AS last_payment_at,
                   JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.billing.payment_count')) AS payment_count
            FROM portal_accounts a
            WHERE a.role = 'customer'" . $whereSql . '
            ORDER BY (a.subscriber_code LIKE "SMOKE-%") ASC,
                     (a.login LIKE "smoke.%") ASC,
                     a.updated_at DESC,
                     a.id DESC
            LIMIT :limit OFFSET :offset';
    $statement = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value);
    }
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $customers = $statement->fetchAll() ?: [];
billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'data', $billingCustomerPerfStart);

    if (!$dataNeedsServiceJoin && $customers !== []) {
        $serviceMap = billing_customer_service_map($db, array_column($customers, 'id'));
        foreach ($customers as $customerIndex => $customerRow) {
            if (!is_array($customerRow)) {
                continue;
            }

            $service = $serviceMap[(int) ($customerRow['id'] ?? 0)] ?? [];
            foreach (['username', 'service_type', 'assigned_ip', 'nat_public_ip', 'static_ip', 'radius_status', 'expires_at', 'tariff_name'] as $serviceKey) {
                $customers[$customerIndex][$serviceKey] = $service[$serviceKey] ?? null;
            }
        }
    }
    if ($customers !== []) {
        foreach ($customers as $customerIndex => $customerRow) {
            if (trim((string) ($customerRow['username'] ?? '')) === '') {
                $customers[$customerIndex]['username'] = (string) (($customerRow['subscriber_code'] ?? '') ?: ($customerRow['login'] ?? ''));
            }
        }
        $onlineUsernames = billing_customer_online_usernames($db, array_column($customers, 'username'));
        foreach ($customers as $customerIndex => $customerRow) {
            $username = strtolower(trim((string) ($customerRow['username'] ?? '')));
            $customers[$customerIndex]['is_online'] = $username !== '' && isset($onlineUsernames[$username]) ? 1 : 0;
        }
    }
billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'service_map', $billingCustomerPerfStart);
    }
} else {
    $mockCustomers = billing_mock_customer_rows();
    $areas = array_values(array_unique(array_filter(array_merge($areas, array_map(static fn (array $row): string => (string) ($row['area'] ?? ''), $mockCustomers)))));
    $summary['total'] = count($mockCustomers);
    $summary['active'] = count(array_filter($mockCustomers, static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1));
    $summary['inactive'] = max(0, $summary['total'] - $summary['active']);
    $summary['online'] = count(array_filter($mockCustomers, static fn (array $row): bool => strtolower((string) ($row['radius_status'] ?? '')) === 'active'));

    $filtered = array_values(array_filter($mockCustomers, static function (array $row) use ($query, $statusFilter, $areaFilter): bool {
        if ($query !== '') {
            $haystack = strtolower(implode(' ', [
                (string) ($row['subscriber_code'] ?? ''),
                (string) ($row['contract_no'] ?? ''),
                (string) ($row['full_name'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['address'] ?? ''),
                (string) ($row['username'] ?? ''),
                (string) ($row['assigned_ip'] ?? ''),
                (string) ($row['static_ip'] ?? ''),
            ]));
            if (!str_contains($haystack, strtolower($query))) {
                return false;
            }
        }

        if ($statusFilter === 'billing_active' && (int) ($row['is_active'] ?? 0) !== 1) {
            return false;
        }
        if ($statusFilter === 'billing_inactive' && (int) ($row['is_active'] ?? 0) === 1) {
            return false;
        }
        if ($statusFilter === 'radius_active' && strtolower((string) ($row['radius_status'] ?? '')) !== 'active') {
            return false;
        }
        if ($statusFilter === 'radius_inactive' && strtolower((string) ($row['radius_status'] ?? '')) === 'active') {
            return false;
        }
        if ($areaFilter !== '' && (string) ($row['area'] ?? '') !== $areaFilter) {
            return false;
        }

        return true;
    }));

    $summary['filtered'] = count($filtered);
    $totalPagesForQuery = max(1, (int) ceil(($summary['filtered'] > 0 ? $summary['filtered'] : 1) / $perPage));
    $page = min($page, $totalPagesForQuery);
    $offset = ($page - 1) * $perPage;
    $customers = array_slice($filtered, $offset, $perPage);
}
foreach ($customers as $customerIndex => $customerRow) {
    if (!is_array($customerRow)) {
        continue;
    }
    $customerExpiresAt = trim((string) (($customerRow['expires_at'] ?? '') ?: ($customerRow['billing_expires_at'] ?? '')));
    if ($customerExpiresAt !== '' && strtotime($customerExpiresAt) !== false) {
        $customers[$customerIndex]['remaining_days_raw'] = app_billing_remaining_days_text($customerExpiresAt);
        $customers[$customerIndex]['days_left'] = app_billing_days_left($customerExpiresAt);
    }
}
if ($db instanceof PDO && $customers !== []) {
    $customerIntelligence = app_customer_intelligence_for_list($db, $customers, clean_text((string) ($_GET['intel'] ?? '')) === 'full');
}
billing_customer_perf_mark($billingCustomerPerfEnabled, $billingCustomerPerfMarks, 'pre_render', $billingCustomerPerfStart);
$totalPages = max(1, (int) ceil(($summary['filtered'] > 0 ? $summary['filtered'] : 1) / $perPage));
$page = min($page, $totalPages);
$queryBase = $_GET;
unset($queryBase['page']);
$filtersOpen = $query !== '' || $statusFilter !== '' || $areaFilter !== '' || $perPage !== 50 || $viewMode !== 'compact';
$createParams = $queryBase + ['create' => '1'];
$createUrl = 'billing-customers.php?' . http_build_query($createParams);
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing musterileri</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .billing-customers-layout{display:flex;flex-direction:column;gap:14px}
        .billing-customers-layout .mgs-command-hero{order:1;margin-bottom:0;padding:14px 18px;border-radius:18px}
        .billing-customers-layout .mgs-command-hero h1{margin:10px 0 8px;font-size:clamp(1.8rem,2.4vw,2.5rem);line-height:1.06}
        .billing-customers-layout .mgs-command-hero p{margin:0 0 12px;line-height:1.5}
        .billing-customers-layout .mgs-command-panel{min-height:0;padding:16px}
        .billing-customer-stats{order:2;margin:0}
        .billing-customer-list-card{order:3;margin:0}
        .billing-create-panel{display:none}
        .billing-create-panel[open]{
            display:block;
            position:fixed;
            inset:0;
            z-index:2147483200;
            margin:0;
            padding:clamp(14px,2vw,28px);
            overflow:hidden;
            border:0;
            border-radius:0;
            background:rgba(15,31,52,.58);
            backdrop-filter:blur(10px);
            isolation:isolate;
        }
        .billing-create-panel[open] .billing-create-summary,
        .billing-create-panel[open] .billing-create-inner{
            width:100%;
            max-width:1180px;
            box-sizing:border-box;
            margin:0 auto;
            background:rgba(255,255,255,.98);
        }
        .billing-create-panel[open] .billing-create-summary{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            padding:16px 18px;
            border:1px solid rgba(17,38,64,.12);
            border-radius:22px 22px 0 0;
            list-style:none;
            cursor:default;
        }
        .billing-create-panel[open] .billing-create-summary::-webkit-details-marker{display:none}
        .billing-create-panel[open] .billing-create-inner{
            max-height:calc(100dvh - 112px);
            overflow-x:hidden;
            overflow-y:auto;
            overscroll-behavior:contain;
            padding:18px;
            border:1px solid rgba(17,38,64,.12);
            border-top:0;
            border-radius:0 0 22px 22px;
            box-shadow:0 28px 70px rgba(13,31,54,.28);
        }
        .billing-create-close{border:0;cursor:pointer}
        body.billing-modal-open{overflow:hidden}
        .billing-create-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .billing-create-grid .wide{grid-column:span 3}
        .billing-ip-picker{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center}
        .billing-ip-picker input,.billing-ip-picker select{min-width:0}
        .billing-ip-picker .portal-ghost-btn{white-space:nowrap;padding:12px 14px}
        .billing-ip-helper{display:block;margin-top:7px;color:#5f718d;font-size:.84rem;line-height:1.35}
        .billing-ip-helper strong{color:#10233f}
        .billing-service-badge{font-weight:900;text-transform:uppercase}
        .billing-customer-list-card .portal-table-wrap{margin-top:10px}
        .billing-customer-list-card .portal-table td,
        .billing-customer-list-card .portal-table th{overflow-wrap:anywhere}
        .billing-customer-list-card .portal-table small{display:block;line-height:1.3}
        .billing-intel-mini{display:grid;gap:5px;min-width:120px}
        .billing-intel-row{display:flex;align-items:center;justify-content:space-between;gap:8px;color:#5f718d;font-size:.78rem}
        .billing-intel-row strong{color:#10233f;font-size:.82rem}
        .billing-intel-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 8px;background:rgba(14,132,141,.1);color:#075c63;font-weight:900;font-size:.76rem}
        .billing-intel-badge.is-risk-high,.billing-intel-badge.is-risk-critical{background:#fee2e2;color:#991b1b}
        .billing-intel-badge.is-risk-medium{background:#ffedd5;color:#9a3412}
        .billing-intel-badge.is-risk-low{background:#dcfce7;color:#166534}
        .billing-intel-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}
        .billing-intel-grid div{border:1px solid rgba(148,163,184,.25);border-radius:14px;padding:9px 10px;background:rgba(248,250,252,.84)}
        .billing-intel-grid span{display:block;color:#5f718d;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}
        .billing-intel-grid strong{display:block;margin-top:3px;color:#10233f;font-size:.92rem}
        @media (max-width:700px){
            .billing-customer-list-card .billing-customer-actions{gap:4px}
            .billing-customer-list-card .billing-customer-actions .portal-ghost-btn,
            .billing-customer-list-card .billing-customer-actions .portal-primary-btn{min-height:30px;padding:6px 8px;font-size:.72rem}
            .billing-intel-grid{grid-template-columns:1fr}
        }
        @media (max-width: 1000px){.billing-create-grid{grid-template-columns:1fr}.billing-create-grid .wide{grid-column:auto}}
        @media (max-width:700px){
            .billing-create-panel[open]{
                padding:8px 0;
            }
            .billing-create-panel[open] .billing-create-summary,
            .billing-create-panel[open] .billing-create-inner{
                width:100%;
            }
            .billing-create-panel[open] .billing-create-summary{
                position:sticky;
                top:8px;
                z-index:2;
                display:grid;
                grid-template-columns:1fr;
                padding:12px;
                border-radius:18px 18px 0 0;
            }
            .billing-create-panel[open] .billing-create-summary .billing-create-close{
                width:100%;
                max-width:100%;
                justify-content:center;
            }
            .billing-create-panel[open] .billing-create-summary small{
                display:none;
            }
            .billing-create-panel[open] .billing-create-inner{
                max-height:calc(100dvh - 78px);
                padding:12px;
                border-radius:0 0 18px 18px;
            }
            .billing-create-panel .portal-card-head{
                margin-bottom:10px;
            }
            .billing-create-panel .portal-card-head h2{
                font-size:1.15rem;
            }
            .billing-create-panel .portal-card-head small{
                display:none;
            }
            .billing-create-panel .mgs-wizard-steps{
                display:grid;
                grid-template-columns:repeat(2,minmax(0,1fr));
                gap:8px;
                margin-bottom:12px;
                overflow:visible;
                scrollbar-width:none;
                -webkit-overflow-scrolling:touch;
            }
            .billing-create-panel .mgs-wizard-steps::-webkit-scrollbar{
                display:none;
                width:0;
                height:0;
            }
            .billing-create-panel .mgs-wizard-steps span{
                flex:1 1 auto;
                min-width:0;
                padding:8px 10px;
                font-size:.8rem;
                border-radius:14px;
            }
            .billing-create-grid{
                gap:10px;
            }
            .billing-ip-picker{
                grid-template-columns:1fr;
            }
            .billing-ip-picker .portal-ghost-btn{
                width:100%;
            }
        }
    </style>
</head>
<body class="portal-body billing-customers-body<?= $openCreateModal ? ' billing-modal-open' : ''; ?>">
    <?php billing_render_header('Musteriler', 'customers', $lang); ?>

    <main class="portal-layout billing-customers-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>" data-autohide-alert>
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <?php
        $billingLegacySection = 'customers';
        $billingLegacyTitle = 'Musteriler';
        $billingLegacyDescription = 'Musteri siyahisi, servis veziyyeti ve profil kecidleri vahid Billing axininda idare olunur.';
        $billingLegacyActions = [
            ['href' => $createUrl, 'label' => 'Yeni musteri', 'icon' => 'fa-user-plus', 'class' => 'portal-primary-btn portal-inline-link'],
            ['href' => 'billing-payments.php', 'label' => 'Odenisler', 'icon' => 'fa-receipt'],
        ];
        require __DIR__ . '/views/billing/_legacy-module-intro.php';
        ?>

        <section class="mgs-stat-grid portal-reveal billing-customer-stats">
            <?php billing_render_stat_card('Cemi musteri', (string) $summary['total'], 'Portal hesablari', 'fa-users', 'blue'); ?>
            <?php billing_render_stat_card('Aktiv', (string) $summary['active'], 'Billing status', 'fa-user-check', 'green'); ?>
            <?php billing_render_stat_card('Deaktiv', (string) $summary['inactive'], 'Passiv/deaktiv', 'fa-user-xmark', 'red'); ?>
            <?php billing_render_stat_card('Radius aktiv', (string) $summary['online'], 'billing_radius_services', 'fa-satellite-dish', 'blue'); ?>
            <?php billing_render_stat_card('Filtr neticesi', (string) $summary['filtered'], count($customers) . ' qeyd gosterilir', 'fa-filter', 'orange'); ?>
            <?php billing_render_stat_card('Gorunus', ucfirst($viewMode), 'table / card / compact', 'fa-table-cells-large', 'navy'); ?>
        </section>

        <?php if ($openCreateModal): ?>
        <details class="mgs-data-card portal-reveal billing-create-panel" id="customer-create" role="dialog" aria-modal="true" aria-labelledby="customer-create-title" open>
            <summary class="billing-create-summary">
                <span>
                    <strong id="customer-create-title">Yeni musteri yarat</strong>
                    <small>Forma modal kimi acilir, siyahi sehifeni uzatmir.</small>
                </span>
                <a class="portal-ghost-btn portal-inline-link billing-create-close" href="billing-customers.php">
                    <i class="fa-solid fa-xmark"></i> Bagla
                </a>
            </summary>
            <div class="billing-create-inner">
                <div class="portal-card-head">
                    <h2>Yeni musteri wizard</h2>
                    <small>Forma 5 mentiqi blok uzre isleyir: sexs, unvan, xidmet, avadanliq ve tesdiq.</small>
                </div>
            <div class="mgs-wizard-steps" aria-label="Yeni musteri addimlari">
                <span><b>1</b> Sexsi melumat</span>
                <span><b>2</b> Unvan ve zona</span>
                <span><b>3</b> Xidmet / IP</span>
                <span><b>4</b> Avadanliq</span>
                <span><b>5</b> Tesdiq</span>
            </div>
                <form method="post" class="portal-stacked-form">
                <input type="hidden" name="action" value="create_billing_customer">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <input type="hidden" name="subscriber_code_auto" value="1" data-customer-code-auto-field>
                <div class="billing-create-grid">
                    <div class="portal-form-row">
                        <label>Musteri kodu</label>
                        <input type="text" name="subscriber_code" placeholder="Ərazi seçildikdə avtomatik doldurulur" data-customer-code-input data-auto-code="1" autofocus>
                        <small class="billing-ip-helper" data-customer-code-helper>Erazi sec ve sistem hemin prefixden novbeti bos kodu versin.</small>
                    </div>
                    <div class="portal-form-row"><label>Ad soyad / sirket</label><input type="text" name="full_name" value="<?=e((string)($_POST['full_name']??''));?>" required></div>
                    <div class="portal-form-row"><label>Mobil nomre</label><input type="text" name="phone" value="<?=e((string)($_POST['phone']??''));?>" placeholder="+994..." required></div>
                    <div class="portal-form-row"><label>FIN kod</label><input type="text" name="fin_code" value="<?=e((string)($_POST['fin_code']??''));?>" maxlength="32" required></div>
                    <div class="portal-form-row"><label>Sexsiyyet vesiqesi</label><input type="text" name="document_no" value="<?=e((string)($_POST['document_no']??''));?>" maxlength="64" required></div>
                    <div class="portal-form-row"><label>Muqavile nomresi</label><input type="text" name="contract_no" value="<?=e((string)($_POST['contract_no']??''));?>" placeholder="bos qalsa kod yazilir"></div>
                    <div class="portal-form-row"><label>Email</label><input type="email" name="email" value="<?=e((string)($_POST['email']??''));?>"></div>
                    <div class="portal-form-row"><label>Kateqoriya</label><input type="text" name="category" value="Residential"></div>
                    <div class="portal-form-row wide"><label>Unvan</label><input type="text" name="address" value="<?=e((string)($_POST['address']??''));?>" required></div>
                    <div class="portal-form-row">
                        <label>Erazi</label>
                        <select name="area" data-area-select>
                            <option value="">Secin</option>
                            <?php foreach ($areas as $area): ?>
                                <?php $areaCode = $areaCodeOptions[$area] ?? ['prefix' => '', 'next_code' => '']; ?>
                                <option
                                    value="<?= e($area); ?>"
                                    data-code-prefix="<?= e((string) ($areaCode['prefix'] ?? '')); ?>"
                                    data-next-code="<?= e((string) ($areaCode['next_code'] ?? '')); ?>"
                                ><?= e($area); ?><?= ($areaCode['prefix'] ?? '') !== '' ? ' / kod ' . e((string) $areaCode['prefix']) : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Xidmet</label>
                        <select name="service_type" id="customerServiceType" data-service-type-select>
                            <option value="pppoe">PPPoE</option>
                            <option value="ipoe">IPoE</option>
                        </select>
                    </div>
                    <div class="portal-form-row"><label>Radius username</label><input type="text" name="radius_username" placeholder="bos qalsa musteri kodu"></div>
                    <div class="portal-form-row"><label>PPPoE sifre</label><input type="text" name="pppoe_password" placeholder="avto"></div>
                    <div class="portal-form-row">
                        <label>Tarif</label>
                        <select name="tariff_id" required data-tariff-select>
                            <?php foreach ($tariffs as $tariff): ?>
                                <option value="<?= e((string) $tariff['id']); ?>" data-service="<?= e(strtolower((string) $tariff['service_type'])); ?>"><?= e((string) $tariff['service_type']); ?> / <?= e((string) $tariff['name']); ?> / <?= e((string) $tariff['monthly_fee']); ?> AZN / <?= e((string) app_billing_tariff_default_days($tariff)); ?> gun</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>IP pool</label>
                        <select name="pool_id" required data-main-pool-select>
                            <?php foreach ($pools as $pool): ?>
                                <?php $poolKind = strtolower((string) ($pool['pool_type'] ?? 'dynamic')); ?>
                                <option
                                    value="<?= e((string) $pool['id']); ?>"
                                    data-service="<?= e(strtolower((string) $pool['service_type'])); ?>"
                                    data-pool-type="<?= e($poolKind); ?>"
                                    data-next-ip="<?= e((string) ($poolNextIps[(int) ($pool['id'] ?? 0)] ?? '')); ?>"
                                ><?= e((string) $pool['service_type']); ?> / <?= e((string) $pool['name']); ?> / <?= e((string) $pool['range_start']); ?>-<?= e((string) $pool['range_end']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="billing-ip-helper" data-main-pool-helper>Secilen xidmete uygun dynamic/local pool gosterilir.</small>
                    </div>
                    <div class="portal-form-row">
                        <label>Dynamic/Local IP</label>
                        <div class="billing-ip-picker">
                            <input id="customerAssignedIp" list="customerFreeLocalIps" type="text" name="assigned_ip" placeholder="bos = secilen pooldan avtomatik alinir">
                            <button type="button" class="portal-ghost-btn portal-inline-link" data-fill-ip="assigned">Pooldan IP al</button>
                        </div>
                        <small class="billing-ip-helper">Bos saxlasan yaradilis zamani secilen PPPoE/IPoE pooldan ilk bos IP avtomatik assign olunur.</small>
                    </div>
                    <div class="portal-form-row">
                        <label>NAT public IP</label>
                        <select data-nat-pool-select>
                            <option value="">NAT pool secin</option>
                            <?php foreach ($natPoolChoices as $poolKey => $natChoice): ?>
                                <option value="<?= e((string) $poolKey); ?>" data-next-ip="<?= e((string) ($natChoice['next_ip'] ?? '')); ?>"><?= e((string) ($natChoice['name'] ?? 'NAT pool')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="billing-ip-picker" style="margin-top:10px;">
                            <input id="customerNatIp" list="customerFreeNatIps" type="text" name="nat_public_ip" placeholder="94.20.46.x">
                            <button type="button" class="portal-ghost-btn portal-inline-link" data-fill-ip="nat">Pooldan IP al</button>
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label>Static IP</label>
                        <select data-static-pool-select>
                            <option value="">Static IP pool secin</option>
                            <?php foreach ($staticPools as $pool): ?>
                                <option
                                    value="<?= e((string) $pool['id']); ?>"
                                    data-service="<?= e(strtolower((string) $pool['service_type'])); ?>"
                                    data-next-ip="<?= e((string) ($poolNextIps[(int) ($pool['id'] ?? 0)] ?? '')); ?>"
                                ><?= e((string) $pool['service_type']); ?> / <?= e((string) $pool['name']); ?> / <?= e((string) $pool['range_start']); ?>-<?= e((string) $pool['range_end']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="billing-ip-picker" style="margin-top:10px;">
                            <input id="customerStaticIp" list="customerFreeStaticIps" type="text" name="static_ip" placeholder="bos = static yoxdur">
                            <button type="button" class="portal-ghost-btn portal-inline-link" data-fill-ip="static">Pooldan IP al</button>
                        </div>
                        <small class="billing-ip-helper">Static IP yazilarsa RADIUS Framed-IP-Address kimi static IP-ni gonderecek.</small>
                    </div>
                    <div class="portal-form-row"><label>VLAN</label><input type="text" name="vlan" placeholder="1100"></div>
                    <div class="portal-form-row"><label>MAC</label><input type="text" name="mac_address"></div>
                    <div class="portal-form-row"><label>OLT</label><input type="text" name="olt_name" placeholder="Kurdexani 21"></div>
                    <div class="portal-form-row"><label>PON</label><input type="text" name="pon_port" placeholder="PON03"></div>
                    <div class="portal-form-row"><label>ONT ID</label><input type="text" name="ont_id" placeholder="032"></div>
                    <div class="portal-form-row"><label>ONT SN</label><input type="text" name="ont_serial"></div>
                    <div class="portal-form-row"><label>Billing gun sayi</label><input type="number" name="billing_days" min="1" max="366" placeholder="bos = tarif/30 gun"></div>
                    <div class="portal-form-row"><label>Stabil odeme gunu</label><input type="number" name="stable_due_day" min="1" max="31" placeholder="meselen 1 = her ayin 1-i"></div>
                    <div class="portal-form-row"><label>Bitme tarixi</label><input type="datetime-local" name="expires_at"></div>
                    <div class="portal-form-row"><label>Prioritet bali (1-10)</label><input type="number" name="priority_score" min="1" max="10" placeholder="bos = 5"></div>
                    <div class="portal-form-row"><label>Risk bali (1-10)</label><input type="number" name="risk_score" min="1" max="10" placeholder="bos = 5"></div>
                    <div class="portal-form-row"><label>Portal sifresi</label><input type="text" name="portal_password" placeholder="bos = random"></div>
                    <div class="portal-form-row wide">
                        <label>RADIUS preview</label>
                        <div class="portal-muted-card">
                            Service-Type=Framed-User, PPPoE ucun Framed-Protocol=PPP, IP varsa Framed-IP-Address, yoxdursa Framed-Pool yazilacaq. NAT public IP audit ve NAT mapping ucun saxlanilir.
                        </div>
                    </div>
                </div>
                <datalist id="customerFreeLocalIps">
                    <?php foreach ($freeLocalIps as $ip): ?><option value="<?= e((string) ($ip['ip_address'] ?? '')); ?>"><?= e((string) ($ip['pool_name'] ?? '')); ?></option><?php endforeach; ?>
                </datalist>
                <datalist id="customerFreeNatIps">
                    <?php foreach ($freeNatIps as $ip): ?><option value="<?= e((string) ($ip['ip_address'] ?? '')); ?>"><?= e((string) ($ip['pool_name'] ?? '')); ?></option><?php endforeach; ?>
                </datalist>
                <datalist id="customerFreeStaticIps">
                    <?php foreach ($freeStaticIps as $ip): ?><option value="<?= e((string) ($ip['ip_address'] ?? '')); ?>"><?= e((string) ($ip['pool_name'] ?? '')); ?></option><?php endforeach; ?>
                </datalist>
                    <button type="submit" class="portal-primary-btn">Musterini yarat ve Radius-a yaz</button>
                </form>
            </div>
        </details>
        <?php endif; ?>

        <?php if (!$openCreateModal): ?>
        <section class="mgs-data-card portal-reveal billing-customer-list-card">
            <div class="mgs-section-head">
                <div>
                    <h2>PPPoE/IPoE musterileri</h2>
                    <p><?= e((string) $summary['filtered']); ?> neticeden <?= e((string) count($customers)); ?> qeyd gosterilir.</p>
                </div>
                <div class="mgs-view-switch" aria-label="Gorunus secimi">
                    <a class="portal-primary-btn portal-inline-link" href="<?= e($createUrl); ?>"><i class="fa-solid fa-user-plus"></i> Yeni musteri</a>
                    <?php foreach (['table' => 'Table', 'card' => 'Card', 'compact' => 'Compact'] as $mode => $label): ?>
                        <?php $viewParams = $queryBase + ['view' => $mode, 'page' => 1]; ?>
                        <a class="mgs-chip<?= $viewMode === $mode ? ' is-active' : ''; ?>" href="billing-customers.php?<?= e(http_build_query($viewParams)); ?>"><?= e($label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <details class="mgs-filter-panel billing-filter-drawer"<?= $filtersOpen ? ' open' : ''; ?>>
                <summary class="billing-filter-summary">
                    <span><i class="fa-solid fa-filter"></i> Axtaris ve filterler</span>
                    <small><?= $filtersOpen ? 'Aktiv filter var' : 'Siyahini daraltmaq ucun ac'; ?></small>
                </summary>
                <form method="get" class="portal-stacked-form" id="billing-customers-filter" data-auto-filter data-auto-filter-targets="billing-customers-results">
                    <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                    <input type="hidden" name="view" value="<?= e($viewMode); ?>">
                    <div class="billing-filter-grid">
                        <div class="portal-form-row"><label>Axtaris</label><input type="text" name="q" value="<?= e($query); ?>" placeholder="kod, muqavile, ad, telefon, FIN, IP, MAC, SN"></div>
                        <div class="portal-form-row">
                            <label>Status</label>
                            <select name="status_filter">
                                <option value="">Butun statuslar</option>
                                <option value="billing_active" <?= $statusFilter === 'billing_active' ? 'selected' : ''; ?>>Billing aktiv</option>
                                <option value="billing_inactive" <?= $statusFilter === 'billing_inactive' ? 'selected' : ''; ?>>Billing deaktiv/arxiv</option>
                                <option value="radius_active" <?= $statusFilter === 'radius_active' ? 'selected' : ''; ?>>Radius aktiv</option>
                                <option value="radius_inactive" <?= $statusFilter === 'radius_inactive' ? 'selected' : ''; ?>>Radius passiv</option>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <label>Erazi</label>
                            <select name="area_filter">
                                <option value="">Butun eraziler</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?= e($area); ?>" <?= $areaFilter === $area ? 'selected' : ''; ?>><?= e($area); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <label>Goster</label>
                            <select name="per_page">
                                <?php foreach ([25, 50] as $size): ?>
                                    <option value="<?= e((string) $size); ?>" <?= $perPage === $size ? 'selected' : ''; ?>><?= e((string) $size); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="billing-filter-actions">
                        <button type="submit" class="portal-primary-btn">Axtar</button>
                        <a class="portal-ghost-btn portal-inline-link" href="billing-customers.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Sifirla</a>
                    </div>
                    <div class="mgs-quick-filters">
                        <a class="mgs-chip<?= $statusFilter === 'billing_active' ? ' is-active' : ''; ?>" data-auto-filter-link data-auto-filter-form="billing-customers-filter" href="billing-customers.php?status_filter=billing_active&view=<?= e($viewMode); ?>">Aktiv</a>
                        <a class="mgs-chip<?= $statusFilter === 'billing_inactive' ? ' is-active' : ''; ?>" data-auto-filter-link data-auto-filter-form="billing-customers-filter" href="billing-customers.php?status_filter=billing_inactive&view=<?= e($viewMode); ?>">Deaktiv</a>
                        <a class="mgs-chip<?= $statusFilter === 'radius_active' ? ' is-active' : ''; ?>" data-auto-filter-link data-auto-filter-form="billing-customers-filter" href="billing-customers.php?status_filter=radius_active&view=<?= e($viewMode); ?>">Online/Radius aktiv</a>
                        <a class="mgs-chip<?= $statusFilter === 'radius_inactive' ? ' is-active' : ''; ?>" data-auto-filter-link data-auto-filter-form="billing-customers-filter" href="billing-customers.php?status_filter=radius_inactive&view=<?= e($viewMode); ?>">Radius passiv</a>
                    </div>
                    <small data-auto-filter-status role="status" aria-live="polite"></small>
                </form>
            </details>

            <div data-auto-filter-results="billing-customers-results" data-auto-filter-form="billing-customers-filter">
            <?php if ($customers === []): ?>
                <div class="portal-section-gap">
                    <?php billing_render_empty_state('Netice tapilmadi', 'Axtaris ve filtr sertlerini deyisib yeniden yoxlayin.', 'fa-magnifying-glass'); ?>
                </div>
            <?php elseif ($viewMode === 'card'): ?>
                <div class="mgs-customer-grid">
                    <?php foreach ($customers as $customer): ?>
                        <?php
                        $profileHref = ((int) ($customer['id'] ?? 0) > 0)
                            ? 'billing-customer-profile.php?id=' . rawurlencode((string) ($customer['id'] ?? 0)) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '')
                            : 'billing-customer-profile.php?code=' . rawurlencode((string) ($customer['subscriber_code'] ?? '')) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '');
                        $isOnline = !empty($customer['is_online']);
                        $onlineText = $isOnline ? 'Online' : 'Offline';
                        $onlineBadgeClass = $isOnline ? ' is-online' : ' is-offline';
                        $editHref = 'billing-customer-edit.php?id=' . rawurlencode((string) ($customer['id'] ?? 0)) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '');
                        $intel = $customerIntelligence[(int) ($customer['id'] ?? 0)] ?? [];
                        $riskClass = strtolower((string) ($intel['churn_risk'] ?? 'low'));
                        ?>
                        <article class="mgs-customer-card <?= $isOnline ? 'billing-row-online' : 'billing-row-offline'; ?>">
                            <div class="mgs-customer-top">
                                <div>
                                    <a class="mgs-link-name" href="<?= e($profileHref); ?>"><?= e((string) ($customer['full_name'] ?? '')); ?></a>
                                    <small><?= e((string) ($customer['subscriber_code'] ?? '')); ?> / <?= e((string) ($customer['contract_no'] ?? '-')); ?></small>
                                </div>
                                <span class="mgs-status-badge<?= e($onlineBadgeClass); ?>"><?= e($onlineText); ?></span>
                            </div>
                            <div class="mgs-customer-meta">
                                <div><span>Paket</span><strong><?= e((string) ($customer['tariff_name'] ?? $customer['plan'] ?? '-')); ?></strong></div>
                                <div><span>IP / NAT</span><strong><?= e((string) (($customer['static_ip'] ?? '') ?: ($customer['assigned_ip'] ?? '-'))); ?></strong><small>NAT <?= e((string) ($customer['nat_public_ip'] ?? '-')); ?></small></div>
                                <div><span>Telefon</span><strong><?= e((string) ($customer['phone'] ?? '-')); ?></strong></div>
                                <div><span>FIN / vesiqe</span><strong><?= e((string) (($customer['fin_code'] ?? '') ?: 'Melumat yoxdur')); ?></strong><small><?= e((string) (($customer['document_no'] ?? '') ?: 'Melumat yoxdur')); ?></small></div>
                                <div><span>Qalan gun / borc</span><strong><?= e((string) (($customer['remaining_days_raw'] ?? '') ?: '-')); ?> / <?= e((string) (($customer['debt_raw'] ?? '') ?: '-')); ?></strong></div>
                                <div><span>Radius</span><strong><?= e((string) ($customer['radius_status'] ?? '-')); ?></strong></div>
                            </div>
                            <div class="billing-intel-grid">
                                <div><span>Activity</span><strong><?= e((string) ($intel['activity_score'] ?? '-')); ?>/100</strong></div>
                                <div><span>Payment</span><strong><?= e((string) ($intel['payment_score'] ?? '-')); ?>/100</strong></div>
                                <div><span>Risk</span><strong class="billing-intel-badge is-risk-<?= e($riskClass); ?>"><?= e((string) ($intel['churn_risk'] ?? '-')); ?></strong></div>
                                <div><span>Deaktiv gun</span><strong><?= e((string) ($intel['current_inactive_days'] ?? 0)); ?></strong></div>
                                <div><span>Illik passiv</span><strong><?= e((string) ($intel['inactive_days_year'] ?? 0)); ?></strong></div>
                                <div><span>Gelir itkisi</span><strong><?= e(number_format((float) ($intel['revenue_loss'] ?? 0), 2)); ?> AZN</strong></div>
                            </div>
                            <div class="billing-customer-actions">
                                <a class="portal-ghost-btn portal-inline-link" href="<?= e($editHref); ?>">Duzelis</a>
                                <button
                                    type="button"
                                    class="portal-primary-btn"
                                    data-refresh-customer
                                    data-account-id="<?= e((string) ($customer['id'] ?? 0)); ?>"
                                    data-username="<?= e((string) ($customer['username'] ?? '')); ?>"
                                >Yenile</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="portal-table-wrap portal-section-gap">
                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>Kod / muqavile</th>
                                <th>Musteri</th>
                                <th>Servis</th>
                                <?php if ($viewMode !== 'compact'): ?><th>IP / NAT</th><?php endif; ?>
                                <th>Status</th>
                                <th>AI risk</th>
                                <th>Bitme / borc</th>
                                <?php if ($viewMode !== 'compact'): ?><th>Son odenis</th><?php endif; ?>
                                <th>Emeliyyat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                $profileHref = ((int) ($customer['id'] ?? 0) > 0)
                                    ? 'billing-customer-profile.php?id=' . rawurlencode((string) ($customer['id'] ?? 0)) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '')
                                    : 'billing-customer-profile.php?code=' . rawurlencode((string) ($customer['subscriber_code'] ?? '')) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '');
                                $isOnline = !empty($customer['is_online']);
                                $onlineText = $isOnline ? 'Online' : 'Offline';
                                $onlineBadgeClass = $isOnline ? ' is-online' : ' is-offline';
                                $rowClass = $isOnline ? 'billing-row-online' : 'billing-row-offline';
                                $editHref = 'billing-customer-edit.php?id=' . rawurlencode((string) ($customer['id'] ?? 0)) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '');
                                $intel = $customerIntelligence[(int) ($customer['id'] ?? 0)] ?? [];
                                $riskClass = strtolower((string) ($intel['churn_risk'] ?? 'low'));
                                ?>
                                <tr class="<?= e($rowClass); ?>">
                                    <td><strong><?= e((string) ($customer['subscriber_code'] ?? '')); ?></strong><br><small><?= e((string) ($customer['contract_no'] ?? '-')); ?></small></td>
                                    <td><a class="mgs-link-name" href="<?= e($profileHref); ?>"><?= e((string) ($customer['full_name'] ?? '')); ?></a><br><small><?= e((string) ($customer['phone'] ?? '-')); ?> / <?= e((string) ($customer['area'] ?? '-')); ?></small><br><small>FIN: <?= e((string) (($customer['fin_code'] ?? '') ?: 'Melumat yoxdur')); ?> / Vesiqe: <?= e((string) (($customer['document_no'] ?? '') ?: 'Melumat yoxdur')); ?></small><?php if ($viewMode !== 'compact'): ?><br><small><?= e((string) ($customer['address'] ?? '-')); ?></small><?php endif; ?></td>
                                    <td><span class="portal-pill billing-service-badge"><?= e((string) ($customer['service_type'] ?? '-')); ?></span><br><small><?= e((string) ($customer['username'] ?? '-')); ?> / <?= e((string) ($customer['tariff_name'] ?? $customer['plan'] ?? '-')); ?></small></td>
                                    <?php if ($viewMode !== 'compact'): ?><td><?= e((string) (($customer['static_ip'] ?? '') ?: ($customer['assigned_ip'] ?? '-'))); ?><br><small>NAT <?= e((string) ($customer['nat_public_ip'] ?? '-')); ?></small></td><?php endif; ?>
                                    <td><span class="mgs-status-badge<?= e($onlineBadgeClass); ?>"><?= e($onlineText); ?></span><br><small>Billing: <?= e((string) ($customer['status'] ?? '-')); ?></small><br><small>Radius: <?= e((string) ($customer['radius_status'] ?? '-')); ?></small></td>
                                    <td>
                                        <div class="billing-intel-mini">
                                            <span class="billing-intel-badge is-risk-<?= e($riskClass); ?>"><?= e((string) ($intel['churn_risk'] ?? '-')); ?> <?= e((string) ($intel['churn_risk_percent'] ?? 0)); ?>%</span>
                                            <span class="billing-intel-row"><span>Activity</span><strong><?= e((string) ($intel['activity_score'] ?? '-')); ?></strong></span>
                                            <span class="billing-intel-row"><span>Payment</span><strong><?= e((string) ($intel['payment_score'] ?? '-')); ?></strong></span>
                                            <span class="billing-intel-row"><span>Passiv</span><strong><?= e((string) ($intel['current_inactive_days'] ?? 0)); ?> gun</strong></span>
                                            <span class="billing-intel-row"><span>Loss</span><strong><?= e(number_format((float) ($intel['revenue_loss'] ?? 0), 2)); ?> AZN</strong></span>
                                        </div>
                                    </td>
                                    <td><?= e((string) (($customer['remaining_days_raw'] ?? '') ?: ($customer['expires_at'] ?? '-'))); ?><br><small><?= e((string) (($customer['debt_raw'] ?? '') ?: '-')); ?></small></td>
                                    <?php if ($viewMode !== 'compact'): ?><td><?= e(billing_datetime($customer['last_payment_at'] ?? null)); ?><br><small><?= e((string) ($customer['payment_count'] ?? 0)); ?> odenis</small></td><?php endif; ?>
                                    <td>
                                        <div class="billing-customer-actions">
                                            <a class="portal-ghost-btn portal-inline-link" href="<?= e($editHref); ?>">Duzelis</a>
                                            <button
                                                type="button"
                                                class="portal-primary-btn"
                                                data-refresh-customer
                                                data-account-id="<?= e((string) ($customer['id'] ?? 0)); ?>"
                                                data-username="<?= e((string) ($customer['username'] ?? '')); ?>"
                                            >Yenile</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
                <nav class="portal-pagination" aria-label="Musteri sehifeleri">
                    <?php
                    $prevParams = $queryBase + ['page' => max(1, $page - 1)];
                    $nextParams = $queryBase + ['page' => min($totalPages, $page + 1)];
                    ?>
                    <a class="portal-ghost-btn portal-inline-link<?= $page <= 1 ? ' portal-disabled-link' : ''; ?>" href="billing-customers.php?<?= e(http_build_query($prevParams)); ?>">Evvelki</a>
                    <span><?= e((string) $page); ?> / <?= e((string) $totalPages); ?></span>
                    <a class="portal-ghost-btn portal-inline-link<?= $page >= $totalPages ? ' portal-disabled-link' : ''; ?>" href="billing-customers.php?<?= e(http_build_query($nextParams)); ?>">Novbeti</a>
                </nav>
            <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <form method="post" id="billingCustomerRefreshForm" hidden>
            <input type="hidden" name="action" value="refresh_customer_radius_session">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <input type="hidden" name="account_id" value="">
            <input type="hidden" name="username" value="">
        </form>
    </main>

    <script>
    (() => {
        try {
            if ('scrollRestoration' in history) {
                history.scrollRestoration = 'manual';
            }
            if (!window.location.hash) {
                window.requestAnimationFrame(() => window.scrollTo({ top: 0, left: 0, behavior: 'auto' }));
            }
        } catch (error) {
            // Scroll restoration is a progressive enhancement; ignore browser-specific failures.
        }

        const createPanel = document.getElementById('customer-create');
        const openCreateButtons = document.querySelectorAll('[data-open-customer-create]');
        const closeCreateButtons = document.querySelectorAll('[data-close-customer-create]');
        let createModalOpener = null;
        const createFocusable = () => Array.from(createPanel?.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ) || []).filter((item) => item.offsetParent !== null);
        const setCreateModal = (open, opener = null) => {
            if (!createPanel) return;
            if (open) {
                createModalOpener = opener || document.activeElement;
                createPanel.setAttribute('open', 'open');
                document.body.classList.add('billing-modal-open');
                (createPanel.querySelector('input[name="subscriber_code"]') || createFocusable()[0])?.focus({ preventScroll: true });
            } else {
                createPanel.removeAttribute('open');
                document.body.classList.remove('billing-modal-open');
                if (createModalOpener instanceof HTMLElement && createModalOpener.isConnected) {
                    createModalOpener.focus({ preventScroll: true });
                }
                createModalOpener = null;
            }
        };

        createPanel?.querySelector('summary')?.addEventListener('click', (event) => {
            event.preventDefault();
        });
        openCreateButtons.forEach((button) => {
            button.addEventListener('click', () => setCreateModal(true, button));
        });
        closeCreateButtons.forEach((button) => {
            button.addEventListener('click', () => setCreateModal(false));
        });
        if (createPanel?.hasAttribute('open')) {
            window.requestAnimationFrame(() => {
                (createPanel.querySelector('input[name="subscriber_code"]') || createFocusable()[0])?.focus({ preventScroll: true });
            });
        }
        createPanel?.addEventListener('click', (event) => {
            if (event.target === createPanel) {
                setCreateModal(false);
            }
        });
        document.addEventListener('keydown', (event) => {
            if (!createPanel?.hasAttribute('open')) return;
            if (event.key === 'Escape') {
                setCreateModal(false);
                return;
            }
            if (event.key === 'Tab') {
                const focusable = createFocusable();
                if (focusable.length === 0) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        const refreshForm = document.getElementById('billingCustomerRefreshForm');
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-refresh-customer]');
            if (!button || !refreshForm) return;
            refreshForm.elements.account_id.value = button.dataset.accountId || '';
            refreshForm.elements.username.value = button.dataset.username || '';
            refreshForm.submit();
        });

        const serviceSelect = document.querySelector('[data-service-type-select]');
        const tariffSelect = document.querySelector('[data-tariff-select]');
        const mainPoolSelect = document.querySelector('[data-main-pool-select]');
        const staticPoolSelect = document.querySelector('[data-static-pool-select]');
        const natPoolSelect = document.querySelector('[data-nat-pool-select]');
        const mainPoolHelper = document.querySelector('[data-main-pool-helper]');
        const assignedInput = document.getElementById('customerAssignedIp');
        const natInput = document.getElementById('customerNatIp');
        const staticInput = document.getElementById('customerStaticIp');
        const areaSelect = document.querySelector('[data-area-select]');
        const customerCodeInput = document.querySelector('[data-customer-code-input]');
        const customerCodeHelper = document.querySelector('[data-customer-code-helper]');
        const customerCodeAutoField = document.querySelector('[data-customer-code-auto-field]');

        const syncCustomerCodeFromArea = (force = false) => {
            if (!areaSelect || !customerCodeInput) return;
            const selectedArea = areaSelect.selectedOptions?.[0] || null;
            const nextCode = selectedArea?.dataset.nextCode || '';
            const prefix = selectedArea?.dataset.codePrefix || '';
            if (customerCodeHelper) {
                customerCodeHelper.innerHTML = nextCode
                    ? `Novbeti bos kod: <strong>${nextCode}</strong>${prefix ? ` (prefix ${prefix})` : ''}`
                    : 'Bu erazi ucun kod prefixi tapilmadi. Ayarlardan erazi prefixi elave edin.';
            }
            const isAuto = (customerCodeInput.dataset.autoCode || '1') === '1';
            if (nextCode !== '' && (force || isAuto || customerCodeInput.value.trim() === '')) {
                customerCodeInput.value = nextCode;
                customerCodeInput.dataset.autoCode = '1';
                if (customerCodeAutoField) customerCodeAutoField.value = '1';
            }
        };

        customerCodeInput?.addEventListener('input', () => {
            customerCodeInput.dataset.autoCode = customerCodeInput.value.trim() === '' ? '1' : '0';
            if (customerCodeAutoField) customerCodeAutoField.value = customerCodeInput.dataset.autoCode;
        });
        areaSelect?.addEventListener('change', () => {
            if (customerCodeInput) customerCodeInput.dataset.autoCode = '1';
            if (customerCodeAutoField) customerCodeAutoField.value = '1';
            syncCustomerCodeFromArea(true);
        });

        const filterOptions = (select, predicate) => {
            if (!select) return;
            let first = null;
            Array.from(select.options).forEach((option) => {
                if (option.value === '') {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }
                const visible = predicate(option);
                option.hidden = !visible;
                option.disabled = !visible;
                if (visible && first === null) first = option;
            });
            if (select.selectedOptions[0]?.disabled && first) {
                select.value = first.value;
            }
        };

        const syncCustomerCreateIpUi = () => {
            const service = (serviceSelect?.value || 'pppoe').toLowerCase();
            filterOptions(tariffSelect, (option) => (option.dataset.service || '').toLowerCase() === service);
            filterOptions(mainPoolSelect, (option) => {
                const optionService = (option.dataset.service || '').toLowerCase();
                const poolType = (option.dataset.poolType || 'dynamic').toLowerCase();
                return optionService === service && poolType !== 'static';
            });
            filterOptions(staticPoolSelect, (option) => (option.dataset.service || '').toLowerCase() === service);

            const selectedPool = mainPoolSelect?.selectedOptions[0] || null;
            if (mainPoolHelper && selectedPool) {
                const nextIp = selectedPool.dataset.nextIp || '';
                mainPoolHelper.innerHTML = nextIp
                    ? `Secilen pooldan novbeti bos IP: <strong>${nextIp}</strong>`
                    : 'Secilen poolda avtomatik bos IP tapilmadi. IP pool sehifesini yoxlayin.';
            }
        };

        const fillFromSelectedOption = (select, input, fallbackSelect = null) => {
            if (!input) return;
            let option = select?.selectedOptions?.[0] || null;
            if ((!option || option.disabled || !(option.dataset.nextIp || '')) && fallbackSelect) {
                option = Array.from(fallbackSelect.options).find((candidate) => !candidate.disabled && (candidate.dataset.nextIp || '')) || null;
            }
            const nextIp = option?.dataset.nextIp || '';
            if (nextIp === '') {
                alert('Bu pool ucun bos IP tapilmadi.');
                return;
            }
            input.value = nextIp;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };

        document.querySelectorAll('[data-fill-ip]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.dataset.fillIp || '';
                if (target === 'assigned') fillFromSelectedOption(mainPoolSelect, assignedInput);
                if (target === 'nat') fillFromSelectedOption(natPoolSelect, natInput, natPoolSelect);
                if (target === 'static') fillFromSelectedOption(staticPoolSelect, staticInput);
            });
        });

        serviceSelect?.addEventListener('change', syncCustomerCreateIpUi);
        syncCustomerCreateIpUi();
        syncCustomerCodeFromArea();
    })();
    </script>
    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php if ($billingCustomerPerfEnabled): ?>
        <?php billing_customer_perf_mark(true, $billingCustomerPerfMarks, 'render_done', $billingCustomerPerfStart); ?>
        <?php file_put_contents('php://stderr', json_encode(['billing_customers_perf' => $billingCustomerPerfMarks], JSON_UNESCAPED_UNICODE) . PHP_EOL); ?>
    <?php endif; ?>
</body>
</html>
