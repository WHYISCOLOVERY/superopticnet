<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);
$ticketId = (int) (app_mobile_request_body()['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    app_mobile_json_response(null, 422, 'ticket_id tələb olunur.');
}

$db = app_db();
$stmt = $db->prepare('SELECT * FROM portal_tickets WHERE id = ? LIMIT 1');
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ticket) {
    app_mobile_json_response(null, 404, 'Bilet tapılmadı.');
}
if (!app_mobile_ticket_is_assigned_to($ticket, (int) $user['id'])) {
    app_mobile_json_response(null, 403, 'Bu bilet sizə təyin edilməyib.');
}
if (in_array((string) ($ticket['status'] ?? ''), ['resolved', 'closed', 'cancelled'], true)) {
    app_mobile_json_response(null, 409, 'Bağlanmış və ya ləğv edilmiş bilet üçün OTP göndərilə bilməz.');
}

$stmtCustomer = $db->prepare('SELECT * FROM portal_accounts WHERE id = ? AND is_active = 1 LIMIT 1');
$stmtCustomer->execute([(int) ($ticket['customer_account_id'] ?? 0)]);
$customer = $stmtCustomer->fetch(PDO::FETCH_ASSOC);
if (!$customer || trim((string) ($customer['phone'] ?? $ticket['phone'] ?? '')) === '') {
    app_mobile_json_response(null, 422, 'Müştərinin aktiv hesabı və əlaqə nömrəsi tapılmadı.');
}

$stmtRecent = $db->prepare(
    'SELECT created_at FROM mobile_task_otps
     WHERE ticket_id = ? AND account_id = ? ORDER BY id DESC LIMIT 1'
);
$stmtRecent->execute([$ticketId, (int) $user['id']]);
$lastOtpAt = $stmtRecent->fetchColumn();
if ($lastOtpAt && strtotime((string) $lastOtpAt) > time() - 60) {
    app_mobile_json_response(null, 429, 'Yeni OTP göndərmək üçün 60 saniyə gözləyin.');
}

$otpCode = (string) random_int(1000, 9999);
$expiresAt = date('Y-m-d H:i:s', time() + 600);
$db->beginTransaction();
try {
    $db->prepare(
        "UPDATE mobile_task_otps SET consumed_at = NOW()
         WHERE ticket_id = ? AND account_id = ? AND consumed_at IS NULL"
    )->execute([$ticketId, (int) $user['id']]);

    $stmtOtp = $db->prepare(
        "INSERT INTO mobile_task_otps
         (ticket_id, account_id, otp_hash, expires_at, delivery_status)
         VALUES (?, ?, ?, ?, 'pending')"
    );
    $stmtOtp->execute([$ticketId, (int) $user['id'], app_mobile_otp_hash($otpCode), $expiresAt]);
    $otpId = (int) $db->lastInsertId();

    $message = "SuperOptic xidmət təhvili üçün təsdiq kodunuz: {$otpCode}. Kodu yalnız iş tam təhvil verildikdə texnikə bildirin.";
    $queued = function_exists('app_message_queue_for_account')
        && app_message_queue_for_account($customer, 'service_resolution_otp', 'sms', $message, $user, false);

    $deliveryStatus = $queued ? 'queued' : 'failed';
    $db->prepare(
        "UPDATE mobile_task_otps
         SET delivery_status = ?, sent_at = CASE WHEN ? = 'queued' THEN NOW() ELSE NULL END
         WHERE id = ?"
    )->execute([$deliveryStatus, $deliveryStatus, $otpId]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

if (!$queued) {
    app_mobile_json_response(null, 503, 'OTP mesajı növbəyə yazıla bilmədi. Mesaj kanalını yoxlayın.');
}

$phone = trim((string) ($customer['phone'] ?? $ticket['phone'] ?? ''));
$maskedPhone = mb_substr($phone, 0, 4) . '***' . mb_substr($phone, -2);
app_mobile_json_response([
    'otp_sent' => false,
    'otp_queued' => true,
    'ticket_id' => $ticketId,
    'destination_masked' => $maskedPhone,
    'expires_in' => 600,
    'message' => 'Təsdiq kodu müştərinin telefonuna göndərilmək üçün növbəyə yazıldı.',
], 200);
