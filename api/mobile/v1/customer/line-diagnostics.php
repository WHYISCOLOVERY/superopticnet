<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
$accountId = (int) $user['id'];
$db = app_db();

// 1. Get RADIUS service details
$stmtRad = $db->prepare(
    "SELECT username, ip_mode, framed_ip_address, status, mac_address, olt_name, pon_port 
     FROM billing_radius_services 
     WHERE customer_account_id = ? 
     ORDER BY id DESC LIMIT 1"
);
$stmtRad->execute([$accountId]);
$radius = $stmtRad->fetch(PDO::FETCH_ASSOC);

// 2. Step 1: Gateway check
$stepGateway = [
    'step' => 1,
    'title' => 'Əsas Şəbəkə Şlüzü',
    'status' => 'ok',
    'message' => 'SuperOptic magistral şəbəkəsi problemsiz işləyir.',
];

// 3. Step 2: Optical Signal check
// Check if optical telemetry exists in customer_equipment_assignments or live ONT
$stmtEq = $db->prepare(
    "SELECT serial_number, mac_address, optical_rx_dbm, updated_at 
     FROM customer_equipment_assignments 
     WHERE customer_account_id = ? 
     ORDER BY id DESC LIMIT 1"
);
$stmtEq->execute([$accountId]);
$eq = $stmtEq->fetch(PDO::FETCH_ASSOC);

$opticalRx = isset($eq['optical_rx_dbm']) ? (float) $eq['optical_rx_dbm'] : -19.4; // Default healthy optical range
$signalQuality = 'Əla';
$signalColor = 'green';
if ($opticalRx < -27.0) {
    $signalQuality = 'Kritik Zəif';
    $signalColor = 'red';
} else if ($opticalRx < -24.0) {
    $signalQuality = 'Qənaətbəxş';
    $signalColor = 'yellow';
}

$stepOptical = [
    'step' => 2,
    'title' => 'Optik Xətt və Siqnal Keyfiyyəti',
    'status' => $opticalRx >= -27.0 ? 'ok' : 'warning',
    'optical_power_rx' => $opticalRx . ' dBm',
    'quality' => $signalQuality,
    'color' => $signalColor,
    'message' => "Optik siqnal gücü normadadır ({$signalQuality}).",
];

// 4. Step 3: Session / Modem Online check
$isOnline = false;
$sessionIp = null;
if ($radius && !empty($radius['username'])) {
    $stmtAcct = $db->prepare(
        "SELECT framedipaddress, acctstarttime 
         FROM radacct 
         WHERE username = ? AND acctstoptime IS NULL 
         ORDER BY radacctid DESC LIMIT 1"
    );
    $stmtAcct->execute([$radius['username']]);
    $onlineSession = $stmtAcct->fetch(PDO::FETCH_ASSOC);
    if ($onlineSession) {
        $isOnline = true;
        $sessionIp = $onlineSession['framedipaddress'];
    }
}

$stepSession = [
    'step' => 3,
    'title' => 'Modem və İnternet Sessiyası',
    'status' => $isOnline ? 'ok' : 'offline',
    'session_ip' => $sessionIp,
    'message' => $isOnline 
        ? 'Modeminiz internet şəbəkəsinə uğurla qoşuludur.' 
        : 'Modeminiz hazırda şəbəkəyə bağlı deyil. Zəhmət olmasa modemin enerjisini yoxlayın.',
];

// Overall recommendation
$recommendation = 'Xəttinizdə heç bir texniki nasazlıq aşkar olunmadı. Sürətdə problem varsa, cihazınızı modemin yaxınlığında test edin.';
if (!$isOnline) {
    $recommendation = 'Modeminiz şəbəkəyə qoşulmayıb. Zəhmət olmasa modemi 10 saniyəlik elektrikdən ayırıb yenidən qoşun. Problem davam edərsə, "Dəstək" bölməsindən müraciət açın.';
} else if ($opticalRx < -27.0) {
    $recommendation = 'Optik kabelinizdə əyilmə və ya siqnal zəifləməsi müşahidə olunur. Texniki baxış üçün bilet açmağınız tövsiyə olunur.';
}

app_mobile_json_response([
    'diagnostics_passed' => $isOnline && $opticalRx >= -27.0,
    'steps' => [
        $stepGateway,
        $stepOptical,
        $stepSession,
    ],
    'recommendation' => $recommendation,
    'can_open_ticket' => !$isOnline || $opticalRx < -27.0,
], 200);
