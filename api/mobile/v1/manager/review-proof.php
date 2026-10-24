<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['admin', 'manager', 'super_admin', 'director', 'team_lead']);
$body = app_mobile_request_body();
$proofId = (int) ($body['proof_id'] ?? 0);
$decision = strtolower(trim((string) ($body['decision'] ?? '')));
$note = trim((string) ($body['note'] ?? ''));

if ($proofId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
    app_mobile_json_response(null, 422, 'proof_id və approved/rejected qərarı tələb olunur.');
}
if ($decision === 'rejected' && $note === '') {
    app_mobile_json_response(null, 422, 'Rədd qərarı üçün qeyd tələb olunur.');
}

$db = app_db();
$stmt = $db->prepare('SELECT * FROM mobile_task_proofs WHERE id = ? LIMIT 1');
$stmt->execute([$proofId]);
$proof = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$proof) {
    app_mobile_json_response(null, 404, 'Sübut qeydi tapılmadı.');
}
if ((string) $proof['status'] !== 'submitted') {
    app_mobile_json_response(null, 409, 'Bu sübut artıq nəzərdən keçirilib.');
}
if ($decision === 'approved' && (int) $proof['otp_verified'] !== 1) {
    app_mobile_json_response(null, 409, 'OTP təsdiqlənməyən sübut qəbul edilə bilməz.');
}

$db->beginTransaction();
try {
    $db->prepare(
        'UPDATE mobile_task_proofs SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = "submitted"'
    )->execute([$decision, (int) $user['id'], $proofId]);
    $verificationStatus = $decision === 'approved' ? 'approved' : 'rejected';
    $db->prepare(
        'UPDATE portal_tickets SET resolution_verification_status = ?, verified_at = NOW(), updated_at = NOW() WHERE id = ?'
    )->execute([$verificationStatus, (int) $proof['ticket_id']]);
    $db->prepare(
        "INSERT INTO portal_ticket_events
         (ticket_id, event_type, event_note, actor_account_id, actor_name, payload_json)
         VALUES (?, 'resolution_reviewed', ?, ?, ?, ?)"
    )->execute([
        (int) $proof['ticket_id'],
        $decision === 'approved' ? 'Mobil iş sübutu təsdiqləndi.' : 'Mobil iş sübutu rədd edildi: ' . $note,
        (int) $user['id'],
        (string) $user['full_name'],
        json_encode(['proof_id' => $proofId, 'decision' => $decision, 'note' => $note], JSON_UNESCAPED_UNICODE),
    ]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

app_mobile_json_response([
    'proof_id' => $proofId,
    'ticket_id' => (int) $proof['ticket_id'],
    'status' => $decision,
], 200);
