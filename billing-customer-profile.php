<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/customer-intelligence-lib.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/router-registry-lib.php';

$lang = app_lang();
$user = billing_require_employee();

function billing_profile_field(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function billing_profile_money(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    if (is_numeric($value)) {
        return number_format((float) $value, 2, '.', '') . ' AZN';
    }

    return (string) $value;
}

function billing_profile_bytes(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    if (!is_numeric($value)) {
        return (string) $value;
    }

    $bytes = (float) $value;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, $index === 0 ? 0 : 2, '.', '') . ' ' . $units[$index];
}

function billing_profile_datetime(mixed $value): string
{
    if ($value === null || trim((string) $value) === '') {
        return '-';
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return (string) $value;
    }

    return date('d.m.Y H:i', $timestamp);
}

function billing_profile_channel_icon(string $channel): string
{
    $normalized = strtolower(trim($channel));
    if (str_contains($normalized, 'whatsapp')) {
        return 'fa-solid fa-phone-volume';
    }
    if (str_contains($normalized, 'telegram')) {
        return 'fa-solid fa-paper-plane';
    }
    if (str_contains($normalized, 'sms')) {
        return 'fa-solid fa-comment-sms';
    }
    if (str_contains($normalized, 'app') || str_contains($normalized, 'push')) {
        return 'fa-solid fa-mobile-screen-button';
    }
    if (str_contains($normalized, 'email')) {
        return 'fa-solid fa-envelope';
    }

    return 'fa-solid fa-message';
}

function billing_profile_channel_class(string $channel): string
{
    $slug = (string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($channel)));
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'unknown';
}

function billing_profile_unique_usernames(array $account, array $billing, array $radiusServices): array
{
    $usernames = [
        (string) ($billing['pppoe_username'] ?? ''),
        (string) ($account['subscriber_code'] ?? ''),
        (string) ($account['login'] ?? ''),
    ];

    foreach ($radiusServices as $service) {
        if (is_array($service)) {
            $usernames[] = (string) ($service['username'] ?? '');
        }
    }

    $usernames = array_values(array_unique(array_filter(array_map('trim', $usernames))));

    return array_slice($usernames, 0, 20);
}

function billing_profile_in_clause(array $values, string $prefix): array
{
    $placeholders = [];
    $params = [];
    foreach (array_values($values) as $index => $value) {
        $key = $prefix . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $value;
    }

    return [implode(',', $placeholders), $params];
}

function billing_profile_random_password(int $length = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $password;
}

function billing_profile_old_tables(array $snapshot, string $key): array
{
    $oldBilling = is_array($snapshot['old_billing'] ?? null) ? $snapshot['old_billing'] : [];
    $profileTables = is_array($oldBilling['profile_tables'] ?? null) ? $oldBilling['profile_tables'] : [];
    $tables = is_array($profileTables[$key] ?? null) ? $profileTables[$key] : [];

    return array_values(array_filter($tables, 'is_array'));
}

function billing_profile_legacy_section_tables(?PDO $db, int $accountId): array
{
    if (!$db instanceof PDO || $accountId <= 0) {
        return [];
    }

    try {
        $statement = $db->prepare(
            'SELECT section_key, headers_json, rows_json
             FROM legacy_billing_profile_sections
             WHERE account_id = :account_id
             ORDER BY FIELD(section_key,
                "billing_info_table",
                "iptv_services",
                "sip_services",
                "static_ip_services",
                "cable_tv_services",
                "payment_history",
                "payment_statement",
                "customer_logs",
                "radius_logs",
                "login_attempts",
                "other_tables"
             ), table_index'
        );
        $statement->execute(['account_id' => $accountId]);
    } catch (Throwable) {
        return [];
    }

    $sections = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $key = (string) ($row['section_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $headers = json_decode((string) ($row['headers_json'] ?? '[]'), true);
        $rows = json_decode((string) ($row['rows_json'] ?? '[]'), true);
        $sections[$key][] = [
            'headers' => is_array($headers) ? $headers : [],
            'rows' => is_array($rows) ? $rows : [],
        ];
    }

    return $sections;
}

function billing_profile_legacy_count_rows(array $tables): int
{
    $count = 0;
    foreach ($tables as $table) {
        if (!is_array($table)) {
            continue;
        }
        $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
        $count += count($rows);
    }

    return $count;
}

function billing_profile_legacy_cell(array $row, int $index, string $fallback = '-'): string
{
    $value = trim((string) ($row[$index] ?? ''));

    return $value === '' ? $fallback : $value;
}

function billing_profile_tab_url(array $account, string $lang, string $tab, string $anchor = ''): string
{
    $params = [
        'id' => (string) ((int) ($account['id'] ?? 0)),
        'tab' => $tab,
    ];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }

    return 'billing-customer-profile.php?' . http_build_query($params) . ($anchor !== '' ? '#' . ltrim($anchor, '#') : '');
}

$db = app_db();
$routerRegistryReady = $db instanceof PDO && router_registry_ensure_schema($db);
$canManageRouterBindings = router_registry_actor_can_manage_bindings($user);
$account = null;
$mockSnapshot = null;
$flash = null;
$status = clean_text((string) ($_GET['status'] ?? ''));
$statusMap = [
    'operator_ticket_created' => ['type' => 'success', 'message' => 'Bilet yaradildi ve musteri profilinde tarixceye elave edildi.'],
    'operator_ticket_failed' => ['type' => 'error', 'message' => 'Bilet yaradilmadi. Movzu, qeyd ve musteri melumatlarini yoxlayin.'],
    'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'],
];
if (isset($statusMap[$status])) {
    $flash = $statusMap[$status];
}
$id = (int) ($_GET['id'] ?? 0);
$code = clean_text((string) ($_GET['code'] ?? ''));
if ($id > 0) {
    $account = app_db_account_by_id($id);
} elseif ($code !== '') {
    $account = app_db_account_by_subscriber_code($code);
}

if ($account === null && !$db instanceof PDO && ($code !== '' || $id > 0)) {
    foreach (billing_mock_customer_rows() as $mockCustomer) {
        $matchesCode = $code !== '' && (string) ($mockCustomer['subscriber_code'] ?? '') === $code;
        $matchesId = $id > 0 && (int) ($mockCustomer['id'] ?? 0) === $id;
        if (!$matchesCode && !$matchesId) {
            continue;
        }

        $resolvedCode = (string) ($mockCustomer['subscriber_code'] ?? $code);
        $account = [
            'id' => (int) ($mockCustomer['id'] ?? 0),
            'role' => 'customer',
            'account_type' => 'customer',
            'login' => (string) ($mockCustomer['login'] ?? $resolvedCode),
            'full_name' => (string) ($mockCustomer['full_name'] ?? $resolvedCode),
            'subscriber_code' => $resolvedCode,
            'contract_no' => (string) ($mockCustomer['contract_no'] ?? $resolvedCode),
            'email' => (string) ($mockCustomer['email'] ?? ''),
            'phone' => (string) ($mockCustomer['phone'] ?? ''),
            'category' => (string) ($mockCustomer['category'] ?? ''),
            'area' => (string) ($mockCustomer['area'] ?? ''),
            'address' => (string) ($mockCustomer['address'] ?? ''),
            'plan' => (string) ($mockCustomer['plan'] ?? ''),
            'status' => (string) ($mockCustomer['status'] ?? 'Aktiv'),
            'is_active' => (int) ($mockCustomer['is_active'] ?? 1),
        ];
        $mockSnapshot = app_mock_customer_snapshot_data($account);
        break;
    }
}

if ($account === null || (string) ($account['role'] ?? '') !== 'customer') {
    header('Location: billing-customers.php?status=customer_not_found' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && clean_text((string) ($_POST['action'] ?? '')) === 'bind_customer_router') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO || !$routerRegistryReady) {
        $flash = ['type' => 'error', 'message' => 'Router registry hazir deyil.'];
    } elseif (!$canManageRouterBindings) {
        $flash = ['type' => 'error', 'message' => 'Router binding deyisikliyi ucun admin ve ya NOC icazesi lazimdir.'];
    } else {
        $bindingResult = router_registry_bind_customer(
            $db,
            (int) ($account['id'] ?? 0),
            (int) ($_POST['router_id'] ?? 0),
            $user,
            'Customer profile router binding change'
        );
        $bindingError = (string) ($bindingResult['error'] ?? 'binding_persistence_failed');
        $flash = !empty($bindingResult['ok'])
            ? ['type' => 'success', 'message' => 'Musteri router binding yenilendi.']
            : ['type' => 'error', 'message' => match ($bindingError) {
                'binding_authorization_required' => 'Router binding deyisikliyi ucun admin ve ya NOC icazesi lazimdir.',
                'router_inactive_or_missing' => 'Secilen router aktiv deyil ve ya tapilmadi. Siyahini yenileyib yeniden secin.',
                default => 'Router binding saxlanmadi. Hech bir deyisiklik tetbiq edilmedi.',
            }];
    }
}

$basePayload = app_db_account_payload($account);
$profile = is_array($basePayload['profile'] ?? null) ? $basePayload['profile'] : [];
$billing = is_array($basePayload['billing'] ?? null) ? $basePayload['billing'] : [];

$tariffs = $db instanceof PDO ? ($db->query('SELECT * FROM billing_tariffs WHERE status = "active" ORDER BY service_type, download_mbps')->fetchAll() ?: []) : [];
$pools = $db instanceof PDO ? ($db->query('SELECT * FROM billing_ip_pools WHERE status = "active" ORDER BY service_type, pool_type, name')->fetchAll() ?: []) : [];
$enginePools = $db instanceof PDO ? app_ip_pool_summaries($db) : [];
$freeLocalIps = $db instanceof PDO ? app_ip_available_addresses($db, 'local', 40) : [];
$freeNatIps = $db instanceof PDO ? app_ip_available_addresses($db, 'nat_public', 40) : [];
$freeStaticIps = $db instanceof PDO ? app_ip_available_addresses($db, 'static_public', 40) : [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && clean_text((string) ($_POST['action'] ?? '')) === 'refresh_customer_radius_session') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO) {
        $flash = ['type' => 'error', 'message' => 'Database baglantisi aktiv deyil.'];
    } else {
        $payloadForRefresh = app_db_account_payload($account);
        $billingForRefresh = is_array($payloadForRefresh['billing'] ?? null) ? $payloadForRefresh['billing'] : [];
        $servicesForRefresh = is_array($payloadForRefresh['internet_services'] ?? null)
            ? array_values(array_filter($payloadForRefresh['internet_services'], 'is_array'))
            : [];
        $username = clean_text((string) ($_POST['username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($servicesForRefresh[0]['username'] ?? $billingForRefresh['pppoe_username'] ?? $account['subscriber_code'] ?? ''));
        }

        $synced = app_billing_sync_radius_account($account);
        $session = ['account_id' => (int) $account['id'], 'username' => $username];
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
            ? app_radius_request_disconnect($db, $session, $user, 'Customer refresh from 360 profile')
            : 0;
        $freshAccount = app_db_account_by_id((int) $account['id']);
        if ($freshAccount !== null) {
            $account = $freshAccount;
        }
        $flash = $synced && $requestId > 0
            ? ['type' => 'success', 'message' => 'RADIUS yenilendi: servis sinxronlandi ve disconnect queue #' . $requestId . ' yaradildi.']
            : ['type' => 'warning', 'message' => 'RADIUS sync yoxlandi, amma online sessiya/username tapilmadi.'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && clean_text((string) ($_POST['action'] ?? '')) === 'release_ip_assignment') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO) {
        $flash = ['type' => 'error', 'message' => 'Database baglantisi aktiv deyil.'];
    } else {
        $serviceUsername = clean_text((string) ($_POST['service_username'] ?? ''));
        $payload = app_db_account_payload($account);
        $servicesForUpdate = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
        foreach ($servicesForUpdate as &$service) {
            if ($serviceUsername === '' || strcasecmp((string) ($service['username'] ?? ''), $serviceUsername) === 0) {
                $service['ip_address'] = '';
                $service['assigned_ip'] = '';
                $service['nat_public_ip'] = '';
                $service['static_ip'] = '';
                $service['ip_assignment_mode'] = 'pool';
                break;
            }
        }
        unset($service);
        if ($servicesForUpdate !== []) {
            $payload['internet_services'] = $servicesForUpdate;
        }
        if (!isset($payload['billing']) || !is_array($payload['billing'])) {
            $payload['billing'] = [];
        }
        $mainUsername = (string) ($payload['billing']['pppoe_username'] ?? $account['subscriber_code'] ?? '');
        if ($serviceUsername === '' || strcasecmp($serviceUsername, $mainUsername) === 0) {
            $payload['billing']['ip_address'] = '';
            $payload['billing']['assigned_ip'] = '';
            $payload['billing']['nat_public_ip'] = '';
            $payload['billing']['static_ip'] = '';
            $payload['billing']['ip_assignment_mode'] = 'pool';
        }

        $released = app_ip_release_customer_assignment(
            $db,
            (int) $account['id'],
            $serviceUsername,
            (int) ($user['id'] ?? 0),
            'Operator IP release'
        );
        $saved = app_billing_save_account_payload($account, $payload, null);
        $updated = $saved ? app_db_account_by_id((int) $account['id']) : null;
        $synced = $updated !== null && app_billing_sync_radius_account($updated);
        if ($updated !== null) {
            $account = $updated;
        }
        $flash = $saved && $synced
            ? ['type' => 'success', 'message' => 'IP assignment release edildi. Azad edilen qeyd sayi: ' . $released . '. RADIUS pool rejimine kecirildi.']
            : ['type' => 'error', 'message' => 'IP release qismen icra olundu, amma RADIUS sync tamamlanmadi.'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && clean_text((string) ($_POST['action'] ?? '')) === 'update_ip_assignment') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO) {
        $flash = ['type' => 'error', 'message' => 'Database baglantisi aktiv deyil.'];
    } else {
        $serviceUsername = clean_text((string) ($_POST['service_username'] ?? ''));
        $localIp = clean_text((string) ($_POST['local_ip'] ?? ''));
        $natIp = clean_text((string) ($_POST['nat_public_ip'] ?? ''));
        $staticIp = clean_text((string) ($_POST['static_ip'] ?? ''));
        $reason = clean_text((string) ($_POST['reason'] ?? 'Operator IP deyisikliyi'));
        $validationError = app_ip_validate_customer_ips($db, $localIp, $natIp, $staticIp, (int) $account['id']);

        if (is_array($validationError)) {
            $flash = ['type' => 'error', 'message' => (string) ($validationError['message'] ?? 'IP validation xetasi')];
        } else {
            $payload = app_db_account_payload($account);
            $servicesForUpdate = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
            $updatedService = false;
            foreach ($servicesForUpdate as &$service) {
                if ($serviceUsername === '' || strcasecmp((string) ($service['username'] ?? ''), $serviceUsername) === 0) {
                    $service['ip_address'] = $staticIp !== '' ? $staticIp : $localIp;
                    $service['assigned_ip'] = $localIp;
                    $service['nat_public_ip'] = $natIp;
                    $service['static_ip'] = $staticIp;
                    $service['ip_assignment_mode'] = ($localIp === '' && $natIp === '' && $staticIp === '') ? 'pool' : 'manual';
                    $updatedService = true;
                    break;
                }
            }
            unset($service);

            if ($updatedService) {
                $payload['internet_services'] = $servicesForUpdate;
            }
            if (!isset($payload['billing']) || !is_array($payload['billing'])) {
                $payload['billing'] = [];
            }
            $payload['billing']['ip_address'] = $staticIp !== '' ? $staticIp : $localIp;
            $payload['billing']['assigned_ip'] = $localIp;
            $payload['billing']['nat_public_ip'] = $natIp;
            $payload['billing']['static_ip'] = $staticIp;
            $payload['billing']['ip_assignment_mode'] = ($localIp === '' && $natIp === '' && $staticIp === '') ? 'pool' : 'manual';

            $saved = app_billing_save_account_payload($account, $payload, null);
            $updated = $saved ? app_db_account_by_id((int) $account['id']) : null;
            $synced = $updated !== null && app_billing_sync_radius_account($updated);
            if ($updated !== null) {
                $account = $updated;
            }
            if ($synced) {
                app_ip_record_customer_assignment($db, (int) $account['id'], $serviceUsername !== '' ? $serviceUsername : (string) ($account['subscriber_code'] ?? ''), $localIp, $natIp, $staticIp, (int) ($user['id'] ?? 0), $reason);
                $flash = ['type' => 'success', 'message' => 'IP deyisikliyi saxlandi, tarixceye yazildi ve RADIUS sync yenilendi. Aktiv sessiya varsa disconnect/CoA teklif olunur.'];
            } else {
                $flash = ['type' => 'error', 'message' => 'IP saxlandi, amma RADIUS sync tamamlanmadi.'];
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && clean_text((string) ($_POST['action'] ?? '')) === 'add_radius_service') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO) {
        $flash = ['type' => 'error', 'message' => 'Database baglantisi aktiv deyil.'];
    } else {
        $serviceType = clean_text((string) ($_POST['service_type'] ?? 'pppoe'));
        $serviceType = $serviceType === 'ipoe' ? 'ipoe' : 'pppoe';
        $username = clean_text((string) ($_POST['username'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        if ($serviceType === 'pppoe' && $password === '') {
            $password = billing_profile_random_password();
        }
        $tariffId = (int) ($_POST['tariff_id'] ?? 0);
        $poolId = (int) ($_POST['pool_id'] ?? 0);
        $tariffStatement = $db->prepare('SELECT * FROM billing_tariffs WHERE id = :id AND status = "active" LIMIT 1');
        $tariffStatement->execute(['id' => $tariffId]);
        $tariff = $tariffStatement->fetch();
        $serviceDays = app_billing_account_default_days($account, [], is_array($tariff) ? $tariff : null);
        $postedServiceDays = (int) ($_POST['service_days'] ?? 0);
        if ($postedServiceDays > 0) {
            $serviceDays = max(1, min(366, $postedServiceDays));
        }
        $existingStableDueDay = app_billing_account_stable_due_day($account, is_array($billing) ? $billing : [], is_array($profile) ? $profile : []);
        $postedStableDueDay = max(0, (int) ($_POST['stable_due_day'] ?? 0));
        $resolvedStableDueDay = $postedStableDueDay >= 1 && $postedStableDueDay <= 31 ? $postedStableDueDay : $existingStableDueDay;
        $poolStatement = $db->prepare('SELECT * FROM billing_ip_pools WHERE id = :id AND status = "active" LIMIT 1');
        $poolStatement->execute(['id' => $poolId]);
        $pool = $poolStatement->fetch();
        $assignedIp = clean_text((string) ($_POST['assigned_ip'] ?? ''));
        $natPublicIp = clean_text((string) ($_POST['nat_public_ip'] ?? ''));
        $staticIp = clean_text((string) ($_POST['static_ip'] ?? ''));
        if ($assignedIp === '' && $staticIp === '' && is_array($pool)) {
            $assignedIp = app_billing_next_available_ip((int) $pool['id']) ?? '';
        }
        $expiresAt = clean_text((string) ($_POST['expires_at'] ?? ''));
        if ($expiresAt === '') {
            $expiresAt = app_billing_resolve_next_expires_at($serviceDays, $resolvedStableDueDay, null, time());
        } else {
            $expiresTimestamp = strtotime($expiresAt);
            $expiresAt = $expiresTimestamp === false
                ? app_billing_resolve_next_expires_at($serviceDays, $resolvedStableDueDay, null, time())
                : date('Y-m-d H:i:s', $expiresTimestamp);
        }

        if ($username === '' || !is_array($tariff) || !is_array($pool)) {
            $flash = ['type' => 'error', 'message' => 'Username, tarif ve IP pool mutleq secilmelidir.'];
        } else {
            $payload = app_db_account_payload($account);
            $internetServices = is_array($payload['internet_services'] ?? null) ? array_values(array_filter($payload['internet_services'], 'is_array')) : [];
            foreach ($internetServices as $service) {
                if (is_array($service) && strcasecmp((string) ($service['username'] ?? ''), $username) === 0) {
                    $flash = ['type' => 'error', 'message' => 'Bu Radius username artiq hemin musteri profilinde var.'];
                    break;
                }
            }
            $validationError = app_ip_validate_customer_ips($db, $assignedIp, $natPublicIp, $staticIp, (int) $account['id']);
            if ($flash === null && is_array($validationError)) {
                $flash = ['type' => 'error', 'message' => (string) ($validationError['message'] ?? 'IP validation xetasi')];
            }

            if ($flash === null) {
                $internetServices[] = [
                    'service_type' => $serviceType,
                    'username' => $username,
                    'password' => $password,
                    'tariff' => (string) ($tariff['name'] ?? ''),
                    'pool' => (string) ($pool['name'] ?? ''),
                    'ip_address' => $staticIp !== '' ? $staticIp : $assignedIp,
                    'assigned_ip' => $assignedIp,
                    'nat_public_ip' => $natPublicIp,
                    'static_ip' => $staticIp,
                    'ip_assignment_mode' => ($assignedIp === '' && $natPublicIp === '' && $staticIp === '') ? 'pool' : 'manual',
                    'vlan' => clean_text((string) ($_POST['vlan'] ?? '')),
                    'mac' => strtoupper(clean_text((string) ($_POST['mac_address'] ?? ''))),
                    'status' => 'active',
                    'expires_at' => $expiresAt,
                    'default_days' => $serviceDays,
                ];
                if ($resolvedStableDueDay > 0) {
                    $internetServices[array_key_last($internetServices)]['stable_due_day'] = $resolvedStableDueDay;
                }
                $payload['internet_services'] = $internetServices;
                if (!isset($payload['billing']) || !is_array($payload['billing'])) {
                    $payload['billing'] = [];
                }
                if (!isset($payload['profile']) || !is_array($payload['profile'])) {
                    $payload['profile'] = [];
                }
                if ($resolvedStableDueDay > 0) {
                    $payload['billing']['stable_due_day'] = $resolvedStableDueDay;
                    $payload['profile']['stable_due_day'] = $resolvedStableDueDay;
                }
                if (count($internetServices) === 1) {
                    $payload['billing']['service_type'] = $serviceType;
                    $payload['billing']['pppoe_username'] = $username;
                    $payload['billing']['pppoe_password'] = $password;
                    $payload['billing']['tariff'] = (string) ($tariff['name'] ?? '');
                    $payload['billing']['pool'] = (string) ($pool['name'] ?? '');
                    $payload['billing']['ip_address'] = $assignedIp;
                    $payload['billing']['assigned_ip'] = $assignedIp;
                    $payload['billing']['nat_public_ip'] = $natPublicIp;
                    $payload['billing']['static_ip'] = $staticIp;
                    $payload['billing']['ip_assignment_mode'] = ($assignedIp === '' && $natPublicIp === '' && $staticIp === '') ? 'pool' : 'manual';
                    $payload['billing']['expires_at'] = $expiresAt;
                    $payload['billing']['default_days'] = $serviceDays;
                    $payload['billing']['billing_days'] = $serviceDays;
                    $payload['billing']['days_left'] = app_billing_days_left($expiresAt);
                    $payload['billing']['remaining_days_raw'] = app_billing_remaining_days_text($expiresAt);
                    $payload['billing']['status'] = 'Aktiv';
                    $payload['billing']['radius_status'] = 'Aktiv';
                }

                $saved = app_billing_save_account_payload($account, $payload, 'Aktiv');
                $updated = $saved ? app_db_account_by_id((int) $account['id']) : null;
                $synced = $updated !== null && app_billing_sync_radius_account($updated);
                if ($synced) {
                    $account = $updated;
                    $flash = ['type' => 'success', 'message' => 'Yeni internet servisi elave edildi ve Radius cedvellerine sinxronlandi.'];
                } else {
                    $flash = ['type' => 'error', 'message' => 'Servis saxlandi, amma Radius sync tamamlanmadi.'];
                    if ($updated !== null) {
                        $account = $updated;
                    }
                }
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && clean_text((string) ($_POST['action'] ?? '')) === 'add_extra_service') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO) {
        $flash = ['type' => 'error', 'message' => 'Database baglantisi aktiv deyil.'];
    } else {
        $service = app_billing_normalize_extra_service([
            'type' => clean_text((string) ($_POST['extra_type'] ?? 'other')),
            'title' => clean_text((string) ($_POST['extra_title'] ?? '')),
            'details' => clean_text((string) ($_POST['extra_details'] ?? '')),
            'price' => $_POST['extra_price'] ?? 0,
            'status' => clean_text((string) ($_POST['extra_status'] ?? 'active')),
        ]);

        if (app_billing_parse_money_amount($service['price'] ?? 0) <= 0) {
            $flash = ['type' => 'error', 'message' => 'Elave xidmetin ayliq qiymeti yazilmalidir.'];
        } else {
            $payload = app_db_account_payload($account);
            $extraServices = app_billing_extra_services($payload);
            array_unshift($extraServices, $service);
            $payload['extra_services'] = $extraServices;
            $billingForTariff = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
            $payload = app_billing_refresh_payload_totals($account, $payload, app_billing_account_tariff($db, $account, $billingForTariff));
            $saveStatement = $db->prepare('UPDATE portal_accounts SET payload_json = :payload_json, updated_at = NOW() WHERE id = :id');
            $saved = $saveStatement->execute([
                'payload_json' => app_db_json_encode($payload),
                'id' => (int) $account['id'],
            ]);
            $updated = $saved ? app_db_account_by_id((int) $account['id']) : null;
            if ($updated !== null) {
                $account = $updated;
                $flash = ['type' => 'success', 'message' => 'Elave ayliq xidmet profile yazildi ve ayliq toplam mebleg yenilendi.'];
            } else {
                $flash = ['type' => 'error', 'message' => 'Elave xidmeti saxlamaq alinmadi.'];
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && clean_text((string) ($_POST['action'] ?? '')) === 'profile_send_message') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } else {
        $templateCode = clean_text((string) ($_POST['template_code'] ?? ''));
        $requestedChannel = clean_text((string) ($_POST['channel'] ?? 'auto'));
        $customBody = trim((string) ($_POST['custom_message'] ?? ''));
        $testMode = isset($_POST['test_mode']);
        $ok = app_message_queue_for_account($account, $templateCode, $requestedChannel, $customBody, $user, $testMode);
        $flash = [
            'type' => $ok ? 'success' : 'error',
            'message' => $ok
                ? 'Mesaj novbeye yazildi ve tarixce yenilendi.'
                : 'Mesaj hazirlanmadi. Sablon secin ve ya metn yazin.',
        ];
    }
}

$activeRouters = $routerRegistryReady && $db instanceof PDO && $canManageRouterBindings
    ? router_registry_list($db, true, $user)
    : [];
$customerRouter = $routerRegistryReady && $db instanceof PDO
    ? router_registry_customer_router($db, (int) ($account['id'] ?? 0), $user)
    : null;

$customerUser = $mockSnapshot !== null ? $account : app_db_user_session_payload($account);
$snapshot = $mockSnapshot ?? app_db_customer_snapshot($customerUser);
$profile = is_array($snapshot['profile'] ?? null) ? $snapshot['profile'] : [];
$billing = is_array($snapshot['billing'] ?? null) ? $snapshot['billing'] : [];
$subscriberCode = (string) ($profile['subscriber_code'] ?? $account['subscriber_code'] ?? '');
$traffic = is_array($snapshot['traffic'] ?? null) ? $snapshot['traffic'] : [];
$services = is_array($snapshot['internet_services'] ?? null) ? array_values(array_filter($snapshot['internet_services'], 'is_array')) : [];
$extraServices = app_billing_extra_services($snapshot);
$onts = is_array($snapshot['ont_profiles'] ?? null) ? array_values(array_filter($snapshot['ont_profiles'], 'is_array')) : [];
$payloadPayments = is_array($snapshot['payments'] ?? null) ? array_values(array_filter($snapshot['payments'], 'is_array')) : [];
$statements = is_array($snapshot['statements'] ?? null) ? array_values(array_filter($snapshot['statements'], 'is_array')) : [];
$messages = is_array($snapshot['messages'] ?? null) ? array_values(array_filter($snapshot['messages'], 'is_array')) : [];
$tickets = is_array($snapshot['tickets'] ?? null) ? array_values(array_filter($snapshot['tickets'], 'is_array')) : [];
$accountId = (int) ($account['id'] ?? 0);
$ipAssignments = $db instanceof PDO ? app_ip_customer_assignments($db, $accountId) : [];
$ipHistory = $db instanceof PDO ? app_ip_customer_history($db, $accountId, 20) : [];
$legacySectionTables = billing_profile_legacy_section_tables($db instanceof PDO ? $db : null, $accountId);
$oldBillingInfoTables = $legacySectionTables['billing_info_table'] ?? billing_profile_old_tables($snapshot, 'billing_info_table');
$oldIptvServiceTables = $legacySectionTables['iptv_services'] ?? billing_profile_old_tables($snapshot, 'iptv_services');
$oldSipServiceTables = $legacySectionTables['sip_services'] ?? billing_profile_old_tables($snapshot, 'sip_services');
$oldStaticIpServiceTables = $legacySectionTables['static_ip_services'] ?? billing_profile_old_tables($snapshot, 'static_ip_services');
$oldCableTvServiceTables = $legacySectionTables['cable_tv_services'] ?? billing_profile_old_tables($snapshot, 'cable_tv_services');
$oldPaymentHistoryTables = $legacySectionTables['payment_history'] ?? billing_profile_old_tables($snapshot, 'payment_history');
$oldPaymentStatementTables = $legacySectionTables['payment_statement'] ?? billing_profile_old_tables($snapshot, 'payment_statement');
$oldCustomerLogTables = $legacySectionTables['customer_logs'] ?? billing_profile_old_tables($snapshot, 'customer_logs');
$oldRadiusLogTables = $legacySectionTables['radius_logs'] ?? billing_profile_old_tables($snapshot, 'radius_logs');
$oldLoginAttemptTables = $legacySectionTables['login_attempts'] ?? billing_profile_old_tables($snapshot, 'login_attempts');
$customerEquipmentLifecycle = $db instanceof PDO ? app_mgs_customer_equipment_lifecycle($db, $subscriberCode, 20) : [];

$radiusServices = [];
$dbPayments = [];
$radiusRows = [];
$postAuthRows = [];
$accountingRows = [];
$dataWarnings = [];
$messageTemplates = app_message_templates();
$primaryIpAssignment = is_array($ipAssignments[0] ?? null) ? $ipAssignments[0] : [];
$firstInternetService = is_array($services[0] ?? null) ? $services[0] : [];
$currentServiceUsername = (string) (($primaryIpAssignment['service_username'] ?? '') ?: ($firstInternetService['username'] ?? $billing['pppoe_username'] ?? $subscriberCode));
$currentLocalIp = (string) (($primaryIpAssignment['local_ip'] ?? '') ?: ($firstInternetService['assigned_ip'] ?? $firstInternetService['ip_address'] ?? $billing['assigned_ip'] ?? $billing['ip_address'] ?? ''));
$currentNatIp = (string) (($primaryIpAssignment['nat_public_ip'] ?? '') ?: ($firstInternetService['nat_public_ip'] ?? $billing['nat_public_ip'] ?? ''));
$currentStaticIp = (string) (($primaryIpAssignment['static_ip'] ?? '') ?: ($firstInternetService['static_ip'] ?? $billing['static_ip'] ?? ''));

if ($db instanceof PDO) {
    try {
        $serviceStatement = $db->prepare(
            'SELECT s.*, t.name AS tariff_name, t.monthly_fee, t.download_mbps, t.upload_mbps, p.name AS pool_name, p.pool_type
             FROM billing_radius_services s
             LEFT JOIN billing_tariffs t ON t.id = s.tariff_id
             LEFT JOIN billing_ip_pools p ON p.id = s.ip_pool_id
             WHERE s.account_id = :account_id
             ORDER BY s.created_at DESC, s.id DESC'
        );
        $serviceStatement->execute(['account_id' => $accountId]);
        $radiusServices = $serviceStatement->fetchAll() ?: [];
    } catch (Throwable $exception) {
        $dataWarnings[] = 'Radius servisleri oxunmadi: ' . $exception->getMessage();
    }

    try {
        $paymentStatement = $db->prepare(
            'SELECT *
             FROM billing_payments
             WHERE account_id = :account_id
             ORDER BY created_at DESC, id DESC
             LIMIT 50'
        );
        $paymentStatement->execute(['account_id' => $accountId]);
        $dbPayments = $paymentStatement->fetchAll() ?: [];
    } catch (Throwable $exception) {
        $dataWarnings[] = 'Odenis cixarisi oxunmadi: ' . $exception->getMessage();
    }

    $radiusUsernames = billing_profile_unique_usernames($account, $billing, $radiusServices);
    if ($radiusUsernames !== []) {
        try {
            [$checkClause, $checkParams] = billing_profile_in_clause($radiusUsernames, 'rcheck');
            [$replyClause, $replyParams] = billing_profile_in_clause($radiusUsernames, 'rreply');
            $radiusStatement = $db->prepare(
                "SELECT 'radcheck' AS source_table, username, attribute, op, value FROM radcheck WHERE username IN ({$checkClause})
                 UNION ALL
                 SELECT 'radreply' AS source_table, username, attribute, op, value FROM radreply WHERE username IN ({$replyClause})
                 ORDER BY username, source_table, attribute"
            );
            $radiusStatement->execute($checkParams + $replyParams);
            $radiusRows = $radiusStatement->fetchAll() ?: [];
        } catch (Throwable $exception) {
            $dataWarnings[] = 'Radius auth cavabi oxunmadi: ' . $exception->getMessage();
        }

        try {
            [$postClause, $postParams] = billing_profile_in_clause($radiusUsernames, 'rpost');
            $postAuthStatement = $db->prepare(
                "SELECT *
                 FROM radpostauth
                 WHERE username IN ({$postClause})
                 ORDER BY authdate DESC, id DESC
                 LIMIT 30"
            );
            $postAuthStatement->execute($postParams);
            $postAuthRows = $postAuthStatement->fetchAll() ?: [];
        } catch (Throwable $exception) {
            $dataWarnings[] = 'Radius login loglari oxunmadi: ' . $exception->getMessage();
        }

        try {
            [$acctClause, $acctParams] = billing_profile_in_clause($radiusUsernames, 'racct');
            $acctStatement = $db->prepare(
                "SELECT *
                 FROM radacct
                 WHERE username IN ({$acctClause})
                 ORDER BY COALESCE(acctupdatetime, acctstarttime) DESC, radacctid DESC
                 LIMIT 30"
            );
            $acctStatement->execute($acctParams);
            $accountingRows = $acctStatement->fetchAll() ?: [];
        } catch (Throwable $exception) {
            $dataWarnings[] = 'Radius accounting oxunmadi: ' . $exception->getMessage();
        }
    }
} else {
    $radiusUsernames = billing_profile_unique_usernames($account, $billing, $services);
    $primaryUsername = (string) ($radiusUsernames[0] ?? $account['subscriber_code'] ?? '');
    $primaryIp = (string) (($billing['static_ip'] ?? '') ?: ($billing['ip_address'] ?? '10.11.20.10'));
    $primaryPassword = (string) (($billing['pppoe_password'] ?? '') ?: ($services[0]['password'] ?? 'mock-pass'));

    $radiusRows = [
        ['username' => $primaryUsername, 'source_table' => 'radcheck', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $primaryPassword],
        ['username' => $primaryUsername, 'source_table' => 'radreply', 'attribute' => 'Service-Type', 'op' => ':=', 'value' => 'Framed-User'],
        ['username' => $primaryUsername, 'source_table' => 'radreply', 'attribute' => 'Framed-IP-Address', 'op' => ':=', 'value' => $primaryIp],
    ];
    $postAuthRows = [
        ['authdate' => date('Y-m-d H:i:s', strtotime('-15 minutes')), 'username' => $primaryUsername, 'reply' => 'Access-Accept', 'class' => 'Mock Radius'],
        ['authdate' => date('Y-m-d H:i:s', strtotime('-1 day')), 'username' => $primaryUsername, 'reply' => 'Access-Accept', 'class' => 'Mock Radius'],
    ];
    $accountingRows = [[
        'acctstarttime' => date('Y-m-d H:i:s', strtotime('-6 hours')),
        'acctstoptime' => '',
        'acctupdatetime' => date('Y-m-d H:i:s', strtotime('-2 minutes')),
        'username' => $primaryUsername,
        'nasipaddress' => '85.132.57.218',
        'nasportid' => 'TenGigabitEthernet1/2/0.1001',
        'callingstationid' => (string) ($services[0]['mac'] ?? 'MOCK-MAC'),
        'framedipaddress' => $primaryIp,
        'acctoutputoctets' => billing_parse_traffic_bytes($traffic['download_used'] ?? 0),
        'acctinputoctets' => billing_parse_traffic_bytes($traffic['upload_used'] ?? 0),
        'acctterminatecause' => 'Active',
    ]];
}

$profileExpiresAt = app_billing_payload_primary_expires_at(['internet_services' => $services], $billing);
if ($profileExpiresAt === '' && is_array($radiusServices[0] ?? null)) {
    $profileExpiresAt = (string) ($radiusServices[0]['expires_at'] ?? '');
}
if ($profileExpiresAt !== '' && strtotime($profileExpiresAt) !== false) {
    $billing['expires_at'] = date('Y-m-d H:i:s', strtotime($profileExpiresAt));
    $billing['days_left'] = app_billing_days_left($profileExpiresAt);
    $billing['remaining_days_raw'] = app_billing_remaining_days_text($profileExpiresAt);
}
$profileTariff = ($db instanceof PDO) ? app_billing_account_tariff($db, $account, $billing) : null;
$monthlyBreakdown = app_billing_account_monthly_total($account, $billing, $profileTariff, $snapshot);
$billing['internet_monthly_fee'] = number_format($monthlyBreakdown['internet'], 2, '.', '') . ' AZN';
$billing['extra_services_total'] = number_format($monthlyBreakdown['extra'], 2, '.', '') . ' AZN';
$billing['monthly_total'] = number_format($monthlyBreakdown['total'], 2, '.', '') . ' AZN';
$billing['monthly_fee'] = $billing['monthly_total'];

$statusText = strtolower((string) ($billing['status'] ?? $profile['status'] ?? ''));
$serviceState = in_array($statusText, ['aktiv', 'active', 'online'], true) ? 'online' : 'offline';
$paymentRows = $dbPayments !== [] ? $dbPayments : $payloadPayments;
$messageRows = $db instanceof PDO ? app_message_customer_history($accountId, 30) : [];
if ($messageRows === []) {
    $messageRows = $messages;
}
$subscriberCode = (string) ($profile['subscriber_code'] ?? $account['subscriber_code'] ?? '');
$contractNo = (string) ($profile['contract_no'] ?? $account['contract_no'] ?? '');
$fullName = (string) ($profile['full_name'] ?? $account['full_name'] ?? '');
$paymentTotal = 0.0;
foreach ($paymentRows as $paymentRow) {
    $paymentTotal += (float) ($paymentRow['amount'] ?? 0);
}
$openTicketCount = 0;
foreach ($tickets as $ticketRow) {
    if ((string) ($ticketRow['status'] ?? '') !== 'Baglandi') {
        $openTicketCount++;
    }
}
$onlineSessionCount = 0;
foreach ($accountingRows as $acctRow) {
    if (trim((string) ($acctRow['acctstoptime'] ?? '')) === '') {
        $onlineSessionCount++;
    }
}
if ($onlineSessionCount > 0) {
    $serviceState = 'online';
}
$activeOntCount = 0;
foreach ($onts as $ontRow) {
    $ontStatus = strtolower((string) billing_profile_field($ontRow, ['status'], ''));
    if ($ontStatus === '' || str_contains($ontStatus, 'online') || str_contains($ontStatus, 'aktiv')) {
        $activeOntCount++;
    }
}
$riskScore = max(0, min(10, (int) ($profile['risk_score'] ?? $account['risk_score'] ?? 0)));
$priorityScore = max(0, min(10, (int) ($profile['priority_score'] ?? $account['priority_score'] ?? 0)));
$qoeScore = max(35, min(100, 92 - ($riskScore * 5) + ($serviceState === 'online' ? 8 : -12)));
$activityIntelligence = $db instanceof PDO
    ? app_customer_activity_intelligence($db, $account)
    : app_customer_activity_intelligence_from_rows($account, $paymentRows, $accountingRows, $tickets);
$activityRiskClass = strtolower((string) ($activityIntelligence['churn_risk'] ?? 'low'));
$displayStatus = (string) ($billing['status'] ?? $profile['status'] ?? '-');
$displayPlan = (string) ($billing['tariff'] ?? $profile['plan'] ?? $account['plan'] ?? '-');
$displayBalance = (string) ($billing['balance'] ?? '-');
$displayDaysLeft = (string) (($billing['remaining_days_raw'] ?? '') ?: '-');
$profileReturnPath = 'billing-customer-profile.php?id=' . rawurlencode((string) ($account['id'] ?? 0)) . '#profile-tickets';
$ticketSubjects = app_portal_ticket_subjects();
$profileTabs = [
    'overview' => ['label' => 'Overview', 'anchor' => 'profile-main'],
    'payments' => ['label' => 'Odenisler', 'anchor' => 'profile-payments'],
    'network' => ['label' => 'Network', 'anchor' => 'profile-services'],
    'devices' => ['label' => 'Devices', 'anchor' => 'profile-modems'],
    'tickets' => ['label' => 'Ticketler', 'anchor' => 'profile-tickets'],
    'communication' => ['label' => 'Communication', 'anchor' => 'profile-messages'],
    'ai' => ['label' => 'AI', 'anchor' => 'profile-intelligence'],
];
$activeProfileTab = strtolower(clean_text((string) ($_GET['tab'] ?? 'overview')));
if (!array_key_exists($activeProfileTab, $profileTabs)) {
    $activeProfileTab = 'overview';
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Musteri profili</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .customer-profile-hero{grid-template-columns:minmax(0,1fr) 360px}
        .customer-profile-actions{display:flex;gap:10px;flex-wrap:wrap}
        .customer-profile-section{scroll-margin-top:90px}
        .customer-profile-overview{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:18px}
        .customer-profile-overview span{display:block;color:var(--portal-muted);font-size:.78rem;text-transform:uppercase;letter-spacing:.06em}
        .customer-profile-overview strong{display:block;margin-top:4px;color:var(--portal-text);font-size:1.02rem}
        .customer-profile-note{max-width:520px;white-space:normal;word-break:break-word}
        .customer-profile-nav{margin-top:18px}
        .customer-profile-nav a.is-active{background:rgba(14,132,141,.13);border-color:rgba(14,132,141,.32);color:#075c63}
        .customer-profile-section[hidden],.customer-profile-section-group[hidden]{display:none!important}
        .customer-context-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .customer-context-action{display:flex;align-items:center;gap:9px;padding:11px 12px;border:1px solid rgba(148,163,184,.28);border-radius:15px;background:rgba(248,250,252,.82);color:var(--portal-text);font-weight:900;text-decoration:none}
        .customer-context-action-form{margin:0}
        .customer-context-action-form .customer-context-action{width:100%;border:1px solid rgba(148,163,184,.28);cursor:pointer;font:inherit;text-align:left}
        .customer-context-action i{color:#f97316}
        .customer-ticket-mini{display:grid;gap:10px;margin-top:12px}
        .customer-ticket-mini select,.customer-ticket-mini textarea{width:100%}
        .customer-profile-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .customer-profile-kv div{border:1px solid var(--portal-border);border-radius:16px;padding:12px;background:var(--portal-surface-soft)}
        .customer-profile-kv span{display:block;color:var(--portal-muted);font-size:.76rem;text-transform:uppercase;letter-spacing:.06em}
        .customer-profile-kv strong{display:block;margin-top:5px;color:var(--portal-text);word-break:break-word}
        .customer-intelligence-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .customer-intelligence-card{border:1px solid rgba(148,163,184,.28);border-radius:18px;background:linear-gradient(180deg,#fff,rgba(248,250,252,.86));padding:14px}
        .customer-intelligence-card span{display:block;color:var(--portal-muted);font-size:.74rem;text-transform:uppercase;letter-spacing:.06em}
        .customer-intelligence-card strong{display:block;margin-top:6px;color:var(--portal-text);font-size:1.35rem}
        .customer-intelligence-card small{display:block;margin-top:5px;color:var(--portal-muted);line-height:1.4}
        .customer-intelligence-risk{display:inline-flex;align-items:center;border-radius:999px;padding:7px 10px;font-weight:900}
        .customer-intelligence-risk.is-low{background:#dcfce7;color:#166534}
        .customer-intelligence-risk.is-medium{background:#ffedd5;color:#9a3412}
        .customer-intelligence-risk.is-high,.customer-intelligence-risk.is-critical{background:#fee2e2;color:#991b1b}
        .customer-intelligence-timeline{display:grid;grid-template-columns:repeat(12,minmax(42px,1fr));gap:8px;align-items:end;min-height:150px;margin-top:12px}
        .customer-intelligence-month{display:grid;gap:7px;align-items:end;text-align:center;color:var(--portal-muted);font-size:.72rem}
        .customer-intelligence-bar{height:108px;border-radius:999px;background:rgba(226,232,240,.9);position:relative;overflow:hidden}
        .customer-intelligence-bar i{position:absolute;left:0;right:0;bottom:0;border-radius:999px 999px 0 0;background:linear-gradient(180deg,#22c55e,#0e848d)}
        .customer-intelligence-ai{border:1px solid rgba(14,132,141,.18);border-radius:20px;background:linear-gradient(135deg,rgba(14,132,141,.09),rgba(255,139,82,.08));padding:16px;color:var(--portal-text);line-height:1.65}
        .customer-message-layout{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(520px,1fr);gap:24px;align-items:start}
        .customer-message-feed{display:grid;gap:14px;min-width:0}
        .customer-message-card{display:grid;gap:12px;padding:18px 20px;border:1px solid rgba(148,163,184,.24);border-radius:20px;background:linear-gradient(180deg,#fff,rgba(248,250,252,.96))}
        .customer-message-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
        .customer-message-head strong{display:block;color:var(--portal-text)}
        .customer-message-meta{display:flex;flex-wrap:wrap;gap:8px;color:var(--portal-muted);font-size:.8rem}
        .customer-message-body{white-space:pre-wrap;word-break:break-word;color:var(--portal-text);line-height:1.55}
        .customer-message-composer{position:sticky;top:92px;min-width:0;border:1px solid rgba(148,163,184,.24);border-radius:24px;background:#fff;padding:22px 24px;box-shadow:0 18px 36px rgba(15,23,42,.06)}
        .customer-message-composer h3{margin:0 0 6px;color:var(--portal-text)}
        .customer-message-composer p{margin:0 0 14px;color:var(--portal-muted);line-height:1.5}
        .customer-message-form{display:grid;gap:12px}
        .customer-message-form .portal-form-row{margin:0}
        .customer-message-inline{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .customer-message-context{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:0 0 14px}
        .customer-message-context .portal-muted-card{height:100%}
        .customer-message-context strong{display:block;margin-top:6px;color:var(--portal-text)}
        .customer-message-char{display:flex;justify-content:flex-end;color:var(--portal-muted);font-size:.78rem;font-weight:800}
        .customer-message-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .customer-message-actions label{display:inline-flex;align-items:center;gap:8px;color:var(--portal-muted);font-size:.85rem}
        .customer-service-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 18px}
        .customer-service-form .wide{grid-column:span 2}
        .legacy-profile-shell{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .legacy-profile-card{border:1px solid var(--portal-border);border-radius:24px;background:linear-gradient(180deg,#fff,rgba(248,250,252,.92));box-shadow:0 18px 42px rgba(15,23,42,.08);overflow:hidden}
        .legacy-profile-card summary{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 20px;cursor:pointer;list-style:none}
        .legacy-profile-card summary::-webkit-details-marker{display:none}
        .legacy-profile-card summary h3{margin:0;color:var(--portal-text);font-size:1rem}
        .legacy-profile-card summary p{margin:4px 0 0;color:var(--portal-muted);font-size:.84rem}
        .legacy-profile-count{display:inline-flex;align-items:center;gap:6px;border-radius:999px;background:rgba(14,165,233,.1);color:#0369a1;font-weight:800;padding:7px 10px;white-space:nowrap}
        .legacy-profile-body{border-top:1px solid var(--portal-border);padding:16px 20px 20px}
        .legacy-profile-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .legacy-profile-kv div,.legacy-profile-event{border:1px solid rgba(148,163,184,.28);border-radius:16px;background:rgba(248,250,252,.76);padding:12px}
        .legacy-profile-kv span,.legacy-profile-event span{display:block;color:var(--portal-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em}
        .legacy-profile-kv strong,.legacy-profile-event strong{display:block;margin-top:4px;color:var(--portal-text);word-break:break-word}
        .legacy-profile-timeline{display:grid;gap:10px;max-height:460px;overflow:auto;padding-right:4px}
        .legacy-profile-event{position:relative}
        .legacy-profile-event small{display:block;margin-top:6px;color:var(--portal-muted);line-height:1.45}
        .legacy-profile-empty{border:1px dashed rgba(148,163,184,.55);border-radius:16px;padding:14px;color:var(--portal-muted);background:rgba(248,250,252,.72)}
        @media (max-width: 1100px){.customer-profile-overview{grid-template-columns:repeat(2,minmax(0,1fr))}.customer-intelligence-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.customer-intelligence-timeline{grid-template-columns:repeat(6,minmax(42px,1fr))}}
        @media (max-width: 980px){.customer-profile-hero{grid-template-columns:1fr}.customer-profile-kv{grid-template-columns:1fr}.customer-service-form{grid-template-columns:1fr 1fr}.customer-service-form .wide{grid-column:auto}.legacy-profile-shell{grid-template-columns:1fr}.customer-message-layout{grid-template-columns:1fr}.customer-message-composer{position:static}}
        @media (max-width: 620px){.customer-profile-overview{grid-template-columns:1fr}.customer-context-actions{grid-template-columns:1fr}.customer-service-form{grid-template-columns:1fr}.legacy-profile-kv{grid-template-columns:1fr}.customer-message-inline{grid-template-columns:1fr}.customer-message-context{grid-template-columns:1fr}.customer-intelligence-grid{grid-template-columns:1fr}.customer-intelligence-timeline{grid-template-columns:repeat(3,minmax(42px,1fr))}}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('Musteri profili', 'customers', $lang); ?>

    <main class="portal-layout">
        <div class="customer-profile-actions portal-reveal">
            <a class="portal-primary-btn portal-inline-link" href="billing-cash-payment.php?subscriber_code=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">Odenis al</a>
                    <a class="portal-ghost-btn portal-inline-link" href="operations-center.php?section=search&q=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">SPoG bax</a>
            <a class="portal-ghost-btn portal-inline-link" href="billing-customers.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Siyahiya qayit</a>
            <a class="portal-ghost-btn portal-inline-link" href="message-center.php?q=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">Mesaj gonder</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>" data-autohide-alert>
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($dataWarnings !== []): ?>
            <div class="portal-alert portal-alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= e(implode(' | ', $dataWarnings)); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero customer-profile-hero portal-reveal">
            <div>
                <span class="portal-kicker">Tam musteri karti</span>
                <h1><?= e($fullName); ?></h1>
                <p>Bu profilde billing, modem/ONT, ticketler, odenis cixarisi, hesab cixarisi, mesaj tarixcesi ve Radius loglari bir yerde toplanir.</p>
                <div class="customer-profile-nav mgs-tab-nav">
                    <?php foreach ($profileTabs as $tabKey => $tab): ?>
                        <a class="<?= $activeProfileTab === $tabKey ? 'is-active' : ''; ?>" href="<?= e(billing_profile_tab_url($account, $lang, (string) $tabKey, (string) ($tab['anchor'] ?? 'profile-main'))); ?>"><?= e((string) ($tab['label'] ?? $tabKey)); ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="customer-profile-overview">
                    <div><span>Musteri kodu</span><strong><?= e($subscriberCode ?: '-'); ?></strong></div>
                    <div><span>Muqavile</span><strong><?= e($contractNo ?: '-'); ?></strong></div>
                    <div><span>Telefon</span><strong><?= e((string) ($profile['phone'] ?? $account['phone'] ?? '-')); ?></strong></div>
                    <div><span>Erazi</span><strong><?= e((string) ($profile['area'] ?? $account['area'] ?? '-')); ?></strong></div>
                </div>
            </div>
            <article class="mgs-command-panel portal-service-card portal-service-<?= e($serviceState); ?>">
                <div class="portal-card-head">
                    <h2><?= e($subscriberCode ?: 'Kod yoxdur'); ?></h2>
                    <small><?= e($contractNo ?: 'Muqavile yoxdur'); ?></small>
                </div>
                <div class="portal-profile-list">
                    <div><span>Status</span><strong><?= e($displayStatus); ?></strong></div>
                    <div><span>Bitme / qalan gun</span><strong><?= e((string) (($billing['expires_at_raw'] ?? '') ?: billing_profile_datetime($billing['expires_at'] ?? null))); ?><?= $displayDaysLeft !== '-' ? ' / ' . e($displayDaysLeft) : ''; ?></strong></div>
                    <div><span>Balans</span><strong><?= e($displayBalance); ?></strong></div>
                    <div><span>Tarif</span><strong><?= e($displayPlan); ?></strong></div>
                </div>
            </article>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Servis', (string) (max(count($services), count($radiusServices)) + count($extraServices)), 'Internet + elave xidmetler', 'fa-network-wired', 'blue', billing_profile_tab_url($account, $lang, 'network', 'profile-services')); ?>
            <?php billing_render_stat_card('Online sessiya', (string) $onlineSessionCount, 'Aciq RADIUS accounting', 'fa-signal', 'green', billing_profile_tab_url($account, $lang, 'network', 'profile-radius-accounting')); ?>
            <?php billing_render_stat_card('Modem/ONT', (string) count($onts), $activeOntCount . ' aktiv qeyd', 'fa-wifi', 'orange', billing_profile_tab_url($account, $lang, 'devices', 'profile-modems')); ?>
            <?php billing_render_stat_card('Aciq ticket', (string) $openTicketCount, count($tickets) . ' umumi muraciet', 'fa-ticket', $openTicketCount > 0 ? 'red' : 'green', billing_profile_tab_url($account, $lang, 'tickets', 'profile-tickets')); ?>
            <?php billing_render_stat_card('Odenis', billing_profile_money($paymentTotal), count($paymentRows) . ' son emeliyyat', 'fa-wallet', 'navy', billing_profile_tab_url($account, $lang, 'payments', 'profile-payments')); ?>
            <?php billing_render_stat_card('QoE score', (string) $qoeScore, 'Risk ' . $riskScore . '/10, prioritet ' . $priorityScore . '/10', 'fa-gauge-high', $qoeScore >= 80 ? 'green' : 'orange', billing_profile_tab_url($account, $lang, 'overview', 'profile-main')); ?>
            <?php billing_render_stat_card('Activity AI', (string) ($activityIntelligence['activity_score'] ?? 0), 'Payment ' . (string) ($activityIntelligence['payment_score'] ?? 0) . '/100, risk ' . (string) ($activityIntelligence['churn_risk'] ?? '-'), 'fa-chart-line', ((int) ($activityIntelligence['activity_score'] ?? 0) >= 70 ? 'green' : 'orange'), billing_profile_tab_url($account, $lang, 'ai', 'profile-intelligence')); ?>
        </section>

        <section class="mgs-profile-shell portal-reveal">
            <aside class="mgs-profile-sidebar">
                <article class="mgs-profile-card">
                    <h2>Musteri xulasesi</h2>
                    <div class="mgs-profile-kv">
                        <div><span>Kod</span><strong><?= e($subscriberCode ?: '-'); ?></strong></div>
                        <div><span>Status</span><strong><?= e($displayStatus); ?></strong></div>
                        <div><span>Qalan gun</span><strong><?= e($displayDaysLeft); ?></strong></div>
                        <div><span>Balans</span><strong><?= e($displayBalance); ?></strong></div>
                    </div>
                </article>
                <article class="mgs-profile-card">
                    <h2>Router binding</h2>
                    <?php if ($customerRouter !== null): ?>
                        <div class="mgs-profile-kv">
                            <div><span>Router</span><strong><?= e((string) ($customerRouter['router_name'] ?? '-')); ?></strong></div>
                            <div><span>Management</span><strong><?= e((string) ($customerRouter['management_ip'] ?? '-')); ?></strong></div>
                            <div><span>NAS</span><strong><?= e((string) ($customerRouter['nas_identity'] ?? '-')); ?></strong></div>
                            <div><span>Status</span><strong><?= (int) ($customerRouter['is_active'] ?? 0) === 1 ? 'Aktiv' : 'Passiv'; ?></strong></div>
                        </div>
                    <?php else: ?>
                        <p>Router teyin edilmeyib.</p>
                    <?php endif; ?>
                    <?php if ($activeRouters !== []): ?>
                        <form class="portal-stacked-form portal-section-gap" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="bind_customer_router">
                            <select name="router_id" aria-label="Router" required>
                                <option value="">Router secin</option>
                                <?php foreach ($activeRouters as $router): ?>
                                    <option value="<?= e((string) ((int) ($router['id'] ?? 0))); ?>" <?= (int) ($customerRouter['id'] ?? 0) === (int) ($router['id'] ?? 0) ? 'selected' : ''; ?>>
                                        <?= e((string) ($router['router_name'] ?? '-')); ?> / <?= e((string) ($router['management_ip'] ?? '-')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="portal-ghost-btn" type="submit">Binding saxla</button>
                        </form>
                    <?php elseif (!$canManageRouterBindings): ?>
                        <p>Binding deyisikliyi yalniz admin ve NOC ucun aktivdir.</p>
                    <?php endif; ?>
                </article>
                <article class="mgs-profile-card">
                    <h2>Saglamliq</h2>
                    <div class="mgs-health-score">
                        <strong><?= e((string) $qoeScore); ?></strong>
                        <span>QoE hesabi status, risk bali, aciq ticket ve aktiv sessiya melumatlarina esasen operativ gosterici kimi gosterilir.</span>
                    </div>
                </article>
                <article class="mgs-profile-card">
                    <h2>Kontekst emeliyyatlari</h2>
                    <div class="customer-context-actions">
                        <a class="customer-context-action" href="billing-customer-edit.php?id=<?= rawurlencode((string) ($account['id'] ?? 0)); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>"><i class="fa-solid fa-pen-to-square"></i> Duzelis et</a>
                        <a class="customer-context-action" href="billing-cash-payment.php?subscriber_code=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>"><i class="fa-solid fa-cash-register"></i> Odenis al</a>
                        <a class="customer-context-action" href="message-center.php?q=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>"><i class="fa-solid fa-message"></i> SMS gonder</a>
                        <form class="customer-context-action-form" method="post" data-profile-radius-refresh>
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="refresh_customer_radius_session">
                            <input type="hidden" name="username" value="<?= e($currentServiceUsername); ?>">
                            <button class="customer-context-action" type="submit"><i class="fa-solid fa-rotate"></i> RADIUS yenile</button>
                        </form>
                        <a class="customer-context-action" href="#profile-services"><i class="fa-solid fa-layer-group"></i> Paket/servis</a>
                        <a class="customer-context-action" href="<?= e(billing_profile_tab_url($account, $lang, 'network', 'profile-radius-accounting')); ?>"><i class="fa-solid fa-satellite-dish"></i> RADIUS</a>
                        <a class="customer-context-action" href="radius-natlog.php?q=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>"><i class="fa-solid fa-magnifying-glass-chart"></i> NAT log</a>
                        <a class="customer-context-action" href="network-definitions.php?q=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>"><i class="fa-solid fa-diagram-project"></i> VLAN/IP</a>
                        <a class="customer-context-action" href="<?= e(billing_profile_tab_url($account, $lang, 'devices', 'profile-modems')); ?>"><i class="fa-solid fa-wifi"></i> ONT/optik</a>
                    <a class="customer-context-action" href="operations-center.php?section=search&q=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>"><i class="fa-solid fa-table-columns"></i> SPoG</a>
                    </div>
                </article>
                <article class="mgs-profile-card customer-ticket-card">
                    <div class="customer-ticket-card-head">
                        <span class="customer-ticket-icon"><i class="fa-solid fa-ticket"></i></span>
                        <div>
                            <h2>Bu profilden ticket ac</h2>
                            <small>Musteri kodu, telefon ve erazi avtomatik ticket-e yazilir.</small>
                        </div>
                    </div>
                    <form class="customer-ticket-mini" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post">
                        <input type="hidden" name="action" value="create_operator_ticket">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">
                        <input type="hidden" name="customer_name" value="<?= e($fullName); ?>">
                        <input type="hidden" name="subscriber_code" value="<?= e($subscriberCode); ?>">
                        <input type="hidden" name="phone" value="<?= e((string) ($profile['phone'] ?? $account['phone'] ?? '')); ?>">
                        <input type="hidden" name="area" value="<?= e((string) ($profile['area'] ?? $account['area'] ?? '')); ?>">
                        <input type="hidden" name="address" value="<?= e((string) ($profile['address'] ?? $account['address'] ?? '')); ?>">
                        <label>
                            <span>Movzu</span>
                            <select name="subject" required>
                                <option value="">Movzu sec</option>
                                <?php foreach ($ticketSubjects as $subject): ?>
                                    <option value="<?= e($subject); ?>"><?= e($subject); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Problem qeydi</span>
                            <textarea name="message" rows="3" maxlength="160" placeholder="Qisa problem qeydi..." required data-ticket-message></textarea>
                        </label>
                        <div class="customer-ticket-footer">
                            <small class="portal-char-counter" data-char-counter>0 / 160</small>
                        </div>
                        <button type="submit" class="portal-primary-btn">Ticket yarat</button>
                    </form>
                </article>
            </aside>

            <div class="mgs-profile-main">

        <?php if ($activeProfileTab === 'overview'): ?>
        <section class="portal-grid portal-grid-2 portal-reveal customer-profile-section" id="profile-main">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Esas melumat</h2><small>Musterinin profil ve elaqe melumatlari.</small></div>
                <div class="customer-profile-kv">
                    <div><span>Ad soyad / sirket</span><strong><?= e($fullName ?: '-'); ?></strong></div>
                    <div><span>Kateqoriya</span><strong><?= e((string) ($profile['category'] ?? $account['category'] ?? '-')); ?></strong></div>
                    <div><span>Telefon</span><strong><?= e((string) ($profile['phone'] ?? $account['phone'] ?? '-')); ?></strong></div>
                    <div><span>Email</span><strong><?= e((string) ($profile['email'] ?? $account['email'] ?? '-')); ?></strong></div>
                    <div><span>Unvan</span><strong><?= e((string) ($profile['address'] ?? $account['address'] ?? '-')); ?></strong></div>
                    <div><span>Portal login</span><strong><?= e((string) ($account['login'] ?? '-')); ?></strong></div>
                    <div><span>Prioritet bali</span><strong><?= e((string) ($profile['priority_score'] ?? '-')); ?>/10</strong></div>
                    <div><span>Risk bali</span><strong><?= e((string) ($profile['risk_score'] ?? '-')); ?>/10</strong></div>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Billing hesabi</h2><small>Tarif, balans, bitme ve trafik xulasesi.</small></div>
                <div class="customer-profile-kv">
                    <div><span>Tarif</span><strong><?= e((string) ($billing['tariff'] ?? $profile['plan'] ?? $account['plan'] ?? '-')); ?></strong></div>
                    <div><span>Xidmet novu</span><strong><?= e((string) ($billing['service_type'] ?? '-')); ?></strong></div>
                    <div><span>IP</span><strong><?= e((string) (($billing['static_ip'] ?? '') ?: ($billing['ip_address'] ?? '-'))); ?></strong></div>
                    <div><span>Pool / zona</span><strong><?= e((string) ($billing['pool'] ?? '-')); ?> / <?= e((string) ($billing['zone'] ?? '-')); ?></strong></div>
                    <div><span>Internet qiymeti</span><strong><?= e((string) ($billing['internet_monthly_fee'] ?? '-')); ?></strong></div>
                    <div><span>Elave xidmetler</span><strong><?= e((string) ($billing['extra_services_total'] ?? '0.00 AZN')); ?></strong></div>
                    <div><span>Ayliq toplam</span><strong><?= e((string) ($billing['monthly_total'] ?? $billing['monthly_fee'] ?? '-')); ?></strong></div>
                    <div><span>Download</span><strong><?= e((string) ($traffic['download_used'] ?? '0 GB')); ?></strong></div>
                    <div><span>Upload</span><strong><?= e((string) ($traffic['upload_used'] ?? '0 GB')); ?></strong></div>
                    <div><span>Son online</span><strong><?= e((string) ($traffic['last_online'] ?? '-')); ?></strong></div>
                    <div><span>Radius status</span><strong><?= e((string) ($billing['radius_status'] ?? $billing['status'] ?? '-')); ?></strong></div>
                    <div><span>Son odenis</span><strong><?= e(billing_profile_datetime($billing['last_payment_at'] ?? null)); ?></strong></div>
                    <div><span>Odenis sayi / cem</span><strong><?= e((string) ($billing['payment_count'] ?? 0)); ?> / <?= e(billing_profile_money($billing['payment_total'] ?? 0)); ?></strong></div>
                </div>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($activeProfileTab === 'ai'): ?>
        <section class="portal-card portal-reveal customer-profile-section" id="profile-intelligence">
            <div class="portal-card-head">
                <h2>Customer Activity Intelligence</h2>
                <small>Aktivlik, odenis intizami, churn riski ve gelir itkisi birlesdirilmis AI xulasesi.</small>
            </div>
            <div class="customer-intelligence-grid">
                <div class="customer-intelligence-card"><span>Activity score</span><strong><?= e((string) ($activityIntelligence['activity_score'] ?? 0)); ?>/100</strong><small>Aktiv gun, sessiya ve cari status esasinda.</small></div>
                <div class="customer-intelligence-card"><span>Payment discipline</span><strong><?= e((string) ($activityIntelligence['payment_score'] ?? 0)); ?>/100</strong><small>Gecikme ve odenis ardicilligina gore.</small></div>
                <div class="customer-intelligence-card"><span>Ilde aktiv</span><strong><?= e((string) ($activityIntelligence['active_days_year'] ?? 0)); ?> gun</strong><small>Son 365 gun aktiv istifade.</small></div>
                <div class="customer-intelligence-card"><span>Ilde passiv</span><strong><?= e((string) ($activityIntelligence['inactive_days_year'] ?? 0)); ?> gun</strong><small>Potensial revenue loss hesabi.</small></div>
                <div class="customer-intelligence-card"><span>Cari deaktiv</span><strong><?= e((string) ($activityIntelligence['current_inactive_days'] ?? 0)); ?> gun</strong><small>Odenis edilen kimi 0 olmalidir.</small></div>
                <div class="customer-intelligence-card"><span>Orta gecikme</span><strong><?= e((string) ($activityIntelligence['average_late_days'] ?? 0)); ?> gun</strong><small>Odenis intervallarindan hesablanir.</small></div>
                <div class="customer-intelligence-card"><span>Maksimum gecikme</span><strong><?= e((string) ($activityIntelligence['max_late_days'] ?? 0)); ?> gun</strong><small>Son 12 ayda en boyuk gecikme.</small></div>
                <div class="customer-intelligence-card"><span>Churn riski</span><strong class="customer-intelligence-risk is-<?= e($activityRiskClass); ?>"><?= e((string) ($activityIntelligence['churn_risk'] ?? '-')); ?> <?= e((string) ($activityIntelligence['churn_risk_percent'] ?? 0)); ?>%</strong><small><?= e((string) ($activityIntelligence['recommendation'] ?? '-')); ?></small></div>
                <div class="customer-intelligence-card"><span>Revenue loss</span><strong><?= e(number_format((float) ($activityIntelligence['revenue_loss'] ?? 0), 2)); ?> AZN</strong><small>Aylig qiymet / 30 * passiv gun.</small></div>
            </div>
            <div class="portal-section-gap">
                <div class="portal-card-head">
                    <h2>12 ayliq aktivlik timeline</h2>
                    <small>Her ay aktiv gun faizi vizual olaraq gosterilir.</small>
                </div>
                <div class="customer-intelligence-timeline">
                    <?php foreach (($activityIntelligence['monthly_timeline'] ?? []) as $month): ?>
                        <?php $height = max(4, min(100, (int) ($month['activity_percent'] ?? 0))); ?>
                        <div class="customer-intelligence-month">
                            <div class="customer-intelligence-bar" title="<?= e((string) ($month['active_days'] ?? 0)); ?> aktiv gun"><i style="height:<?= e((string) $height); ?>%"></i></div>
                            <strong><?= e((string) ($month['activity_percent'] ?? 0)); ?>%</strong>
                            <span><?= e((string) ($month['label'] ?? '-')); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="customer-intelligence-ai portal-section-gap">
                <strong>AI netice:</strong>
                <?= e((string) ($activityIntelligence['ai_summary'] ?? 'Analiz ucun kifayet qeder data yoxdur.')); ?>
            </div>
        </section>

        <?php endif; ?>

        <?php if ($activeProfileTab === 'network'): ?>
        <section class="portal-card portal-reveal customer-profile-section" id="profile-ip">
            <div class="portal-card-head">
                <h2>IP / NAT / Static IP</h2>
                <small>RADIUS reply, IP pool engine ve musteri IP tarixcesi bir yerde.</small>
            </div>
            <div class="customer-profile-kv">
                <div><span>Radius username</span><strong><?= e($currentServiceUsername ?: '-'); ?></strong></div>
                <div><span>Local IP</span><strong><?= e($currentLocalIp ?: '-'); ?></strong></div>
                <div><span>NAT public IP</span><strong><?= e($currentNatIp ?: '-'); ?></strong></div>
                <div><span>Static IP</span><strong><?= e($currentStaticIp ?: '-'); ?></strong></div>
                <div><span>Radius sync</span><strong><?= !empty($primaryIpAssignment['radius_synced']) ? 'Synced' : 'Profil/RADIUS esasinda' ?></strong></div>
                <div><span>Aktiv assignment</span><strong><?= e((string) count($ipAssignments)); ?></strong></div>
            </div>
            <form method="post" class="portal-stacked-form portal-section-gap">
                <input type="hidden" name="action" value="update_ip_assignment">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <div class="customer-service-form">
                    <div class="portal-form-row">
                        <label>Servis username</label>
                        <select name="service_username">
                            <option value="<?= e($currentServiceUsername); ?>"><?= e($currentServiceUsername ?: 'Esas servis'); ?></option>
                            <?php foreach ($services as $service): ?>
                                <?php $serviceUsername = trim((string) ($service['username'] ?? '')); ?>
                                <?php if ($serviceUsername !== '' && $serviceUsername !== $currentServiceUsername): ?>
                                    <option value="<?= e($serviceUsername); ?>"><?= e($serviceUsername); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Local IP</label>
                        <input list="freeLocalIps" type="text" name="local_ip" value="<?= e($currentLocalIp); ?>" placeholder="10.11.x.x">
                    </div>
                    <div class="portal-form-row">
                        <label>NAT public IP</label>
                        <input list="freeNatIps" type="text" name="nat_public_ip" value="<?= e($currentNatIp); ?>" placeholder="94.20.46.x">
                    </div>
                    <div class="portal-form-row">
                        <label>Static IP</label>
                        <input list="freeStaticIps" type="text" name="static_ip" value="<?= e($currentStaticIp); ?>" placeholder="94.20.46.x">
                    </div>
                    <div class="portal-form-row wide">
                        <label>Deyisiklik sebebi</label>
                        <input type="text" name="reason" value="Operator IP deyisikliyi">
                    </div>
                </div>
                <datalist id="freeLocalIps">
                    <?php foreach ($freeLocalIps as $ip): ?><option value="<?= e((string) ($ip['ip_address'] ?? '')); ?>"><?= e((string) ($ip['pool_name'] ?? '')); ?></option><?php endforeach; ?>
                </datalist>
                <datalist id="freeNatIps">
                    <?php foreach ($freeNatIps as $ip): ?><option value="<?= e((string) ($ip['ip_address'] ?? '')); ?>"><?= e((string) ($ip['pool_name'] ?? '')); ?></option><?php endforeach; ?>
                </datalist>
                <datalist id="freeStaticIps">
                    <?php foreach ($freeStaticIps as $ip): ?><option value="<?= e((string) ($ip['ip_address'] ?? '')); ?>"><?= e((string) ($ip['pool_name'] ?? '')); ?></option><?php endforeach; ?>
                </datalist>
                <div class="portal-inline-inputs">
                    <button type="submit" class="portal-primary-btn">IP deyis ve RADIUS sync et</button>
                    <a class="portal-ghost-btn portal-inline-link" href="ip-pools.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">IP pool sehifesine kec</a>
                </div>
                <small>Qeyd: IP deyisende aktiv sessiya varsa operator CoA/disconnect etmeyi secmelidir; sistem bu merhelede RADIUS cedvellerini yenileyir.</small>
            </form>
            <form method="post" class="portal-inline-inputs portal-section-gap">
                <input type="hidden" name="action" value="release_ip_assignment">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <input type="hidden" name="service_username" value="<?= e($currentServiceUsername); ?>">
                <button type="submit" class="portal-ghost-btn">IP assignment release et</button>
                <small>Bu emeliyyat manual Local/NAT/Static IP baglantisini azad edir ve servisi RADIUS pool rejimine qaytarir.</small>
            </form>
            <?php if ($ipHistory !== []): ?>
                <div class="portal-table-wrap portal-section-gap">
                    <table class="portal-table">
                        <thead><tr><th>Tarix</th><th>Username</th><th>Local</th><th>NAT</th><th>Static</th><th>Sebeb</th></tr></thead>
                        <tbody>
                            <?php foreach ($ipHistory as $historyRow): ?>
                                <tr>
                                    <td><?= e(billing_profile_datetime($historyRow['changed_at'] ?? null)); ?></td>
                                    <td><?= e((string) ($historyRow['service_username'] ?? '-')); ?></td>
                                    <td><?= e((string) ($historyRow['old_local_ip'] ?? '-')); ?> -> <?= e((string) ($historyRow['new_local_ip'] ?? '-')); ?></td>
                                    <td><?= e((string) ($historyRow['old_nat_ip'] ?? '-')); ?> -> <?= e((string) ($historyRow['new_nat_ip'] ?? '-')); ?></td>
                                    <td><?= e((string) ($historyRow['old_static_ip'] ?? '-')); ?> -> <?= e((string) ($historyRow['new_static_ip'] ?? '-')); ?></td>
                                    <td><?= e((string) ($historyRow['reason'] ?? '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="portal-card portal-reveal customer-profile-section" id="profile-services">
            <div class="portal-card-head"><h2>Internet servisleri</h2><small>Bir muqavile altinda bir ve ya bir nece PPPoE/IPoE xidmeti.</small></div>
            <form method="post" class="portal-stacked-form" id="profile-add-service">
                <input type="hidden" name="action" value="add_radius_service">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <div class="customer-service-form">
                    <div class="portal-form-row">
                        <label>Xidmet</label>
                        <select name="service_type">
                            <option value="pppoe">PPPoE</option>
                            <option value="ipoe">IPoE</option>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Radius username</label>
                        <input type="text" name="username" placeholder="<?= e($subscriberCode !== '' ? $subscriberCode . '-2' : '1100000-2'); ?>" required>
                    </div>
                    <div class="portal-form-row">
                        <label>PPPoE sifre</label>
                        <input type="text" name="password" placeholder="bos = avtomatik">
                    </div>
                    <div class="portal-form-row">
                        <label>VLAN</label>
                        <input type="text" name="vlan" placeholder="1100">
                    </div>
                    <div class="portal-form-row wide">
                        <label>Tarif</label>
                        <select name="tariff_id" required>
                            <?php foreach ($tariffs as $tariff): ?>
                                <option value="<?= e((string) $tariff['id']); ?>"><?= e((string) $tariff['service_type']); ?> / <?= e((string) $tariff['name']); ?> / <?= e((string) $tariff['monthly_fee']); ?> AZN / <?= e((string) app_billing_tariff_default_days($tariff)); ?> gun</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row wide">
                        <label>IP pool</label>
                        <select name="pool_id" required>
                            <?php foreach ($pools as $pool): ?>
                                <option value="<?= e((string) $pool['id']); ?>"><?= e((string) $pool['service_type']); ?> / <?= e((string) $pool['name']); ?> / <?= e((string) $pool['range_start']); ?>-<?= e((string) $pool['range_end']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Dynamic IP</label>
                        <input list="freeLocalIps" type="text" name="assigned_ip" placeholder="bos = pooldan al">
                    </div>
                    <div class="portal-form-row">
                        <label>NAT public IP</label>
                        <input list="freeNatIps" type="text" name="nat_public_ip" placeholder="94.20.46.x">
                    </div>
                    <div class="portal-form-row">
                        <label>Static IP</label>
                        <input list="freeStaticIps" type="text" name="static_ip">
                    </div>
                    <div class="portal-form-row">
                        <label>MAC</label>
                        <input type="text" name="mac_address">
                    </div>
                    <div class="portal-form-row">
                        <label>Bitme tarixi</label>
                        <input type="datetime-local" name="expires_at">
                    </div>
                    <div class="portal-form-row">
                        <label>Servis gun sayi</label>
                        <input type="number" name="service_days" min="1" max="366" placeholder="bos = tarif/30 gun">
                    </div>
                    <div class="portal-form-row">
                        <label>Stabil odeme gunu</label>
                        <input type="number" name="stable_due_day" min="1" max="31" placeholder="bos = musteri stabil gunu">
                    </div>
                </div>
                <button type="submit" class="portal-primary-btn">Servisi elave et ve Radius-a yaz</button>
            </form>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Nov</th><th>Username / sifre</th><th>Tarif</th><th>IP / NAT / VLAN</th><th>Pool</th><th>Status</th><th>Bitme</th></tr></thead>
                    <tbody>
                        <?php if ($radiusServices === [] && $services === []): ?><tr><td colspan="7">Servis tapilmadi.</td></tr><?php endif; ?>
                        <?php foreach ($radiusServices as $service): ?>
                            <tr>
                                <td><?= e((string) ($service['service_type'] ?? '-')); ?></td>
                                <td><strong><?= e((string) ($service['username'] ?? '-')); ?></strong><br><small><?= e((string) ($billing['pppoe_password'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($service['tariff_name'] ?? '-')); ?><br><small><?= e(billing_profile_money($service['monthly_fee'] ?? null)); ?></small></td>
                                <td>
                                    <?= e((string) (($service['static_ip'] ?? '') ?: ($service['assigned_ip'] ?? '-'))); ?>
                                    <br><small>NAT <?= e((string) ($service['nat_public_ip'] ?? '-')); ?></small>
                                    <br><small>VLAN <?= e((string) ($billing['vlan'] ?? billing_profile_field($services[0] ?? [], ['vlan'], '-'))); ?></small>
                                </td>
                                <td><?= e((string) ($service['pool_name'] ?? '-')); ?><br><small><?= e((string) ($service['pool_type'] ?? '-')); ?></small></td>
                                <td><span class="portal-pill"><?= e((string) ($service['status'] ?? '-')); ?></span></td>
                                <td><?= e(billing_profile_datetime($service['expires_at'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($radiusServices === []): ?>
                            <?php foreach ($services as $service): ?>
                                <tr>
                                    <td><?= e((string) ($service['service_type'] ?? '-')); ?></td>
                                    <td><strong><?= e((string) ($service['username'] ?? '-')); ?></strong><br><small><?= e((string) ($service['password'] ?? '-')); ?></small></td>
                                    <td><?= e((string) ($service['tariff'] ?? '-')); ?></td>
                                    <td>
                                        <?= e((string) (($service['static_ip'] ?? '') ?: ($service['ip_address'] ?? '-'))); ?>
                                        <br><small>NAT <?= e((string) ($service['nat_public_ip'] ?? '-')); ?></small>
                                        <br><small>VLAN <?= e((string) ($service['vlan'] ?? '-')); ?></small>
                                    </td>
                                    <td><?= e((string) ($service['pool'] ?? '-')); ?></td>
                                    <td><span class="portal-pill"><?= e((string) ($service['status'] ?? '-')); ?></span></td>
                                    <td><?= e(billing_profile_datetime($billing['expires_at'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="portal-card-head portal-section-gap">
                <h2>Elave ayliq xidmetler</h2>
                <small>TV, IPTV, SIP phone, static IP ve diger pullu xidmetler ayliq toplam meblege avtomatik elave olunur.</small>
            </div>
            <form method="post" class="portal-stacked-form">
                <input type="hidden" name="action" value="add_extra_service">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <div class="customer-service-form">
                    <div class="portal-form-row">
                        <label>Xidmet tipi</label>
                        <select name="extra_type">
                            <?php foreach (app_billing_extra_service_types() as $typeKey => $typeLabel): ?>
                                <option value="<?= e($typeKey); ?>"><?= e($typeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Xidmet adi</label>
                        <input type="text" name="extra_title" placeholder="IPTV paket / SIP nomre / Static IP">
                    </div>
                    <div class="portal-form-row">
                        <label>Ayliq qiymet</label>
                        <input type="number" name="extra_price" min="0.01" step="0.01" placeholder="5.00" required>
                    </div>
                    <div class="portal-form-row">
                        <label>Status</label>
                        <select name="extra_status">
                            <option value="active">Aktiv</option>
                            <option value="inactive">Passiv</option>
                            <option value="disabled">Sondurulub</option>
                        </select>
                    </div>
                    <div class="portal-form-row wide">
                        <label>Melumat / qeyd</label>
                        <input type="text" name="extra_details" placeholder="IPTV login, SIP nomre, static IP, cihaz ve ya muqavile qeydi">
                    </div>
                </div>
                <button type="submit" class="portal-primary-btn">Elave xidmeti yaz ve ayliq meblegi yenile</button>
            </form>
            <div class="portal-table-wrap portal-section-gap">
                <table class="portal-table">
                    <thead><tr><th>Tip</th><th>Ad</th><th>Melumat</th><th>Qiymet</th><th>Status</th><th>Tarix</th></tr></thead>
                    <tbody>
                        <?php if ($extraServices === []): ?><tr><td colspan="6">Elave ayliq xidmet yoxdur.</td></tr><?php endif; ?>
                        <?php foreach ($extraServices as $extraService): ?>
                            <tr>
                                <td><?= e((string) ($extraService['type_label'] ?? $extraService['type'] ?? '-')); ?></td>
                                <td><strong><?= e((string) ($extraService['title'] ?? '-')); ?></strong></td>
                                <td class="customer-profile-note"><?= e((string) ($extraService['details'] ?? '-')); ?></td>
                                <td><?= e(billing_profile_money($extraService['price'] ?? 0)); ?></td>
                                <td><span class="portal-pill"><?= e((string) ($extraService['status'] ?? '-')); ?></span></td>
                                <td><?= e(billing_profile_datetime($extraService['created_at'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3">Aktiv elave xidmet cemi</th>
                            <th colspan="3"><?= e((string) ($billing['extra_services_total'] ?? '0.00 AZN')); ?></th>
                        </tr>
                        <tr>
                            <th colspan="3">Ayliq toplam</th>
                            <th colspan="3"><?= e((string) ($billing['monthly_total'] ?? $billing['monthly_fee'] ?? '-')); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <?php endif; ?>

        <?php if ($activeProfileTab === 'devices'): ?>
        <section class="portal-card portal-reveal customer-profile-section" id="profile-modems">
            <div class="portal-card-head"><h2>Modem / ONT melumatlari</h2><small>SN, MAC, OLT/PON/ONT yeri, kabel, optik deyerler ve temperatur.</small></div>
            <div class="portal-card-head portal-section-gap" data-customer-equipment-lifecycle>
                <h3>Customer equipment</h3>
                <small>Anbardan, texnikden ve musteriye baglanmis cihaz lifecycle tarixcesi.</small>
            </div>
            <?php if ($customerEquipmentLifecycle !== []): ?>
                <div class="mgs-insight-grid customer-equipment-lifecycle-grid">
                    <?php foreach (array_slice($customerEquipmentLifecycle, 0, 6) as $equipment): ?>
                        <?php
                        $modelTitle = trim((string) (($equipment['vendor'] ?? '') . ' ' . ($equipment['model'] ?? '')));
                        $modelTitle = $modelTitle !== '' ? $modelTitle : (string) ($equipment['device_type'] ?? 'Cihaz');
                        $equipmentStatus = (string) ($equipment['assignment_status'] ?? '-');
                        $conditionStatus = (string) ($equipment['condition_status'] ?? '-');
                        ?>
                        <article class="mgs-insight-card customer-equipment-card">
                            <span><?= e((string) ($equipment['ownership_type'] ?? 'company')); ?> / <?= e($equipmentStatus); ?></span>
                            <strong><?= e($modelTitle); ?></strong>
                            <small>SN: <?= e((string) ($equipment['serial_number'] ?? '-')); ?></small>
                            <small>MAC: <?= e((string) ($equipment['mac_address'] ?? '-')); ?></small>
                            <div class="mgs-compact-list">
                                <div class="mgs-compact-row"><span>Asset</span><strong><?= e((string) ($equipment['asset_code'] ?? '-')); ?></strong></div>
                                <div class="mgs-compact-row"><span>Condition</span><strong><?= e($conditionStatus); ?></strong></div>
                                <div class="mgs-compact-row"><span>Ticket</span><strong><?= e((string) ($equipment['installed_ticket_no'] ?? '-')); ?></strong></div>
                                <div class="mgs-compact-row"><span>Installed</span><strong><?= e(billing_profile_datetime($equipment['installed_at'] ?? null)); ?></strong></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="portal-empty-state">
                    <strong>Customer equipment baglantisi yoxdur.</strong>
                    <small>Texnik ticket baglayanda SN/MAC secdikde cihaz lifecycle avtomatik bu bolmede gorunecek.</small>
                </div>
            <?php endif; ?>
            <?php if ($onts !== []): ?>
                <div class="mgs-insight-grid">
                    <?php foreach (array_slice($onts, 0, 6) as $ont): ?>
                        <article class="mgs-insight-card">
                            <span><?= e(billing_profile_field($ont, ['olt', 'olt_name', 'device_name'])); ?> / <?= e(billing_profile_field($ont, ['pon', 'pon_port'])); ?>/<?= e(billing_profile_field($ont, ['ont_id', 'ont'])); ?></span>
                            <strong><?= e(billing_profile_field($ont, ['serial', 'sn'])); ?></strong>
                            <small>MAC: <?= e(billing_profile_field($ont, ['mac', 'mac_address'])); ?></small>
                            <div class="mgs-compact-list">
                                <div class="mgs-compact-row"><span>ONT RX/TX</span><strong><?= e(billing_profile_field($ont, ['rx_power', 'ont_rx', 'rx'])); ?> / <?= e(billing_profile_field($ont, ['tx_power', 'ont_tx', 'tx'])); ?></strong></div>
                                <div class="mgs-compact-row"><span>OLT RX/TX</span><strong><?= e(billing_profile_field($ont, ['olt_rx', 'olt_rx_power'])); ?> / <?= e(billing_profile_field($ont, ['olt_tx', 'olt_tx_power'])); ?></strong></div>
                                <div class="mgs-compact-row"><span>Temp</span><strong><?= e(billing_profile_field($ont, ['temperature', 'temp'])); ?> C</strong></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>OLT</th><th>PON/ONT</th><th>SN / MAC</th><th>RX / TX</th><th>OLT RX / TX</th><th>Temp</th><th>Wi-Fi</th><th>Kabel</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if ($onts === []): ?><tr><td colspan="9">ONT melumati yoxdur.</td></tr><?php endif; ?>
                        <?php foreach ($onts as $ont): ?>
                            <tr>
                                <td><?= e(billing_profile_field($ont, ['olt', 'olt_name', 'device_name'])); ?></td>
                                <td><?= e(billing_profile_field($ont, ['pon', 'pon_port'])); ?>/<?= e(billing_profile_field($ont, ['ont_id', 'ont'])); ?></td>
                                <td><?= e(billing_profile_field($ont, ['serial', 'sn'])); ?><br><small><?= e(billing_profile_field($ont, ['mac', 'mac_address'])); ?></small></td>
                                <td><?= e(billing_profile_field($ont, ['rx_power', 'ont_rx', 'rx'])); ?> / <?= e(billing_profile_field($ont, ['tx_power', 'ont_tx', 'tx'])); ?></td>
                                <td><?= e(billing_profile_field($ont, ['olt_rx', 'olt_rx_power'])); ?> / <?= e(billing_profile_field($ont, ['olt_tx', 'olt_tx_power'])); ?></td>
                                <td><?= e(billing_profile_field($ont, ['temperature', 'temp'])); ?> C</td>
                                <td><?= e(billing_profile_field($ont, ['wifi_ssid', 'ssid'])); ?><br><small><?= e(billing_profile_field($ont, ['wifi_password', 'wifi_pass'])); ?></small></td>
                                <td><?= e(billing_profile_field($ont, ['cable_meter', 'cable_meters', 'drop_cable_meter'])); ?> m<br><small><?= e(billing_profile_field($ont, ['fast_connector', 'connector'])); ?></small></td>
                                <td><span class="portal-pill"><?= e(billing_profile_field($ont, ['status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php endif; ?>

        <?php if ($activeProfileTab === 'payments'): ?>
        <section class="portal-grid portal-grid-2 portal-reveal customer-profile-section-group">
            <article class="portal-card customer-profile-section" id="profile-payments">
                <div class="portal-card-head"><h2>Odenis cixarisi</h2><small>Terminal, nagd ve manual odenislerin tarixi.</small></div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Tarix</th><th>Mebleg</th><th>Metod</th><th>Transaction</th><th>Qeyd/status</th></tr></thead>
                        <tbody>
                            <?php if ($paymentRows === []): ?><tr><td colspan="5">Odenis yoxdur.</td></tr><?php endif; ?>
                            <?php foreach ($paymentRows as $payment): ?>
                                <tr>
                                    <td><?= e(billing_profile_datetime($payment['created_at'] ?? ($payment['date'] ?? null))); ?></td>
                                    <td><?= e(billing_profile_money($payment['amount'] ?? '-')); ?></td>
                                    <td><?= e((string) ($payment['payment_method'] ?? $payment['method'] ?? '-')); ?></td>
                                    <td><?= e((string) ($payment['transaction_id'] ?? '-')); ?></td>
                                    <td class="customer-profile-note"><?= e((string) ($payment['note'] ?? $payment['status'] ?? '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="portal-card customer-profile-section" id="profile-statements">
                <div class="portal-card-head"><h2>Hesab / borc cixarisi</h2><small>Aylig borc, paket yenilenmesi ve balans hereketleri.</small></div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Tarix</th><th>Emeliyyat</th><th>Mebleg</th><th>Evvel</th><th>Sonra</th><th>Qeyd</th></tr></thead>
                        <tbody>
                            <?php if ($statements === []): ?><tr><td colspan="6">Hesab cixarisi yoxdur.</td></tr><?php endif; ?>
                            <?php foreach ($statements as $statement): ?>
                                <tr>
                                    <td><?= e(billing_profile_datetime($statement['created_at'] ?? $statement['date'] ?? null)); ?></td>
                                    <td><?= e(billing_profile_field($statement, ['operation', 'type', 'status'])); ?></td>
                                    <td><?= e(billing_profile_money($statement['amount'] ?? null)); ?></td>
                                    <td><?= e(billing_profile_money($statement['previous_balance'] ?? $statement['before'] ?? null)); ?></td>
                                    <td><?= e(billing_profile_money($statement['new_balance'] ?? $statement['after'] ?? null)); ?></td>
                                    <td class="customer-profile-note"><?= e(billing_profile_field($statement, ['note', 'description', 'comment'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <?php endif; ?>
        <?php if ($activeProfileTab === 'payments'): ?>
        <section class="portal-card portal-reveal customer-profile-section" id="profile-old-billing">
            <?php
            $oldSections = [
                'billing_info_table' => ['title' => 'Kohne billing: profil melumati', 'tables' => $oldBillingInfoTables],
                'iptv_services' => ['title' => 'Kohne billing: IPTV xidmetleri', 'tables' => $oldIptvServiceTables],
                'sip_services' => ['title' => 'Kohne billing: SIP xidmetleri', 'tables' => $oldSipServiceTables],
                'static_ip_services' => ['title' => 'Kohne billing: static IP xidmetleri', 'tables' => $oldStaticIpServiceTables],
                'cable_tv_services' => ['title' => 'Kohne billing: Cable TV xidmetleri', 'tables' => $oldCableTvServiceTables],
                'payment_history' => ['title' => 'Kohne billing: odenis tarixcesi', 'tables' => $oldPaymentHistoryTables],
                'payment_statement' => ['title' => 'Kohne billing: odenis cixarisi', 'tables' => $oldPaymentStatementTables],
                'customer_logs' => ['title' => 'Kohne billing: musteri loglari', 'tables' => $oldCustomerLogTables],
                'radius_logs' => ['title' => 'Kohne billing: RADIUS loglari', 'tables' => $oldRadiusLogTables],
                'login_attempts' => ['title' => 'Kohne billing: login cehdleri', 'tables' => $oldLoginAttemptTables],
            ];
            ?>
            <div class="portal-card-head">
                <h2>Kohne billing tarixcesi</h2>
                <small>Odenis, hesab cixarisi, musteri loglari, RADIUS tarixcesi ve elave xidmetler arxivden oxunur.</small>
            </div>
            <div class="legacy-profile-shell">
            <?php foreach ($oldSections as $sectionKey => $section): ?>
                <?php
                $tables = is_array($section['tables'] ?? null) ? $section['tables'] : [];
                $rowCount = billing_profile_legacy_count_rows($tables);
                ?>
                <details class="legacy-profile-card" <?= in_array($sectionKey, ['billing_info_table', 'payment_history', 'radius_logs'], true) ? 'open' : ''; ?>>
                    <summary>
                        <div>
                            <h3><?= e((string) ($section['title'] ?? $sectionKey)); ?></h3>
                            <p><?= e((string) count($tables)); ?> table / <?= e((string) $rowCount); ?> setr</p>
                        </div>
                        <span class="legacy-profile-count"><?= e((string) $rowCount); ?> qeyd</span>
                    </summary>
                    <div class="legacy-profile-body">
                        <?php if ($tables === []): ?>
                            <div class="legacy-profile-empty">Bu bolme ucun arxiv qeydi yoxdur.</div>
                        <?php endif; ?>

                        <?php foreach ($tables as $table): ?>
                            <?php
                            $headers = is_array($table['headers'] ?? null) ? $table['headers'] : [];
                            $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
                            $visibleRows = array_slice($rows, 0, 50);
                            ?>

                            <?php if ($sectionKey === 'billing_info_table'): ?>
                                <div class="legacy-profile-kv">
                                    <?php foreach ($visibleRows as $row): ?>
                                        <?php $row = is_array($row) ? $row : []; ?>
                                        <div>
                                            <span><?= e(billing_profile_legacy_cell($row, 0, 'Parametr')); ?></span>
                                            <strong><?= e(billing_profile_legacy_cell($row, 1)); ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <?php if ($rows === []): ?>
                                    <div class="legacy-profile-empty">Qeyd yoxdur.</div>
                                <?php endif; ?>
                                <div class="legacy-profile-timeline">
                                    <?php foreach ($visibleRows as $row): ?>
                                        <?php $row = is_array($row) ? $row : []; ?>
                                        <article class="legacy-profile-event">
                                            <?php if ($sectionKey === 'payment_history'): ?>
                                                <span><?= e(billing_profile_legacy_cell($row, 4, 'Tarix yoxdur')); ?></span>
                                                <strong><?= e(billing_profile_legacy_cell($row, 2, '0 AZN')); ?> / <?= e(billing_profile_legacy_cell($row, 1, 'Terminal')); ?></strong>
                                                <small>Transaction: <?= e(billing_profile_legacy_cell($row, 3)); ?></small>
                                            <?php elseif ($sectionKey === 'payment_statement'): ?>
                                                <span><?= e(billing_profile_legacy_cell($row, 5, 'Tarix yoxdur')); ?></span>
                                                <strong><?= e(billing_profile_legacy_cell($row, 1, 'Status')); ?> / <?= e(billing_profile_legacy_cell($row, 2, '0 AZN')); ?></strong>
                                                <small>Evvel gun: <?= e(billing_profile_legacy_cell($row, 3)); ?> / Evvel odenis: <?= e(billing_profile_legacy_cell($row, 4)); ?></small>
                                            <?php elseif ($sectionKey === 'customer_logs'): ?>
                                                <span><?= e(billing_profile_legacy_cell($row, 5, 'Tarix yoxdur')); ?></span>
                                                <strong><?= e(billing_profile_legacy_cell($row, 1, 'Sahe')); ?>: <?= e(billing_profile_legacy_cell($row, 2)); ?> -> <?= e(billing_profile_legacy_cell($row, 3)); ?></strong>
                                                <small>Admin: <?= e(billing_profile_legacy_cell($row, 4)); ?></small>
                                            <?php elseif ($sectionKey === 'radius_logs'): ?>
                                                <span><?= e(billing_profile_legacy_cell($row, 0, 'Baslangic yoxdur')); ?> - <?= e(billing_profile_legacy_cell($row, 1, '-')); ?></span>
                                                <strong><?= e(billing_profile_legacy_cell($row, 5, 'Sebeb yoxdur')); ?> / <?= e(billing_profile_legacy_cell($row, 2, 'Sure yoxdur')); ?></strong>
                                                <small>Download: <?= e(billing_profile_legacy_cell($row, 3)); ?> / Upload: <?= e(billing_profile_legacy_cell($row, 4)); ?></small>
                                            <?php elseif ($sectionKey === 'login_attempts'): ?>
                                                <span><?= e(billing_profile_legacy_cell($row, 0, 'Tarix yoxdur')); ?></span>
                                                <strong><?= e(billing_profile_legacy_cell($row, 1, 'Status')); ?></strong>
                                                <small><?= e(billing_profile_legacy_cell($row, 2, 'Netice yoxdur')); ?></small>
                                            <?php else: ?>
                                                <span><?= e(billing_profile_legacy_cell($row, 0, 'Qeyd')); ?></span>
                                                <strong><?= e(billing_profile_legacy_cell($row, 1, 'Xidmet')); ?> / <?= e(billing_profile_legacy_cell($row, 2, '0 AZN')); ?></strong>
                                                <small>Bitme: <?= e(billing_profile_legacy_cell($row, 3)); ?> / Borc: <?= e(billing_profile_legacy_cell($row, 4)); ?></small>
                                            <?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (count($rows) > count($visibleRows)): ?>
                                <div class="legacy-profile-empty"><?= e((string) (count($rows) - count($visibleRows))); ?> elave setr gizledildi. Tam arxiv DB-de saxlanilir.</div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            </div>
        </section>

        <?php endif; ?>
        <?php if ($activeProfileTab === 'tickets'): ?>
        <section class="portal-card portal-reveal customer-profile-section" id="profile-tickets">
            <div class="portal-card-head"><h2>Ticketler ve muracietler</h2><small>Musteriye acilmis butun CRM biletlari, hell qeydi ve texnik melumati.</small></div>
            <div class="portal-ticket-list">
                <?php if ($tickets === []): ?><article class="portal-ticket-item"><div class="portal-ticket-body"><strong>Ticket yoxdur</strong></div></article><?php endif; ?>
                <?php foreach ($tickets as $ticket): ?>
                    <article class="portal-ticket-item">
                        <div class="portal-ticket-body">
                            <strong><?= e((string) ($ticket['id'] ?? '')); ?> - <?= e((string) ($ticket['subject'] ?? '')); ?></strong>
                            <p><?= e((string) ($ticket['message'] ?? '')); ?></p>
                            <div class="portal-ticket-details">
                                <span><strong>Acilma:</strong> <?= e((string) ($ticket['opened_at'] ?? $ticket['updated_at'] ?? '-')); ?></span>
                                <span><strong>Status:</strong> <?= e((string) ($ticket['status'] ?? '-')); ?></span>
                                <span><strong>Texnik:</strong> <?= e((string) ($ticket['assigned_technician_name'] ?? '-')); ?></span>
                                <?php if ((string) ($ticket['resolved_at'] ?? '') !== ''): ?>
                                    <span><strong>Baglanma:</strong> <?= e((string) $ticket['resolved_at']); ?></span>
                                <?php endif; ?>
                                <?php if ((string) ($ticket['resolution_note'] ?? '') !== ''): ?>
                                    <span><strong>Gorulen is:</strong> <?= e((string) $ticket['resolution_note']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="portal-ticket-meta"><span><?= e((string) ($ticket['status'] ?? '-')); ?></span></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <?php endif; ?>
        <?php if ($activeProfileTab === 'communication'): ?>
        <section class="portal-card portal-reveal customer-profile-section" id="profile-messages">
            <div class="portal-card-head"><h2>Mesaj tarixcesi</h2><small>SMS, WhatsApp, Telegram, app push ve auto bildirisler.</small></div>
            <div class="customer-message-layout">
                <div class="customer-message-feed">
                    <?php if ($messageRows === []): ?>
                        <?php billing_render_empty_state('Mesaj yoxdur', 'Bu musteri ucun hele mesaj qeydiyyati yoxdur.', 'fa-message'); ?>
                    <?php endif; ?>
                    <?php foreach ($messageRows as $message): ?>
                        <?php
                        $messageChannel = trim((string) ($message['channel'] ?? '-'));
                        $messageChannelClass = billing_profile_channel_class($messageChannel);
                        ?>
                        <article class="customer-message-card">
                            <div class="customer-message-head">
                                <div>
                                    <strong><?= e((string) ($message['template_code'] ?? 'manual')); ?></strong>
                                    <div class="customer-message-meta">
                                        <span><?= e(billing_profile_datetime($message['created_at'] ?? null)); ?></span>
                                        <span class="mgs-channel-badge is-<?= e($messageChannelClass); ?>"><i class="<?= e(billing_profile_channel_icon($messageChannel)); ?>"></i><?= e($messageChannel !== '' ? $messageChannel : '-'); ?></span>
                                        <?php if (trim((string) ($message['destination'] ?? '')) !== ''): ?>
                                            <span><?= e((string) ($message['destination'] ?? '')); ?></span>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($message['fallback_chain'] ?? '')) !== ''): ?>
                                            <span><?= e((string) ($message['fallback_chain'] ?? '')); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="portal-pill"><?= e((string) ($message['status'] ?? '-')); ?></span>
                            </div>
                            <div class="customer-message-body"><?= e((string) ($message['message_body'] ?? '-')); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <aside class="customer-message-composer">
                    <h3>Mesaj gonder</h3>
                    <p>Profil daxilinden birbasa novbeye yazilir. Kanal `auto` secilende fallback zenciri istifade olunur.</p>
                    <div class="customer-message-context">
                        <div class="portal-muted-card">
                            <span>Esas hedef</span>
                            <strong><?= e((string) (($profile['phone'] ?? '') ?: ($account['phone'] ?? '-') )); ?></strong>
                        </div>
                        <div class="portal-muted-card">
                            <span>Fallback zenciri</span>
                            <strong>WhatsApp -> App -> Telegram -> SMS</strong>
                        </div>
                    </div>
                    <form method="post" class="customer-message-form" id="profile-message-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="profile_send_message">
                        <div class="customer-message-inline">
                            <div class="portal-form-row">
                                <label for="profileMessageTemplate">Sablon</label>
                                <select id="profileMessageTemplate" name="template_code">
                                    <option value="">Manual mesaj</option>
                                    <?php foreach ($messageTemplates as $template): ?>
                                        <option value="<?= e((string) ($template['code'] ?? '')); ?>"><?= e((string) (($template['title'] ?? '') ?: ($template['code'] ?? ''))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="portal-form-row">
                                <label for="profileMessageChannel">Kanal</label>
                                <select id="profileMessageChannel" name="channel">
                                    <option value="auto">Avto fallback</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="app">Oz app / portal</option>
                                    <option value="telegram">Telegram</option>
                                    <option value="sms">SMS</option>
                                </select>
                            </div>
                        </div>
                        <div class="portal-form-row">
                            <label for="profileMessageBody">Mesaj metni</label>
                            <textarea id="profileMessageBody" name="custom_message" rows="12" maxlength="640" placeholder="Manual mesaj yazin ve ya yalniz sablon secin..." data-message-body></textarea>
                            <div class="customer-message-char"><span data-message-char-count>0</span>/640</div>
                        </div>
                        <div class="customer-message-actions">
                            <button class="portal-primary-btn" type="submit">Novbeye yaz</button>
                            <label><input type="checkbox" name="test_mode" value="1"> Test rejimi</label>
                            <a class="portal-ghost-btn portal-inline-link" href="message-center.php?q=<?= rawurlencode($subscriberCode); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">Mesaj merkezini ac</a>
                        </div>
                    </form>
                </aside>
            </div>
        </section>

        <?php endif; ?>
        <?php if ($activeProfileTab === 'network'): ?>
        <section class="portal-grid portal-grid-2 portal-reveal customer-profile-section" id="profile-radius">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Radius auth cavabi</h2><small>radcheck ve radreply cedvellerinde musteriye yazilan atributlar.</small></div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Username</th><th>Cedvel</th><th>Attribute</th><th>Op</th><th>Value</th></tr></thead>
                        <tbody>
                            <?php if ($radiusRows === []): ?><tr><td colspan="5">Radius auth row yoxdur.</td></tr><?php endif; ?>
                            <?php foreach ($radiusRows as $row): ?>
                                <tr>
                                    <td><?= e((string) ($row['username'] ?? '-')); ?></td>
                                    <td><?= e((string) ($row['source_table'] ?? '-')); ?></td>
                                    <td><?= e((string) ($row['attribute'] ?? '-')); ?></td>
                                    <td><?= e((string) ($row['op'] ?? '-')); ?></td>
                                    <td class="customer-profile-note"><?= e((string) ($row['value'] ?? '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Radius login loglari</h2><small>radpostauth - son auth accept/reject cavablari.</small></div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Tarix</th><th>Username</th><th>Reply</th><th>Class</th></tr></thead>
                        <tbody>
                            <?php if ($postAuthRows === []): ?><tr><td colspan="4">Login logu yoxdur.</td></tr><?php endif; ?>
                            <?php foreach ($postAuthRows as $row): ?>
                                <tr>
                                    <td><?= e(billing_profile_datetime($row['authdate'] ?? null)); ?></td>
                                    <td><?= e((string) ($row['username'] ?? '-')); ?></td>
                                    <td><span class="portal-pill"><?= e((string) ($row['reply'] ?? '-')); ?></span></td>
                                    <td><?= e((string) ($row['class'] ?? '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="portal-card portal-reveal customer-profile-section" id="profile-radius-accounting">
            <div class="portal-card-head"><h2>Radius accounting sessiyalari</h2><small>radacct - IP, MAC, NAS, start/stop ve trafik cixarisi.</small></div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Start</th><th>Stop/update</th><th>Username</th><th>NAS</th><th>Port/MAC</th><th>IP</th><th>Download / Upload</th><th>Sebeb</th></tr></thead>
                    <tbody>
                        <?php if ($accountingRows === []): ?><tr><td colspan="8">Accounting yoxdur.</td></tr><?php endif; ?>
                        <?php foreach ($accountingRows as $row): ?>
                            <tr>
                                <td><?= e(billing_profile_datetime($row['acctstarttime'] ?? null)); ?></td>
                                <td><?= e(billing_profile_datetime(($row['acctstoptime'] ?? '') ?: ($row['acctupdatetime'] ?? null))); ?></td>
                                <td><?= e((string) ($row['username'] ?? '-')); ?></td>
                                <td><?= e((string) ($row['nasipaddress'] ?? '-')); ?></td>
                                <td><?= e((string) ($row['nasportid'] ?? '-')); ?><br><small><?= e((string) ($row['callingstationid'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($row['framedipaddress'] ?? '-')); ?></td>
                                <td><?= e(billing_profile_bytes($row['acctoutputoctets'] ?? null)); ?> / <?= e(billing_profile_bytes($row['acctinputoctets'] ?? null)); ?></td>
                                <td><?= e((string) ($row['acctterminatecause'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
            </div>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const bodyField = document.querySelector('[data-message-body]');
            const counter = document.querySelector('[data-message-char-count]');
            if (bodyField && counter) {
                const syncCount = function () {
                    counter.textContent = String(bodyField.value.length);
                };
                bodyField.addEventListener('input', syncCount);
                syncCount();
            }

            const navLinks = Array.from(document.querySelectorAll('.customer-profile-nav a[href^="#profile-"]'));
            const sections = Array.from(document.querySelectorAll('.customer-profile-section, .customer-profile-section-group'));
            if (navLinks.length === 0 || sections.length === 0) {
                return;
            }

            const hasSection = function (section, id) {
                return section.id === id || section.querySelector('#' + CSS.escape(id)) !== null;
            };
            const activate = function (id, updateHash) {
                const targetId = sections.some((section) => hasSection(section, id)) ? id : 'profile-main';
                sections.forEach((section) => {
                    section.hidden = !hasSection(section, targetId);
                });
                navLinks.forEach((link) => {
                    link.classList.toggle('is-active', link.getAttribute('href') === '#' + targetId);
                });
                if (updateHash && window.location.hash !== '#' + targetId) {
                    history.replaceState(null, '', '#' + targetId);
                }
            };
            navLinks.forEach((link) => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    activate((link.getAttribute('href') || '#profile-main').slice(1), true);
                });
            });
            activate((window.location.hash || '#profile-main').slice(1), false);
        });
    </script>
</body>
</html>
