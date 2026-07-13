<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/noc-scan-results-lib.php';
require_once __DIR__ . '/finance-module-lib.php';
require_once __DIR__ . '/hr-module-lib.php';
require_once __DIR__ . '/inventory-module-lib.php';
require_once __DIR__ . '/finance-operations-lib.php';
require_once __DIR__ . '/hr-employee-service.php';
require_once __DIR__ . '/inventory-operations-lib.php';
require_once __DIR__ . '/operations-permission-lib.php';
require_once __DIR__ . '/analytics-center-lib.php';
require_once __DIR__ . '/ui-language-lib.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();
$query = clean_text((string) ($_GET['q'] ?? ''));

if (!function_exists('spog_table_exists')) {
function spog_table_exists(?PDO $db, string $table): bool
{
    if (!$db instanceof PDO || $table === '') {
        return false;
    }

    try {
        $statement = $db->prepare('SHOW TABLES LIKE :table_name');
        $statement->execute(['table_name' => $table]);
        return (bool) $statement->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function spog_count(?PDO $db, string $sql, array $params = []): int
{
    if (!$db instanceof PDO) {
        return 0;
    }

    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function spog_sum(?PDO $db, string $sql, array $params = []): float
{
    if (!$db instanceof PDO) {
        return 0.0;
    }

    try {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return (float) $statement->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

function spog_rows(?PDO $db, string $sql, array $params = [], int $limit = 12): array
{
    if (!$db instanceof PDO) {
        return [];
    }

    try {
        $statement = $db->prepare($sql . ' LIMIT ' . max(1, min($limit, 50)));
        $statement->execute($params);
        return $statement->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function spog_service_module(string $path, array $query = []): array
{
    $response = app_mgs_service_get_json($path, $query, 25);
    return [
        'ok' => (bool) ($response['ok'] ?? false),
        'status' => (int) ($response['status'] ?? 0),
        'error' => (string) ($response['error'] ?? ''),
        'data' => is_array($response['data'] ?? null) ? $response['data'] : [],
        'url' => (string) ($response['url'] ?? ''),
    ];
}

function spog_service_rows(array $module, string $key): array
{
    $rows = $module['data'][$key] ?? [];
    return is_array($rows) ? $rows : [];
}

function spog_service_summary(array $module): array
{
    $rows = $module['data']['summary'] ?? [];
    return is_array($rows) ? $rows : [];
}

function spog_backup_flash_redirect(array $flash, string $lang): never
{
    app_session_boot();
    $_SESSION['operations_center_flash'] = $flash;
    $target = spog_operations_url('reports', $lang, ['focus' => 'backup']);
    header('Location: ' . $target);
    exit;
}

function spog_operations_flash_redirect(array $flash, string $lang, string $anchor = 'noc-panel', ?array $state = null): never
{
    app_session_boot();
    $_SESSION['operations_center_flash'] = $flash;
    if (is_array($state)) {
        $_SESSION['operations_center_state'] = $state;
    }
    $anchorMap = [
        'noc-panel' => 'noc',
        'olt-panel' => 'olt',
        'ont-panel' => 'olt',
        'inventory' => 'inventory',
        'finance' => 'finance',
        'technical' => 'technical',
        'hr' => 'hr',
        'attendance' => 'hr',
        'kpi' => 'hr',
        'bots' => 'bots',
        'reports' => 'reports',
        'backup' => 'reports',
    ];
    $targetSection = $anchorMap[ltrim($anchor, '#')] ?? 'overview';
    $targetQuery = [];
    if ($targetSection === 'finance') {
        $targetQuery['finance_tab'] = app_finance_tab($_POST);
    } elseif ($targetSection === 'hr') {
        $targetQuery['hr_tab'] = app_hr_tab($_POST);
    } elseif ($targetSection === 'inventory') {
        $targetQuery['inventory_tab'] = app_inventory_tab($_POST);
    }
    $target = spog_operations_url($targetSection, $lang, $targetQuery);
    header('Location: ' . $target);
    exit;
}

function spog_operations_url(string $section, string $lang, array $query = []): string
{
    $params = ['section' => $section] + $query;
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }

    return 'operations-center.php?' . http_build_query($params);
}

function spog_update_package_from_request(): array
{
    $typedPackage = trim((string) ($_POST['update_package'] ?? ''));
    $file = $_FILES['update_package_file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => $typedPackage];
    }

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Update fayli yuklenmedi. Upload kodu: ' . $errorCode];
    }

    $originalName = basename((string) ($file['name'] ?? 'update.zip'));
    if (!preg_match('/\.zip$/i', $originalName)) {
        return ['ok' => false, 'error' => 'Update paketi yalniz .zip fayli olmalidir.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        return ['ok' => false, 'error' => 'Update fayli temp qovluqda tapilmadi.'];
    }

    $targetDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'update-packages';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'error' => 'Update temp qovlugu yaradilmaq olmadi.'];
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $originalName) ?: 'update.zip';
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . $safeName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        if (PHP_SAPI !== 'cli' || !@rename($tmpName, $targetPath)) {
            return ['ok' => false, 'error' => 'Update fayli temp qovluqa kocurulmedi.'];
        }
    }

    return ['ok' => true, 'path' => $targetPath];
}

function spog_summary_note(array $row): string
{
    return clean_text((string) ($row['note'] ?? ''));
}

function spog_summary_value_text(array $row): string
{
    $value = $row['value'] ?? '';
    if (is_scalar($value)) {
        return clean_text((string) $value);
    }

    return '';
}

function spog_percent_value(float $value, float $max): int
{
    if ($max <= 0.0) {
        return 0;
    }

    return max(0, min(100, (int) round(($value / $max) * 100)));
}

function spog_employee_id(array $employee): string
{
    foreach (['id', 'userId', 'user_id', 'employee_id'] as $key) {
        if (isset($employee[$key]) && (string) $employee[$key] !== '') {
            return (string) $employee[$key];
        }
    }

    return '';
}

function spog_employee_name(array $employee): string
{
    foreach (['name', 'fullName', 'full_name', 'userName', 'username', 'login'] as $key) {
        if (isset($employee[$key])) {
            $value = trim((string) $employee[$key]);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }
    }

    $id = spog_employee_id($employee);
    return $id !== '' ? 'Employee #' . $id : '-';
}

function spog_employee_login(array $employee): string
{
    foreach (['login', 'email', 'userName', 'username'] as $key) {
        if (isset($employee[$key]) && trim((string) $employee[$key]) !== '') {
            return (string) $employee[$key];
        }
    }

    return '';
}

function spog_employee_role(array $employee): string
{
    foreach (['employee_role', 'roleName', 'account_type', 'type', 'role'] as $key) {
        if (isset($employee[$key])) {
            $value = trim((string) $employee[$key]);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }
    }

    return '-';
}

function spog_employee_branch(array $employee): string
{
    foreach (['branchName', 'branch_name', 'areaName', 'area_name', 'area', 'region'] as $key) {
        if (isset($employee[$key])) {
            $value = trim((string) $employee[$key]);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }
    }

    return '-';
}

function spog_is_operational_employee(array $employee): bool
{
    $haystack = strtolower(implode(' ', [
        (string) ($employee['full_name'] ?? $employee['fullName'] ?? $employee['employee_name'] ?? $employee['name'] ?? ''),
        (string) ($employee['login'] ?? $employee['username'] ?? $employee['userName'] ?? ''),
        (string) ($employee['employee_role'] ?? $employee['role'] ?? ''),
    ]));

    foreach (['role agent', 'copilot texnik', 'telegram smoke', 'smoke password', 'agent.', 'cp-tech-', 'tg-operator-'] as $marker) {
        if (str_contains($haystack, $marker)) {
            return false;
        }
    }

    return true;
}

function spog_operational_employees(array $employees): array
{
    return array_values(array_filter($employees, static fn ($employee): bool => is_array($employee) && spog_is_operational_employee($employee)));
}

function spog_monitor_tone(array $device): string
{
    $status = strtolower((string) ($device['status'] ?? $device['discoveryStatus'] ?? 'unknown'));
    if (in_array($status, ['online', 'up', 'healthy'], true)) {
        return 'up';
    }
    if (in_array($status, ['maintenance', 'planned'], true)) {
        return 'maintenance';
    }
    if (in_array($status, ['warning', 'degraded'], true)) {
        return 'warning';
    }
    if (in_array($status, ['offline', 'down', 'critical'], true)) {
        return 'down';
    }

    return 'unknown';
}

function spog_normalize_devices_for_path_down(array $devices): array
{
    $normalized = [];
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }

        $device['status'] = 'maintenance';
        $device['discoveryStatus'] = 'path_down';

        $normalized[] = $device;
    }

    return $normalized;
}

function spog_monitor_points(array $devices): array
{
    $count = count($devices);
    if ($count === 0) {
        return [];
    }

    $points = [];
    $radius = $count > 8 ? 38 : 34;
    $centerX = 50;
    $centerY = 50;
    foreach (array_values($devices) as $index => $device) {
        $angle = (-90 + (($index * 360) / max($count, 1))) * (M_PI / 180);
        $points[] = [
            'device' => $device,
            'x' => round($centerX + (cos($angle) * $radius), 2),
            'y' => round($centerY + (sin($angle) * $radius), 2),
        ];
    }

    return $points;
}

function spog_monitor_device_label(array $device): string
{
    $parts = array_filter([
        clean_text((string) ($device['vendor'] ?? '')),
        clean_text((string) ($device['model'] ?? '')),
    ], static fn (string $value): bool => $value !== '');

    return $parts !== [] ? implode(' / ', $parts) : '-';
}

function spog_monitor_device_meta(array $device): string
{
    $parts = array_filter([
        clean_text((string) ($device['deviceType'] ?? '')),
        clean_text((string) ($device['location'] ?? '')),
        clean_text((string) ($device['status'] ?? $device['discoveryStatus'] ?? '')),
    ], static fn (string $value): bool => $value !== '');

    return $parts !== [] ? implode(' • ', $parts) : '-';
}

}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_operations_action_allowed($user, $action)) {
        $requiredModule = app_operations_action_module($action) ?? 'overview';
        $anchor = match ($requiredModule) {
            'network' => 'noc-panel',
            'messaging' => 'bots',
            default => $requiredModule,
        };
        spog_operations_flash_redirect([
            'type' => 'error',
            'message' => 'Bu emeliyyat ucun rol icazeniz yoxdur.',
        ], $lang, $anchor);
    }
    if (in_array($action, ['scan_network', 'create_monitor_device'], true)) {
        if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
            spog_operations_flash_redirect([
                'type' => 'error',
                'message' => 'Network monitor emeliyyati ucun CSRF yoxlamasi ugursuz oldu.',
            ], $lang, 'noc-panel');
        }

        if ($action === 'scan_network') {
            $payload = [
                'target' => clean_text((string) ($_POST['scan_target'] ?? '')),
                'ports' => clean_text((string) ($_POST['scan_ports'] ?? '22,80,443,161')),
                'maxHosts' => max(1, min(1024, (int) ($_POST['scan_max_hosts'] ?? 64))),
                'concurrency' => max(1, min(128, (int) ($_POST['scan_concurrency'] ?? 24))),
            ];
            $result = app_mgs_service_request_json('/api/service/network-scan', 'POST', $payload, 60);
            if (empty($result['ok'])) {
                spog_operations_flash_redirect([
                    'type' => 'error',
                    'message' => 'IP scan icra olunmadi: ' . ($result['error'] ?: 'service cavabi yoxdur'),
                ], $lang, 'noc-panel');
            }

            $scanData = is_array($result['data'] ?? null) ? $result['data'] : [];
            if (!array_key_exists('target', $scanData)) {
                $scanData['target'] = $payload['target'];
            }
            spog_operations_flash_redirect([
                'type' => 'success',
                'message' => 'Scan tamamlandi. Tapilan cihazlari secib monitorinqe elave edin.',
            ], $lang, 'noc-panel', [
                'scan' => $scanData,
            ]);
        }

        if ($action === 'create_monitor_device') {
            $payload = [
                'name' => clean_text((string) ($_POST['device_name'] ?? '')),
                'ipAddress' => clean_text((string) ($_POST['device_ip'] ?? '')),
                'deviceType' => clean_text((string) ($_POST['device_type'] ?? 'auto')),
                'vendor' => clean_text((string) ($_POST['device_vendor'] ?? '')),
                'model' => clean_text((string) ($_POST['device_model'] ?? '')),
                'location' => clean_text((string) ($_POST['device_location'] ?? '')),
                'noteText' => clean_text((string) ($_POST['device_note'] ?? '')),
                'sshPort' => max(1, (int) ($_POST['ssh_port'] ?? 22)),
                'sshUsername' => clean_text((string) ($_POST['ssh_username'] ?? '')),
                'sshPassword' => (string) ($_POST['ssh_password'] ?? ''),
                'snmpPort' => max(1, (int) ($_POST['snmp_port'] ?? 161)),
                'snmpTrapPort' => max(1, (int) ($_POST['snmp_trap_port'] ?? 162)),
                'snmpVersion' => clean_text((string) ($_POST['snmp_version'] ?? '2c')),
                'snmpReadCommunity' => clean_text((string) ($_POST['snmp_read_community'] ?? 'public')),
                'snmpWriteCommunity' => clean_text((string) ($_POST['snmp_write_community'] ?? 'private')),
                'driverMode' => clean_text((string) ($_POST['driver_mode'] ?? 'real')),
            ];
            $result = app_mgs_service_request_json('/api/service/devices', 'POST', $payload, 60);
            if (empty($result['ok'])) {
                spog_operations_flash_redirect([
                    'type' => 'error',
                    'message' => 'Cihaz elave olunmadi: ' . ($result['error'] ?: 'service cavabi yoxdur'),
                ], $lang, 'noc-panel');
            }

            spog_operations_flash_redirect([
                'type' => 'success',
                'message' => 'Cihaz yaradildi ve discovery job queue-ya yazildi.',
            ], $lang, 'noc-panel');
        }
    }

    if (in_array($action, ['create_finance_income', 'void_finance_income', 'save_finance_counterparty', 'link_finance_counterparty', 'calculate_employee_bonus_period', 'change_employee_bonus_period_status', 'save_employee_bonus_setting', 'save_employee_bonus_salary_tier', 'save_employee_bonus_active_day_bracket', 'save_employee_bonus_quality_rule', 'save_customer_bonus_assignment', 'save_employee_bonus_adjustment', 'change_employee_bonus_adjustment_status', 'create_hr_employee', 'update_hr_employee', 'create_inventory_intake', 'create_inventory_branch_transfer', 'update_inventory_item', 'deactivate_inventory_item', 'adjust_inventory_stock', 'create_inventory_handover_local', 'confirm_inventory_handover', 'return_inventory_from_employee', 'create_inventory_external_request', 'approve_inventory_request', 'reject_inventory_request', 'create_inventory_category', 'create_inventory_movement', 'create_inventory_handover', 'create_finance_entry', 'save_asset_depreciation', 'create_finance_debt_record', 'record_finance_debt_payment', 'import_finance_e_invoice', 'create_technical_task', 'save_attendance_profile', 'record_attendance_event', 'save_hr_employee_compensation', 'record_hr_score_event', 'create_hr_payroll_record', 'create_hr_leave_request', 'create_hr_travel_request', 'create_hr_employee_document', 'update_hr_record_status', 'record_hr_finance_expense', 'send_bot_test_message'], true)) {
        if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
            $csrfSection = (str_contains($action, 'finance') || in_array($action, ['save_asset_depreciation','import_finance_e_invoice'], true)) ? 'finance' : (str_contains($action, 'hr_') || in_array($action, ['save_attendance_profile','record_attendance_event'], true) ? 'hr' : 'inventory');
            $csrfExtra = $csrfSection === 'finance' ? ['finance_tab' => clean_text((string)($_POST['finance_tab'] ?? 'overview'))] : ($csrfSection === 'hr' ? ['hr_tab' => clean_text((string)($_POST['hr_tab'] ?? 'overview'))] : []);
            spog_operations_flash_redirect([
                'type' => 'error',
                'message' => 'Emeliyyat ucun CSRF yoxlamasi ugursuz oldu.',
            ], $lang, $csrfSection, $csrfExtra);
        }

        $endpoint = '';
        $anchor = 'inventory';
        $payload = [];
        if ($action === 'create_finance_income') {
            $localResult = app_finance_save_entry($db, [
                'entry_id' => (int) ($_POST['entry_id'] ?? 0),
                'entry_type' => 'income',
                'title' => (string) ($_POST['title'] ?? ''),
                'category' => (string) ($_POST['category'] ?? 'manual'),
                'amount' => (float) ($_POST['amount'] ?? 0),
                'currency' => (string) ($_POST['currency'] ?? 'AZN'),
                'transaction_date' => (string) ($_POST['transaction_date'] ?? date('Y-m-d')),
                'branch_id' => (int) ($_POST['branch_id'] ?? 0),
                'counterparty_id' => (int) ($_POST['counterparty_id'] ?? 0),
                'payment_source' => (string) ($_POST['payment_source'] ?? ''),
                'reference_no' => (string) ($_POST['reference_no'] ?? ''),
                'note' => (string) ($_POST['note'] ?? ''),
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Gelir qeydi saxlanildi.' : 'Gelir saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'income']);
        } elseif ($action === 'void_finance_income') {
            $localResult = app_finance_void_entry($db, (int) ($_POST['entry_id'] ?? 0), $user, (string) ($_POST['reason'] ?? ''));
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Gelir qeydi legv edildi.' : 'Gelir legv edilmedi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'income']);
        } elseif ($action === 'save_finance_counterparty') {
            $localResult = app_finance_save_counterparty($db, [
                'counterparty_id' => (int) ($_POST['counterparty_id'] ?? 0),
                'display_name' => (string) ($_POST['display_name'] ?? ''),
                'counterparty_type' => (string) ($_POST['counterparty_type'] ?? 'company'),
                'tax_id' => (string) ($_POST['tax_id'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'address' => (string) ($_POST['address'] ?? ''),
                'status' => (string) ($_POST['status'] ?? 'active'),
                'note' => (string) ($_POST['note'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Qarsi teref reyestrde saxlanildi.' : 'Qarsi teref saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'companies']);
        } elseif ($action === 'link_finance_counterparty') {
            $localResult=app_finance_link_legacy_counterparty($db,['counterparty_id'=>(int)($_POST['counterparty_id']??0),'legacy_name'=>(string)($_POST['legacy_name']??''),'legacy_type'=>(string)($_POST['legacy_type']??'other')],$user);
            spog_operations_flash_redirect(['type'=>!empty($localResult['ok'])?'success':'error','message'=>!empty($localResult['ok'])?'Tarixi maliyye qeydleri reyestrle baglandi.':'Baglanti alinmadi: '.(string)($localResult['error']??'namelum xeta')],$lang,'finance',['finance_tab'=>'companies']);
        } elseif ($action === 'calculate_employee_bonus_period') {
            $period = app_bonus_month_period((string) ($_POST['bonus_month'] ?? ''));
            $localResult = app_bonus_calculate_period($db, (string) $period['start'], (string) $period['end'], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Emekdas bonus periodi hesablandi.' : 'Bonus hesablanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => 'calculations', 'period_id' => (int) ($localResult['period_id'] ?? 0)]);
        } elseif ($action === 'change_employee_bonus_period_status') {
            $localResult = app_bonus_update_period_status(
                $db,
                (int) ($_POST['period_id'] ?? 0),
                clean_text((string) ($_POST['next_status'] ?? '')),
                $user,
                (string) ($_POST['reason'] ?? '')
            );
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Bonus period statusu yenilendi.' : 'Status deyismedi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => clean_text((string) ($_POST['bonus_view'] ?? 'calculations')), 'period_id' => (int) ($_POST['period_id'] ?? 0)]);
        } elseif ($action === 'save_employee_bonus_setting') {
            $localResult = app_bonus_save_setting($db, [
                'setting_key' => (string) ($_POST['setting_key'] ?? ''),
                'setting_value' => (string) ($_POST['setting_value'] ?? ''),
                'value_type' => (string) ($_POST['value_type'] ?? 'string'),
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Bonus ayari saxlanildi.' : 'Bonus ayari saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => 'rules']);
        } elseif ($action === 'save_employee_bonus_salary_tier') {
            $localResult = app_bonus_save_salary_tier($db, [
                'tier_id' => (int) ($_POST['tier_id'] ?? 0),
                'min_customers' => (int) ($_POST['min_customers'] ?? 0),
                'max_customers' => (string) ($_POST['max_customers'] ?? ''),
                'salary_amount' => (float) ($_POST['salary_amount'] ?? 0),
                'area_quality_percent' => (float) ($_POST['area_quality_percent'] ?? 100),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Sabit maas tier saxlanildi.' : 'Tier saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => 'rules']);
        } elseif ($action === 'save_employee_bonus_active_day_bracket') {
            $localResult = app_bonus_save_active_day_bracket($db, [
                'bracket_id' => (int) ($_POST['bracket_id'] ?? 0),
                'min_days' => (int) ($_POST['min_days'] ?? 0),
                'max_days' => (int) ($_POST['max_days'] ?? 0),
                'active_percent' => (float) ($_POST['active_percent'] ?? 0),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Aktiv gun bracket saxlanildi.' : 'Bracket saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => 'rules']);
        } elseif ($action === 'save_employee_bonus_quality_rule') {
            $localResult = app_bonus_save_quality_rule($db, [
                'quality_rule_id' => (int) ($_POST['quality_rule_id'] ?? 0),
                'problem_key' => (string) ($_POST['problem_key'] ?? ''),
                'problem_label' => (string) ($_POST['problem_label'] ?? ''),
                'problem_source' => (string) ($_POST['problem_source'] ?? 'unknown'),
                'severity' => (string) ($_POST['severity'] ?? 'normal'),
                'quality_percent' => (float) ($_POST['quality_percent'] ?? 100),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Keyfiyyet qaydasi saxlanildi.' : 'Keyfiyyet qaydasi saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => 'rules']);
        } elseif ($action === 'save_customer_bonus_assignment') {
            $localResult = app_bonus_save_assignment($db, [
                'subscriber_code' => (string) ($_POST['subscriber_code'] ?? ''),
                'finder_employee_id' => (int) ($_POST['finder_employee_id'] ?? 0),
                'installer_employee_id' => (int) ($_POST['installer_employee_id'] ?? 0),
                'area_responsible_employee_id' => (int) ($_POST['area_responsible_employee_id'] ?? 0),
                'bonus_owner_employee_id' => (int) ($_POST['bonus_owner_employee_id'] ?? 0),
                'assigned_area_id' => (string) ($_POST['assigned_area_id'] ?? ''),
                'split_mode' => (string) ($_POST['split_mode'] ?? 'finder_100'),
                'note' => (string) ($_POST['note'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Musteri bonus izine baglandi.' : 'Musteri bonus izi saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => 'customer-traces']);
        } elseif ($action === 'save_employee_bonus_adjustment') {
            $localResult = app_bonus_save_adjustment($db, [
                'period_id' => (int) ($_POST['period_id'] ?? 0),
                'employee_id' => (int) ($_POST['employee_id'] ?? 0),
                'adjustment_type' => (string) ($_POST['adjustment_type'] ?? 'bonus'),
                'amount' => (float) ($_POST['amount'] ?? 0),
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Bonus duzelisi saxlanildi.' : 'Bonus duzelisi saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => 'adjustments']);
        } elseif ($action === 'change_employee_bonus_adjustment_status') {
            $localResult = app_bonus_update_adjustment_status($db, (int) ($_POST['adjustment_id'] ?? 0), clean_text((string) ($_POST['next_status'] ?? '')), $user, (string) ($_POST['reason'] ?? ''));
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Bonus duzelisi statusu yenilendi.' : 'Bonus duzelisi yenilenmedi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'finance', ['finance_tab' => 'employee-bonuses', 'bonus_view' => 'adjustments']);
        } elseif ($action === 'create_hr_employee') {
            $localResult = app_hr_create_employee($db, [
                'full_name' => (string) ($_POST['full_name'] ?? ''),
                'login' => (string) ($_POST['login'] ?? ''),
                'job_title' => (string) ($_POST['job_title'] ?? ''),
                'hire_date' => (string) ($_POST['hire_date'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'area' => (string) ($_POST['area'] ?? ''),
                'branch_id' => (string) ($_POST['branch_id'] ?? ''),
                'address' => (string) ($_POST['address'] ?? ''),
                'salary_mode' => (string) ($_POST['salary_mode'] ?? 'gross'),
                'effective_from' => (string) ($_POST['effective_from'] ?? ''),
                'gross_salary' => (float) ($_POST['gross_salary'] ?? 0),
                'net_salary' => (float) ($_POST['net_salary'] ?? 0),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Emekdas passiv hesabla yaradildi; rolu admin vermelidir.' : 'Emekdas yaradilmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'hr', ['hr_tab' => 'employees']);
        } elseif ($action === 'update_hr_employee') {
            $localResult = app_hr_update_employee($db, [
                'employee_account_id' => (int) ($_POST['employee_account_id'] ?? 0),
                'full_name' => (string) ($_POST['full_name'] ?? ''), 'login' => (string) ($_POST['login'] ?? ''),
                'job_title' => (string) ($_POST['job_title'] ?? ''), 'hire_date' => (string) ($_POST['hire_date'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''), 'email' => (string) ($_POST['email'] ?? ''),
                'area' => (string) ($_POST['area'] ?? ''), 'branch_id' => (string) ($_POST['branch_id'] ?? ''), 'address' => (string) ($_POST['address'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Emekdas melumatlari yenilendi.' : 'Emekdas yenilenmedi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'hr', ['hr_tab' => 'employees']);
        } elseif ($action === 'create_inventory_intake') {
            $localResult = app_inventory_post_intake($db, $_POST, $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Mal medaxili saxlanildi: ' . (string) ($localResult['document_no'] ?? '') : 'Medaxil saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'movements', 'movement_view' => 'lifecycle']);
        } elseif ($action === 'create_inventory_branch_transfer') {
            $localResult = app_inventory_transfer_branch($db, $_POST, $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Filial transferi saxlanildi: ' . (string) ($localResult['document_no'] ?? '') : 'Transfer saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'movements', 'movement_view' => 'lifecycle']);
        } elseif ($action === 'update_inventory_item') {
            $localResult = app_inventory_update_item($db, [
                'item_id' => (int) ($_POST['item_id'] ?? 0),
                'item_name' => (string) ($_POST['item_name'] ?? ''),
                'category' => (string) ($_POST['category'] ?? 'other'),
                'unit' => (string) ($_POST['unit'] ?? 'eded'),
                'minimum_quantity' => (float) ($_POST['minimum_quantity'] ?? 0),
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Mehsul karti yenilendi.' : 'Mehsul yenilenmedi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'catalog']);
        } elseif ($action === 'deactivate_inventory_item') {
            $localResult = app_inventory_deactivate_item($db, [
                'item_id' => (int) ($_POST['item_id'] ?? 0),
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Mehsul deaktiv edildi; tarixce saxlanildi.' : 'Mehsul deaktiv edilmedi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'catalog']);
        } elseif ($action === 'adjust_inventory_stock') {
            $localResult = app_inventory_adjust_stock($db, [
                'item_id' => (int) ($_POST['item_id'] ?? 0),
                'location_type' => (string) ($_POST['location_type'] ?? 'warehouse'),
                'location_id' => (string) ($_POST['location_id'] ?? 'MAIN'),
                'quantity_delta' => (float) ($_POST['quantity_delta'] ?? 0),
                'reason' => (string) ($_POST['reason'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Stok duzelisi saxlanildi: ' . (string) ($localResult['document_no'] ?? '') : 'Stok duzelisi alinmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'movements', 'movement_view' => 'lifecycle']);
        } elseif ($action === 'create_inventory_handover_local') {
            $localResult = app_inventory_handover_employee($db, [
                'item_id' => (int) ($_POST['handover_product_id'] ?? 0),
                'employee_id' => (int) ($_POST['handover_employee_user_id'] ?? 0),
                'quantity' => (float) ($_POST['handover_quantity'] ?? 0),
                'source_branch_id' => (string) ($_POST['source_branch_id'] ?? ''),
                'serial_number' => (string) ($_POST['handover_serial_number'] ?? ''),
                'mac_address' => (string) ($_POST['handover_mac_address'] ?? ''),
                'item_note' => (string) ($_POST['handover_item_note_text'] ?? ''),
                'note' => (string) ($_POST['handover_note_text'] ?? ''),
                'require_confirmation' => true,
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Tehvil emekdas tesdiqine gonderildi: ' . (string) ($localResult['document_no'] ?? '') : 'Tehvil saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'handover']);
        } elseif ($action === 'confirm_inventory_handover') {
            $localResult = app_inventory_confirm_employee_handover($db, (int) ($_POST['handover_document_id'] ?? 0), $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Tehvil tesdiqlendi ve mal emekdas balansina kecdi.' : 'Tehvil tesdiqlenmedi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'handover']);
        } elseif ($action === 'return_inventory_from_employee') {
            $localResult = app_inventory_return_employee_stock($db, [
                'item_id' => (int) ($_POST['return_item_id'] ?? 0),
                'employee_id' => (int) ($_POST['return_employee_id'] ?? 0),
                'quantity' => (float) ($_POST['return_quantity'] ?? 0),
                'destination_type' => (string) ($_POST['return_destination_type'] ?? 'warehouse'),
                'destination_id' => (string) ($_POST['return_destination_id'] ?? 'MAIN'),
                'serial_number' => (string) ($_POST['return_serial_number'] ?? ''),
                'mac_address' => (string) ($_POST['return_mac_address'] ?? ''),
                'note' => (string) ($_POST['return_note'] ?? ''),
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Emekdasdan qaytarma qebul edildi: ' . (string) ($localResult['document_no'] ?? '') : 'Qaytarma alinmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'technicians']);
        } elseif ($action === 'create_inventory_external_request') {
            [$sourceLocationType, $sourceLocationId] = app_inventory_normalize_location_pair($_POST, 'source', 'branch', (string) ($_POST['source_location_id'] ?? ''));
            $localResult = app_inventory_create_external_request($db, [
                'source_location_type' => $sourceLocationType,
                'source_location_id' => $sourceLocationId,
                'destination_type' => (string) ($_POST['destination_type'] ?? 'company'),
                'destination_name' => (string) ($_POST['destination_name'] ?? ''),
                'reason' => (string) ($_POST['reason'] ?? 'other'),
                'disposition' => (string) ($_POST['disposition'] ?? 'return_to_supplier'),
                'note' => (string) ($_POST['note'] ?? ''),
                'items' => [[
                    'item_id' => (int) ($_POST['item_id'] ?? 0),
                    'quantity' => (float) ($_POST['quantity'] ?? 0),
                    'unit_price' => (float) ($_POST['unit_price'] ?? 0),
                    'serial_number' => (string) ($_POST['serial_number'] ?? ''),
                    'mac_address' => (string) ($_POST['mac_address'] ?? ''),
                    'note' => (string) ($_POST['note'] ?? ''),
                ]],
            ], $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Qaytarma tesdiqe gonderildi: ' . (string) ($localResult['document_no'] ?? '') : 'Qaytarma yaradilmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'approvals']);
        } elseif ($action === 'approve_inventory_request') {
            $localResult = app_inventory_approve_request($db, (int) ($_POST['request_id'] ?? 0), $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Sened tesdiqlendi ve stok yenilendi.' : 'Tesdiq alinmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'approvals']);
        } elseif ($action === 'reject_inventory_request') {
            $localResult = app_inventory_reject_request($db, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['rejection_reason'] ?? ''), $user);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Sened imtina edildi; stok deyismedi.' : 'Imtina alinmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'inventory', ['inventory_tab' => 'approvals']);
        } elseif ($action === 'create_inventory_category') {
            $anchor = 'inventory';
            $categoryName = clean_text((string) ($_POST['category_name'] ?? ''));
            $categoryCode = clean_text((string) ($_POST['category_code'] ?? ''));
            $description = clean_text((string) ($_POST['category_description'] ?? ''));
            $localResult = app_mgs_inventory_upsert_category($db, $categoryName, $categoryCode, $description);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Inventory kateqoriyasi yaradildi: ' . ((string) ($localResult['name'] ?? $categoryName))
                    : ('Kateqoriya yaradilmadi: ' . ((string) ($localResult['reason'] ?? '') ?: 'melumat natamamdir')),
            ], $lang, $anchor, ['inventory_tab' => 'catalog']);
        } elseif ($action === 'create_inventory_movement') {
            // legacy_inventory_action_disabled: use intake, branch transfer or approved return flows.
            spog_operations_flash_redirect(['type'=>'error','message'=>'Kohne lokasiyasiz stok emeliyyati baglanib. Yeni medaxil/transfer formasindan istifade edin.'],$lang,'inventory',['inventory_tab'=>'movements']);
            $anchor = 'inventory';
            $endpoint = '/api/service/stock-movements';
            $movementType = clean_text((string) ($_POST['movement_type'] ?? 'purchase'));
            $productId = clean_text((string) ($_POST['product_id'] ?? ''));
            $newSku = clean_text((string) ($_POST['inventory_new_sku'] ?? ''));
            $newItemName = clean_text((string) ($_POST['inventory_new_item_name'] ?? ''));
            $newCategory = clean_text((string) ($_POST['inventory_category_code'] ?? 'ont_modem'));
            $selectedProduct = null;
            if ($productId !== '') {
                try {
                    $productStatement = $db->prepare('SELECT sku, item_name, category, unit FROM inventory_stock_items WHERE id = :id LIMIT 1');
                    $productStatement->execute(['id' => (int) $productId]);
                    $selectedProduct = $productStatement->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable) {
                    $selectedProduct = null;
                }
            }
            if (is_array($selectedProduct) || ($productId === '' && $newSku !== '')) {
                $localMovementMap = [
                    'purchase' => 'in',
                    'issue' => 'issue',
                    'return' => 'return',
                    'consume' => 'out',
                    'defective' => 'out',
                ];
                $resolvedSku = is_array($selectedProduct) ? (string) ($selectedProduct['sku'] ?? '') : $newSku;
                $resolvedName = is_array($selectedProduct) ? (string) ($selectedProduct['item_name'] ?? $resolvedSku) : ($newItemName !== '' ? $newItemName : $newSku);
                $resolvedCategory = is_array($selectedProduct) ? (string) ($selectedProduct['category'] ?? 'other') : ($newCategory !== '' ? $newCategory : 'other');
                $resolvedUnit = is_array($selectedProduct) ? (string) ($selectedProduct['unit'] ?? 'eded') : 'eded';
                $localResult = app_mgs_record_inventory_movement([
                    'sku' => $resolvedSku,
                    'item_name' => $resolvedName,
                    'category' => $resolvedCategory,
                    'unit' => $resolvedUnit,
                    'quantity' => max(0.01, (float) ($_POST['quantity'] ?? 1)),
                    'movement_type' => $localMovementMap[$movementType] ?? 'issue',
                    'technician_account_id' => (int) ($_POST['to_employee_user_id'] ?? 0),
                    'actor_account_id' => (int) ($user['id'] ?? 0),
                    'actor_name' => (string) ($user['full_name'] ?? $user['name'] ?? 'operator'),
                    'note' => clean_text((string) ($_POST['note_text'] ?? '')),
                    'allow_negative_stock' => in_array($movementType, ['issue', 'consume', 'defective'], true),
                    'metadata' => [
                        'source' => 'operations_inventory_quick_create',
                        'unit_price' => max(0, (float) ($_POST['unit_price'] ?? 0)),
                        'serial_number' => clean_text((string) ($_POST['serial_number'] ?? '')),
                        'mac_address' => clean_text((string) ($_POST['mac_address'] ?? '')),
                        'asset_label' => clean_text((string) ($_POST['asset_label'] ?? '')),
                        'usage_location' => clean_text((string) ($_POST['usage_location'] ?? '')),
                    ],
                ], true);
                spog_operations_flash_redirect([
                    'type' => !empty($localResult['ok']) ? 'success' : 'error',
                    'message' => !empty($localResult['ok'])
                        ? 'Stok hereketi yazildi.'
                        : ('Inventory hereketi alinmadi: ' . ((string) ($localResult['reason'] ?? '') ?: 'local helper cavabi yoxdur')),
                ], $lang, $anchor);
            }
            $payload = [
                'productId' => $productId,
                'movementType' => $movementType,
                'quantity' => max(0, (float) ($_POST['quantity'] ?? 0)),
                'unitPrice' => max(0, (float) ($_POST['unit_price'] ?? 0)),
                'fromBranchId' => clean_text((string) ($_POST['from_branch_id'] ?? '')),
                'toBranchId' => clean_text((string) ($_POST['to_branch_id'] ?? '')),
                'toEmployeeUserId' => clean_text((string) ($_POST['to_employee_user_id'] ?? '')),
                'serialNumber' => clean_text((string) ($_POST['serial_number'] ?? '')),
                'macAddress' => clean_text((string) ($_POST['mac_address'] ?? '')),
                'assetLabel' => clean_text((string) ($_POST['asset_label'] ?? '')),
                'usageLocation' => clean_text((string) ($_POST['usage_location'] ?? '')),
                'reasonType' => clean_text((string) ($_POST['reason_type'] ?? $movementType)),
                'noteText' => clean_text((string) ($_POST['note_text'] ?? '')),
            ];
        } elseif ($action === 'create_inventory_handover') {
            // legacy_inventory_action_disabled: employee handover must update branch and employee balances locally.
            spog_operations_flash_redirect(['type'=>'error','message'=>'Kohne tehvil emeliyyati baglanib. Yeni filial-balansli tehvil formasindan istifade edin.'],$lang,'inventory',['inventory_tab'=>'handover']);
            $anchor = 'inventory';
            $endpoint = '/api/service/inventory/handovers';
            $payload = [
                'branchId' => clean_text((string) ($_POST['handover_branch_id'] ?? '')),
                'employeeUserId' => clean_text((string) ($_POST['handover_employee_user_id'] ?? '')),
                'noteText' => clean_text((string) ($_POST['handover_note_text'] ?? '')),
                'items' => [[
                    'productId' => clean_text((string) ($_POST['handover_product_id'] ?? '')),
                    'quantity' => max(0, (float) ($_POST['handover_quantity'] ?? 0)),
                    'serialNumber' => clean_text((string) ($_POST['handover_serial_number'] ?? '')),
                    'macAddress' => clean_text((string) ($_POST['handover_mac_address'] ?? '')),
                    'itemType' => clean_text((string) ($_POST['handover_item_type'] ?? 'balance')),
                    'noteText' => clean_text((string) ($_POST['handover_item_note_text'] ?? '')),
                ]],
            ];
        } elseif ($action === 'create_finance_entry') {
            $anchor = 'finance';
            $endpoint = '/api/service/finance/entries';
            $payload = [
                'entryType' => clean_text((string) ($_POST['entry_type'] ?? 'expense')),
                'category' => clean_text((string) ($_POST['category'] ?? 'manual')),
                'title' => clean_text((string) ($_POST['title'] ?? '')),
                'amount' => max(0, (float) ($_POST['amount'] ?? 0)),
                'branchId' => clean_text((string) ($_POST['branch_id'] ?? '')),
                'employeeUserId' => clean_text((string) ($_POST['employee_user_id'] ?? '')),
                'noteText' => clean_text((string) ($_POST['note_text'] ?? '')),
            ];
        } elseif ($action === 'save_asset_depreciation') {
            $anchor = 'finance';
            $localResult = app_mgs_save_inventory_depreciation([
                'asset_id' => (int) ($_POST['depreciation_asset_id'] ?? 0),
                'stock_item_id' => (int) ($_POST['depreciation_stock_item_id'] ?? 0),
                'asset_label' => clean_text((string) ($_POST['depreciation_asset_label'] ?? '')),
                'acquisition_cost' => max(0, (float) ($_POST['acquisition_cost'] ?? 0)),
                'depreciation_rate' => max(0, (float) ($_POST['depreciation_rate'] ?? 0)),
                'fiscal_year' => (int) ($_POST['fiscal_year'] ?? date('Y')),
                'writeoff_threshold' => max(0, (float) ($_POST['writeoff_threshold'] ?? 0)),
                'request_writeoff' => isset($_POST['request_writeoff']) ? 1 : 0,
                'note_text' => clean_text((string) ($_POST['depreciation_note'] ?? '')),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Asset amortizasiya/writeoff hesabi saxlandi.'
                    : ('Asset amortizasiya saxlanmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'create_finance_debt_record') {
            $anchor = 'finance';
            $localResult = app_mgs_save_finance_debt_record([
                'ledger_type' => clean_text((string) ($_POST['ledger_type'] ?? 'receivable')),
                'counterparty_name' => clean_text((string) ($_POST['counterparty_name'] ?? '')),
                'counterparty_type' => clean_text((string) ($_POST['counterparty_type'] ?? 'other')),
                'amount' => max(0, (float) ($_POST['debt_amount'] ?? 0)),
                'document_no' => clean_text((string) ($_POST['document_no'] ?? '')),
                'due_date' => clean_text((string) ($_POST['due_date'] ?? '')),
                'note_text' => clean_text((string) ($_POST['debt_note'] ?? '')),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Borc ledger qeydi saxlandi.'
                    : ('Borc ledger saxlanmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'record_finance_debt_payment') {
            $anchor = 'finance';
            $localResult = app_mgs_record_finance_debt_payment(
                max(0, (int) ($_POST['debt_id'] ?? 0)),
                [
                    'payment_amount' => max(0, (float) ($_POST['payment_amount'] ?? 0)),
                    'payment_date' => clean_text((string) ($_POST['payment_date'] ?? '')),
                    'payment_method' => clean_text((string) ($_POST['payment_method'] ?? 'manual')),
                    'reference_no' => clean_text((string) ($_POST['payment_reference_no'] ?? '')),
                    'note_text' => clean_text((string) ($_POST['payment_note'] ?? '')),
                ],
                $user,
                $db
            );
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Borc odenisi yazildi.'
                    : ('Borc odenisi yazilmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'import_finance_e_invoice') {
            $anchor = 'finance';
            $localResult = app_mgs_import_finance_e_invoice_rows(
                (string) ($_POST['e_invoice_rows'] ?? ''),
                $user,
                $db
            );
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'E-qaime import tamamlandi: ' . ((string) ($localResult['imported'] ?? 0)) . ' setir.'
                    : ('E-qaime import alinmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'setir tapilmadi')),
            ], $lang, $anchor);
        } elseif ($action === 'create_technical_task') {
            $anchor = 'technical';
            $endpoint = '/api/service/technical-tasks';
            $payload = [
                'taskType' => clean_text((string) ($_POST['task_type'] ?? 'field_work')),
                'customerName' => clean_text((string) ($_POST['customer_name'] ?? '')),
                'customerAddress' => clean_text((string) ($_POST['customer_address'] ?? '')),
                'assignedUserId' => clean_text((string) ($_POST['assigned_user_id'] ?? '')),
                'priority' => clean_text((string) ($_POST['priority'] ?? 'normal')),
                'status' => clean_text((string) ($_POST['status'] ?? 'open')),
                'noteText' => clean_text((string) ($_POST['note_text'] ?? '')),
            ];
        } elseif ($action === 'save_attendance_profile') {
            $anchor = 'hr';
            $endpoint = '/api/service/attendance/profiles';
            $payload = [
                'userId' => clean_text((string) ($_POST['user_id'] ?? '')),
                'branchId' => clean_text((string) ($_POST['branch_id'] ?? '')),
                'cardCode' => clean_text((string) ($_POST['card_code'] ?? '')),
                'workStartTime' => clean_text((string) ($_POST['work_start_time'] ?? '09:00')),
                'workEndTime' => clean_text((string) ($_POST['work_end_time'] ?? '18:00')),
                'lateGraceMinutes' => max(0, (int) ($_POST['late_grace_minutes'] ?? 10)),
                'expectedWorkMinutes' => max(1, (int) ($_POST['expected_work_minutes'] ?? 480)),
                'noteText' => clean_text((string) ($_POST['note_text'] ?? '')),
            ];
        } elseif ($action === 'record_attendance_event') {
            $anchor = 'hr';
            $targetEmployeeId = app_portal_is_admin($user) || strtolower((string) ($user['employee_role'] ?? '')) === 'hr'
                ? (int) ($_POST['attendance_employee_account_id'] ?? 0)
                : (int) ($user['id'] ?? 0);
            $localResult = app_mgs_record_hr_attendance_event([
                'employee_account_id' => $targetEmployeeId,
                'event_type' => (string) ($_POST['attendance_event_type'] ?? 'check_in'),
                'work_date' => (string) ($_POST['attendance_work_date'] ?? date('Y-m-d')),
                'event_at' => (string) ($_POST['attendance_event_at'] ?? date('Y-m-d H:i:s')),
                'branch_id' => (string) ($_POST['attendance_branch_id'] ?? ''),
                'source' => 'portal',
                'note' => (string) ($_POST['attendance_note'] ?? ''),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok']) ? 'Davamiyyet hadisesi saxlandi.' : 'Davamiyyet saxlanmadi: ' . (string) ($localResult['error'] ?? 'namelum xeta'),
            ], $lang, 'hr', ['hr_tab' => 'attendance']);
        } elseif ($action === 'save_hr_employee_compensation') {
            $anchor = 'hr';
            $localResult = app_mgs_save_hr_employee_compensation([
                'employee_account_id' => (int) ($_POST['comp_employee_account_id'] ?? 0),
                'salary_mode' => clean_text((string) ($_POST['salary_mode'] ?? 'gross')),
                'gross_salary' => max(0, (float) ($_POST['comp_gross_salary'] ?? 0)),
                'net_salary' => max(0, (float) ($_POST['comp_net_salary'] ?? 0)),
                'effective_from' => clean_text((string) ($_POST['comp_effective_from'] ?? date('Y-m-d'))),
                'note_text' => clean_text((string) ($_POST['comp_note'] ?? '')),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Emekdas maas bazasi saxlandi.'
                    : ('Emekdas maas bazasi saxlanmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'record_hr_score_event') {
            $anchor = 'hr';
            $localResult = app_mgs_record_hr_score_event([
                'employee_account_id' => (int) ($_POST['score_employee_account_id'] ?? 0),
                'event_type' => clean_text((string) ($_POST['score_event_type'] ?? 'penalty')),
                'reason_code' => clean_text((string) ($_POST['score_reason_code'] ?? 'manual')),
                'points_delta' => (float) ($_POST['score_points_delta'] ?? 0),
                'event_date' => clean_text((string) ($_POST['score_event_date'] ?? date('Y-m-d'))),
                'note_text' => clean_text((string) ($_POST['score_note'] ?? '')),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Sicil bal hadisesi saxlandi.'
                    : ('Sicil bal hadisesi saxlanmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'create_hr_payroll_record') {
            $anchor = 'hr';
            $localResult = app_mgs_save_hr_payroll_record([
                'employee_account_id' => (int) ($_POST['payroll_employee_account_id'] ?? 0),
                'period_month' => clean_text((string) ($_POST['period_month'] ?? date('Y-m'))),
                'gross_salary' => max(0, (float) ($_POST['gross_salary'] ?? 0)),
                'leave_days' => max(0, (float) ($_POST['leave_days'] ?? 0)),
                'leave_daily_rate' => max(0, (float) ($_POST['leave_daily_rate'] ?? 0)),
                'travel_days' => max(0, (float) ($_POST['travel_days'] ?? 0)),
                'travel_daily_rate' => max(0, (float) ($_POST['travel_daily_rate'] ?? 0)),
                'taxable_travel_amount' => max(0, (float) ($_POST['taxable_travel_amount'] ?? 0)),
                'status' => clean_text((string) ($_POST['payroll_status'] ?? 'draft')),
                'note_text' => clean_text((string) ($_POST['payroll_note'] ?? '')),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'HR maas/vergi analizi saxlandi.'
                    : ('HR maas analizi saxlanmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'create_hr_leave_request') {
            $anchor = 'hr';
            $localResult = app_mgs_save_hr_leave_request([
                'employee_account_id' => (int) ($_POST['leave_employee_account_id'] ?? 0),
                'period_month' => clean_text((string) ($_POST['leave_period_month'] ?? date('Y-m'))),
                'leave_type' => clean_text((string) ($_POST['leave_type'] ?? 'annual')),
                'start_date' => clean_text((string) ($_POST['leave_start_date'] ?? '')),
                'end_date' => clean_text((string) ($_POST['leave_end_date'] ?? '')),
                'leave_days' => max(0, (float) ($_POST['leave_days'] ?? 0)),
                'status' => clean_text((string) ($_POST['leave_status'] ?? 'draft')),
                'note_text' => clean_text((string) ($_POST['leave_note'] ?? '')),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Mezuniyyet hesablamasi saxlandi.'
                    : ('Mezuniyyet saxlanmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'create_hr_travel_request') {
            $anchor = 'hr';
            $localResult = app_mgs_save_hr_travel_request([
                'employee_account_id' => (int) ($_POST['travel_employee_account_id'] ?? 0),
                'destination' => clean_text((string) ($_POST['destination'] ?? '')),
                'start_date' => clean_text((string) ($_POST['travel_start_date'] ?? '')),
                'end_date' => clean_text((string) ($_POST['travel_end_date'] ?? '')),
                'travel_days' => max(0, (float) ($_POST['travel_days'] ?? 0)),
                'daily_rate' => max(0, (float) ($_POST['daily_rate'] ?? 0)),
                'taxable_travel_amount' => max(0, (float) ($_POST['travel_taxable_amount'] ?? 0)),
                'purpose' => clean_text((string) ($_POST['travel_purpose'] ?? '')),
                'status' => clean_text((string) ($_POST['travel_status'] ?? 'draft')),
                'note_text' => clean_text((string) ($_POST['travel_note'] ?? '')),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Ezamiyyet hesablamasi saxlandi.'
                    : ('Ezamiyyet saxlanmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'create_hr_employee_document') {
            $anchor = 'hr';
            $localResult = app_mgs_save_hr_employee_document([
                'employee_account_id' => (int) ($_POST['document_employee_account_id'] ?? 0),
                'document_type' => clean_text((string) ($_POST['document_type'] ?? '')),
                'document_no' => clean_text((string) ($_POST['document_no'] ?? '')),
                'status' => clean_text((string) ($_POST['document_status'] ?? 'pending')),
                'issued_at' => clean_text((string) ($_POST['document_issued_at'] ?? '')),
                'expires_at' => clean_text((string) ($_POST['document_expires_at'] ?? '')),
                'file_ref' => clean_text((string) ($_POST['document_file_ref'] ?? '')),
                'note_text' => clean_text((string) ($_POST['document_note'] ?? '')),
            ], $user, $db);
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'Isci senedi saxlandi.'
                    : ('Isci senedi saxlanmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'update_hr_record_status') {
            $anchor = 'hr';
            $localResult = app_mgs_hr_update_record_status(
                clean_text((string) ($_POST['hr_record_type'] ?? '')),
                max(0, (int) ($_POST['hr_record_id'] ?? 0)),
                clean_text((string) ($_POST['hr_record_status'] ?? '')),
                $user,
                $db,
                clean_text((string) ($_POST['reason'] ?? $_POST['hr_status_reason'] ?? ''))
            );
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'HR status yenilendi.'
                    : ('HR status yenilenmedi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'record_hr_finance_expense') {
            $anchor = 'hr';
            $localResult = app_mgs_hr_record_finance_expense(
                clean_text((string) ($_POST['hr_record_type'] ?? '')),
                max(0, (int) ($_POST['hr_record_id'] ?? 0)),
                $user,
                $db
            );
            spog_operations_flash_redirect([
                'type' => !empty($localResult['ok']) ? 'success' : 'error',
                'message' => !empty($localResult['ok'])
                    ? 'HR xerc maliyye bridge jurnalina yazildi.'
                    : ('HR xerc yazilmadi: ' . ((string) ($localResult['error'] ?? '') ?: 'DB cavabi yoxdur')),
            ], $lang, $anchor);
        } elseif ($action === 'send_bot_test_message') {
            $anchor = 'bots';
            $endpoint = '/api/service/telegram-bots/test-message';
            $payload = [
                'scope' => clean_text((string) ($_POST['scope'] ?? 'general')),
                'severity' => clean_text((string) ($_POST['severity'] ?? 'info')),
                'text' => clean_text((string) ($_POST['message_text'] ?? 'TEST MESAJI')),
            ];
        }

        $result = app_mgs_service_request_json($endpoint, 'POST', $payload, 60);
        spog_operations_flash_redirect([
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => !empty($result['ok'])
                ? 'Emeliyyat icra edildi.'
                : ('Emeliyyat alinmadi: ' . ((string) ($result['error'] ?? '') ?: 'service cavabi yoxdur')),
        ], $lang, $anchor);
    }

    if (in_array($action, ['backup_now', 'backup_telegram', 'install_weekly_backup_task', 'restore_verify', 'restore_execute', 'check_update_channel', 'verify_update_package', 'apply_update_package'], true)) {
        if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
            spog_backup_flash_redirect([
                'type' => 'error',
                'message' => 'Backup emeliyyati ucun CSRF yoxlamasi ugursuz oldu.',
            ], $lang);
        }
        if (!app_portal_is_admin($user)) {
            spog_backup_flash_redirect([
                'type' => 'error',
                'message' => 'Backup ve restore emeliyyatlari yalniz admin roluna aciqdir.',
            ], $lang);
        }

        if ($action === 'restore_execute' && strtoupper(trim((string) ($_POST['confirm_phrase'] ?? ''))) !== 'RESTORE') {
            spog_backup_flash_redirect([
                'type' => 'error',
                'message' => 'Restore icrasi ucun confirm sahesine RESTORE yazilmalidir.',
            ], $lang);
        }
        if ($action === 'apply_update_package' && strtoupper(trim((string) ($_POST['confirm_phrase'] ?? ''))) !== 'UPDATE') {
            spog_backup_flash_redirect([
                'type' => 'error',
                'message' => 'Update icrasi ucun confirm sahesine UPDATE yazilmalidir.',
            ], $lang);
        }

        $updatePackage = trim((string) ($_POST['update_package'] ?? ''));
        if (in_array($action, ['verify_update_package', 'apply_update_package'], true)) {
            $packageResult = spog_update_package_from_request();
            if (empty($packageResult['ok'])) {
                spog_backup_flash_redirect([
                    'type' => 'error',
                    'message' => (string) ($packageResult['error'] ?? 'Update paketi oxunmadi.'),
                ], $lang);
            }
            $updatePackage = (string) ($packageResult['path'] ?? '');
        }

        $options = [
            'restore_file' => clean_text((string) ($_POST['restore_file'] ?? '')),
            'restore_files' => isset($_POST['restore_files']) ? 1 : 0,
            'restore_env' => isset($_POST['restore_env']) ? 1 : 0,
            'update_package' => $updatePackage,
            'update_env' => isset($_POST['update_env']) ? 1 : 0,
        ];
        $result = app_mgs_backup_center_action($action, $options, $user);
        $details = trim((string) (($result['stdout'] ?? '') !== '' ? $result['stdout'] : ($result['stderr'] ?? '')));
        spog_backup_flash_redirect([
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => clean_text((string) ($result['summary'] ?? 'Backup emeliyyati icra edildi.')),
            'details' => $details !== '' ? mb_substr($details, 0, 4000) : '',
        ], $lang);
    }
}

$opsFlash = null;
app_session_boot();
if (is_array($_SESSION['operations_center_flash'] ?? null)) {
    $opsFlash = $_SESSION['operations_center_flash'];
    unset($_SESSION['operations_center_flash']);
}
$opsState = [];
if (is_array($_SESSION['operations_center_state'] ?? null)) {
    $opsState = $_SESSION['operations_center_state'];
    unset($_SESSION['operations_center_state']);
}

$validOpsSections = ['overview', 'noc', 'olt', 'inventory', 'finance', 'technical', 'hr', 'bots', 'reports', 'search'];
$activeOpsSection = clean_text((string) ($_GET['section'] ?? 'overview'));
if ($activeOpsSection === 'network') {
    $activeOpsSection = 'noc';
}
if (!in_array($activeOpsSection, $validOpsSections, true)) {
    $activeOpsSection = 'overview';
}
if ($activeOpsSection === 'noc') {
    $params = ['view' => 'monitor'];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    header('Location: noc-devices.php?' . http_build_query($params), true, 302);
    exit;
}
if ($activeOpsSection === 'olt') {
    $params = ['view' => 'devices'];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    header('Location: noc-devices.php?' . http_build_query($params), true, 302);
    exit;
}

if (isset($_GET['finance_einvoice_export'])) {
    $ledgerType = clean_text((string) ($_GET['ledger_type'] ?? 'all'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finance-e-invoice-ledger-' . date('Ymd-His') . '.csv"');
    echo app_mgs_export_finance_e_invoice_csv($db, $ledgerType);
    exit;
}

if (isset($_GET['bonus_export']) && $activeOpsSection === 'finance') {
    $bonusExport = app_bonus_export_csv(
        $db,
        (int) ($_GET['period_id'] ?? 0),
        clean_text((string) ($_GET['bonus_export'] ?? 'summary')),
        $user,
        (int) ($_GET['employee_id'] ?? 0)
    );
    if (!empty($bonusExport['ok'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename((string) ($bonusExport['filename'] ?? 'employee-bonus.csv')) . '"');
        echo (string) ($bonusExport['csv'] ?? '');
        exit;
    }
    http_response_code(403);
    echo 'Bonus export alinmadi: ' . clean_text((string) ($bonusExport['error'] ?? 'namelum_xeta'));
    exit;
}

$restorePoints = $activeOpsSection === 'reports' ? app_mgs_list_restore_points(8) : [];
$updateChannel = $activeOpsSection === 'reports' ? app_mgs_update_channel_status() : [];
$latestRestorePoint = $restorePoints[0] ?? null;
$restorePointCount = count($restorePoints);
$updateConfigured = !empty($updateChannel['configured']);
$updateHealthy = !empty($updateChannel['manifest_ok']);

if (isset($_GET['download_restore'])) {
    if (!app_portal_is_admin($user)) {
        http_response_code(403);
        echo 'Restore point yuklemek ucun admin rolu teleb olunur.';
        exit;
    }

    $requestedRestore = basename((string) $_GET['download_restore']);
    $downloadPoint = null;
    foreach (app_mgs_list_restore_points(50) as $restorePoint) {
        if ((string) ($restorePoint['name'] ?? '') === $requestedRestore) {
            $downloadPoint = $restorePoint;
            break;
        }
    }

    $backupRoot = realpath(app_mgs_backup_root());
    $downloadPath = $downloadPoint !== null ? realpath((string) ($downloadPoint['path'] ?? '')) : false;
    if ($backupRoot !== false && $downloadPath !== false && strncmp($downloadPath, $backupRoot, strlen($backupRoot)) === 0 && is_file($downloadPath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($downloadPath) . '"');
        header('Content-Length: ' . (string) filesize($downloadPath));
        readfile($downloadPath);
        exit;
    }

    http_response_code(404);
    echo 'Restore point tapilmadi.';
    exit;
}

$hasInventory = spog_table_exists($db, 'inventory_items');
$hasAttendance = spog_table_exists($db, 'hr_attendance_events');
$hasEmployees = spog_table_exists($db, 'portal_accounts');
$hasNetworkDefinitions = spog_table_exists($db, 'network_vlan_definitions');

$metrics = [
    'customers' => spog_count($db, "SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer'"),
    'active_customers' => spog_count($db, "SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer' AND is_active = 1"),
    'online_sessions' => spog_count($db, "SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL AND acctstarttime >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
    'payments_today' => spog_sum($db, "SELECT COALESCE(SUM(amount),0) FROM billing_payments WHERE created_at >= CURDATE()"),
    'open_tickets' => spog_count($db, "SELECT COUNT(*) FROM portal_tickets WHERE status <> 'Baglandi'"),
    'queued_messages' => spog_count($db, "SELECT COUNT(*) FROM communication_messages WHERE status = 'queued'"),
    'radius_rejects' => spog_count($db, "SELECT COUNT(*) FROM radpostauth WHERE reply LIKE '%Reject%' AND authdate >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
    'vlan_plans' => $hasNetworkDefinitions ? spog_count($db, "SELECT COUNT(*) FROM network_vlan_definitions") : 0,
];

$emptyServiceModule = ['ok' => false, 'status' => 0, 'error' => '', 'data' => [], 'url' => ''];
$serviceNetwork = $emptyServiceModule;
$serviceDevices = $emptyServiceModule;
$servicePlatform = $emptyServiceModule;
$serviceInventory = $emptyServiceModule;
$serviceFinance = $emptyServiceModule;
$serviceTechnical = $emptyServiceModule;
$serviceAttendance = $emptyServiceModule;
$serviceKpi = $emptyServiceModule;
$serviceReports = $emptyServiceModule;
$serviceBots = $emptyServiceModule;

$needsInventoryService = $activeOpsSection === 'inventory';
$needsFinanceService = $activeOpsSection === 'finance';
$needsTechnicalService = $activeOpsSection === 'technical';
$needsHrService = $activeOpsSection === 'hr';
$needsReportsService = $activeOpsSection === 'reports';
$needsBotsService = $activeOpsSection === 'bots';

if ($needsTechnicalService) {
    $serviceTechnical = spog_service_module('/api/service/technical');
}
if ($needsReportsService) {
    $serviceReports = spog_service_module('/api/service/reports');
}
if ($needsBotsService) {
    $serviceBots = spog_service_module('/api/service/telegram-bots');
}

$serviceHealth = [
    'network' => $serviceNetwork['ok'],
    'devices' => $serviceDevices['ok'],
    'platform' => $servicePlatform['ok'],
    'inventory' => $serviceInventory['ok'],
    'finance' => $serviceFinance['ok'],
    'technical' => $serviceTechnical['ok'],
    'attendance' => $serviceAttendance['ok'],
    'kpi' => $serviceKpi['ok'],
    'reports' => $serviceReports['ok'],
    'bots' => $serviceBots['ok'],
];

$customerResults = [];
$ticketResults = [];
$radiusResults = [];
$vlanResults = [];
if ($query !== '' && $db instanceof PDO) {
    $like = '%' . $query . '%';
    $customerResults = spog_rows(
        $db,
        "SELECT id, subscriber_code, full_name, phone, area, plan, status, is_active
         FROM portal_accounts
         WHERE role = 'customer'
           AND (subscriber_code LIKE :q OR contract_no LIKE :q OR full_name LIKE :q OR phone LIKE :q OR login LIKE :q OR address LIKE :q)
         ORDER BY updated_at DESC",
        ['q' => $like],
        8
    );
    $ticketResults = spog_rows(
        $db,
        "SELECT ticket_no, customer_account_id, subscriber_code, customer_name, subject, status, area, opened_at
         FROM portal_tickets
         WHERE ticket_no LIKE :q OR subscriber_code LIKE :q OR customer_name LIKE :q OR phone LIKE :q OR subject LIKE :q OR message LIKE :q
         ORDER BY opened_at DESC, id DESC",
        ['q' => $like],
        8
    );
    $radiusResults = spog_rows(
        $db,
        "SELECT username, framedipaddress, callingstationid, nasipaddress, acctstarttime, acctstoptime, acctinputoctets, acctoutputoctets
         FROM radacct
         WHERE username LIKE :q OR framedipaddress LIKE :q OR callingstationid LIKE :q OR nasipaddress LIKE :q
         ORDER BY COALESCE(acctupdatetime, acctstarttime) DESC, radacctid DESC",
        ['q' => $like],
        8
    );
    if ($hasNetworkDefinitions) {
        $vlanResults = spog_rows(
            $db,
            "SELECT id, name, vlan_id, customer_name, customer_code, branch, primary_device_name, primary_device_ip, access_port, status
             FROM network_vlan_definitions
             WHERE name LIKE :q OR customer_name LIKE :q OR customer_code LIKE :q OR primary_device_ip LIKE :q OR access_port LIKE :q
             ORDER BY updated_at DESC, id DESC",
            ['q' => $like],
            8
        );
    }
}

$recentTickets = spog_rows(
    $db,
    "SELECT ticket_no, customer_account_id, subscriber_code, customer_name, subject, status, area, opened_at
     FROM portal_tickets
     WHERE status <> 'Baglandi'
     ORDER BY opened_at DESC, id DESC",
    [],
    8
);
$recentPayments = spog_rows(
    $db,
    "SELECT p.account_id, p.subscriber_code, p.amount, p.payment_method, p.transaction_id, p.created_at, a.full_name
     FROM billing_payments p
     LEFT JOIN portal_accounts a ON a.id = p.account_id
     ORDER BY p.created_at DESC, p.id DESC",
    [],
    8
);
$recentMessages = spog_rows(
    $db,
    "SELECT account_id, subscriber_code, recipient_name, channel, status, template_code, created_at
     FROM communication_messages
     ORDER BY created_at DESC, id DESC",
    [],
    8
);
$recentRadius = spog_rows(
    $db,
    "SELECT username, framedipaddress, callingstationid, nasipaddress, acctstarttime, acctstoptime, acctinputoctets, acctoutputoctets
     FROM radacct
     ORDER BY COALESCE(acctupdatetime, acctstarttime) DESC, radacctid DESC",
    [],
    8
);

$networkSummary = spog_service_summary($serviceNetwork);
$networkPlatform = is_array($serviceNetwork['data']['platform'] ?? null) ? $serviceNetwork['data']['platform'] : [];
$networkDevices = is_array($serviceDevices['data']['devices'] ?? null)
    ? $serviceDevices['data']['devices']
    : (is_array($networkPlatform['devices'] ?? null) ? $networkPlatform['devices'] : []);
$networkAlarms = is_array($networkPlatform['alarms'] ?? null) ? array_slice($networkPlatform['alarms'], 0, 6) : [];
$networkTickets = is_array($networkPlatform['tickets'] ?? null) ? array_slice($networkPlatform['tickets'], 0, 6) : [];
$managementPathReady = app_mgs_management_path_ready();
$displayNetworkDevices = $managementPathReady ? $networkDevices : spog_normalize_devices_for_path_down($networkDevices);
$displayNetworkAlarms = $managementPathReady ? $networkAlarms : [];
$networkDevicePreview = array_slice($displayNetworkDevices, 0, 8);
$primaryMonitorDevice = is_array($displayNetworkDevices[0] ?? null) ? $displayNetworkDevices[0] : [];
$problemMonitorDevices = array_slice(array_values(array_filter(
    $displayNetworkDevices,
    static fn ($device): bool => is_array($device) && in_array(spog_monitor_tone($device), ['down', 'warning'], true)
)), 0, 6);
$monitorListDevices = array_slice($displayNetworkDevices, 0, 10);
$monitorPoints = spog_monitor_points($networkDevicePreview);
$monitorCounts = [
    'up' => 0,
    'down' => 0,
    'warning' => 0,
    'maintenance' => 0,
    'unknown' => 0,
];
foreach ($displayNetworkDevices as $device) {
    $tone = spog_monitor_tone(is_array($device) ? $device : []);
    $monitorCounts[$tone] = ($monitorCounts[$tone] ?? 0) + 1;
}
$scanState = is_array($opsState['scan'] ?? null) ? $opsState['scan'] : [];
$scanResults = app_noc_scan_results_from_state($scanState);
$scanTarget = (string) ($scanState['target'] ?? '');

$inventorySummary = spog_service_summary($serviceInventory);
$inventoryBalances = array_slice(spog_service_rows($serviceInventory, 'balances'), 0, 6);
$inventoryMovements = array_slice(spog_service_rows($serviceInventory, 'movements'), 0, 6);
$inventoryAssets = array_slice(spog_service_rows($serviceInventory, 'assets'), 0, 6);
$inventoryHandovers = array_slice(spog_service_rows($serviceInventory, 'handovers'), 0, 6);
$inventoryProducts = spog_service_rows($serviceInventory, 'products');
$inventoryCategories = is_array($serviceInventory['data']['categories'] ?? null) ? $serviceInventory['data']['categories'] : [];
if ($inventoryCategories === []) {
    $inventoryCategories = [
        ['code' => 'ont_modem', 'name' => 'ONT / Modem', 'description' => 'Musteriye qurasdirilan ONT ve modem avadanliqlari'],
        ['code' => 'router', 'name' => 'Router', 'description' => 'Router ve CPE cihazlari'],
        ['code' => 'switch', 'name' => 'Switch', 'description' => 'Switch ve access avadanliqlari'],
        ['code' => 'sfp', 'name' => 'SFP / Optik modul', 'description' => 'Optik modul ve transceiver'],
        ['code' => 'fiber_cable', 'name' => 'Fiber kabel', 'description' => 'Kabel barabani ve metrajli fiber'],
        ['code' => 'connector', 'name' => 'Connector', 'description' => 'Fast connector ve patchcord'],
        ['code' => 'tool', 'name' => 'Alet', 'description' => 'Texnik aletleri'],
        ['code' => 'other', 'name' => 'Diger', 'description' => 'Diger inventar'],
    ];
}
$inventoryCategoryNames = [];
foreach ($inventoryCategories as $category) {
    $code = (string) ($category['code'] ?? '');
    if ($code !== '') {
        $inventoryCategoryNames[$code] = (string) ($category['name'] ?? $code);
    }
}
$inventoryBranches = is_array($serviceInventory['data']['branches'] ?? null) ? $serviceInventory['data']['branches'] : [];
$inventorySources = is_array($serviceInventory['data']['sources'] ?? null) ? $serviceInventory['data']['sources'] : [];

$financeContext = $needsFinanceService && $db instanceof PDO
    ? app_finance_build_context($db, $user, $_GET)
    : null;
$inventoryContext = $needsInventoryService && $db instanceof PDO
    ? app_inventory_build_context($db, $user, $_GET)
    : null;
$hrContext = $needsHrService && $db instanceof PDO
    ? app_hr_build_context($db, $user, $_GET)
    : null;

$technicalTasks = array_slice(spog_service_rows($serviceTechnical, 'tasks'), 0, 6);
$technicalPlanning = is_array($serviceTechnical['data']['planning'] ?? null) ? $serviceTechnical['data']['planning'] : [];
$technicalBranches = is_array($serviceTechnical['data']['branches'] ?? null) ? $serviceTechnical['data']['branches'] : $inventoryBranches;
$technicalUsers = is_array($serviceTechnical['data']['users'] ?? null) ? spog_operational_employees($serviceTechnical['data']['users']) : [];

$telegramBots = is_array($serviceBots['data']['bots'] ?? null) ? array_slice($serviceBots['data']['bots'], 0, 8) : [];

$reportsSummary = spog_service_summary($serviceReports);
$reportBranches = is_array($serviceReports['data']['branchSummaries'] ?? null) ? array_slice($serviceReports['data']['branchSummaries'], 0, 6) : [];
$reportOptical = is_array($serviceReports['data']['opticalTrendSummary'] ?? null) ? array_slice($serviceReports['data']['opticalTrendSummary'], 0, 4) : [];
$reportModule = clean_text((string) ($_GET['module'] ?? ''));
$reportType = clean_text((string) ($_GET['report_type'] ?? ''));
$reportStatisticsUrl = app_portal_statistics_context_url($reportModule, $reportType, $_GET, $lang);
$analyticsCenter = $activeOpsSection === 'reports' && $db instanceof PDO ? app_analytics_center_context($db, $user) : [];

$validOpsSections = ['overview', 'noc', 'olt', 'inventory', 'finance', 'technical', 'hr', 'bots', 'reports', 'search'];
$activeOpsSection = clean_text((string) ($_GET['section'] ?? 'overview'));
if ($activeOpsSection === 'network') {
    $activeOpsSection = 'noc';
}
if (!in_array($activeOpsSection, $validOpsSections, true)) {
    $activeOpsSection = 'overview';
}
$operationsPageTitles = [
    'overview' => 'Operations Center',
    'search' => 'Operations Center',
    'finance' => 'Maliyye',
    'hr' => 'HR',
    'inventory' => 'Anbar',
    'noc' => 'Sebeke',
    'olt' => 'Sebeke',
    'technical' => 'Sebeke',
    'reports' => 'Statistika',
    'bots' => 'AI',
];
$operationsPageTitle = $operationsPageTitles[$activeOpsSection] ?? 'Operations Center';
$inventoryFocus = clean_text((string) ($_GET['focus'] ?? ''));
$inventoryTabQuery = $_GET;
if (($inventoryTabQuery['inventory_tab'] ?? '') === '' && $inventoryFocus !== '') {
    $inventoryTabQuery['inventory_tab'] = $inventoryFocus;
}
$inventoryTab = function_exists('app_inventory_tab') ? app_inventory_tab($inventoryTabQuery) : 'overview';
if ($activeOpsSection === 'noc') {
    $params = ['view' => 'monitor'];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    header('Location: noc-devices.php?' . http_build_query($params), true, 302);
    exit;
}
if ($activeOpsSection === 'olt') {
    $params = ['view' => 'devices'];
    if ($lang !== 'az') {
        $params['lang'] = $lang;
    }
    header('Location: noc-devices.php?' . http_build_query($params), true, 302);
    exit;
}
$shouldRenderSearch = $activeOpsSection === 'search' || ($query !== '' && $activeOpsSection === 'overview');

$moduleCards = [
    [
        'title' => 'Musteri 360',
        'status' => 'aktiv',
        'icon' => 'fa-user-shield',
        'text' => 'Profil, balans, Radius, ONT, odenis, ticket ve mesaj tarixcesi bir yerde.',
        'href' => 'billing-customers.php',
    ],
    [
        'title' => 'Billing / maliyye',
        'status' => 'aktiv',
        'icon' => 'fa-wallet',
        'text' => 'Odenisler, nagd odenis, paketler, borclular ve provider tarixcesi.',
        'href' => 'billing-payments.php',
    ],
    [
        'title' => 'RADIUS / NAS',
        'status' => 'aktiv',
        'icon' => 'fa-satellite-dish',
        'text' => 'Online sessiyalar, auth/reject, accounting ve NAT log axtarisi.',
        'href' => 'radius-active.php',
    ],
    [
        'title' => 'Network / NOC',
        'status' => $serviceHealth['network'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-network-wired',
        'text' => 'VLAN/MPLS planlari, alarmlar, root cause ve device konteksti.',
        'href' => 'noc-devices.php?view=monitor',
    ],
    [
        'title' => 'Anbar',
        'status' => $serviceHealth['inventory'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-boxes-stacked',
        'text' => 'Modem/ONT, kabel baraban, kritik stok ve barkod axini burada birlesmelidir.',
        'href' => spog_operations_url('inventory', $lang),
    ],
    [
        'title' => 'Maliyye',
        'status' => $serviceHealth['finance'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-coins',
        'text' => 'Medaxil, mexaric, satis, filial kesri ve gunluk maliyye qeydleri.',
        'href' => spog_operations_url('finance', $lang),
    ],
    [
        'title' => 'Texniki isler',
        'status' => $serviceHealth['technical'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-screwdriver-wrench',
        'text' => 'Ticketden texniki task yaratmaq, saha isi ve material istifadesi.',
        'href' => spog_operations_url('technical', $lang),
    ],
    [
        'title' => 'HR / KPI',
        'status' => ($serviceHealth['attendance'] && $serviceHealth['kpi']) ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-id-card-clip',
        'text' => 'Is qrafiki, davamiyyet, mezuniyyet, ticket performansi ve KPI.',
        'href' => spog_operations_url('hr', $lang),
    ],
    [
        'title' => 'SPoG integration',
        'status' => 'aktiv',
        'icon' => 'fa-layer-group',
        'text' => 'Alarm, dedup, Telegram bot, backup worker ve process registry vahid panelde.',
        'href' => 'mgs-integration.php',
    ],
];

if (app_portal_is_admin($user)) {
    $moduleCards[] = [
        'title' => 'MyGov ID / SSO',
        'status' => 'hazirliq',
        'icon' => 'fa-id-card',
        'text' => 'Identity mapping, callback state ve gelecek vahid login modulu.',
        'href' => 'identity-integrations.php',
    ];
}

$legacyModuleMap = [
    [
        'title' => 'Network monitor',
        'source' => 'GET /api/service/network-dashboard',
        'target' => 'NOC panel',
        'href' => 'noc-devices.php?view=monitor',
        'status' => $serviceHealth['network'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-heart-pulse',
    ],
    [
        'title' => 'OLT / ONT axtaris',
        'source' => 'native NOC + live OLT context',
        'target' => 'OLT / ONT panel',
        'href' => 'noc-devices.php?view=devices',
        'status' => 'Aktiv',
        'icon' => 'fa-wifi',
    ],
    [
        'title' => 'MPLS / VLAN',
        'source' => 'oltidareetme/public/modules/mpls-page.js',
        'target' => 'Network definitions',
        'href' => 'network-definitions.php',
        'status' => 'islek kecid',
        'icon' => 'fa-route',
    ],
    [
        'title' => 'Anbar / barkod',
        'source' => 'GET /api/service/inventory',
        'target' => 'Anbar panel',
        'href' => spog_operations_url('inventory', $lang),
        'status' => $serviceHealth['inventory'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-boxes-stacked',
    ],
    [
        'title' => 'HR / davamiyyet',
        'source' => 'GET /api/service/attendance',
        'target' => 'HR panel',
        'href' => spog_operations_url('hr', $lang),
        'status' => $serviceHealth['attendance'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-id-card-clip',
    ],
    [
        'title' => 'KPI / statistika',
        'source' => 'GET /api/service/kpi',
        'target' => 'Analitika',
        'href' => spog_operations_url('hr', $lang, ['focus' => 'kpi']),
        'status' => $serviceHealth['kpi'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-chart-line',
    ],
    [
        'title' => 'Maliyye hereketleri',
        'source' => 'GET /api/service/finance',
        'target' => 'Finance panel',
        'href' => spog_operations_url('finance', $lang),
        'status' => $serviceHealth['finance'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-coins',
    ],
    [
        'title' => 'Texniki task paneli',
        'source' => 'GET /api/service/technical',
        'target' => 'Texniki isler',
        'href' => spog_operations_url('technical', $lang),
        'status' => $serviceHealth['technical'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-screwdriver-wrench',
    ],
    [
        'title' => 'Istifadeciler / rollar',
        'source' => 'GET /api/service/attendance + portal auth',
        'target' => 'Users panel',
        'href' => spog_operations_url('hr', $lang),
        'status' => 'Aktiv',
        'icon' => 'fa-users-gear',
    ],
    [
        'title' => 'Hesabat / backup',
        'source' => 'GET /api/service/reports',
        'target' => 'Hesabat ve backup',
        'href' => 'statistics-center.php' . ($lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''),
        'status' => $serviceHealth['reports'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-file-lines',
    ],
    [
        'title' => 'SNMP trap / botlar',
        'source' => 'oltidareetme/server/services/snmp-trap-service.js',
        'target' => 'NOC + mesaj merkezi',
        'href' => spog_operations_url('bots', $lang),
        'status' => $serviceHealth['bots'] ? 'Aktiv' : 'Yoxlanilir',
        'icon' => 'fa-robot',
    ],
    [
        'title' => 'SPoG process registry',
        'source' => 'superopticnet/mgs-integration.php',
        'target' => 'SPoG integration',
        'href' => 'mgs-integration.php',
        'status' => 'Aktiv',
        'icon' => 'fa-layer-group',
    ],
];

if (app_portal_is_admin($user)) {
    $legacyModuleMap[] = [
        'title' => 'MyGov ID / SSO',
        'source' => 'superopticnet/identity-integrations.php',
        'target' => 'Identity / SSO',
        'href' => 'identity-integrations.php',
        'status' => 'hazirliq',
        'icon' => 'fa-id-card',
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($operationsPageTitle); ?></title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .spog-search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}
        .spog-module-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:18px}
        .spog-module-card{display:grid;gap:12px;padding:20px;border:1px solid rgba(148,163,184,.22);border-radius:22px;background:linear-gradient(145deg,#fff,#f8fbff);text-decoration:none;color:inherit}
        .spog-module-card i{width:42px;height:42px;display:grid;place-items:center;border-radius:16px;background:rgba(255,140,45,.13);color:#d66a16}
        .spog-result-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
        .spog-quick-actions{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
        .spog-action{display:flex;align-items:center;gap:10px;padding:14px 16px;border-radius:18px;background:#fff;border:1px solid rgba(148,163,184,.24);font-weight:900;text-decoration:none;color:inherit}
        .spog-action i{color:#f97316}
        .spog-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
        .spog-service-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .spog-service-card{display:grid;gap:8px;padding:14px;border-radius:18px;border:1px solid rgba(148,163,184,.22);background:#fff}
        .spog-service-card strong{font-size:1rem;color:#10233f}
        .spog-service-card small{color:#64748b}
        .spog-service-card p{margin:0;color:#64748b;font-size:.84rem;line-height:1.45}
        .spog-list{display:grid;gap:10px}
        .spog-list-item{display:grid;gap:6px;padding:12px 14px;border-radius:16px;background:rgba(248,250,252,.94);border:1px solid rgba(148,163,184,.18)}
        .spog-list-item strong{color:#10233f}
        .spog-list-item small{color:#64748b}
        .spog-status-inline{display:flex;flex-wrap:wrap;gap:8px}
        .spog-status-inline span{padding:6px 10px;border-radius:999px;background:#eef8f7;color:#087b83;font-size:.76rem;font-weight:900}
        .spog-service-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .portal-layout .portal-table{min-width:0;table-layout:fixed}
        .portal-layout .portal-table th,
        .portal-layout .portal-table td{overflow-wrap:anywhere}
        .spog-backup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .spog-backup-form{display:grid;gap:12px}
        .spog-backup-quick-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0 16px}
        .spog-backup-metric{display:grid;gap:4px;padding:14px 16px;border-radius:18px;background:rgba(248,250,252,.96);border:1px solid rgba(148,163,184,.18)}
        .portal-report-action-panel{display:grid;gap:10px;min-width:min(520px,100%);max-width:760px}
        .portal-report-action-group{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;padding:10px;border:1px solid rgba(148,163,184,.18);border-radius:18px;background:rgba(248,250,252,.78)}
        .portal-report-action-group.is-statistics{grid-template-columns:repeat(4,minmax(0,1fr))}
        .portal-report-action-title{grid-column:1/-1;margin:0;color:#64748b;font-size:.74rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em}
        .portal-report-action-group .portal-inline-link{min-width:0;min-height:42px;justify-content:center;white-space:normal;text-align:center;line-height:1.2}
        .spog-backup-metric span{font-size:.78rem;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
        .spog-backup-metric strong{color:#10233f;overflow-wrap:anywhere}
        .spog-backup-form .portal-primary-btn,.spog-backup-form .portal-ghost-btn{width:100%;justify-content:center;white-space:normal;line-height:1.25}
        .spog-update-card{grid-column:1/-1}
        .spog-update-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}
        .spog-update-upload{display:grid;gap:6px;padding:12px;border:1px dashed rgba(11,123,135,.36);border-radius:16px;background:rgba(238,248,247,.72)}
        .spog-update-upload label{font-size:.78rem;font-weight:900;color:#0b7b87;text-transform:uppercase;letter-spacing:.04em}
        .spog-update-upload input[type=file]{width:100%;min-height:42px;padding:8px;border:1px solid rgba(148,163,184,.32);border-radius:12px;background:#fff;color:#10233f}
        .spog-update-upload small{color:#64748b}
        .spog-backup-output{margin:0 0 18px;padding:12px 14px;border-radius:16px;background:#0f172a;color:#e2e8f0;white-space:pre-wrap;word-break:break-word;font-size:.83rem;line-height:1.5}
        .spog-restore-points{display:grid;gap:10px}
        .spog-restore-point{display:grid;gap:4px;padding:12px 14px;border-radius:16px;background:rgba(248,250,252,.94);border:1px solid rgba(148,163,184,.18)}
        .spog-restore-point strong{color:#10233f}
        .spog-restore-point small{color:#64748b}
        .spog-monitor-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(340px,.9fr);gap:18px}
        .native-monitor-board{position:relative;min-height:640px;padding:18px;border-radius:18px;border:1px solid rgba(148,163,184,.18);background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(248,250,252,.95)),linear-gradient(90deg,rgba(15,23,42,.04) 1px,transparent 1px),linear-gradient(rgba(15,23,42,.04) 1px,transparent 1px);background-size:auto,32px 32px,32px 32px;overflow:hidden;touch-action:none}
        .monitor-toolbar{position:absolute;z-index:8;top:14px;left:14px;display:flex;gap:8px;align-items:center;padding:6px;border-radius:14px;background:rgba(255,255,255,.92);border:1px solid rgba(148,163,184,.22);box-shadow:0 10px 24px rgba(15,23,42,.08)}
        .monitor-tool{width:36px;height:34px;border:0;border-radius:10px;background:#eef6f7;color:#0b7b87;font-weight:900}
        .monitor-tool:hover{background:#d8eef1}
        .monitor-zoom-value{min-width:54px;text-align:center;color:#64748b;font-weight:900;font-size:.8rem}
        .monitor-stage{position:absolute;inset:0;transform-origin:0 0;transition:transform .12s ease}
        .monitor-links{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
        .monitor-link{stroke-width:2.2;opacity:.8}
        .monitor-link-up{stroke:#22c55e}
        .monitor-link-down{stroke:#f43f5e}
        .monitor-link-warning{stroke:#f59e0b}
        .monitor-link-maintenance{stroke:#64748b}
        .monitor-link-unknown{stroke:#94a3b8}
        .monitor-hub-native{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:260px;min-height:160px;display:grid;place-content:center;gap:8px;padding:20px;border-radius:18px;background:#1e293b;color:#f8fafc;text-align:center;box-shadow:0 20px 40px rgba(15,23,42,.25)}
        .monitor-hub-native span{font-size:.78rem;text-transform:uppercase;letter-spacing:.12em;color:#fbbf24}
        .monitor-node{position:absolute;transform:translate(-50%,-50%);width:min(230px,30%);min-width:190px;display:grid;gap:6px;padding:14px;border-radius:14px;background:#fff;border:2px solid #60a5fa;box-shadow:0 18px 30px rgba(15,23,42,.08);cursor:grab;user-select:none}
        .monitor-node:active{cursor:grabbing}
        .monitor-node-up{border-color:#22c55e;background:linear-gradient(180deg,#ffffff,#f0fdf4)}
        .monitor-node-down{border-color:#fb7185;background:linear-gradient(180deg,#ffffff,#fff1f2)}
        .monitor-node-warning{border-color:#f59e0b;background:linear-gradient(180deg,#ffffff,#fffbeb)}
        .monitor-node-maintenance{border-color:#94a3b8;background:linear-gradient(180deg,#ffffff,#f8fafc)}
        .monitor-node-unknown{border-color:#60a5fa;background:linear-gradient(180deg,#ffffff,#eff6ff)}
        .monitor-node-type{font-size:.8rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
        .monitor-node-name{font-size:1rem;font-weight:900;color:#10233f;overflow-wrap:anywhere}
        .monitor-node-meta,.monitor-node-ip{font-size:.84rem;color:#64748b;overflow-wrap:anywhere}
        .monitor-node-note{width:100%;min-height:42px;resize:vertical;border:1px solid rgba(148,163,184,.24);border-radius:10px;padding:8px 10px;background:rgba(255,255,255,.82);color:#10233f;font-size:.8rem;line-height:1.35;user-select:text}
        .monitor-node-note:focus{outline:0;border-color:rgba(11,123,135,.48);box-shadow:0 0 0 3px rgba(11,123,135,.1)}
        .monitor-action-grid{display:grid;gap:16px}
        .spog-monitor-focus{display:grid;gap:12px;padding:16px 18px;border-radius:22px;background:rgba(248,250,252,.96);border:1px solid rgba(148,163,184,.18)}
        .spog-monitor-focus-head{display:grid;gap:4px}
        .spog-monitor-focus-head strong{font-size:1.08rem;color:#10233f}
        .spog-monitor-focus-head small{color:#64748b}
        .spog-monitor-focus-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .spog-monitor-metric{display:grid;gap:4px;padding:10px 12px;border-radius:16px;background:#fff;border:1px solid rgba(148,163,184,.18)}
        .spog-monitor-metric span{font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#64748b}
        .spog-monitor-metric strong,.spog-monitor-metric small{color:#10233f;overflow-wrap:anywhere}
        .spog-monitor-device-list{display:grid;gap:10px}
        .spog-monitor-device-row{display:grid;gap:4px;padding:12px 14px;border-radius:16px;background:rgba(248,250,252,.94);border:1px solid rgba(148,163,184,.18)}
        .spog-monitor-device-row strong{color:#10233f}
        .spog-monitor-device-row small{color:#64748b;overflow-wrap:anywhere}
        .spog-monitor-device-row .portal-chip{justify-self:start}
        .spog-scan-results{display:grid;gap:10px;margin-top:14px}
        .spog-scan-result{padding:12px 14px;border-radius:16px;background:rgba(248,250,252,.94);border:1px solid rgba(148,163,184,.18)}
        .spog-scan-result strong{display:block;color:#10233f}
        .spog-scan-result small{display:block;color:#64748b;margin-top:4px}
        .noc-scan-dialog{grid-column:1/-1;display:grid;gap:14px;margin-top:14px;padding:16px;border:1px solid rgba(11,123,135,.18);border-radius:22px;background:linear-gradient(135deg,rgba(238,248,247,.95),rgba(255,255,255,.96))}
        .noc-scan-dialog.is-empty[hidden]{display:none!important}
        .noc-scan-dialog.is-open{position:fixed;z-index:620;inset:0;display:grid;align-content:center;justify-items:center;gap:0;margin:0;padding:24px;border:0;border-radius:0;background:rgba(15,23,42,.48);backdrop-filter:blur(7px);overflow:auto}
        .noc-scan-dialog.is-open>.noc-scan-head,.noc-scan-dialog.is-open>.noc-scan-results{width:min(1100px,calc(100vw - 32px));box-sizing:border-box}
        .noc-scan-dialog.is-open>.noc-scan-head{padding:18px 20px;border:1px solid rgba(148,163,184,.28);border-bottom:0;border-radius:22px 22px 0 0;background:linear-gradient(180deg,#fff,rgba(248,250,252,.98))}
        .noc-scan-dialog.is-open>.noc-scan-results{max-height:min(68vh,720px);overflow:auto;padding:0 20px 20px;border:1px solid rgba(148,163,184,.28);border-top:0;border-radius:0 0 22px 22px;background:rgba(248,250,252,.98)}
        .noc-scan-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
        .noc-scan-head h3{margin:4px 0;color:#10233f}
        .noc-scan-head small{color:#64748b}
        .noc-scan-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
        .noc-scan-close{background:#e5eaf0;color:#10233f}
        .noc-scan-results{display:grid;gap:10px}
        .noc-scan-results.is-attention{outline:3px solid rgba(249,115,22,.18);border-radius:18px}
        .noc-scan-empty,.noc-scan-result{display:grid;gap:10px;padding:12px 14px;border-radius:18px;background:#fff;border:1px solid rgba(148,163,184,.22);min-width:0}
        .noc-scan-result{grid-template-columns:auto minmax(160px,.55fr) minmax(0,1.45fr);align-items:start}
        .noc-scan-check{display:inline-flex;gap:7px;align-items:center;font-weight:900;color:#475569}
        .noc-scan-summary strong{display:block;color:#10233f;overflow-wrap:anywhere}
        .noc-scan-summary small,.noc-scan-empty small{display:block;margin-top:4px;color:#64748b;overflow-wrap:anywhere}
        .noc-scan-add-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:8px;align-items:center}
        .noc-scan-add-form input,.noc-scan-add-form select{width:100%;min-width:0;min-height:40px;border:1px solid rgba(148,163,184,.35);border-radius:12px;padding:0 10px;background:#fff;color:#10233f;font-weight:800}
        .noc-scan-add-form button{min-height:40px;white-space:nowrap;padding:9px 12px;font-size:.84rem}
        @media (max-width:1100px){.spog-module-grid,.spog-result-grid,.spog-quick-actions,.spog-service-strip{grid-template-columns:1fr}.spog-search{grid-template-columns:1fr}}
        @media (max-width:1100px){.spog-service-grid,.spog-backup-grid,.spog-backup-quick-summary,.spog-update-forms{grid-template-columns:1fr}}
        @media (max-width:1100px){.spog-monitor-grid{grid-template-columns:1fr}.monitor-node{width:min(82vw,260px)}.native-monitor-board{min-height:min(72vh,680px)}}
        @media (max-width:760px){
            .portal-operations-report>.portal-card-head{align-items:stretch}
            .portal-report-action-panel{min-width:0;max-width:none;width:100%}
            .portal-report-action-group,.portal-report-action-group.is-statistics{grid-template-columns:1fr 1fr}
            .native-monitor-board{min-height:0;overflow:hidden;touch-action:auto}
            .monitor-toolbar,.monitor-links,.monitor-hub-native{display:none}
            .monitor-stage{position:static;inset:auto;display:grid;grid-template-columns:1fr;gap:12px;transform:none!important}
            .monitor-stage{width:100%!important;height:auto!important}
            .monitor-node{position:static!important;left:auto!important;top:auto!important;transform:none!important;width:100%;min-width:0;max-width:100%;cursor:default}
            .monitor-node-note{min-height:38px}
            .spog-monitor-focus-grid{grid-template-columns:1fr}
            .noc-scan-result,.noc-scan-add-form{grid-template-columns:1fr}
            .noc-scan-head .portal-ghost-btn{width:100%}
            .spog-action,.spog-module-card,.spog-service-card,.spog-list-item{border-radius:16px;padding:12px}
        }
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header($operationsPageTitle, 'operations', $lang); ?>

    <main class="portal-layout">
        <!-- active-ops-section: <?= e($activeOpsSection); ?> -->
        <?php if (is_array($opsFlash)): ?>
            <div class="portal-alert portal-alert-<?= e((string) ($opsFlash['type'] ?? 'success')); ?>">
                <i class="fa-solid <?= (($opsFlash['type'] ?? '') === 'error') ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                <span><?= e((string) ($opsFlash['message'] ?? '')); ?></span>
            </div>
            <?php if (trim((string) ($opsFlash['details'] ?? '')) !== ''): ?>
                <pre class="spog-backup-output"><?= e((string) ($opsFlash['details'] ?? '')); ?></pre>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($activeOpsSection === 'overview'): ?>
        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">Single pane of glass</span>
                <h1>Butun emeliyyat kontekti bir ekranda</h1>
                <p>Musteri, billing, RADIUS, ticket, NOC, anbar ve HR modullari bu shell daxilinde native render olunur. Ayrica proqram, iframe ve duplike header axini saxlanilmir.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn" href="billing-customers.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) . '&create=1' : '?create=1'; ?>"><i class="fa-solid fa-user-plus"></i> Musteri yarat</a>
                    <a class="portal-ghost-btn portal-inline-link" href="billing-cash-payment.php"><i class="fa-solid fa-cash-register"></i> Odenis al</a>
                    <a class="portal-ghost-btn portal-inline-link" href="crm.php"><i class="fa-solid fa-ticket"></i> Ticketler</a>
                    <a class="portal-ghost-btn portal-inline-link" href="message-center.php"><i class="fa-solid fa-message"></i> Mesaj gonder</a>
                    <a class="portal-ghost-btn portal-inline-link" href="mgs-integration.php"><i class="fa-solid fa-layer-group"></i> Sistem servislari</a>
                    <?php if (app_portal_is_admin($user)): ?>
                        <a class="portal-ghost-btn portal-inline-link" href="identity-integrations.php"><i class="fa-solid fa-id-card"></i> MyGov ID</a>
                    <?php endif; ?>
                </div>
            </div>
            <aside class="mgs-command-panel">
                <span>Hazirliq statusu</span>
                <strong><?= e($db instanceof PDO ? 'DB aktiv' : 'DB passiv'); ?></strong>
                <small><?= array_sum(array_map(static fn (bool $item): int => $item ? 1 : 0, $serviceHealth)); ?>/<?= count($serviceHealth); ?> servis qati cavab verir.</small>
            </aside>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Cemi musteri', (string) $metrics['customers'], $metrics['active_customers'] . ' aktiv', 'fa-users', 'blue', 'billing-customers.php'); ?>
            <?php billing_render_stat_card('Online sessiya', (string) $metrics['online_sessions'], 'Radius accounting', 'fa-wifi', 'green', 'radius-active.php'); ?>
            <?php billing_render_stat_card('Bugun gelir', billing_money($metrics['payments_today']), 'Billing/maliyye', 'fa-coins', 'orange', 'billing-payments.php'); ?>
            <?php billing_render_stat_card('Aciq ticket', (string) $metrics['open_tickets'], 'CRM/NOC', 'fa-ticket', 'red', 'crm.php'); ?>
            <?php billing_render_stat_card('Mesaj queue', (string) $metrics['queued_messages'], 'Provider gozleyen', 'fa-message', 'navy', 'message-center.php'); ?>
            <?php billing_render_stat_card('VLAN plan', (string) $metrics['vlan_plans'], 'MPLS/provisioning', 'fa-diagram-project', 'blue', 'network-definitions.php'); ?>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <div>
                    <h2>Modul kecidleri</h2>
                    <small>Operations Center yalniz xulase saxlayir; icra isleri oz canonical sehifesinde acilir.</small>
                </div>
            </div>
            <div class="spog-quick-actions">
                <?php foreach ($moduleCards as $moduleCard): ?>
                    <a class="spog-action" href="<?= e((string) ($moduleCard['href'] ?? '#')); ?>">
                        <i class="fa-solid <?= e((string) ($moduleCard['icon'] ?? 'fa-circle')); ?>"></i>
                        <?= e((string) ($moduleCard['title'] ?? 'Modul')); ?>
                    </a>
                <?php endforeach; ?>
                <?php foreach ($legacyModuleMap as $legacyModule): ?>
                    <a class="spog-action" href="<?= e((string) ($legacyModule['href'] ?? '#')); ?>">
                        <i class="fa-solid <?= e((string) ($legacyModule['icon'] ?? 'fa-circle')); ?>"></i>
                        <?= e((string) ($legacyModule['title'] ?? 'Native modul')); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <div>
                    <h2>Servis statusu</h2>
                    <small>Bu panel billing shell daxilinde qalir. `oltidareetme` gorunmur, yalniz service data qatindan melumat cekilir.</small>
                </div>
            </div>
            <div class="spog-service-grid">
                <?php foreach ($serviceHealth as $serviceKey => $serviceOk): ?>
                    <article class="spog-service-card">
                        <strong><?= e(strtoupper($serviceKey)); ?></strong>
                        <div class="spog-status-inline">
                            <span><?= $serviceOk ? 'Aktiv' : 'Yoxlanilir'; ?></span>
                        </div>
                        <p><?= e(match ($serviceKey) {
                            'network' => 'Discovery, cihaz statusu, topology ve outage qati.',
                            'platform' => 'Platform process, queue ve servis yigilmasi.',
                            'inventory' => 'Anbar, barkod, stok ve tehvil hereketleri.',
                            'finance' => 'Gelir, xerc, satis ve filial hereketleri.',
                            'technical' => 'Texniki task, ticket baglama ve saha planlamasi.',
                            'attendance' => 'Davamiyyet, novbe ve isci hereketleri.',
                            'kpi' => 'Performans, SLA ve liderlik paneli.',
                            'reports' => 'Hesabat, backup ve analitika xulasesi.',
                            default => 'Native servis qati.'
                        }); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($activeOpsSection === 'noc'): ?>
        <section class="portal-card portal-reveal" id="noc-panel">
            <div class="portal-card-head">
                <div>
                    <h2>NOC ve network monitor</h2>
                    <small>Legacy MGS monitorundaki cihaz board-u, device add ve IP scan axini bu shell icinde API ile render olunur.</small>
                </div>
                <a class="portal-ghost-btn portal-inline-link" href="crm.php"><i class="fa-solid fa-triangle-exclamation"></i> Aktiv alarmlar</a>
            </div>
            <div class="spog-quick-actions">
                <a class="spog-action" id="network-monitor" href="noc-devices.php?view=monitor"><i class="fa-solid fa-heart-pulse"></i> Network monitor</a>
                <a class="spog-action" id="root-cause" href="billing-statistics.php#network-quality"><i class="fa-solid fa-brain"></i> Root cause</a>
                <a class="spog-action" id="device-discovery" href="noc-devices.php?view=devices"><i class="fa-solid fa-magnifying-glass-location"></i> Discovery</a>
                <a class="spog-action" id="mpls" href="network-definitions.php"><i class="fa-solid fa-route"></i> MPLS / VLAN</a>
            </div>
            <div class="spog-monitor-grid">
                <article class="portal-muted-card" data-inventory-posting-panel>
                    <div class="native-monitor-board" data-native-monitor-board>
                        <div class="monitor-toolbar" aria-label="Monitor aletleri">
                            <button type="button" class="monitor-tool" data-monitor-zoom="out" title="Kicilt">-</button>
                            <span class="monitor-zoom-value" data-monitor-zoom-value>100%</span>
                            <button type="button" class="monitor-tool" data-monitor-zoom="in" title="Boyut">+</button>
                            <button type="button" class="monitor-tool" data-monitor-reset title="Sifirla">R</button>
                        </div>
                        <div class="monitor-stage" data-monitor-stage>
                        <svg class="monitor-links" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            <?php foreach ($monitorPoints as $point): ?>
                                <?php $tone = spog_monitor_tone((array) $point['device']); ?>
                                <line x1="50" y1="50" x2="<?= e((string) $point['x']); ?>" y2="<?= e((string) $point['y']); ?>" class="monitor-link monitor-link-<?= e($tone); ?>"></line>
                            <?php endforeach; ?>
                        </svg>
                        <div class="monitor-hub-native">
                            <span>SuperOptik monitor</span>
                            <strong><?= e((string) count($displayNetworkDevices)); ?> avadanliq</strong>
                            <p><?= e((string) ($monitorCounts['up'] ?? 0)); ?> up • <?= e((string) (($monitorCounts['down'] ?? 0) + ($monitorCounts['warning'] ?? 0))); ?> problemli • <?= e((string) ($monitorCounts['maintenance'] ?? 0)); ?> maintenance</p>
                        </div>
                        <?php foreach ($monitorPoints as $point): ?>
                            <?php
                            $device = (array) $point['device'];
                            $tone = spog_monitor_tone($device);
                            $deviceKey = (string) (($device['id'] ?? '') ?: (($device['ipAddress'] ?? '') ?: ($device['name'] ?? 'device')));
                            ?>
                            <div class="monitor-node monitor-node-<?= e($tone); ?>" data-monitor-node data-monitor-id="<?= e($deviceKey); ?>" data-default-x="<?= e((string) $point['x']); ?>" data-default-y="<?= e((string) $point['y']); ?>" style="left:<?= e((string) $point['x']); ?>%;top:<?= e((string) $point['y']); ?>%;">
                                <span class="monitor-node-type"><?= e(strtoupper((string) ($device['deviceType'] ?? 'DEV'))); ?></span>
                                <strong class="monitor-node-name"><?= e((string) ($device['name'] ?? '-')); ?></strong>
                                <span class="monitor-node-meta"><?= e((string) ($device['vendor'] ?? '-')); ?> • <?= e((string) ($device['status'] ?? $device['discoveryStatus'] ?? 'unknown')); ?></span>
                                <span class="monitor-node-ip"><?= e((string) ($device['ipAddress'] ?? '-')); ?></span>
                                <textarea class="monitor-node-note" data-monitor-note placeholder="Qeyd..."><?= e((string) ($device['noteText'] ?? '')); ?></textarea>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </article>
                <article class="portal-muted-card">
                    <div class="monitor-action-grid">
                        <div class="spog-monitor-focus" data-monitor-focus-card>
                            <div class="spog-monitor-focus-head">
                                <span class="portal-kicker">Focus device</span>
                                <strong><?= e((string) ($primaryMonitorDevice['name'] ?? 'Device secilmeyib')); ?></strong>
                                <small><?= e(spog_monitor_device_label($primaryMonitorDevice)); ?></small>
                            </div>
                            <div class="spog-monitor-focus-grid">
                                <div class="spog-monitor-metric">
                                    <span>Status</span>
                                    <strong><?= e((string) ($primaryMonitorDevice['status'] ?? $primaryMonitorDevice['discoveryStatus'] ?? 'unknown')); ?></strong>
                                </div>
                                <div class="spog-monitor-metric">
                                    <span>IP</span>
                                    <strong><?= e((string) ($primaryMonitorDevice['ipAddress'] ?? '-')); ?></strong>
                                </div>
                                <div class="spog-monitor-metric">
                                    <span>Lokasiya</span>
                                    <small><?= e((string) ($primaryMonitorDevice['location'] ?? '-')); ?></small>
                                </div>
                                <div class="spog-monitor-metric">
                                    <span>Tip</span>
                                    <small><?= e((string) ($primaryMonitorDevice['deviceType'] ?? '-')); ?></small>
                                </div>
                            </div>
                        </div>
                        <div>
                            <strong>Cihaz elave et</strong>
                            <form class="portal-stacked-form" method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                <input type="hidden" name="action" value="create_monitor_device">
                                <div class="portal-form-row portal-grid-slim">
                                    <input type="text" name="device_name" placeholder="Cihaz adi" required>
                                    <input type="text" name="device_ip" placeholder="IP unvani" required>
                                </div>
                                <div class="portal-form-row portal-grid-slim">
                                    <select name="device_type">
                                        <option value="olt">OLT</option>
                                        <option value="router">Router</option>
                                        <option value="switch">Switch</option>
                                        <option value="server">Server</option>
                                        <option value="auto">Auto</option>
                                    </select>
                                    <input type="text" name="device_location" placeholder="Lokasiya" required>
                                </div>
                                <div class="portal-form-row portal-grid-slim">
                                    <input type="text" name="device_vendor" placeholder="Vendor" value="HSGQ">
                                    <input type="text" name="device_model" placeholder="Model">
                                </div>
                                <div class="portal-form-row portal-grid-slim">
                                    <input type="text" name="ssh_username" placeholder="SSH user" value="sardar" required>
                                    <input type="password" name="ssh_password" placeholder="SSH pass" required>
                                </div>
                                <div class="portal-form-row portal-grid-slim">
                                    <input type="number" name="ssh_port" placeholder="SSH port" value="22" min="1" max="65535">
                                    <input type="number" name="snmp_port" placeholder="SNMP port" value="<?= e((string) app_setting('mgs_snmp_port', 161)); ?>" min="1" max="65535">
                                </div>
                                <div class="portal-form-row portal-grid-slim">
                                    <input type="text" name="snmp_read_community" placeholder="SNMP RO" value="<?= e((string) app_setting('mgs_snmp_read_community', 'public')); ?>">
                                    <input type="text" name="snmp_write_community" placeholder="SNMP RW" value="<?= e((string) app_setting('mgs_snmp_write_community', 'private')); ?>">
                                </div>
                                <div class="portal-form-row portal-grid-slim">
                                    <input type="number" name="snmp_trap_port" placeholder="Trap port" value="<?= e((string) app_setting('mgs_snmp_trap_port', 162)); ?>" min="1" max="65535">
                                    <select name="driver_mode">
                                        <option value="real">real</option>
                                        <option value="experimental">experimental</option>
                                    </select>
                                </div>
                                <button type="submit" class="portal-primary-btn">Cihaz elave et</button>
                            </form>
                        </div>
                        <div>
                            <strong>Sebekeni IP scan et</strong>
                            <form class="portal-stacked-form" method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                <input type="hidden" name="action" value="scan_network">
                                <div class="portal-form-row">
                                    <input type="text" name="scan_target" placeholder="Meselen: 172.16.5.0/24" required>
                                </div>
                                <div class="portal-form-row portal-grid-slim">
                                    <input type="text" name="scan_ports" placeholder="22,80,443,161" value="22,80,443,161">
                                    <input type="number" name="scan_max_hosts" placeholder="Max host" value="64" min="1" max="1024">
                                </div>
                                <div class="portal-form-row">
                                    <input type="number" name="scan_concurrency" placeholder="Concurrency" value="24" min="1" max="128">
                                </div>
                                <button type="submit" class="portal-primary-btn">Sebekeni IP scan et</button>
                            </form>
                        </div>
                        <div class="spog-list">
                            <div class="spog-list-item">
                                <strong>Network summary</strong>
                                <small><?= e((string) ($monitorCounts['up'] ?? 0)); ?> up / <?= e((string) ($monitorCounts['down'] ?? 0)); ?> down / <?= e((string) ($monitorCounts['maintenance'] ?? 0)); ?> maintenance</small>
                            </div>
                            <div class="spog-list-item">
                                <strong>Top devices</strong>
                                <small><?= $networkDevicePreview === [] ? 'Device listesi service API-den gelmeyib.' : e((string) ($networkDevicePreview[0]['name'] ?? '-')); ?></small>
                            </div>
                            <div class="spog-list-item">
                                <strong>Open alarms</strong>
                                <small><?= $managementPathReady ? ($displayNetworkAlarms === [] ? 'Alarm yoxdur ve ya service cavabi yoxdur.' : e((string) count($displayNetworkAlarms)) . ' aktiv alarm') : 'VLAN 550 path off olduqu ucun internal offline alarm suppress edilir.'; ?></small>
                            </div>
                        </div>
                        <div class="spog-monitor-device-list" data-monitor-device-list>
                            <?php foreach ($monitorListDevices as $device): ?>
                                <?php if (!is_array($device)) { continue; } ?>
                                <?php $tone = spog_monitor_tone($device); ?>
                                <div class="spog-monitor-device-row">
                                    <span class="portal-chip portal-chip-<?= e($tone); ?>"><?= e(strtoupper($tone)); ?></span>
                                    <strong><?= e((string) ($device['name'] ?? '-')); ?></strong>
                                    <small><?= e(spog_monitor_device_label($device)); ?></small>
                                    <small><?= e((string) ($device['ipAddress'] ?? '-')); ?> • <?= e(spog_monitor_device_meta($device)); ?></small>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($monitorListDevices === []): ?>
                                <div class="spog-monitor-device-row">
                                    <strong>Device listesi service API-den gelmeyib.</strong>
                                    <small>Legacy MGS scanner/service cihaz siyahisini qaytarmayib.</small>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($problemMonitorDevices !== []): ?>
                            <div class="spog-list">
                                <?php foreach ($problemMonitorDevices as $device): ?>
                                    <div class="spog-list-item">
                                        <strong><?= e((string) ($device['name'] ?? '-')); ?></strong>
                                        <small><?= e((string) ($device['ipAddress'] ?? '-')); ?> • <?= e((string) ($device['status'] ?? $device['discoveryStatus'] ?? 'unknown')); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php app_noc_render_scan_results($scanResults, $scanTarget, ['limit' => 24]); ?>
                </article>
            </div>
        </section>

        <?php endif; ?>

        <?php if ($activeOpsSection === 'olt'): ?>
        <section class="portal-card portal-reveal" id="olt-panel">
            <div class="portal-card-head">
                <div>
                    <h2>OLT / ONT idareetme</h2>
                    <small>OLT, PON, ONT, optik siqnal ve VLAN isleri ucun esas kecidler.</small>
                </div>
                <a class="portal-ghost-btn portal-inline-link" href="network-definitions.php"><i class="fa-solid fa-diagram-project"></i> VLAN/MPLS planla</a>
            </div>
            <div class="spog-quick-actions">
                <a class="spog-action" href="network-definitions.php"><i class="fa-solid fa-route"></i> VLAN/MPLS</a>
                <a class="spog-action" href="billing-statistics.php#optical"><i class="fa-solid fa-chart-line"></i> Optik trend</a>
                <a class="spog-action" href="noc-devices.php?view=ont-search"><i class="fa-solid fa-wifi"></i> ONT konteksti</a>
                <a class="spog-action" href="radius-active.php"><i class="fa-solid fa-satellite-dish"></i> RADIUS sessiya</a>
            </div>
            <div class="portal-grid portal-grid-2" id="ont-panel">
                <article class="portal-muted-card" id="ont-search">
                    <strong>ONT axtarisi</strong>
                    <p>SN, MAC, PPPoE username, IP ve musteri kodu ile axtaris ucun universal axtarisden istifade et.</p>
                </article>
                <article class="portal-muted-card">
                    <strong>PON problem qruplasmasi</strong>
                    <p>Eyni PON-da coxsayli ONT problemi olduqda musterilere tek-tek bilet acilmir, sebeke problemi kimi qruplanir.</p>
                </article>
            </div>
            <?php if ($networkTickets !== []): ?>
                <div class="spog-list" style="margin-top:14px">
                    <?php foreach ($networkTickets as $item): ?>
                        <div class="spog-list-item">
                            <strong><?= e((string) ($item['title'] ?? $item['ticketNo'] ?? '-')); ?></strong>
                            <small><?= e((string) ($item['status'] ?? '-')); ?> / <?= e((string) ($item['customerName'] ?? $item['scopeLabel'] ?? '')); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php endif; ?>

        <?php if ($activeOpsSection === 'inventory' && is_array($inventoryContext)): ?>
            <?php require __DIR__ . '/views/inventory/module.php'; ?>
        <?php endif; ?>

        <?php if (false && $activeOpsSection === 'inventory'): ?>
        <section class="portal-card portal-reveal" id="inventory">
            <div class="portal-card-head">
                <div>
                    <h2>Anbar ve avadanliq</h2>
                    <small>Modem/ONT, kabel barabani, barkod, kritik stok ve texnike tehvil native API ile eyni shell daxilinde gorunur.</small>
                </div>
                <div class="portal-badge-list">
                    <a class="<?= $inventoryTab === 'stock' ? 'portal-primary-btn' : 'portal-ghost-btn'; ?> portal-inline-link" href="<?= e(spog_operations_url('inventory', $lang, ['inventory_tab' => 'stock'])); ?>"><i class="fa-solid fa-boxes-stacked"></i> Anbar axini</a>
                    <a class="<?= $inventoryTab === 'handover' ? 'portal-primary-btn' : 'portal-ghost-btn'; ?> portal-inline-link" href="<?= e(spog_operations_url('inventory', $lang, ['inventory_tab' => 'handover'])); ?>"><i class="fa-solid fa-screwdriver-wrench"></i> Tehvil / saha</a>
                    <a class="<?= $inventoryTab === 'lifecycle' ? 'portal-primary-btn' : 'portal-ghost-btn'; ?> portal-inline-link" href="<?= e(spog_operations_url('inventory', $lang, ['inventory_tab' => 'lifecycle'])); ?>"><i class="fa-solid fa-arrows-rotate"></i> Asset lifecycle</a>
                </div>
            </div>
            <div class="portal-grid portal-grid-3">
                <?php foreach (array_slice($inventorySummary, 0, 3) as $item): ?>
                    <article class="portal-muted-card">
                        <strong><?= e((string) ($item['title'] ?? '-')); ?></strong>
                        <p><?= e(spog_summary_value_text($item)); ?></p>
                        <small><?= e(spog_summary_note($item)); ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="portal-grid portal-grid-2">
                <article class="portal-muted-card" id="barcode">
                    <strong>Barkod scan</strong>
                    <p><?= $serviceHealth['inventory'] ? 'Anbar barkod ve stok hereketleri ucun hazirdir; kamera ve scanner axini bu data qatina oturacaq.' : 'Anbar servis qati yoxlanilir; barkod axini hele local fallback-dedir.'; ?></p>
                </article>
                <article class="portal-muted-card" id="critical-stock">
                    <strong>Kritik stok</strong>
                    <p><?= $inventoryBalances !== [] ? 'Real stok balanslari geldi; kritik qaliq ve bos stok siyahisi bu blokda prioritetlesdirilir.' : 'Hazir sample bazada balans yoxdur; yeni medaxil/mexaric yazilan kimi bu blok dolacaq.'; ?></p>
                </article>
            </div>
            <?php if ($inventoryTab === 'stock'): ?>
            <div class="portal-grid portal-grid-2" id="inventory-categories" style="margin-top:14px">
                <article class="portal-muted-card">
                    <div class="portal-card-head">
                        <div>
                            <strong>Kateqoriyalar</strong>
                            <small>Butun emlak ve stok mehsullari bu kateqoriyalardan biri ile yaradilir.</small>
                        </div>
                        <span class="portal-pill"><?= count($inventoryCategories); ?> kateqoriya</span>
                    </div>
                    <div class="portal-badge-list">
                        <?php foreach ($inventoryCategories as $category): ?>
                            <span class="portal-status-badge">
                                <i class="fa-solid fa-layer-group"></i>
                                <?= e((string) ($category['name'] ?? $category['code'] ?? '-')); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </article>
                <article class="portal-muted-card">
                    <strong>Kateqoriya elave et</strong>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_inventory_category">
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="category_name" placeholder="Kateqoriya adi (mes: IPTV box)" required>
                            <input type="text" name="category_code" placeholder="Kod bos qalarsa avtomatik">
                        </div>
                        <div class="portal-form-row">
                            <input type="text" name="category_description" placeholder="Qisa izah">
                        </div>
                        <button class="portal-primary-btn" type="submit">Kateqoriya yarat</button>
                    </form>
                </article>
            </div>
            <div class="portal-grid portal-grid-2" id="inventory-flow" style="margin-top:14px">
                <article class="portal-muted-card">
                    <div class="portal-card-head">
                        <div>
                            <strong>Stok axini</strong>
                            <small>Medaxil, mexaric ve texnike tehvil emeliyyatlari secilmis kateqoriya ile yazilir.</small>
                        </div>
                    </div>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_inventory_movement">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="product_id">
                                <option value="">Mehsul sec veya yeni SKU yaz</option>
                                <?php foreach ($inventoryProducts as $product): ?>
                                    <?php $productCategoryCode = (string) ($product['category'] ?? ''); ?>
                                    <option value="<?= e((string) ($product['id'] ?? '')); ?>">
                                        <?= e((string) ($product['sku'] ?? '')); ?> / <?= e((string) ($product['name'] ?? '-')); ?> / <?= e($inventoryCategoryNames[$productCategoryCode] ?? $productCategoryCode ?: 'kateqoriya yox'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="movement_type" required>
                                <option value="purchase">Medaxil / alis</option>
                                <option value="issue">Texnike ver</option>
                                <option value="return">Geri al</option>
                                <option value="consume">Mexaric / istifade</option>
                                <option value="defective">Defekt</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="inventory_new_sku" placeholder="Yeni mal SKU (mes: ONT-HSGQ-001)">
                            <input type="text" name="inventory_new_item_name" placeholder="Yeni mal adi">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="inventory_category_code" required>
                                <option value="">Kateqoriya sec</option>
                                <?php foreach ($inventoryCategories as $category): ?>
                                    <option value="<?= e((string) ($category['code'] ?? $category['name'] ?? '')); ?>"><?= e((string) ($category['name'] ?? $category['code'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="inventory_new_branch_note" placeholder="Filial qeydi (filial siyahidan secilir)">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" step="0.01" min="0" name="quantity" placeholder="Miqdar" required>
                            <input type="number" step="0.01" min="0" name="unit_price" placeholder="Birim qiymet">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="to_branch_id">
                                <option value="">Filial/anbar sec</option>
                                <?php foreach ($inventoryBranches as $branch): ?>
                                    <option value="<?= e((string) ($branch['id'] ?? '')); ?>"><?= e((string) ($branch['name'] ?? $branch['branchName'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="to_employee_user_id">
                                <option value="">Isciye tehvil yoxdursa bos</option>
                                <?php foreach ($technicalUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="serial_number" placeholder="SN">
                            <input type="text" name="mac_address" placeholder="MAC">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="asset_label" placeholder="Barkod / etiket">
                            <input type="text" name="usage_location" placeholder="Istifade yeri">
                        </div>
                        <div class="portal-form-row">
                            <textarea name="note_text" placeholder="Qeyd"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Stok hereketi yaz</button>
                    </form>
                </article>
                <article class="portal-muted-card">
                    <strong>Son stok hereketleri</strong>
                    <div class="spog-list">
                        <?php foreach ($inventoryMovements as $movement): ?>
                            <div class="spog-list-item">
                                <strong><?= e((string) ($movement['productName'] ?? $movement['productSku'] ?? '-')); ?></strong>
                                <small><?= e((string) ($movement['movementType'] ?? '-')); ?> / <?= e((string) ($movement['quantity'] ?? '0')); ?> / <?= e(billing_datetime($movement['createdAt'] ?? null)); ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($inventoryMovements === []): ?>
                            <div class="spog-list-item"><strong>Hereket yoxdur</strong><small>Medaxil/mexaric yazildiqca burada gorunecek.</small></div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
            <?php endif; ?>
            <?php if ($inventoryTab === 'handover'): ?>
            <div class="portal-grid portal-grid-2" id="inventory-handover" style="margin-top:14px">
                <article class="portal-muted-card">
                    <strong>Texnikden tehvil qebulu</strong>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_inventory_handover">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="handover_employee_user_id" required>
                                <option value="">Tehvil veren isci</option>
                                <?php foreach ($technicalUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="handover_branch_id">
                                <option value="">Filial/anbar</option>
                                <?php foreach ($inventoryBranches as $branch): ?>
                                    <option value="<?= e((string) ($branch['id'] ?? '')); ?>"><?= e((string) ($branch['name'] ?? $branch['branchName'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="handover_product_id" required>
                                <option value="">Qaytarilan mehsul</option>
                                <?php foreach ($inventoryProducts as $product): ?>
                                    <?php $handoverCategoryCode = (string) ($product['category'] ?? ''); ?>
                                    <option value="<?= e((string) ($product['id'] ?? '')); ?>">
                                        <?= e((string) ($product['sku'] ?? '')); ?> / <?= e((string) ($product['name'] ?? '-')); ?> / <?= e($inventoryCategoryNames[$handoverCategoryCode] ?? $handoverCategoryCode ?: 'kateqoriya yox'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" step="0.01" min="0" name="handover_quantity" placeholder="Miqdar" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="handover_serial_number" placeholder="SN">
                            <input type="text" name="handover_mac_address" placeholder="MAC">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="handover_item_type">
                                <option value="balance">Balans mehsulu</option>
                                <option value="asset">Inventar aktiv</option>
                            </select>
                            <input type="text" name="handover_item_note_text" placeholder="Setir qeydi">
                        </div>
                        <div class="portal-form-row">
                            <textarea name="handover_note_text" placeholder="Tehvil akti qeydi"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Tehvil qebul et</button>
                    </form>
                </article>
                <article class="portal-muted-card">
                    <strong>Son tehvil aktlari</strong>
                    <div class="spog-list">
                        <?php foreach ($inventoryHandovers as $handover): ?>
                            <div class="spog-list-item">
                                <strong><?= e((string) ($handover['employeeName'] ?? $handover['employeeUserName'] ?? '-')); ?></strong>
                                <small><?= e((string) ($handover['branchName'] ?? '-')); ?> / <?= e((string) ($handover['itemCount'] ?? 0)); ?> item / <?= e(billing_datetime($handover['confirmedAt'] ?? null)); ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($inventoryHandovers === []): ?>
                            <div class="spog-list-item"><strong>Tehvil akti yoxdur</strong><small>Texnikden qaytarilan mallar burada gorunecek.</small></div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
            <?php endif; ?>
            <?php if ($inventoryTab === 'lifecycle'): ?>
            <div class="portal-grid portal-grid-3" data-inventory-lifecycle-panel style="margin-top:14px">
                <article class="portal-muted-card">
                    <strong>Avadanliq dovriyyesi</strong>
                    <p>Techizatci -> anbar -> texnik -> musteri -> qaytarma -> yoxlama -> yeniden istifade axini deyismez jurnalda saxlanilir.</p>
                    <small>Duplicate MAC/SN, aktiv assignment ve idempotency guard test olunur.</small>
                </article>
                <article class="portal-muted-card">
                    <strong>Kabel barabani</strong>
                    <p>Baraban qaligi metr ile izlenir; ticket baglananda istifade olunan kabel ve connector balansdan dusur.</p>
                    <small>Smoke ssenari: 1000m -> 930m, 50 connector -> 46 connector.</small>
                </article>
                <article class="portal-muted-card">
                    <strong>Musteri avadanligi</strong>
                    <p>Musteriye baglanan modem/ONT musteri profilinde aktiv baglanti kimi saxlanilir.</p>
                    <small>Customer-owned cihaz stokdan dusmur, amma tarixceye dusur.</small>
                </article>
            </div>
            <div class="portal-grid portal-grid-2" style="margin-top:14px">
                <article class="portal-muted-card">
                    <strong>Qebul ssenarisi</strong>
                    <div class="spog-list">
                        <?php foreach (['Texnike modem, connector ve kabel barabani verilir', 'Musteride 55m kabel + 2 connector + modem quraşdırılır', 'Servis gedisinde 15m kabel + 2 connector yazılır', 'Modem A qaytarılır, inspected USED_WORKING olur', 'Customer-owned modem stokdan dusmeden qeyd olunur'] as $step): ?>
                            <div class="spog-list-item"><strong><?= e($step); ?></strong><small>Anbar dovriyyesi yoxlamasi</small></div>
                        <?php endforeach; ?>
                    </div>
                </article>
                <article class="portal-muted-card">
                    <strong>Validation guard-lar</strong>
                    <div class="spog-list">
                        <?php foreach (['Duplicate active modem assignment bloklanır', 'Insufficient cable/connector bloklanır', 'Duplicate MAC/SN bloklanır', 'Returned device inspection olmadan reuse edilməməlidir', 'Movement deletion yox, reversal/audit prinsipi'] as $guard): ?>
                            <div class="spog-list-item"><strong><?= e($guard); ?></strong><small>Qebul qaydasi</small></div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </div>
            <?php endif; ?>
            <?php if ($inventoryTab === 'stock'): ?>
            <div class="portal-table-wrap" style="margin-top:14px">
                <table class="portal-table">
                    <thead><tr><th>SKU / mehsul</th><th>Vahid</th><th>Kateqoriya</th><th>Minimal stok</th><th>Son qaliq</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice(spog_service_rows($serviceInventory, 'products'), 0, 6) as $product): ?>
                            <tr>
                                <td><strong><?= e((string) ($product['sku'] ?? '-')); ?></strong><br><small><?= e((string) ($product['name'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($product['unitName'] ?? '-')); ?></td>
                                <?php $tableCategoryCode = (string) ($product['category'] ?? $product['subcategory'] ?? ''); ?>
                                <td><?= e($inventoryCategoryNames[$tableCategoryCode] ?? $tableCategoryCode ?: '-'); ?></td>
                                <td><?= e((string) ($product['minStockLevel'] ?? '0')); ?></td>
                                <td><?= e((string) ($product['quantityOnHand'] ?? $product['currentStock'] ?? $product['stockBalance'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (spog_service_rows($serviceInventory, 'products') === []): ?>
                            <tr><td colspan="5">Inventory API cavabinda mehsul yoxdur.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <?php endif; ?>

        <?php if ($activeOpsSection === 'finance' && is_array($financeContext)): ?>
            <?php require __DIR__ . '/views/finance/module.php'; ?>
        <?php endif; ?>

        <?php if ($activeOpsSection === 'technical'): ?>
        <section class="portal-card portal-reveal" id="technical">
            <div class="portal-card-head">
                <div>
                    <h2>Texniki isler ve ticket baglama</h2>
                    <small>Ticketden texniki task yaratmaq, material silinmesi ve saha neticesi texniki service API ile oxunur.</small>
                </div>
                <div class="portal-badge-list">
                    <a class="portal-primary-btn portal-inline-link" href="<?= e(spog_operations_url('technical', $lang)); ?>"><i class="fa-solid fa-screwdriver-wrench"></i> Texniki task paneli</a>
                    <a class="portal-ghost-btn portal-inline-link" href="crm.php"><i class="fa-solid fa-ticket"></i> Ticketlere kec</a>
                </div>
            </div>
            <div class="portal-grid portal-grid-3">
                <article class="portal-muted-card">
                    <strong>Saha planlama</strong>
                    <p><?= e((string) ($technicalPlanning['summary']['title'] ?? 'Planlama')); ?></p>
                    <small><?= e((string) ($technicalPlanning['summary']['note'] ?? 'Texnik teyin etme, due date ve prioritet burada toplanir.')); ?></small>
                </article>
                <article class="portal-muted-card">
                    <strong>Material istifadesi</strong>
                    <p><?= $technicalTasks !== [] ? 'Task baglananda mehsul, ONT/modem ve kabel hereketini eyni ticket kontekstinde izlemek olur.' : 'Task datasi geldikce anbar hereketi ve sahe neticesi burada gorunecek.'; ?></p>
                </article>
                <article class="portal-muted-card">
                    <strong>Baglanma auditi</strong>
                    <p>Kim bagladi, ne yazdi, hansi avadanliq istifade olundu eyni shell daxilinde qalir.</p>
                </article>
            </div>
            <div class="portal-grid portal-grid-2" style="margin-top:14px">
                <article class="portal-muted-card">
                    <strong>Texniki task yarat</strong>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_technical_task">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="task_type" required>
                                <option value="installation">Qurasdirma</option>
                                <option value="repair">Temir</option>
                                <option value="field_work">Saha isi</option>
                                <option value="preventive">Profilaktika</option>
                            </select>
                            <select name="priority">
                                <option value="normal">Normal</option>
                                <option value="high">Yuksek</option>
                                <option value="critical">Kritik</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="customer_name" placeholder="Musteri / obyekt">
                            <select name="assigned_user_id">
                                <option value="">Texnik sec</option>
                                <?php foreach ($technicalUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <input type="text" name="customer_address" placeholder="Unvan" required>
                        </div>
                        <div class="portal-form-row">
                            <textarea name="note_text" placeholder="Tapshiriq qeydi"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Task yarat</button>
                    </form>
                </article>
                <article class="portal-muted-card">
                    <strong>Task + anbar baglantisi</strong>
                    <p>Task baglananda istifade olunan modem, ONT ve kabel hereketleri anbar service ile eyni kontekste dusur.</p>
                </article>
            </div>
            <div class="portal-table-wrap" style="margin-top:14px">
                <table class="portal-table">
                    <thead><tr><th>Task</th><th>Status</th><th>Texnik</th><th>Filial</th><th>Prioritet</th></tr></thead>
                    <tbody>
                        <?php foreach ($technicalTasks as $task): ?>
                            <tr>
                                <td><strong><?= e((string) ($task['title'] ?? $task['taskNo'] ?? '-')); ?></strong><br><small><?= e((string) ($task['customerName'] ?? $task['address'] ?? '')); ?></small></td>
                                <td><?= e((string) ($task['status'] ?? '-')); ?></td>
                                <td><?= e((string) ($task['assignedUserName'] ?? $task['assigneeName'] ?? '-')); ?></td>
                                <td><?= e((string) ($task['branchName'] ?? '-')); ?></td>
                                <td><?= e((string) ($task['priority'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($technicalTasks === []): ?>
                            <tr><td colspan="5">Texniki task tapilmadi.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php endif; ?>

        <?php if ($activeOpsSection === 'hr' && is_array($hrContext)): ?>
            <?php require __DIR__ . '/views/hr/module.php'; ?>
        <?php endif; ?>

        <?php if (false && $activeOpsSection === 'hr'): ?>
        <section class="portal-card portal-reveal" id="hr">
            <div class="portal-card-head">
                <div>
                    <h2>HR, davamiyyet ve KPI</h2>
                    <small>Is qrafiki, kart reader davamiyyeti, mezuniyyet, texnik performansi ve KPI native API axini ile birlesdirilir.</small>
                </div>
                <div class="portal-badge-list">
                    <a class="portal-primary-btn portal-inline-link" href="<?= e(spog_operations_url('hr', $lang)); ?>"><i class="fa-solid fa-id-card-clip"></i> HR paneli</a>
                    <a class="portal-ghost-btn portal-inline-link" href="<?= e(spog_operations_url('hr', $lang, ['focus' => 'kpi'])); ?>"><i class="fa-solid fa-chart-line"></i> KPI paneli</a>
                    <a class="portal-ghost-btn portal-inline-link" href="admin-users.php"><i class="fa-solid fa-users-gear"></i> Istifadeciler</a>
                </div>
            </div>
            <div class="portal-grid portal-grid-3">
                <?php foreach (array_slice($attendanceSummary, 0, 2) as $item): ?>
                    <article class="portal-muted-card"<?= (($item['title'] ?? '') === 'Davamiyyet' ? ' id="attendance"' : ''); ?>>
                        <strong><?= e((string) ($item['title'] ?? '-')); ?></strong>
                        <p><?= e(spog_summary_value_text($item)); ?></p>
                        <small><?= e(spog_summary_note($item)); ?></small>
                    </article>
                <?php endforeach; ?>
                <?php foreach (array_slice($kpiSummary, 0, 1) as $item): ?>
                    <article class="portal-muted-card" id="kpi">
                        <strong><?= e((string) ($item['title'] ?? 'KPI')); ?></strong>
                        <p><?= e(spog_summary_value_text($item)); ?></p>
                        <small><?= e(spog_summary_note($item)); ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="mgs-chart-strip" style="margin-top:14px">
                <article class="mgs-chart-tile">
                    <h3>KPI resolved liderleri</h3>
                    <div class="mgs-progress-list">
                        <?php foreach ($kpiTopRows as $row): ?>
                            <?php $resolvedCount = (int) ($row['resolvedCount'] ?? $row['ticketResolvedCount'] ?? 0); ?>
                            <div class="mgs-progress-row"><span><?= e(spog_employee_name($row)); ?></span><i class="mgs-progress-track"><b class="mgs-progress-fill" style="width:<?= e((string) spog_percent_value($resolvedCount, $kpiMaxResolved)); ?>%"></b></i><strong><?= e((string) $resolvedCount); ?></strong></div>
                        <?php endforeach; ?>
                        <?php if ($kpiTopRows === []): ?>
                            <div class="mgs-progress-row"><span>KPI data yoxdur</span><i class="mgs-progress-track"><b class="mgs-progress-fill" style="width:8%"></b></i><strong>0</strong></div>
                        <?php endif; ?>
                    </div>
                </article>
                <article class="mgs-chart-tile">
                    <h3>Install / saha isleri</h3>
                    <div class="mgs-histogram" aria-label="Install KPI">
                        <?php foreach ($kpiTopRows as $row): ?>
                            <?php $installCount = (int) ($row['installationCount'] ?? 0); ?>
                            <span title="<?= e(spog_employee_name($row)); ?>: <?= e((string) $installCount); ?>" style="height:<?= e((string) max(8, spog_percent_value($installCount, $kpiMaxInstall))); ?>%"></span>
                        <?php endforeach; ?>
                        <?php if ($kpiTopRows === []): ?>
                            <?php for ($i = 0; $i < 6; $i++): ?><span style="height:8%"></span><?php endfor; ?>
                        <?php endif; ?>
                    </div>
                    <p>Texnik performansi formadan yuxarida qisa vizual oxunur.</p>
                </article>
                <article class="mgs-chart-tile">
                    <h3>Davamiyyet ve rol nezareti</h3>
                    <p><?= e((string) count($attendanceUsers)); ?> aktiv emekdas HR secimlerinde gorunmelidir. KPI ve davamiyyet eyni emekdas bazasindan oxunur.</p>
                    <small>Gizli melumat yoxdur, rollar ve login qisa formatda saxlanilir.</small>
                </article>
            </div>
            <div class="portal-grid portal-grid-2" style="margin-top:14px">
                <article class="portal-muted-card" data-hr-employee-compensation>
                    <strong>Emekdas gross/net maas profili</strong>
                    <p>Isci yaradilan zaman yazilan net ve ya gross maas buradan da idare olunur; vergi, gelir ve xerc hesabatlari bu bazadan oxunur.</p>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_hr_employee_compensation">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="comp_employee_account_id" required>
                                <option value="">Isci sec</option>
                                <?php foreach ($attendanceUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="salary_mode">
                                <option value="gross"><?= e(app_ui_label('gross_salary')); ?> yazilib</option>
                                <option value="net">Net yazilib</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" step="0.01" min="0" name="comp_gross_salary" placeholder="<?= e(app_ui_label('gross_salary')); ?>">
                            <input type="number" step="0.01" min="0" name="comp_net_salary" placeholder="<?= e(app_ui_label('net_salary')); ?>">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="date" name="comp_effective_from" value="<?= e(date('Y-m-d')); ?>">
                            <input type="text" name="comp_note" placeholder="Emr/qeyd">
                        </div>
                        <button class="portal-primary-btn" type="submit">Maas profilini saxla</button>
                    </form>
                    <div class="portal-table-wrap" style="margin-top:12px">
                        <table class="portal-table mgs-compact-table">
                            <thead><tr><th>Isci</th><th><?= e(app_ui_label('salary_mode')); ?></th><th><?= e(app_ui_label('gross_salary')); ?></th><th><?= e(app_ui_label('net_salary')); ?></th><th>Tam xerc</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($hrCompensationRows, 0, 6) as $compRow): ?>
                                    <tr>
                                        <td><strong><?= e((string) ($compRow['employee_name'] ?? '-')); ?></strong><br><small><?= e((string) ($compRow['employee_role'] ?? $compRow['account_type'] ?? '-')); ?></small></td>
                                        <td><?= e((string) ($compRow['salary_mode'] ?? 'gross')); ?></td>
                                        <td><?= e(billing_money($compRow['gross_salary'] ?? 0)); ?></td>
                                        <td><?= e(billing_money($compRow['net_salary'] ?? 0)); ?></td>
                                        <td><?= e(billing_money($compRow['employer_total_cost'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($hrCompensationRows === []): ?><tr><td colspan="5">Aktiv maas profili yoxdur.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <article class="portal-muted-card" data-hr-score-system>
                    <strong>Sicil bali ve mukafat/ceza sistemi</strong>
                    <p>Her isci 100 baldan baslayir. Ceza ballari cixilir, mukafat ballari elave olunur; illik ve secilmis araliqla hesablanir.</p>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="record_hr_score_event">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="score_employee_account_id" required>
                                <option value="">Isci sec</option>
                                <?php foreach ($attendanceUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="score_event_type">
                                <option value="penalty">Ceza</option>
                                <option value="reward">Mukafat</option>
                                <option value="reset">Reset qeyd</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="score_reason_code">
                                <option value="absence">Ise gelmeyib (-20)</option>
                                <option value="late">Ise gecikib (-5)</option>
                                <option value="task_missed">Tapsiriq icra etmeyib (-20)</option>
                                <option value="discipline">Intizam pozuntusu (-10)</option>
                                <option value="bonus">Mukafat (+10)</option>
                                <option value="quality">Keyfiyyetli icra (+5)</option>
                                <option value="manual">Manual qeyd</option>
                            </select>
                            <input type="number" step="0.5" name="score_points_delta" placeholder="Default baldan ferqli ise">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="date" name="score_event_date" value="<?= e(date('Y-m-d')); ?>">
                            <input type="text" name="score_note" placeholder="Sebeb/qeyd">
                        </div>
                        <button class="portal-primary-btn" type="submit">Sicil hadisesini saxla</button>
                    </form>
                    <form class="portal-inline-form" method="get" style="margin-top:12px">
                        <input type="hidden" name="section" value="hr">
                        <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                        <input type="date" name="score_period_start" value="<?= e($hrScorePeriodStart); ?>">
                        <input type="date" name="score_period_end" value="<?= e($hrScorePeriodEnd); ?>">
                        <button class="portal-ghost-btn" type="submit">Araligi goster</button>
                    </form>
                    <div class="portal-table-wrap" style="margin-top:12px">
                        <table class="portal-table mgs-compact-table">
                            <thead><tr><th>Isci</th><th>Sicil bali</th><th>Cezalar</th><th>Mukafat</th><th>Net/Gross</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($hrScoreboardRows, 0, 8) as $scoreRow): ?>
                                    <tr>
                                        <td><strong><?= e((string) ($scoreRow['employee_name'] ?? $scoreRow['full_name'] ?? '-')); ?></strong><br><small><?= e((string) ($scoreRow['employee_role'] ?? $scoreRow['account_type'] ?? '-')); ?></small></td>
                                        <td><strong><?= e((string) ($scoreRow['score'] ?? 100)); ?>/100</strong><br><small><?= e((string) ($scoreRow['event_count'] ?? 0)); ?> hadise</small></td>
                                        <td><?= e((string) ($scoreRow['penalty_points'] ?? 0)); ?></td>
                                        <td><?= e((string) ($scoreRow['reward_points'] ?? 0)); ?></td>
                                        <td><?= e(billing_money($scoreRow['net_salary'] ?? 0)); ?> / <?= e(billing_money($scoreRow['gross_salary'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($hrScoreboardRows === []): ?><tr><td colspan="5">Sicil siyahisi yoxdur.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
            <div class="portal-grid portal-grid-2" style="margin-top:14px" data-hr-payroll-legal-analysis>
                <article class="portal-muted-card">
                    <strong>Maas, mezuniyyet ve ezamiyyet analizi</strong>
                    <p>Gross maasdan xalis net, isci adindan tutulan vergiler ve sirketin isci ucun tam xercini saxlayir.</p>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_hr_payroll_record">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="payroll_employee_account_id" required>
                                <option value="">Isci sec</option>
                                <?php foreach ($attendanceUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="month" name="period_month" value="<?= e(date('Y-m')); ?>">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" step="0.01" min="0" name="gross_salary" value="<?= e((string) $payrollPreview['gross_salary']); ?>" placeholder="<?= e(app_ui_label('gross_salary')); ?>">
                            <select name="payroll_status">
                                <option value="draft"><?= e(app_ui_status('draft')); ?></option>
                                <option value="approved"><?= e(app_ui_status('approved')); ?></option>
                                <option value="paid"><?= e(app_ui_status('paid')); ?></option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" step="0.5" min="0" name="leave_days" placeholder="Mezuniyyet gunu">
                            <input type="number" step="0.01" min="0" name="leave_daily_rate" placeholder="Mezuniyyet gunluk">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" step="0.5" min="0" name="travel_days" placeholder="Ezamiyyet gunu">
                            <input type="number" step="0.01" min="0" name="travel_daily_rate" placeholder="Ezamiyyet gunluk">
                        </div>
                        <div class="portal-form-row">
                            <input type="number" step="0.01" min="0" name="taxable_travel_amount" placeholder="Ezamiyyetin vergiye celb olunan hissesi">
                        </div>
                        <div class="portal-form-row">
                            <textarea name="payroll_note" placeholder="Qanuni esas, emr nomresi ve qeyd"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Maas/vergi analizini saxla</button>
                    </form>
                </article>
                <article class="portal-muted-card">
                    <div class="portal-card-head">
                        <div>
                            <strong>2026 <?= e(app_ui_label('legal_profile_preview')); ?></strong>
                            <small><?= e((string) ($payrollLegalProfile['profile_code'] ?? 'az_private_non_oil_2026')); ?></small>
                        </div>
                    </div>
                    <div class="mgs-progress-list">
                        <div class="mgs-progress-row mgs-progress-row-stacked"><span>Gross baza</span><i class="mgs-progress-track"><b class="mgs-progress-fill" style="width:100%"></b></i><strong><?= e(billing_money($payrollPreview['payroll_tax_base'])); ?></strong></div>
                        <div class="mgs-progress-row mgs-progress-row-stacked"><span><?= e(app_ui_label('income_tax')); ?></span><i class="mgs-progress-track"><b class="mgs-progress-fill is-hot" style="width:<?= e((string) spog_percent_value($payrollPreview['employee_income_tax'], max(1, $payrollPreview['payroll_tax_base']))); ?>%"></b></i><strong><?= e(billing_money($payrollPreview['employee_income_tax'])); ?></strong></div>
                        <div class="mgs-progress-row mgs-progress-row-stacked"><span>Isci sosial/tibbi/issizlik</span><i class="mgs-progress-track"><b class="mgs-progress-fill is-warm" style="width:<?= e((string) spog_percent_value($payrollPreview['employee_total_deductions'], max(1, $payrollPreview['payroll_tax_base']))); ?>%"></b></i><strong><?= e(billing_money($payrollPreview['employee_total_deductions'])); ?></strong></div>
                        <div class="mgs-progress-row mgs-progress-row-stacked"><span>Xalis net</span><i class="mgs-progress-track"><b class="mgs-progress-fill" style="width:<?= e((string) spog_percent_value($payrollPreview['net_salary'], max(1, $payrollPreview['employer_total_cost']))); ?>%"></b></i><strong><?= e(billing_money($payrollPreview['net_salary'])); ?></strong></div>
                        <div class="mgs-progress-row mgs-progress-row-stacked"><span><?= e(app_ui_label('employer_total_cost')); ?></span><i class="mgs-progress-track"><b class="mgs-progress-fill is-hot" style="width:100%"></b></i><strong><?= e(billing_money($payrollPreview['employer_total_cost'])); ?></strong></div>
                    </div>
                    <small>Mezuniyyet pulu payroll bazasina daxil edilir; ezamiyyetde vergiye celb olunan ve azad hisseler ayrica saxlanilir.</small>
                </article>
            </div>
            <div class="portal-card" style="margin-top:14px">
                <div class="portal-card-head">
                    <h2>Son maas/vergi analizleri</h2>
                    <small>Net: <?= e(billing_money($payrollCostTotals['net_salary'])); ?> / Isci tutulmalari: <?= e(billing_money($payrollCostTotals['employee_total_deductions'])); ?> / Sirket xerc: <?= e(billing_money($payrollCostTotals['employer_total_cost'])); ?></small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table mgs-compact-table">
                        <thead><tr><th>Isci</th><th><?= e(app_ui_label('period')); ?></th><th><?= e(app_ui_label('gross_salary')); ?></th><th><?= e(app_ui_label('net_salary')); ?></th><th>Isci vergileri</th><th>Sirket xerc</th></tr></thead>
                        <tbody>
                            <?php foreach ($payrollRows as $payrollRow): ?>
                                <tr>
                                    <td><strong><?= e((string) ($payrollRow['employee_name'] ?? '-')); ?></strong><br><small><?= e((string) ($payrollRow['profile_code'] ?? '-')); ?></small></td>
                                    <td><?= e(substr((string) ($payrollRow['period_month'] ?? ''), 0, 7)); ?></td>
                                    <td><?= e(billing_money($payrollRow['gross_salary'] ?? 0)); ?></td>
                                    <td><?= e(billing_money($payrollRow['net_salary'] ?? 0)); ?></td>
                                    <td><?= e(billing_money($payrollRow['employee_total_deductions'] ?? 0)); ?></td>
                                    <td><?= e(billing_money($payrollRow['employer_total_cost'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($payrollRows === []): ?>
                                <tr><td colspan="6">Maas/vergi analizi hele saxlanmayib.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="portal-grid portal-grid-3" style="margin-top:14px">
                <article class="portal-muted-card" data-hr-leave-average>
                    <strong>Mezuniyyet orta maas bazasi</strong>
                    <p>Son 12 ay payroll bazasindan orta: <?= e(billing_money($hrLeaveAveragePreview['average_monthly_salary'] ?? 0)); ?></p>
                    <small>Ay sayi: <?= e((string) ($hrLeaveAveragePreview['months_count'] ?? 0)); ?> / gunluk orta: <?= e(billing_money($hrLeaveAveragePreview['average_daily_salary'] ?? 0)); ?></small>
                    <form class="portal-stacked-form" method="post" style="margin-top:12px">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_hr_leave_request">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="leave_employee_account_id" required>
                                <option value="">Isci sec</option>
                                <?php foreach ($attendanceUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="month" name="leave_period_month" value="<?= e(date('Y-m')); ?>">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="leave_type">
                                <option value="annual">Illik mezuniyyet</option>
                                <option value="social">Sosial mezuniyyet</option>
                                <option value="unpaid">Odenissiz mezuniyyet</option>
                            </select>
                            <input type="number" step="0.5" min="0" name="leave_days" placeholder="Gun sayi">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="date" name="leave_start_date">
                            <input type="date" name="leave_end_date">
                        </div>
                        <div class="portal-form-row">
                            <textarea name="leave_note" placeholder="Emr nomresi ve mezuniyyet qeydi"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Mezuniyyeti hesabla</button>
                    </form>
                </article>
                <article class="portal-muted-card" data-hr-travel-workflow>
                    <strong>Ezamiyyet workflow</strong>
                    <p>Gundelik norma, faktiki vergiye celb olunan hisse ve vergiden azad hisse ayrica saxlanilir.</p>
                    <form class="portal-stacked-form" method="post" style="margin-top:12px">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_hr_travel_request">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="travel_employee_account_id" required>
                                <option value="">Isci sec</option>
                                <?php foreach ($attendanceUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="destination" placeholder="Seher/olke">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="date" name="travel_start_date">
                            <input type="date" name="travel_end_date">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" step="0.5" min="0" name="travel_days" placeholder="Gun sayi">
                            <input type="number" step="0.01" min="0" name="daily_rate" placeholder="Gundelik norma">
                        </div>
                        <div class="portal-form-row">
                            <input type="number" step="0.01" min="0" name="travel_taxable_amount" placeholder="Vergiye celb olunan hisse">
                        </div>
                        <div class="portal-form-row">
                            <textarea name="travel_purpose" placeholder="Ezamiyyet sebebi"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Ezamiyyeti saxla</button>
                    </form>
                </article>
                <article class="portal-muted-card" data-hr-document-completeness>
                    <strong>Sened tamligi</strong>
                    <p><?= e((string) count($hrRequiredDocumentTypes)); ?> mecburi sened tipi uzre isci fayli yoxlanir.</p>
                    <form class="portal-stacked-form" method="post" style="margin-top:12px">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_hr_employee_document">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="document_employee_account_id" required>
                                <option value="">Isci sec</option>
                                <?php foreach ($attendanceUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="document_type" required>
                                <option value="">Sened tipi</option>
                                <?php foreach ($hrRequiredDocumentTypes as $docType => $docLabel): ?>
                                    <option value="<?= e((string) $docType); ?>"><?= e((string) $docLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="document_no" placeholder="Sened nomresi">
                            <select name="document_status">
                                <option value="complete">Tamam</option>
                                <option value="pending">Gozlemede</option>
                                <option value="missing">Catismir</option>
                                <option value="expired">Vaxti bitib</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="date" name="document_issued_at">
                            <input type="date" name="document_expires_at">
                        </div>
                        <div class="portal-form-row">
                            <input type="text" name="document_file_ref" placeholder="Fayl linki/ref">
                        </div>
                        <button class="portal-primary-btn" type="submit">Senedi saxla</button>
                    </form>
                </article>
            </div>
            <div class="portal-grid portal-grid-2" style="margin-top:14px">
                <article class="portal-card">
                    <div class="portal-card-head"><h2>Son mezuniyyet hesablamalari</h2><small>12 ayliq orta baza ile</small></div>
                    <div class="portal-table-wrap">
                        <table class="portal-table mgs-compact-table">
                            <thead><tr><th>Isci</th><th>Gun</th><th>Orta</th><th>Mebleg</th><th><?= e(app_ui_label('status')); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($hrLeaveRows as $leaveRow): ?>
                                    <tr>
                                        <td><?= e((string) ($leaveRow['employee_name'] ?? '-')); ?></td>
                                        <td><?= e((string) ($leaveRow['leave_days'] ?? '0')); ?></td>
                                        <td><?= e(billing_money($leaveRow['average_daily_salary'] ?? 0)); ?></td>
                                        <td><?= e(billing_money($leaveRow['calculated_leave_pay'] ?? 0)); ?></td>
                                        <td><?= e(app_ui_status((string) ($leaveRow['status'] ?? '-'))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($hrLeaveRows === []): ?><tr><td colspan="5">Mezuniyyet hesablamasi yoxdur.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <article class="portal-card">
                    <div class="portal-card-head"><h2>Son ezamiyyetler</h2><small>Vergiye celb olunan ve azad hisse ayrilir</small></div>
                    <div class="portal-table-wrap">
                        <table class="portal-table mgs-compact-table">
                            <thead><tr><th>Isci</th><th>Yer</th><th>Gun</th><th>Azad</th><th>Vergili</th></tr></thead>
                            <tbody>
                                <?php foreach ($hrTravelRows as $travelRow): ?>
                                    <tr>
                                        <td><?= e((string) ($travelRow['employee_name'] ?? '-')); ?></td>
                                        <td><?= e((string) ($travelRow['destination'] ?? '-')); ?></td>
                                        <td><?= e((string) ($travelRow['travel_days'] ?? '0')); ?></td>
                                        <td><?= e(billing_money($travelRow['tax_exempt_amount'] ?? 0)); ?></td>
                                        <td><?= e(billing_money($travelRow['taxable_travel_amount'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($hrTravelRows === []): ?><tr><td colspan="5">Ezamiyyet hesabi yoxdur.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
            <div class="portal-card" style="margin-top:14px">
                <div class="portal-card-head"><h2>Isci senedlerinin tamligi</h2><small>Catismayan senedler HR risk kimi gosterilir.</small></div>
                <div class="portal-table-wrap">
                    <table class="portal-table mgs-compact-table">
                        <thead><tr><th>Isci</th><th>Tamam</th><th>Faiz</th><th>Catismayanlar</th></tr></thead>
                        <tbody>
                            <?php foreach ($hrDocumentCompleteness as $docRow): ?>
                                <tr>
                                    <td><strong><?= e((string) ($docRow['employee_name'] ?? '-')); ?></strong><br><small><?= e((string) ($docRow['role'] ?? '')); ?></small></td>
                                    <td><?= e((string) ($docRow['complete_count'] ?? 0)); ?> / <?= e((string) ($docRow['required_count'] ?? 0)); ?></td>
                                    <td><?= e((string) ($docRow['complete_percent'] ?? 0)); ?>%</td>
                                    <td><?= e(implode(', ', array_slice((array) ($docRow['missing_documents'] ?? []), 0, 3))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($hrDocumentCompleteness === []): ?><tr><td colspan="4">Sened tamligi datası yoxdur.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="portal-grid portal-grid-2" style="margin-top:14px">
                <article class="portal-muted-card" data-hr-finance-bridge>
                    <strong>HR <?= e(strtolower(app_ui_label('finance_bridge'))); ?></strong>
                    <p>Tesdiqlenmis payroll, mezuniyyet ve ezamiyyet xercini maliyye jurnalina expense kimi yazir.</p>
                    <form class="portal-stacked-form" method="post" style="margin-top:12px">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_hr_record_status">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="hr_record_type" required>
                                <option value="payroll">Maas</option>
                                <option value="leave">Mezuniyyet</option>
                                <option value="travel">Ezamiyyet</option>
                            </select>
                            <input type="number" min="1" name="hr_record_id" placeholder="<?= e(app_ui_label('record_id')); ?>" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="hr_record_status" required>
                                <option value="approved"><?= e(app_ui_status('approved')); ?></option>
                                <option value="paid"><?= e(app_ui_status('paid')); ?></option>
                                <option value="draft"><?= e(app_ui_status('draft')); ?></option>
                                <option value="cancelled"><?= e(app_ui_status('cancelled')); ?></option>
                                <option value="void"><?= e(app_ui_status('void')); ?></option>
                            </select>
                            <input name="reason" placeholder="Status sebebi" required>
                            <button class="portal-primary-btn" type="submit"><?= e(app_ui_action('update_status')); ?></button>
                        </div>
                    </form>
                    <form class="portal-stacked-form" method="post" style="margin-top:12px">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="record_hr_finance_expense">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="hr_record_type" required>
                                <option value="payroll">Maas</option>
                                <option value="leave">Mezuniyyet</option>
                                <option value="travel">Ezamiyyet</option>
                            </select>
                            <input type="number" min="1" name="hr_record_id" placeholder="<?= e(app_ui_label('record_id')); ?>" required>
                        </div>
                        <button class="portal-ghost-btn" type="submit">Maliyye xerci yaz</button>
                    </form>
                    <div class="mgs-progress-list" style="margin-top:12px">
                        <?php foreach ($hrFinanceBridgeRows as $bridgeRow): ?>
                            <?php $bridgePayload = is_array($bridgeRow['payload'] ?? null) ? $bridgeRow['payload'] : []; ?>
                            <div class="mgs-progress-row">
                                <span><?= e((string) ($bridgePayload['employeeName'] ?? $bridgeRow['entity_id'] ?? '-')); ?></span>
                                <i class="mgs-progress-track"><b class="mgs-progress-fill" style="width:100%"></b></i>
                                <strong><?= e(billing_money($bridgePayload['amount'] ?? 0)); ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($hrFinanceBridgeRows === []): ?>
                            <small>Finance bridge jurnali hele bosdur.</small>
                        <?php endif; ?>
                    </div>
                </article>
                <article class="portal-card" data-hr-cost-report>
                    <div class="portal-card-head">
                        <div>
                            <h2>HR xerc hesabatı</h2>
                            <small>Maas, mezuniyyet ve ezamiyyet sətirləri vahid cədvəldə.</small>
                        </div>
                        <button type="button" class="portal-ghost-btn" data-hr-print-report onclick="window.print()">Cap et</button>
                    </div>
                    <div class="portal-table-wrap">
                        <table class="portal-table mgs-compact-table">
                            <thead><tr><th>Tip / ID</th><th>Isci</th><th><?= e(app_ui_label('period')); ?></th><th>Mebleg</th><th><?= e(app_ui_label('status')); ?></th><th><?= e(app_ui_label('action')); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($hrCostReportRows as $costRow): ?>
                                    <tr>
                                        <td><strong><?= e((string) ($costRow['type_label'] ?? $costRow['record_type'] ?? '-')); ?></strong><br><small>#<?= e((string) ($costRow['record_id'] ?? '0')); ?></small></td>
                                        <td><?= e((string) ($costRow['employee_name'] ?? '-')); ?></td>
                                        <td><?= e((string) ($costRow['period'] ?? '-')); ?></td>
                                        <td><?= e(billing_money($costRow['amount'] ?? 0)); ?></td>
                                        <td><?= e(app_ui_status((string) ($costRow['status'] ?? '-'))); ?></td>
                                        <td>
                                            <form method="post" class="portal-inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="record_hr_finance_expense">
                                                <input type="hidden" name="hr_record_type" value="<?= e((string) ($costRow['record_type'] ?? '')); ?>">
                                                <input type="hidden" name="hr_record_id" value="<?= e((string) ($costRow['record_id'] ?? 0)); ?>">
                                                <button class="portal-ghost-btn" type="submit">Finance</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($hrCostReportRows === []): ?><tr><td colspan="6">HR xerc hesabatinda setir yoxdur.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
            <div class="portal-grid portal-grid-2" style="margin-top:14px">
                <article class="portal-muted-card">
                    <strong>Isci davamiyyet profili</strong>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_attendance_profile">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="user_id" required>
                                <option value="">Isci sec</option>
                                <?php foreach ($attendanceUsers as $employee): ?>
                                    <option value="<?= e(spog_employee_id($employee)); ?>"><?= e(spog_employee_name($employee)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="branch_id">
                                <option value="">Filial sec</option>
                                <?php foreach ($attendanceBranches as $branch): ?>
                                    <option value="<?= e((string) ($branch['id'] ?? '')); ?>"><?= e((string) ($branch['name'] ?? $branch['branchName'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="card_code" placeholder="Kart/RFID kodu">
                            <input type="number" name="late_grace_minutes" min="0" value="10" placeholder="Gecikme limiti">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="time" name="work_start_time" value="09:00">
                            <input type="time" name="work_end_time" value="18:00">
                        </div>
                        <div class="portal-form-row">
                            <textarea name="note_text" placeholder="Qrafik qeydi"></textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Davamiyyet profilini saxla</button>
                    </form>
                </article>
                <article class="portal-muted-card">
                    <div class="portal-card-head">
                        <div>
                            <strong>Movcud isciler</strong>
                            <small><?= count($attendanceUsers); ?> aktiv emekdas</small>
                        </div>
                    </div>
                    <div class="spog-list">
                        <?php foreach (array_slice($attendanceUsers, 0, 20) as $employee): ?>
                            <div class="spog-list-item">
                                <strong><?= e(spog_employee_name($employee)); ?></strong>
                                <small><?= e(spog_employee_role($employee)); ?> / <?= e(spog_employee_login($employee) ?: spog_employee_branch($employee)); ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($attendanceUsers === []): ?>
                            <div class="spog-list-item"><strong>Isci siyahisi bosdur</strong><small>Attendance service user list qaytarmayib.</small></div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
            <div class="portal-grid portal-grid-2" style="margin-top:14px">
                <article class="portal-card">
                    <div class="portal-card-head"><h2>Bugunku davamiyyet</h2><small>Filial uzre anliq isci statusu</small></div>
                    <div class="portal-table-wrap">
                        <table class="portal-table mgs-compact-table">
                            <?php if ($attendancePresenceBoard !== []): ?>
                            <thead><tr><th>Filial</th><th>Ofisde</th><th>Field</th><th>Mezuniyyet</th><th>Absent</th></tr></thead>
                            <tbody>
                                <?php foreach ($attendancePresenceBoard as $row): ?>
                                    <tr>
                                        <td><?= e((string) ($row['branchName'] ?? '-')); ?></td>
                                        <td><?= e((string) ($row['totals']['inOffice'] ?? 0)); ?></td>
                                        <td><?= e((string) ($row['totals']['fieldAssignments'] ?? 0)); ?></td>
                                        <td><?= e((string) ($row['totals']['onLeave'] ?? 0)); ?></td>
                                        <td><?= e((string) ($row['totals']['absent'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php else: ?>
                            <thead><tr><th>Isci</th><th>Rol</th><th>Login</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($attendanceUsers, 0, 20) as $employee): ?>
                                    <tr>
                                        <td><strong><?= e(spog_employee_name($employee)); ?></strong></td>
                                        <td><?= e(spog_employee_role($employee)); ?></td>
                                        <td><?= e(spog_employee_login($employee) ?: '-'); ?></td>
                                        <td><span class="portal-status-badge"><?= e((string) ($employee['status'] ?? 'Aktiv')); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($attendanceUsers === []): ?>
                                    <tr><td colspan="4">Davamiyyet ucun aktiv isci yoxdur.</td></tr>
                                <?php endif; ?>
                            </tbody>
                            <?php endif; ?>
                        </table>
                    </div>
                </article>
                <article class="portal-card">
                    <div class="portal-card-head"><h2>KPI liderleri</h2><small>Ticket ve sahe performansi</small></div>
                    <div class="portal-table-wrap">
                        <table class="portal-table mgs-compact-table">
                            <thead><tr><th>Isci</th><th>Rol</th><th>Resolved</th><th>Install</th></tr></thead>
                            <tbody>
                                <?php foreach ($kpiLeaderboard as $row): ?>
                                    <tr>
                                        <td><strong><?= e(spog_employee_name($row)); ?></strong><br><small><?= e(spog_employee_login($row) ?: spog_employee_branch($row)); ?></small></td>
                                        <td><?= e(spog_employee_role($row)); ?></td>
                                        <td><?= e((string) ($row['resolvedCount'] ?? $row['ticketResolvedCount'] ?? '0')); ?></td>
                                        <td><?= e((string) ($row['installationCount'] ?? '0')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($kpiLeaderboard === []): ?>
                                    <tr><td colspan="4">KPI leaderboard bosdur.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <?php endif; ?>

        <?php if ($activeOpsSection === 'bots'): ?>
        <section class="portal-card portal-reveal" id="bots">
            <div class="portal-card-head">
                <div>
                    <h2>Multi bot ve bildiris axini</h2>
                    <small>Telegram botlar, test mesajlari ve operational bildiris scope-lari service API ile yoxlanir.</small>
                </div>
                <a class="portal-ghost-btn portal-inline-link" href="message-center.php"><i class="fa-solid fa-message"></i> Mesaj merkezi</a>
            </div>
            <div class="portal-grid portal-grid-3">
                <article class="portal-muted-card">
                    <strong>Bot test mesaji</strong>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="send_bot_test_message">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="scope">
                                <option value="general">Umumi</option>
                                <option value="network"><?= e(app_ui_status('network')); ?></option>
                                <option value="customer"><?= e(app_ui_status('customer')); ?></option>
                                <option value="inventory">Anbar</option>
                                <option value="reports">Hesabatlar</option>
                                <option value="backup">Backup</option>
                                <option value="diagnostics">Diaqnostika</option>
                            </select>
                            <select name="severity">
                                <option value="info">Melumat</option>
                                <option value="warning">Xeberdarliq</option>
                                <option value="critical">Kritik</option>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <textarea name="message_text">TEST MESAJI&#10;&#10;SuperOptic MGS bildiris yoxlamasi.</textarea>
                        </div>
                        <button class="portal-primary-btn" type="submit">Test mesaj gonder</button>
                    </form>
                </article>
                <article class="portal-muted-card">
                    <strong>Aktiv botlar</strong>
                    <div class="spog-list">
                        <?php foreach ($telegramBots as $bot): ?>
                            <div class="spog-list-item">
                                <strong><?= e((string) ($bot['name'] ?? '-')); ?></strong>
                                <small><?= e((string) ($bot['botScope'] ?? $bot['bot_scope'] ?? '-')); ?> / <?= e(!empty($bot['isActive'] ?? $bot['is_active'] ?? false) ? app_ui_status('active') : app_ui_status('inactive')); ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($telegramBots === []): ?>
                            <div class="spog-list-item"><strong>Bot qeydiyyati gorunmur</strong><small>Service endpoint cavab vermedise restart lazim ola biler.</small></div>
                        <?php endif; ?>
                    </div>
                </article>
                <article class="portal-muted-card spog-bot-admin">
                    <strong>Bot idareetme</strong>
                    <p>Bot tokenleri UI-de gosterilmir. Buradan qeydiyyat, provider testi ve emeliyyat jurnalina kecid edilir.</p>
                    <div class="spog-bot-actions">
                        <a class="portal-ghost-btn portal-inline-link" href="mgs-integration.php?view=bots"><i class="fa-solid fa-robot"></i> Bot registry</a>
                        <a class="portal-ghost-btn portal-inline-link" href="message-center.php?view=test"><i class="fa-solid fa-paper-plane"></i> Provider test</a>
                        <a class="portal-ghost-btn portal-inline-link" href="mgs-integration.php?view=actions"><i class="fa-solid fa-list-check"></i> <?= e(app_ui_label('action')); ?> jurnali</a>
                    </div>
                    <div class="spog-bot-scope-grid">
                        <?php foreach ($telegramBots as $bot): ?>
                            <span class="portal-pill"><?= e((string) ($bot['botScope'] ?? $bot['bot_scope'] ?? $bot['scope'] ?? 'general')); ?></span>
                        <?php endforeach; ?>
                        <?php if ($telegramBots === []): ?>
                            <span class="portal-pill"><?= e(app_ui_status('network')); ?></span>
                            <span class="portal-pill"><?= e(app_ui_status('customer')); ?></span>
                            <span class="portal-pill">Anbar</span>
                            <span class="portal-pill">Backup</span>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </section>

        <?php endif; ?>

        <?php if ($activeOpsSection === 'reports'): ?>
        <section class="portal-card portal-reveal portal-operations-report" id="reports">
            <div class="portal-card-head">
                <div>
                    <h2>Hesabatlar, statistika ve backup</h2>
                    <small>Maliyye, anbar, network statistika, backup ve restore axini vahid hesabat paneline yigilir.</small>
                </div>
                <div class="portal-report-action-panel" data-report-action-panel>
                    <div class="portal-report-action-group" data-report-action-group="primary">
                        <span class="portal-report-action-title">Esas kecidler</span>
                        <a class="portal-primary-btn portal-inline-link" href="statistics-center.php<?= $lang !== 'az' ? '?lang=' . e(rawurlencode($lang)) : ''; ?>"><i class="fa-solid fa-chart-line"></i> Statistika merkezi</a>
                        <?php if ($reportStatisticsUrl !== null): ?>
                            <a class="portal-ghost-btn portal-inline-link" data-statistics-context="<?= e($reportModule . ':' . $reportType); ?>" href="<?= e($reportStatisticsUrl); ?>"><i class="fa-solid fa-chart-line"></i> Statistika</a>
                        <?php endif; ?>
                        <a class="portal-ghost-btn portal-inline-link" href="#data-analytics-center"><i class="fa-solid fa-chart-simple"></i> <?= e(app_ui_label('data_analytics')); ?></a>
                        <a class="portal-ghost-btn portal-inline-link" href="mgs-integration.php#backup"><i class="fa-solid fa-box-archive"></i> <?= e(app_ui_label('backup_worker')); ?></a>
                    </div>
                    <?php if ($reportStatisticsUrl === null): ?>
                        <div class="portal-report-action-group is-statistics" data-report-action-group="statistics">
                            <span class="portal-report-action-title">Modul statistikalari</span>
                            <a class="portal-ghost-btn portal-inline-link" href="operations-center.php?section=finance&amp;finance_tab=statistics"><i class="fa-solid fa-coins"></i> Maliyye</a>
                            <a class="portal-ghost-btn portal-inline-link" href="operations-center.php?section=inventory&amp;inventory_tab=statistics"><i class="fa-solid fa-boxes-stacked"></i> Anbar</a>
                            <a class="portal-ghost-btn portal-inline-link" href="operations-center.php?section=hr&amp;hr_tab=statistics"><i class="fa-solid fa-users"></i> HR</a>
                            <a class="portal-ghost-btn portal-inline-link" href="billing-statistics.php"><i class="fa-solid fa-chart-line"></i> Billing</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($analyticsCenter !== []): ?>
            <article class="portal-card" id="data-analytics-center" style="margin-bottom:14px">
                <div class="portal-card-head">
                    <div>
                        <h2><?= e(app_ui_label('analytics_center')); ?></h2>
                        <small><?= e(app_ui_label('operational_snapshot')); ?></small>
                    </div>
                    <span class="portal-pill"><?= e((string) ($analyticsCenter['generated_at'] ?? '')); ?></span>
                </div>
                <div class="portal-grid portal-grid-3">
                    <?php foreach ((array) ($analyticsCenter['kpis'] ?? []) as $kpi): ?>
                        <article class="portal-muted-card">
                            <strong><?= e((string) ($kpi['label'] ?? '-')); ?></strong>
                            <p><?= (($kpi['kind'] ?? 'count') === 'money') ? e(app_analytics_money($kpi['value'] ?? 0)) : e(number_format((float) ($kpi['value'] ?? 0), 0, '.', ' ')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="portal-grid portal-grid-2" style="margin-top:14px">
                    <?php
                    $analyticsChartSets = [
                        'monthly_revenue' => ['title' => 'Son 12 ay gelir', 'money' => true],
                        'provider_revenue' => ['title' => app_ui_label('provider_terminal_revenue'), 'money' => true],
                        'radius_risk' => ['title' => app_ui_label('radius_status'), 'money' => false],
                        'bonus_periods' => ['title' => app_ui_label('bonus_periods'), 'money' => true],
                        'message_channels' => ['title' => 'Mesaj kanallari', 'money' => false],
                    ];
                    ?>
                    <?php foreach ($analyticsChartSets as $chartKey => $chartMeta): ?>
                        <?php $chartRows = (array) (($analyticsCenter['charts'][$chartKey] ?? [])); $values = array_map(static fn (array $row): float => (float) ($row['value_total'] ?? $row['row_count'] ?? 0), $chartRows); $maxValue = $values === [] ? 1.0 : max(1.0, ...$values); ?>
                        <article class="portal-muted-card">
                            <strong><?= e((string) $chartMeta['title']); ?></strong>
                            <div class="spog-list" style="margin-top:10px">
                                <?php foreach ($chartRows as $row): ?>
                                    <?php $value = (float) ($row['value_total'] ?? $row['row_count'] ?? 0); ?>
                                    <div class="mgs-progress-row">
                                        <span><?= e((string) ($row['label'] ?? '-')); ?></span>
                                        <i class="mgs-progress-track"><b class="mgs-progress-fill" style="width:<?= e((string) max(4, min(100, round(($value / $maxValue) * 100, 2)))); ?>%"></b></i>
                                        <strong><?= !empty($chartMeta['money']) ? e(app_analytics_money($value)) : e(number_format($value, 0, '.', ' ')); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($chartRows === []): ?><div class="spog-list-item"><strong>Melumat yoxdur</strong><small>Bu data seti ucun melumat tapilmadi.</small></div><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="portal-table-wrap" style="margin-top:14px">
                    <table class="portal-table">
                        <thead><tr><th>Cedvel</th><th>Domen</th><th>Setir</th><th>Status</th><th>Ilk analitika isi</th></tr></thead>
                        <tbody>
                            <?php foreach ((array) ($analyticsCenter['tables'] ?? []) as $row): ?>
                                <tr>
                                    <td><strong><?= e((string) ($row['table'] ?? '-')); ?></strong></td>
                                    <td><?= e((string) ($row['domain'] ?? '-')); ?></td>
                                    <td><?= e(number_format((int) ($row['rows'] ?? 0), 0, '.', ' ')); ?></td>
                                    <td><span class="portal-pill"><?= e(app_ui_status((string) ($row['status'] ?? '-'))); ?></span></td>
                                    <td><?= e((string) ($row['task'] ?? '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="spog-list" style="margin-top:14px">
                    <?php foreach ((array) ($analyticsCenter['source_notes'] ?? []) as $note): ?><div class="spog-list-item"><strong>Metrika qeydi</strong><small><?= e((string) $note); ?></small></div><?php endforeach; ?>
                </div>
            </article>
            <?php endif; ?>
            <div class="portal-grid portal-grid-3">
                <?php foreach (array_slice($reportsSummary, 0, 3) as $item): ?>
                    <article class="portal-muted-card"<?= (($item['title'] ?? '') === 'Backup' ? ' id="backup"' : ''); ?>>
                        <strong><?= e((string) ($item['title'] ?? '-')); ?></strong>
                        <p><?= e(spog_summary_value_text($item)); ?></p>
                        <small><?= e(spog_summary_note($item)); ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="portal-grid <?= $reportBranches === [] ? 'portal-grid-1' : 'portal-grid-2'; ?>" style="margin-top:14px">
                <?php if ($reportBranches !== []): ?>
                <article class="portal-card">
                    <div class="portal-card-head"><h2>Filial neticeleri</h2><small>Gelir / xerc / task xulasəsi</small></div>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead><tr><th>Filial</th><th>Gelir</th><th>Xerc</th><th>Net</th></tr></thead>
                            <tbody>
                                <?php foreach ($reportBranches as $row): ?>
                                    <tr>
                                        <td><?= e((string) ($row['branchName'] ?? '-')); ?></td>
                                        <td><?= e((string) ($row['income'] ?? '0')); ?></td>
                                        <td><?= e((string) ($row['expense'] ?? '0')); ?></td>
                                        <td><?= e((string) ($row['net'] ?? '0')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($reportBranches === []): ?>
                                    <tr><td colspan="4">Filial hesabatlari yoxdur.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <?php endif; ?>
                <article class="portal-card" id="backup">
                    <div class="portal-card-head"><h2>Backup ve yenileme</h2><small>Billing + MGS fayllari, DB ve restore pointler native panelden idare olunur.</small></div>
                    <div class="spog-backup-quick-summary">
                        <div class="spog-backup-metric">
                            <span>Son restore point</span>
                            <strong><?= e((string) ($latestRestorePoint['name'] ?? 'hele yoxdur')); ?></strong>
                        </div>
                        <div class="spog-backup-metric">
                            <span>Qeyd sayi</span>
                            <strong><?= e((string) $restorePointCount); ?></strong>
                        </div>
                        <div class="spog-backup-metric">
                            <span>Update kanali</span>
                            <strong><?= e($updateConfigured ? (string) ($updateChannel['channel'] ?? 'stable') : 'konfiq yoxdur'); ?></strong>
                        </div>
                        <div class="spog-backup-metric">
                            <span>Remote versiya</span>
                            <strong><?= e(trim((string) ($updateChannel['remote_version'] ?? '')) !== '' ? (string) ($updateChannel['remote_version'] ?? '') : ($updateHealthy ? 'manifest ok' : 'gelen yoxdur')); ?></strong>
                        </div>
                    </div>
                    <div class="spog-backup-grid">
                        <article class="portal-muted-card">
                            <strong>Heftelik backup</strong>
                            <p>Legacy backup skripti indi hem `oltidareetme`, hem `superopticnet` fayllarini eyni restore point daxilinde saxlayir.</p>
                            <div class="spog-backup-location">
                                <span>Backup qovlugu</span>
                                <strong><?= e(app_mgs_backup_root()); ?></strong>
                            </div>
                            <?php if (app_portal_is_admin($user)): ?>
                                <form method="post" class="spog-backup-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="backup_now">
                                    <button type="submit" class="portal-primary-btn"><i class="fa-solid fa-box-archive"></i> Backup yarat</button>
                                </form>
                                <form method="post" class="spog-backup-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="backup_telegram">
                                    <button type="submit" class="portal-ghost-btn"><i class="fa-solid fa-paper-plane"></i> Backup + Telegram</button>
                                </form>
                                <form method="post" class="spog-backup-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="install_weekly_backup_task">
                                    <button type="submit" class="portal-ghost-btn"><i class="fa-solid fa-calendar-days"></i> Heftelik scheduler qur</button>
                                </form>
                            <?php else: ?>
                                <small>Backup yaratmaq ve scheduler qurmaq admin rolu teleb edir.</small>
                            <?php endif; ?>
                        </article>
                        <article class="portal-muted-card">
                            <strong>Restore noqtesi</strong>
                            <p>Evvelce verify edin. Real restore yalniz `RESTORE` tesdiqi ile ishleyir.</p>
                            <?php if (app_portal_is_admin($user)): ?>
                                <form method="post" class="spog-backup-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="restore_verify">
                                    <div class="portal-form-row">
                                        <label>Restore point sec</label>
                                        <select name="restore_file" required>
                                            <?php foreach ($restorePoints as $restorePoint): ?>
                                                <option value="<?= e((string) ($restorePoint['name'] ?? '')); ?>"><?= e((string) ($restorePoint['name'] ?? '')); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="portal-ghost-btn"><i class="fa-solid fa-shield-check"></i> Verify et</button>
                                </form>
                                <form method="post" class="spog-backup-form" style="margin-top:12px">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="restore_execute">
                                    <div class="portal-form-row">
                                        <label>Restore point sec</label>
                                        <select name="restore_file" required>
                                            <?php foreach ($restorePoints as $restorePoint): ?>
                                                <option value="<?= e((string) ($restorePoint['name'] ?? '')); ?>"><?= e((string) ($restorePoint['name'] ?? '')); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <label class="admin-users-inline"><input type="checkbox" name="restore_files" value="1"><span>Fayllari da restore et</span></label>
                                    <label class="admin-users-inline"><input type="checkbox" name="restore_env" value="1"><span>.env de geri qaytarilsin</span></label>
                                    <div class="portal-form-row">
                                        <label>Tesdiq</label>
                                        <input type="text" name="confirm_phrase" placeholder="RESTORE">
                                    </div>
                                    <button type="submit" class="portal-primary-btn"><i class="fa-solid fa-rotate-left"></i> Restore icra et</button>
                                </form>
                            <?php else: ?>
                                <small>Restore funksiyasi admin rolu teleb edir.</small>
                            <?php endif; ?>
                        </article>
                        <article class="portal-muted-card spog-update-card" id="update-package-card" data-update-package-card>
                            <strong>Update kanali</strong>
                            <p><?= e((string) ($updateChannel['notes'] ?? '')); ?></p>
                            <div class="spog-meta">
                                <span class="portal-pill">kanal: <?= e((string) ($updateChannel['channel'] ?? 'stable')); ?></span>
                                <span class="portal-pill"><?= !empty($updateChannel['manifest_ok']) ? 'manifest ok' : (!empty($updateChannel['configured']) ? 'manifest cavabi yoxdur' : 'konfiq yoxdur'); ?></span>
                                <?php if (trim((string) ($updateChannel['remote_version'] ?? '')) !== ''): ?>
                                    <span class="portal-pill">remote: <?= e((string) ($updateChannel['remote_version'] ?? '')); ?></span>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="spog-backup-form" style="margin-top:12px">
                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                <input type="hidden" name="action" value="check_update_channel">
                                <button type="submit" class="portal-ghost-btn"><i class="fa-solid fa-arrows-rotate"></i> Update kanalini yoxla</button>
                            </form>
                            <?php if (app_portal_is_admin($user)): ?>
                                <div class="spog-update-forms">
                                <form method="post" class="spog-backup-form" style="margin-top:12px" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="verify_update_package">
                                    <div class="spog-update-upload" data-update-package-upload>
                                        <label>Fayl sec</label>
                                        <input type="file" name="update_package_file" accept=".zip,application/zip,application/x-zip-compressed" data-update-package-picker>
                                        <small>.zip secile biler; bos qalarsa asagidaki path/URL ve ya manifest istifade olunur.</small>
                                    </div>
                                    <div class="portal-form-row">
                                        <label>Update paketi</label>
                                        <input type="text" name="update_package" data-update-package-path placeholder="Bos saxla -> manifest package_url, veya C:\...\update.zip">
                                    </div>
                                    <button type="submit" class="portal-ghost-btn"><i class="fa-solid fa-box-open"></i> Paketi verify et</button>
                                </form>
                                <form method="post" class="spog-backup-form" style="margin-top:12px" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="apply_update_package">
                                    <div class="spog-update-upload" data-update-package-upload>
                                        <label>Fayl sec</label>
                                        <input type="file" name="update_package_file" accept=".zip,application/zip,application/x-zip-compressed" data-update-package-picker>
                                        <small>.zip secile biler; bos qalarsa asagidaki path/URL ve ya manifest istifade olunur.</small>
                                    </div>
                                    <div class="portal-form-row">
                                        <label>Update paketi</label>
                                        <input type="text" name="update_package" data-update-package-path placeholder="Bos saxla -> manifest package_url, veya C:\...\update.zip">
                                    </div>
                                    <label class="admin-users-inline"><input type="checkbox" name="update_env" value="1"><span>config.local.php ve .env de tetbiq edilsin</span></label>
                                    <div class="portal-form-row">
                                        <label>Tesdiq</label>
                                        <input type="text" name="confirm_phrase" placeholder="UPDATE">
                                    </div>
                                    <button type="submit" class="portal-primary-btn"><i class="fa-solid fa-download"></i> Update tetbiq et</button>
                                </form>
                                </div>
                            <?php else: ?>
                                <small>Update verify/apply emeliyyatlari admin rolu teleb edir.</small>
                            <?php endif; ?>
                            <small>Apply axini evvelce avtomatik backup yaradir, sonra paketi yoxlayib legacy + billing fayllarini tetbiq edir.</small>
                        </article>
                        <article class="portal-muted-card">
                            <strong>Son restore pointler</strong>
                            <div class="spog-restore-points">
                                <?php foreach ($restorePoints as $restorePoint): ?>
                                    <div class="spog-restore-point">
                                        <strong><?= e((string) ($restorePoint['name'] ?? '-')); ?></strong>
                                        <small><?= e(billing_datetime(isset($restorePoint['modified_at']) ? date('Y-m-d H:i:s', (int) $restorePoint['modified_at']) : null)); ?></small>
                                        <small><?= e(billing_bytes($restorePoint['size_bytes'] ?? 0)); ?></small>
                                        <?php if (app_portal_is_admin($user)): ?>
                                            <a class="portal-ghost-btn portal-inline-link spog-download-link" href="<?= e(spog_operations_url('reports', $lang, ['download_restore' => (string) ($restorePoint['name'] ?? '')])); ?>"><i class="fa-solid fa-download"></i> Yukle</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($restorePoints === []): ?>
                                    <div class="spog-restore-point">
                                        <strong>Restore point tapilmadi</strong>
                                        <small>Backup qovlugu hele bosdur.</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                </article>
            </div>
        </section>

        <?php endif; ?>

        <?php if ($shouldRenderSearch): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <div>
                    <h2>Universal axtaris</h2>
                    <small>Kod, ad, telefon, IP, MAC, ticket, PPPoE username veya VLAN melumati ile axtar.</small>
                </div>
            </div>
            <form class="spog-search" method="get">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="portal-form-row">
                    <label for="spogSearch">Axtaris sorgusu</label>
                    <input id="spogSearch" name="q" value="<?= e($query); ?>" placeholder="1100000 / SN / MAC / IP / ticket / username">
                </div>
                <button class="portal-primary-btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Axtar</button>
            </form>
        </section>

        <?php if ($query !== ''): ?>
            <section class="spog-result-grid portal-reveal">
                <article class="portal-card">
                    <div class="portal-card-head"><h2>Musteri neticeleri</h2><small>Profil acildiqda odenis, Radius, ONT, ticket ve mesajlar bir yerde gorunur.</small></div>
                    <div class="portal-action-list">
                        <?php if ($customerResults === []): ?><?php billing_render_empty_state('Musteri tapilmadi', 'Axtaris diger bloklarda ola biler.', 'fa-user-magnifying-glass'); ?><?php endif; ?>
                        <?php foreach ($customerResults as $row): ?>
                            <?php
                            $customerProfileHref = !empty($row['subscriber_code'])
                                ? 'billing-customer-profile.php?code=' . rawurlencode((string) $row['subscriber_code'])
                                : 'billing-customer-profile.php?id=' . rawurlencode((string) ($row['id'] ?? 0));
                            ?>
                            <a class="portal-link-card" href="<?= e($customerProfileHref); ?>">
                                <div>
                                    <strong><?= e((string) ($row['subscriber_code'] ?? '-')); ?> - <?= e((string) ($row['full_name'] ?? '-')); ?></strong>
                                    <span><?= e((string) ($row['area'] ?? '-')); ?> / <?= e((string) ($row['plan'] ?? '-')); ?></span>
                                    <div class="spog-meta"><span class="portal-pill"><?= e((string) ($row['status'] ?? '-')); ?></span><span class="portal-pill"><?= ((int) ($row['is_active'] ?? 0)) === 1 ? 'aktiv' : 'deaktiv'; ?></span></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="portal-card">
                    <div class="portal-card-head"><h2>Ticket / CRM</h2><small>Musteri problemi, status ve erazi konteksti.</small></div>
                    <div class="portal-action-list">
                        <?php if ($ticketResults === []): ?><?php billing_render_empty_state('Ticket tapilmadi', 'Sorguya uygun aciq ve ya kecmis bilet yoxdur.', 'fa-ticket'); ?><?php endif; ?>
                        <?php foreach ($ticketResults as $row): ?>
                            <a class="portal-link-card" href="crm.php?q=<?= e(rawurlencode((string) ($row['ticket_no'] ?? ''))); ?>">
                                <div>
                                    <strong><?= e((string) ($row['ticket_no'] ?? '-')); ?> - <?= e((string) ($row['subject'] ?? '-')); ?></strong>
                                    <span><?= e((string) ($row['customer_name'] ?? '-')); ?> / <?= e((string) ($row['area'] ?? '-')); ?></span>
                                    <div class="spog-meta"><span class="portal-pill"><?= e((string) ($row['status'] ?? '-')); ?></span><span class="portal-pill"><?= e(billing_datetime($row['opened_at'] ?? null)); ?></span></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="portal-card">
                    <div class="portal-card-head"><h2>RADIUS / sessiya</h2><small>Username, IP, MAC ve NAS uzerinden tapilan izler.</small></div>
                    <div class="portal-action-list">
                        <?php if ($radiusResults === []): ?><?php billing_render_empty_state('Radius izi tapilmadi', 'Accounting tarixcesinde uygun setr yoxdur.', 'fa-satellite-dish'); ?><?php endif; ?>
                        <?php foreach ($radiusResults as $row): ?>
                            <a class="portal-link-card" href="radius-active.php?q=<?= e(rawurlencode((string) ($row['username'] ?? ''))); ?>">
                                <div>
                                    <strong><?= e((string) ($row['username'] ?? '-')); ?> / <?= e((string) ($row['framedipaddress'] ?? '-')); ?></strong>
                                    <span>MAC: <?= e((string) ($row['callingstationid'] ?? '-')); ?> / NAS: <?= e((string) ($row['nasipaddress'] ?? '-')); ?></span>
                                    <div class="spog-meta"><span class="portal-pill"><?= e(billing_bytes($row['acctoutputoctets'] ?? 0)); ?> down</span><span class="portal-pill"><?= e(billing_bytes($row['acctinputoctets'] ?? 0)); ?> up</span></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="portal-card">
                    <div class="portal-card-head"><h2>Network / VLAN</h2><small>MPLS/VLAN planlama ve musteri port konteksti.</small></div>
                    <div class="portal-action-list">
                        <?php if ($vlanResults === []): ?><?php billing_render_empty_state('VLAN neticesi yoxdur', 'Network definitions daxilinde uygun qeyd tapilmadi.', 'fa-diagram-project'); ?><?php endif; ?>
                        <?php foreach ($vlanResults as $row): ?>
                            <a class="portal-link-card" href="network-definitions.php?q=<?= e(rawurlencode((string) ($row['vlan_id'] ?? ''))); ?>">
                                <div>
                                    <strong>VLAN <?= e((string) ($row['vlan_id'] ?? '-')); ?> - <?= e((string) ($row['name'] ?? '-')); ?></strong>
                                    <span><?= e((string) ($row['primary_device_name'] ?? '-')); ?> / <?= e((string) ($row['access_port'] ?? '-')); ?></span>
                                    <div class="spog-meta"><span class="portal-pill"><?= e((string) ($row['branch'] ?? '-')); ?></span><span class="portal-pill"><?= e((string) ($row['status'] ?? '-')); ?></span></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>
        <?php endif; ?>

        <section class="spog-quick-actions portal-reveal">
            <a class="spog-action" href="billing-cash-payment.php"><i class="fa-solid fa-cash-register"></i> Odenis al</a>
            <a class="spog-action" href="crm.php"><i class="fa-solid fa-ticket"></i> Ticket ac / izle</a>
            <a class="spog-action" href="message-center.php"><i class="fa-solid fa-message"></i> Mesaj gonder</a>
            <a class="spog-action" href="network-definitions.php"><i class="fa-solid fa-diagram-project"></i> VLAN planla</a>
            <a class="spog-action" href="radius-natlog.php"><i class="fa-solid fa-magnifying-glass-chart"></i> NAT log axtar</a>
            <a class="spog-action" href="radius-active.php"><i class="fa-solid fa-satellite-dish"></i> Sessiyalara bax</a>
            <a class="spog-action" href="campaigns.php"><i class="fa-solid fa-bullhorn"></i> Kampaniya</a>
            <a class="spog-action" href="billing-statistics.php"><i class="fa-solid fa-chart-line"></i> Hesabat</a>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Aciq emeliyyat axini</h2><small>Ticket, mesaj, odenis ve Radius hadiseleri bir yerde.</small></div>
                <div class="portal-action-list">
                    <?php foreach ($recentTickets as $ticket): ?>
                        <a class="portal-link-card" href="crm.php?q=<?= e(rawurlencode((string) ($ticket['ticket_no'] ?? ''))); ?>">
                            <div><strong><?= e((string) ($ticket['ticket_no'] ?? '-')); ?> / <?= e((string) ($ticket['subject'] ?? '-')); ?></strong><span><?= e((string) ($ticket['customer_name'] ?? '-')); ?> - <?= e((string) ($ticket['status'] ?? '-')); ?></span></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($recentTickets === []): ?><?php billing_render_empty_state('Aciq ticket yoxdur', 'CRM axini temiz gorunur.', 'fa-ticket'); ?><?php endif; ?>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Son biznes hadiseleri</h2><small>Odenis ve bildiris queue tarixcesi.</small></div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Tip</th><th>Kod</th><th>Status/mebleg</th><th>Vaxt</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentPayments as $payment): ?>
                                <tr><td>Odenis</td><td><?= e((string) ($payment['subscriber_code'] ?? '-')); ?></td><td><?= e(billing_money($payment['amount'] ?? 0)); ?></td><td><?= e(billing_datetime($payment['created_at'] ?? null)); ?></td></tr>
                            <?php endforeach; ?>
                            <?php foreach ($recentMessages as $message): ?>
                                <tr><td>Mesaj</td><td><?= e((string) ($message['subscriber_code'] ?? '-')); ?></td><td><?= e((string) ($message['channel'] ?? '-')); ?> / <?= e((string) ($message['status'] ?? '-')); ?></td><td><?= e(billing_datetime($message['created_at'] ?? null)); ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($recentPayments === [] && $recentMessages === []): ?><tr><td colspan="4">Hadise yoxdur.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head"><h2>RADIUS live kontekst</h2><small>Operator bura baxib username/IP/MAC-den musteri profiline kecmelidir.</small></div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Username</th><th>IP</th><th>MAC</th><th>NAS</th><th>Trafik</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentRadius as $row): ?>
                            <tr>
                                <td><a href="<?= e(spog_operations_url('search', $lang, ['q' => (string) ($row['username'] ?? '')])); ?>"><?= e((string) ($row['username'] ?? '-')); ?></a></td>
                                <td><?= e((string) ($row['framedipaddress'] ?? '-')); ?></td>
                                <td><?= e((string) ($row['callingstationid'] ?? '-')); ?></td>
                                <td><?= e((string) ($row['nasipaddress'] ?? '-')); ?></td>
                                <td><?= e(billing_bytes($row['acctoutputoctets'] ?? 0)); ?> / <?= e(billing_bytes($row['acctinputoctets'] ?? 0)); ?></td>
                                <td><span class="portal-pill"><?= empty($row['acctstoptime']) ? 'online/active' : 'closed'; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($recentRadius === []): ?><tr><td colspan="6">Radius accounting yoxdur.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <script>
    (() => {
        let financeRequest = null;

        const openFinanceDialog = (name) => {
            if (!name) return;
            const dialog = document.querySelector(`[data-finance-dialog="${CSS.escape(name)}"]`);
            if (!dialog) return;
            document.body.appendChild(dialog);
            dialog.hidden = false;
            document.body.classList.add('finance-dialog-open');
            const firstControl = dialog.querySelector('input:not([type="hidden"]),select,textarea,button');
            if (firstControl) firstControl.focus();
        };

        const closeFinanceDialog = (dialog) => {
            if (!dialog) {
                return;
            }
            dialog.hidden = true;
            const module = document.querySelector('[data-finance-module]');
            if (module && dialog.parentElement === document.body) module.appendChild(dialog);
            if (!document.querySelector('.finance-dialog:not([hidden])')) {
                document.body.classList.remove('finance-dialog-open');
            }
        };

        const financeEndpoint = (canonicalUrl, fragment = '') => {
            const endpoint = new URL('finance-module-partial.php', window.location.href);
            canonicalUrl.searchParams.forEach((value, key) => endpoint.searchParams.set(key, value));
            endpoint.searchParams.delete('section');
            if (fragment !== '') {
                endpoint.searchParams.set('finance_fragment', fragment);
            }
            return endpoint;
        };

        const loadFinance = async (targetUrl, options = {}) => {
            const canonicalUrl = new URL(targetUrl, window.location.href);
            const fragment = options.fragment || '';
            const module = document.querySelector('[data-finance-module]');
            const target = fragment === 'statistics-results'
                ? document.querySelector('[data-finance-statistics-panel]')
                : module;
            if (!target) {
                window.location.assign(canonicalUrl.href);
                return;
            }

            if (financeRequest) {
                financeRequest.abort();
            }
            financeRequest = new AbortController();
            const scrollX = window.scrollX;
            const scrollY = window.scrollY;
            target.setAttribute('aria-busy', 'true');
            const status = document.querySelector('[data-finance-statistics-status]');
            if (status) {
                status.textContent = 'Yenilenir...';
            }

            try {
                const response = await fetch(financeEndpoint(canonicalUrl, fragment), {
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    signal: financeRequest.signal,
                });
                if (!response.ok) {
                    throw new Error(`finance_partial_${response.status}`);
                }
                const template = document.createElement('template');
                template.innerHTML = await response.text();
                const replacement = fragment === 'statistics-results'
                    ? template.content.querySelector('[data-finance-statistics-panel]')
                    : template.content.querySelector('[data-finance-module]');
                if (!replacement) {
                    throw new Error('finance_partial_missing');
                }

                target.replaceWith(replacement);
                if (options.history === 'replace') {
                    history.replaceState({finance: true}, '', canonicalUrl.pathname + canonicalUrl.search + canonicalUrl.hash);
                } else if (options.history !== 'none') {
                    history.pushState({finance: true}, '', canonicalUrl.pathname + canonicalUrl.search + canonicalUrl.hash);
                }
                requestAnimationFrame(() => window.scrollTo(scrollX, scrollY));
                const nextStatus = document.querySelector('[data-finance-statistics-status]');
                if (nextStatus) {
                    nextStatus.textContent = 'Melumat yenilendi.';
                }
                const requestedDialog = canonicalUrl.searchParams.get('open');
                if (requestedDialog) requestAnimationFrame(() => openFinanceDialog(requestedDialog));
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                target.removeAttribute('aria-busy');
                if (status) {
                    status.textContent = 'Yenileme alinmadi. Bir daha cehd edin.';
                }
            }
        };

        document.addEventListener('click', (event) => {
            const nav = event.target.closest('[data-finance-nav]');
            if (nav && document.querySelector('[data-finance-module]')) {
                event.preventDefault();
                loadFinance(nav.href);
                return;
            }

            const counterpartyEditor = event.target.closest('[data-finance-counterparty-edit]');
            if (counterpartyEditor) {
                const dialog = document.querySelector('[data-finance-dialog="counterparty-create"]');
                const form = dialog ? dialog.querySelector('form') : null;
                let data = {};
                try {
                    data = JSON.parse(counterpartyEditor.dataset.counterparty || '{}');
                } catch (error) {
                    data = {};
                }
                if (form) {
                    const values = {...data, counterparty_id: data.id || data.counterparty_id || ''};
                    ['counterparty_id', 'display_name', 'counterparty_type', 'tax_id', 'phone', 'email', 'address', 'status', 'note'].forEach((name) => {
                        const field = form.elements.namedItem(name);
                        if (field) field.value = values[name] ?? (name === 'status' ? 'active' : '');
                    });
                    const title = dialog.querySelector('[data-counterparty-dialog-title]');
                    if (title) title.textContent = 'Qarsi terefi duzelt';
                }
            }

            const legacyLinker = event.target.closest('[data-finance-legacy-link]');
            if (legacyLinker) {
                const dialog = document.querySelector('[data-finance-dialog="counterparty-link"]');
                const form = dialog ? dialog.querySelector('form') : null;
                let data = {};
                try { data = JSON.parse(legacyLinker.dataset.legacy || '{}'); } catch (error) { data = {}; }
                if (form) {
                    const nameField=form.elements.namedItem('legacy_name');const typeField=form.elements.namedItem('legacy_type');
                    if(nameField)nameField.value=data.name||'';if(typeField)typeField.value=data.type||'other';
                    const select=form.elements.namedItem('counterparty_id');if(select)select.value='';
                }
                const label=dialog?dialog.querySelector('[data-finance-legacy-label]'):null;if(label)label.textContent=(data.name||'')+' / '+(data.type||'other');
            }

            const incomeEditor = event.target.closest('[data-finance-income-edit]');
            if (incomeEditor) {
                const dialog = document.querySelector('[data-finance-dialog="income-create"]');
                const form = dialog ? dialog.querySelector('form') : null;
                let data = {};
                try {
                    data = JSON.parse(incomeEditor.dataset.income || '{}');
                } catch (error) {
                    data = {};
                }
                if (form) {
                    const values = {...data, entry_id: data.id || data.entry_id || ''};
                    ['entry_id', 'title', 'category', 'amount', 'currency', 'transaction_date', 'branch_id', 'counterparty_id', 'payment_source', 'reference_no', 'note'].forEach((name) => {
                        const field = form.elements.namedItem(name);
                        if (field) field.value = values[name] ?? '';
                    });
                    const reasonField = form.elements.namedItem('reason');
                    if (reasonField) reasonField.value = '';
                    const title = dialog.querySelector('[data-income-dialog-title]');
                    if (title) title.textContent = 'Geliri duzelt';
                }
            }

            const opener = event.target.closest('[data-finance-dialog-open]');
            if (opener) {
                if (!counterpartyEditor && opener.dataset.financeDialogOpen === 'counterparty-create') {
                    const dialog = document.querySelector('[data-finance-dialog="counterparty-create"]');
                    const form = dialog ? dialog.querySelector('form') : null;
                    if (form) {
                        form.reset();
                        const idField = form.elements.namedItem('counterparty_id');
                        if (idField) idField.value = '';
                    }
                    const title = dialog ? dialog.querySelector('[data-counterparty-dialog-title]') : null;
                    if (title) title.textContent = 'Yeni qarsi teref';
                }
                if (!incomeEditor && opener.dataset.financeDialogOpen === 'income-create') {
                    const dialog = document.querySelector('[data-finance-dialog="income-create"]');
                    const form = dialog ? dialog.querySelector('form') : null;
                    if (form) {
                        form.reset();
                        const idField = form.elements.namedItem('entry_id');
                        if (idField) idField.value = '';
                    }
                    const title = dialog ? dialog.querySelector('[data-income-dialog-title]') : null;
                    if (title) title.textContent = 'Yeni gelir';
                }
                openFinanceDialog(opener.dataset.financeDialogOpen);
                return;
            }

            const closer = event.target.closest('[data-finance-dialog-close]');
            if (closer) {
                closeFinanceDialog(closer.closest('[data-finance-dialog]'));
            }
        });

        document.addEventListener('submit', (event) => {
            const form = event.target.closest('[data-finance-filter],[data-finance-statistics-form]');
            if (!form) {
                return;
            }
            event.preventDefault();
            const canonicalUrl = new URL(form.action, window.location.href);
            new FormData(form).forEach((value, key) => canonicalUrl.searchParams.set(key, String(value)));
            canonicalUrl.searchParams.delete('page');
            const historyMode = form.dataset.filterHistory === 'replace' ? 'replace' : undefined;
            delete form.dataset.filterHistory;
            loadFinance(canonicalUrl, {
                fragment: form.matches('[data-finance-statistics-form]') ? 'statistics-results' : '',
                history: historyMode,
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeFinanceDialog(document.querySelector('.finance-dialog:not([hidden])'));
            }
        });

        window.addEventListener('popstate', () => {
            const current = new URL(window.location.href);
            if (current.searchParams.get('section') === 'finance' && document.querySelector('[data-finance-module]')) {
                loadFinance(current, {history: 'none'});
            }
        });
        const initialDialog = new URL(window.location.href).searchParams.get('open');
        if (initialDialog) requestAnimationFrame(() => openFinanceDialog(initialDialog));
    })();

    (() => {
        let hrRequest = null;

        const openHrDialog = (name) => {
            if (!name) return;
            const dialog = document.querySelector(`[data-hr-dialog="${CSS.escape(name)}"]`);
            if (!dialog) return;
            document.body.appendChild(dialog);
            dialog.hidden = false;
            document.body.classList.add('hr-dialog-open');
            const firstControl = dialog.querySelector('input:not([type="hidden"]),select,textarea,button');
            if (firstControl) firstControl.focus();
        };

        const closeHrDialog = (dialog) => {
            if (!dialog) {
                return;
            }
            dialog.hidden = true;
            const hrModule = document.querySelector('[data-hr-module]');
            if (hrModule && dialog.parentElement === document.body) {
                hrModule.appendChild(dialog);
            }
            if (!document.querySelector('.hr-dialog:not([hidden])')) {
                document.body.classList.remove('hr-dialog-open');
            }
        };

        const hrEndpoint = (canonicalUrl, fragment = '') => {
            const endpoint = new URL('hr-module-partial.php', window.location.href);
            canonicalUrl.searchParams.forEach((value, key) => endpoint.searchParams.set(key, value));
            endpoint.searchParams.delete('section');
            if (fragment !== '') {
                endpoint.searchParams.set('hr_fragment', fragment);
            }
            return endpoint;
        };

        const loadHr = async (targetUrl, options = {}) => {
            const canonicalUrl = new URL(targetUrl, window.location.href);
            const fragment = options.fragment || '';
            const module = document.querySelector('[data-hr-module]');
            const target = fragment === 'statistics-results'
                ? document.querySelector('[data-hr-statistics-panel]')
                : module;
            if (!target) {
                window.location.assign(canonicalUrl.href);
                return;
            }

            if (hrRequest) {
                hrRequest.abort();
            }
            hrRequest = new AbortController();
            const scrollX = window.scrollX;
            const scrollY = window.scrollY;
            target.setAttribute('aria-busy', 'true');
            const status = document.querySelector('[data-hr-statistics-status]');
            if (status) {
                status.textContent = 'Yenilenir...';
            }

            try {
                const response = await fetch(hrEndpoint(canonicalUrl, fragment), {
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    signal: hrRequest.signal,
                });
                if (!response.ok) {
                    throw new Error(`hr_partial_${response.status}`);
                }
                const template = document.createElement('template');
                template.innerHTML = await response.text();
                const replacement = fragment === 'statistics-results'
                    ? template.content.querySelector('[data-hr-statistics-panel]')
                    : template.content.querySelector('[data-hr-module]');
                if (!replacement) {
                    throw new Error('hr_partial_missing');
                }

                target.replaceWith(replacement);
                if (options.history === 'replace') {
                    history.replaceState({hr: true}, '', canonicalUrl.pathname + canonicalUrl.search + canonicalUrl.hash);
                } else if (options.history !== 'none') {
                    history.pushState({hr: true}, '', canonicalUrl.pathname + canonicalUrl.search + canonicalUrl.hash);
                }
                requestAnimationFrame(() => window.scrollTo(scrollX, scrollY));
                const nextStatus = document.querySelector('[data-hr-statistics-status]');
                if (nextStatus) {
                    nextStatus.textContent = 'Melumat yenilendi.';
                }
                const requestedDialog = canonicalUrl.searchParams.get('open');
                if (requestedDialog) requestAnimationFrame(() => openHrDialog(requestedDialog));
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                target.removeAttribute('aria-busy');
                if (status) {
                    status.textContent = 'Yenileme alinmadi. Bir daha cehd edin.';
                }
            }
        };

        document.addEventListener('click', (event) => {
            const nav = event.target.closest('[data-hr-nav]');
            if (nav && document.querySelector('[data-hr-module]')) {
                event.preventDefault();
                loadHr(nav.href);
                return;
            }

            const employeeEditor = event.target.closest('[data-hr-employee-edit]');
            if (employeeEditor) {
                const dialog = document.querySelector('[data-hr-dialog="employee-edit"]');
                const form = dialog ? dialog.querySelector('form') : null;
                let data = {};
                try {
                    data = JSON.parse(employeeEditor.dataset.employee || '{}');
                } catch (error) {
                    data = {};
                }
                if (form) {
                    const values = {...data, employee_account_id: data.id || ''};
                    ['employee_account_id', 'full_name', 'login', 'job_title', 'hire_date', 'phone', 'email', 'area', 'branch_id', 'address'].forEach((name) => {
                        const field = form.elements.namedItem(name);
                        if (field) field.value = values[name] ?? '';
                    });
                }
            }

            const opener = event.target.closest('[data-hr-dialog-open]');
            if (opener) {
                openHrDialog(opener.dataset.hrDialogOpen);
                return;
            }

            const closer = event.target.closest('[data-hr-dialog-close]');
            if (closer) {
                closeHrDialog(closer.closest('[data-hr-dialog]'));
            }
        });

        document.addEventListener('submit', (event) => {
            const form = event.target.closest('[data-hr-filter],[data-hr-statistics-form]');
            if (!form) {
                return;
            }
            event.preventDefault();
            const canonicalUrl = new URL(form.action, window.location.href);
            new FormData(form).forEach((value, key) => canonicalUrl.searchParams.set(key, String(value)));
            canonicalUrl.searchParams.delete('page');
            const historyMode = form.dataset.filterHistory === 'replace' ? 'replace' : undefined;
            delete form.dataset.filterHistory;
            loadHr(canonicalUrl, {
                fragment: form.matches('[data-hr-statistics-form]') ? 'statistics-results' : '',
                history: historyMode,
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeHrDialog(document.querySelector('.hr-dialog:not([hidden])'));
            }
        });

        window.addEventListener('popstate', () => {
            const current = new URL(window.location.href);
            if (current.searchParams.get('section') === 'hr' && document.querySelector('[data-hr-module]')) {
                loadHr(current, {history: 'none'});
            }
        });
        const initialDialog = new URL(window.location.href).searchParams.get('open');
        if (initialDialog) requestAnimationFrame(() => openHrDialog(initialDialog));
    })();

    (() => {
        let inventoryRequest = null;

        const openInventoryDialog = (name) => {
            if (!name) return;
            const dialog = document.querySelector(`[data-inventory-dialog="${CSS.escape(name)}"]`);
            if (!dialog) return;
            document.body.appendChild(dialog);
            dialog.hidden = false;
            document.body.classList.add('inventory-dialog-open');
            const firstControl = dialog.querySelector('input:not([type="hidden"]),select,textarea,button');
            if (firstControl) firstControl.focus();
        };

        const closeInventoryDialog = (dialog) => {
            if (!dialog) {
                return;
            }
            dialog.hidden = true;
            const module = document.querySelector('[data-inventory-module]');
            if (module && dialog.parentElement === document.body) {
                module.appendChild(dialog);
            }
            if (!document.querySelector('.inventory-dialog:not([hidden])')) {
                document.body.classList.remove('inventory-dialog-open');
            }
        };

        const inventoryEndpoint = (canonicalUrl, fragment = '') => {
            const endpoint = new URL('inventory-module-partial.php', window.location.href);
            canonicalUrl.searchParams.forEach((value, key) => endpoint.searchParams.set(key, value));
            endpoint.searchParams.delete('section');
            if (fragment !== '') {
                endpoint.searchParams.set('inventory_fragment', fragment);
            }
            return endpoint;
        };

        const loadInventory = async (targetUrl, options = {}) => {
            const canonicalUrl = new URL(targetUrl, window.location.href);
            const fragment = options.fragment || '';
            const module = document.querySelector('[data-inventory-module]');
            const target = fragment === 'statistics-results'
                ? document.querySelector('[data-inventory-statistics-panel]')
                : module;
            if (!target) {
                window.location.assign(canonicalUrl.href);
                return;
            }

            if (inventoryRequest) {
                inventoryRequest.abort();
            }
            inventoryRequest = new AbortController();
            const scrollX = window.scrollX;
            const scrollY = window.scrollY;
            target.setAttribute('aria-busy', 'true');
            const status = document.querySelector('[data-inventory-statistics-status]');
            if (status) {
                status.textContent = 'Yenilenir...';
            }

            try {
                const response = await fetch(inventoryEndpoint(canonicalUrl, fragment), {
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    signal: inventoryRequest.signal,
                });
                if (!response.ok) {
                    throw new Error(`inventory_partial_${response.status}`);
                }
                const template = document.createElement('template');
                template.innerHTML = await response.text();
                const replacement = fragment === 'statistics-results'
                    ? template.content.querySelector('[data-inventory-statistics-panel]')
                    : template.content.querySelector('[data-inventory-module]');
                if (!replacement) {
                    throw new Error('inventory_partial_missing');
                }

                target.replaceWith(replacement);
                if (options.history === 'replace') {
                    history.replaceState({inventory: true}, '', canonicalUrl.pathname + canonicalUrl.search + canonicalUrl.hash);
                } else if (options.history !== 'none') {
                    history.pushState({inventory: true}, '', canonicalUrl.pathname + canonicalUrl.search + canonicalUrl.hash);
                }
                requestAnimationFrame(() => window.scrollTo(scrollX, scrollY));
                const nextStatus = document.querySelector('[data-inventory-statistics-status]');
                if (nextStatus) {
                    nextStatus.textContent = 'Melumat yenilendi.';
                }
                const requestedDialog = canonicalUrl.searchParams.get('open');
                if (requestedDialog) requestAnimationFrame(() => openInventoryDialog(requestedDialog));
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                target.removeAttribute('aria-busy');
                if (status) {
                    status.textContent = 'Yenileme alinmadi. Bir daha cehd edin.';
                }
            }
        };

        document.addEventListener('click', (event) => {
            const nav = event.target.closest('[data-inventory-nav]');
            if (nav && document.querySelector('[data-inventory-module]')) {
                event.preventDefault();
                loadInventory(nav.href);
                return;
            }

            const opener = event.target.closest('[data-inventory-dialog-open]');
            if (opener) {
                openInventoryDialog(opener.dataset.inventoryDialogOpen);
                return;
            }

            const closer = event.target.closest('[data-inventory-dialog-close]');
            if (closer) {
                closeInventoryDialog(closer.closest('[data-inventory-dialog]'));
            }
        });

        document.addEventListener('submit', (event) => {
            const form = event.target.closest('[data-inventory-filter],[data-inventory-statistics-form]');
            if (!form) {
                return;
            }
            event.preventDefault();
            const canonicalUrl = new URL(form.action, window.location.href);
            new FormData(form).forEach((value, key) => canonicalUrl.searchParams.set(key, String(value)));
            canonicalUrl.searchParams.delete('page');
            const historyMode = form.dataset.filterHistory === 'replace' ? 'replace' : undefined;
            delete form.dataset.filterHistory;
            loadInventory(canonicalUrl, {
                fragment: form.matches('[data-inventory-statistics-form]') ? 'statistics-results' : '',
                history: historyMode,
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeInventoryDialog(document.querySelector('.inventory-dialog:not([hidden])'));
            }
        });

        window.addEventListener('popstate', () => {
            const current = new URL(window.location.href);
            if (current.searchParams.get('section') === 'inventory' && document.querySelector('[data-inventory-module]')) {
                loadInventory(current, {history: 'none'});
            }
        });
        const initialDialog = new URL(window.location.href).searchParams.get('open');
        if (initialDialog) requestAnimationFrame(() => openInventoryDialog(initialDialog));
    })();

    (() => {
        const filterSelectors = ['[data-finance-filter]', '[data-finance-statistics-form]', '[data-hr-filter]', '[data-hr-statistics-form]', '[data-inventory-filter]', '[data-inventory-statistics-form]'];
        const selector = filterSelectors.join(',');
        const inputSelector = filterSelectors.map((item) => `${item} input,${item} textarea`).join(',');
        const changeSelector = filterSelectors.map((item) => `${item} select,${item} input,${item} textarea`).join(',');
        const timers = new WeakMap();

        const schedule = (control, delay) => {
            const form = control.closest(selector);
            if (!form || String(form.method || 'get').toLowerCase() !== 'get' || control.disabled) {
                return;
            }
            const previous = timers.get(form);
            if (previous) {
                window.clearTimeout(previous);
            }
            const timer = window.setTimeout(() => {
                if (!form.isConnected) {
                    return;
                }
                form.dataset.filterHistory = 'replace';
                form.requestSubmit();
            }, delay);
            timers.set(form, timer);
        };

        document.addEventListener('input', (event) => {
            const control = event.target.closest(inputSelector);
            if (!control || ['hidden', 'submit', 'button', 'reset'].includes(control.type)) {
                return;
            }
            schedule(control, control.type === 'search' || control.type === 'text' ? 320 : 180);
        });

        document.addEventListener('change', (event) => {
            const rulePicker = event.target.closest('[data-bonus-rule-picker]');
            if (rulePicker) {
                const form = rulePicker.closest('form');
                const option = rulePicker.selectedOptions[0];
                if (form && option && option.value !== '0') {
                    const setValue = (name, value) => {
                        const field = form.elements.namedItem(name);
                        if (field && typeof value === 'string') field.value = value;
                    };
                    const mode = rulePicker.dataset.bonusRulePicker;
                    if (mode === 'salary') {
                        setValue('min_customers', option.dataset.minCustomers || '0');
                        setValue('max_customers', option.dataset.maxCustomers || '');
                        setValue('salary_amount', option.dataset.salaryAmount || '0');
                        setValue('area_quality_percent', option.dataset.areaQualityPercent || '100');
                    } else if (mode === 'active-days') {
                        setValue('min_days', option.dataset.minDays || '0');
                        setValue('max_days', option.dataset.maxDays || '0');
                        setValue('active_percent', option.dataset.activePercent || '0');
                    } else if (mode === 'quality') {
                        setValue('problem_key', option.dataset.problemKey || '');
                        setValue('problem_label', option.dataset.problemLabel || '');
                        setValue('problem_source', option.dataset.problemSource || 'unknown');
                        setValue('severity', option.dataset.severity || 'normal');
                        setValue('quality_percent', option.dataset.qualityPercent || '100');
                    }
                    const active = form.elements.namedItem('is_active');
                    if (active) active.checked = option.dataset.active === '1';
                }
            }
            const control = event.target.closest(changeSelector);
            if (!control || ['hidden', 'submit', 'button', 'reset'].includes(control.type)) {
                return;
            }
            schedule(control, 0);
        });
    })();
    </script>
    <script>
    (() => {
        const board = document.querySelector('[data-native-monitor-board]');
        if (!board) {
            return;
        }
        const stage = board.querySelector('[data-monitor-stage]');
        const nodes = Array.from(board.querySelectorAll('[data-monitor-node]'));
        const lines = Array.from(board.querySelectorAll('.monitor-link'));
        const zoomValue = board.querySelector('[data-monitor-zoom-value]');
        const storageKey = 'superoptic.monitor.map.v1';
        let state = {};
        let zoom = 1;

        try {
            state = JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
        } catch {
            state = {};
        }

        const save = () => localStorage.setItem(storageKey, JSON.stringify(state));
        const updateLine = (index, x, y) => {
            const line = lines[index];
            if (!line) {
                return;
            }
            line.setAttribute('x2', String(x));
            line.setAttribute('y2', String(y));
        };
        const applyZoom = () => {
            if (stage) {
                stage.style.transform = `scale(${zoom})`;
            }
            if (zoomValue) {
                zoomValue.textContent = `${Math.round(zoom * 100)}%`;
            }
        };
        const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

        nodes.forEach((node, index) => {
            const id = node.dataset.monitorId || `node-${index}`;
            const saved = state[id] || {};
            const defaultX = Number(node.dataset.defaultX || 50);
            const defaultY = Number(node.dataset.defaultY || 50);
            const x = Number.isFinite(Number(saved.x)) ? Number(saved.x) : defaultX;
            const y = Number.isFinite(Number(saved.y)) ? Number(saved.y) : defaultY;
            node.style.left = `${x}%`;
            node.style.top = `${y}%`;
            updateLine(index, x, y);

            const note = node.querySelector('[data-monitor-note]');
            if (note && typeof saved.note === 'string') {
                note.value = saved.note;
            }
            if (note) {
                note.addEventListener('input', () => {
                    state[id] = { ...(state[id] || {}), note: note.value };
                    save();
                });
            }

            let drag = null;
            node.addEventListener('pointerdown', (event) => {
                if (event.target && event.target.closest('textarea,input,select,button,a')) {
                    return;
                }
                event.preventDefault();
                node.setPointerCapture(event.pointerId);
                const rect = board.getBoundingClientRect();
                drag = { rect };
            });
            node.addEventListener('pointermove', (event) => {
                if (!drag) {
                    return;
                }
                const rect = drag.rect;
                const xPct = clamp(((event.clientX - rect.left) / zoom / rect.width) * 100, 5, 95);
                const yPct = clamp(((event.clientY - rect.top) / zoom / rect.height) * 100, 8, 92);
                node.style.left = `${xPct}%`;
                node.style.top = `${yPct}%`;
                updateLine(index, Number(xPct.toFixed(2)), Number(yPct.toFixed(2)));
                state[id] = { ...(state[id] || {}), x: Number(xPct.toFixed(2)), y: Number(yPct.toFixed(2)) };
                save();
            });
            node.addEventListener('pointerup', () => {
                drag = null;
            });
            node.addEventListener('pointercancel', () => {
                drag = null;
            });
        });

        board.querySelectorAll('[data-monitor-zoom]').forEach((button) => {
            button.addEventListener('click', () => {
                zoom = clamp(zoom + (button.dataset.monitorZoom === 'in' ? 0.1 : -0.1), 0.65, 1.6);
                applyZoom();
            });
        });
        const reset = board.querySelector('[data-monitor-reset]');
        if (reset) {
            reset.addEventListener('click', () => {
                nodes.forEach((node, index) => {
                    const id = node.dataset.monitorId || `node-${index}`;
                    const x = Number(node.dataset.defaultX || 50);
                    const y = Number(node.dataset.defaultY || 50);
                    node.style.left = `${x}%`;
                    node.style.top = `${y}%`;
                    updateLine(index, x, y);
                    const note = node.querySelector('[data-monitor-note]');
                    state[id] = { ...(state[id] || {}), x, y, note: note ? note.value : '' };
                });
                zoom = 1;
                applyZoom();
                save();
            });
        }
        applyZoom();
    })();
    </script>
    <?php app_noc_render_scan_bulk_script(); ?>
    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
