<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/mgs-integration-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, [
    'admin', 'manager', 'super_admin', 'director', 'team_lead',
    'operator', 'night_operator', 'engineer', 'noc', 'network_engineer',
]);

$body = app_mobile_request_body();
$customerCode = clean_text((string) ($body['customer_code'] ?? ''));
$deviceKey = clean_text((string) ($body['device_key'] ?? ''));
$pon = clean_text((string) ($body['pon'] ?? ''));
$ontId = clean_text((string) ($body['ont_id'] ?? ''));
$expectedSerial = app_mgs_inventory_normalize_serial((string) ($body['serial'] ?? ''));
if ($customerCode === '' || $deviceKey === '' || $pon === '' || $ontId === '' || $expectedSerial === '') {
    app_mobile_json_response(null, 422, 'Müştəri kodu və canlı ONT məlumatları tam deyil.');
}

$db = app_db();
if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
    app_mobile_json_response(null, 503, 'Verilənlər bazası hazır deyil.');
}
if (!app_mgs_management_path_ready()) {
    app_mobile_json_response(['reason' => 'management_path_down'], 409, 'OLT idarəetmə xətti hazır deyil.');
}

$device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $user);
if (!is_array($device) || strtolower((string) ($device['device_type'] ?? '')) !== 'olt') {
    app_mobile_json_response(null, 404, 'OLT profili tapılmadı.');
}
$snapshot = app_mgs_hsgq_live_ont_snapshot($device, $pon, $ontId, $user, $db);
if (empty($snapshot['ok']) || !is_array($snapshot['ont'] ?? null)) {
    app_mobile_json_response(['reason' => $snapshot['reason'] ?? 'live_probe_failed'], 409, 'ONT canlı olaraq təsdiqlənmədi.');
}
$ont = $snapshot['ont'];
$liveSerial = app_mgs_inventory_normalize_serial((string) ($ont['serial'] ?? ''));
if ($liveSerial === '' || !hash_equals($expectedSerial, $liveSerial)) {
    app_mobile_json_response(null, 409, 'OLT-dəki aktual serial seçilmiş cihazla uyğun gəlmir.');
}

$db->beginTransaction();
try {
    $customerStatement = $db->prepare(
        'SELECT * FROM portal_accounts WHERE role = "customer" AND subscriber_code = :code AND is_active = 1 LIMIT 1 FOR UPDATE'
    );
    $customerStatement->execute(['code' => $customerCode]);
    $customer = $customerStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($customer)) {
        throw new DomainException('customer_not_found');
    }

    $assetStatement = $db->prepare(
        'SELECT * FROM inventory_assets WHERE serial_number_normalized = :serial LIMIT 1 FOR UPDATE'
    );
    $assetStatement->execute(['serial' => $liveSerial]);
    $asset = $assetStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($asset)) {
        $modelId = app_mgs_inventory_upsert_asset_model($db, 'HSGQ', 'OLT-dən aşkarlanan GPON ONT', 'ont');
        $assetId = app_mgs_inventory_create_asset($db, [
            'model_id' => $modelId,
            'asset_code' => 'ONT-DISC-' . strtoupper(substr(hash('sha256', $liveSerial), 0, 16)),
            'serial_number' => (string) ($ont['serial'] ?? $liveSerial),
            'ownership_type' => 'unknown',
            'lifecycle_status' => 'discovered',
            'condition_status' => 'working',
            'current_location_type' => 'network_discovery',
            'current_location_id' => (string) ($device['device_key'] ?? ''),
            'source' => 'live_olt_discovery',
            'olt' => (string) ($device['device_name'] ?? ''),
            'pon' => $pon,
            'ont_id' => $ontId,
        ]);
    } else {
        $assetId = (int) $asset['id'];
    }

    $activeStatement = $db->prepare(
        'SELECT customer_code FROM customer_equipment_assignments WHERE asset_id = :asset_id AND assignment_status = "active" LIMIT 1 FOR UPDATE'
    );
    $activeStatement->execute(['asset_id' => $assetId]);
    $activeCustomer = (string) ($activeStatement->fetchColumn() ?: '');
    if ($activeCustomer !== '' && !hash_equals($activeCustomer, $customerCode)) {
        throw new DomainException('ont_already_assigned');
    }
    if ($activeCustomer === '') {
        app_mgs_inventory_install_asset(
            $db,
            $assetId,
            $customerCode,
            (string) ($user['login'] ?? 'mobile'),
            'OLT-LINK-' . date('YmdHis'),
            'unknown',
            false
        );
    }

    $payload = app_db_account_payload($customer);
    $profiles = is_array($payload['ont_profiles'] ?? null) ? $payload['ont_profiles'] : [];
    $profiles = array_values(array_filter($profiles, static function (mixed $profile) use ($liveSerial, $device, $pon, $ontId): bool {
        if (!is_array($profile)) {
            return false;
        }
        $serial = app_mgs_inventory_normalize_serial((string) ($profile['serial'] ?? $profile['sn'] ?? ''));
        $samePath = trim((string) ($profile['olt'] ?? '')) === trim((string) ($device['device_name'] ?? ''))
            && trim((string) ($profile['pon'] ?? '')) === $pon
            && trim((string) ($profile['ont_id'] ?? '')) === $ontId;
        return $serial !== $liveSerial && !$samePath;
    }));
    $profiles[] = [
        'olt' => (string) ($device['device_name'] ?? ''),
        'device_key' => (string) ($device['device_key'] ?? ''),
        'pon' => $pon,
        'ont_id' => $ontId,
        'serial' => (string) ($ont['serial'] ?? $liveSerial),
        'mac' => (string) ($ont['mac'] ?? ''),
        'status' => (string) ($ont['status'] ?? 'unknown'),
        'temperature' => $ont['temperature'] ?? null,
        'rx_power' => $ont['ont_rx'] ?? null,
        'tx_power' => $ont['ont_tx'] ?? null,
        'olt_rx_power' => $ont['olt_rx'] ?? null,
        'olt_tx_power' => $ont['olt_tx'] ?? null,
        'linked_at' => date(DATE_ATOM),
        'linked_by' => (string) ($user['login'] ?? ''),
        'source' => 'live_olt_discovery',
    ];
    $payload['ont_profiles'] = $profiles;
    $update = $db->prepare('UPDATE portal_accounts SET payload_json = :payload WHERE id = :id');
    $update->execute(['payload' => app_db_json_encode($payload), 'id' => (int) $customer['id']]);
    $db->commit();

    app_mgs_record_operation_action([
        'action_type' => 'customer_ont_link',
        'entity_type' => 'customer',
        'entity_id' => $customerCode,
        'status' => 'executed',
        'actor' => $user,
        'summary' => 'Canlı OLT cihazı müştəriyə bağlandı',
        'payload' => [
            'customer_code' => $customerCode,
            'asset_id' => $assetId,
            'device_key' => $deviceKey,
            'pon' => $pon,
            'ont_id' => $ontId,
            'serial' => (string) ($ont['serial'] ?? ''),
        ],
    ]);
    app_mobile_json_response([
        'customer_code' => $customerCode,
        'asset_id' => $assetId,
        'device_key' => $deviceKey,
        'pon' => $pon,
        'ont_id' => $ontId,
        'serial' => (string) ($ont['serial'] ?? ''),
    ], 200, 'ONT müştəriyə bağlandı.');
} catch (DomainException $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $reason = $exception->getMessage();
    $message = $reason === 'ont_already_assigned'
        ? 'Bu ONT artıq başqa müştəriyə aktiv bağlıdır.'
        : 'Müştəri tapılmadı.';
    app_mobile_json_response(['reason' => $reason], 409, $message);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    app_mobile_json_response(['reason' => 'link_failed'], 500, 'ONT müştəriyə bağlanmadı.');
}
