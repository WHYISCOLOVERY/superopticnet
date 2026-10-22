<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-inventory-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);
$body = app_mobile_request_body();
$accountId = (int) $user['id'];
app_mobile_require_recent_location($user);

try {
$result = app_mobile_idempotent_mutation(
    trim((string) ($body['client_uuid'] ?? '')),
    $accountId,
    'task_resolve',
    $body,
    static function (array $payload) use ($accountId, $user): array {
        $db = app_db();
        $ticketId = (int) ($payload['ticket_id'] ?? 0);
        $customerOtp = trim((string) ($payload['customer_otp_code'] ?? ''));
        if ($ticketId <= 0) {
            return ['status_code' => 422, 'data' => ['error' => 'ticket_id tələb olunur.']];
        }
        if (preg_match('/^\d{4}$/', $customerOtp) !== 1) {
            return ['status_code' => 422, 'data' => ['error' => '4 rəqəmli müştəri OTP kodu tələb olunur.']];
        }

        $stmtTicket = $db->prepare('SELECT * FROM portal_tickets WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmtTicket->execute([$ticketId]);
        $ticket = $stmtTicket->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            return ['status_code' => 404, 'data' => ['error' => 'Bilet tapılmadı.']];
        }
        if (!app_mobile_ticket_is_assigned_to($ticket, $accountId)) {
            return ['status_code' => 403, 'data' => ['error' => 'Bu bileti bağlamaq səlahiyyətiniz yoxdur.']];
        }
        if (in_array((string) ($ticket['status'] ?? ''), ['resolved', 'closed', 'cancelled'], true)) {
            return ['status_code' => 409, 'data' => ['error' => 'Bilet artıq bağlanıb və ya ləğv edilib.']];
        }
        $startedCheck = $db->prepare(
            "SELECT COUNT(*) FROM portal_ticket_events
              WHERE ticket_id=? AND actor_account_id=? AND event_type='started'"
        );
        $startedCheck->execute([$ticketId, $accountId]);
        if ((int) $startedCheck->fetchColumn() < 1) {
            return ['status_code' => 409, 'data' => ['error' => 'Bileti təhvil verməzdən əvvəl “İcraya başladım” düyməsini basın.']];
        }

        $stmtOtp = $db->prepare(
            "SELECT * FROM mobile_task_otps
             WHERE ticket_id = ? AND account_id = ? AND delivery_status = 'queued' AND consumed_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmtOtp->execute([$ticketId, $accountId]);
        $otp = $stmtOtp->fetch(PDO::FETCH_ASSOC);
        if (!$otp || strtotime((string) $otp['expires_at']) < time()) {
            return ['status_code' => 400, 'data' => ['error' => 'OTP tapılmadı və ya vaxtı bitib. Yeni kod göndərin.']];
        }
        if ((int) $otp['attempts'] >= (int) $otp['max_attempts']) {
            return ['status_code' => 429, 'data' => ['error' => 'OTP cəhd limiti bitib. Yeni kod göndərin.']];
        }
        if (!hash_equals((string) $otp['otp_hash'], app_mobile_otp_hash($customerOtp))) {
            $db->prepare('UPDATE mobile_task_otps SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $otp['id']]);
            return ['status_code' => 400, 'data' => ['error' => 'Müştəri təsdiq OTP kodu yanlışdır.']];
        }

        $photoBase64 = (string) ($payload['photo_base64'] ?? '');
        $cleanBase64 = preg_replace('#^data:image/jpeg;base64,#i', '', $photoBase64);
        $binary = is_string($cleanBase64) ? base64_decode($cleanBase64, true) : false;
        if ($binary === false || $binary === '' || strlen($binary) > 8 * 1024 * 1024) {
            return ['status_code' => 422, 'data' => ['error' => 'Etibarlı JPEG təhvil şəkli tələb olunur (maksimum 8 MB).']];
        }
        $imageInfo = @getimagesizefromstring($binary);
        if (!is_array($imageInfo) || ($imageInfo['mime'] ?? '') !== 'image/jpeg') {
            return ['status_code' => 422, 'data' => ['error' => 'Təhvil şəkli JPEG formatında olmalıdır.']];
        }

        $uploadDir = dirname(__DIR__, 4) . '/data/mobile_proofs/' . date('Y/m');
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Sübut qovluğu yaradıla bilmədi.');
        }
        $filename = 'proof_' . $ticketId . '_' . bin2hex(random_bytes(8)) . '.jpg';
        $absolutePhotoPath = $uploadDir . '/' . $filename;
        if (file_put_contents($absolutePhotoPath, $binary, LOCK_EX) !== strlen($binary)) {
            throw new RuntimeException('Sübut şəkli saxlanmadı.');
        }
        $photoPath = 'data/mobile_proofs/' . date('Y/m') . '/' . $filename;

        try {
            $materials = isset($payload['materials_used']) && is_array($payload['materials_used']) ? $payload['materials_used'] : [];
            $notes = trim((string) ($payload['resolution_notes'] ?? '')) ?: 'İş sahədə tamamlandı.';
            $problemType = trim((string) ($payload['problem_type'] ?? ''));
            $resolutionReason = trim((string) ($payload['resolution_reason'] ?? 'Sahə texniki tərəfindən həll edildi'));
            $completedActions = isset($payload['completed_actions']) && is_array($payload['completed_actions']) ? $payload['completed_actions'] : [];
            $equipment = isset($payload['equipment']) && is_array($payload['equipment']) ? $payload['equipment'] : [];
            $opticalPower = isset($payload['optical_power_dbm']) ? (float) $payload['optical_power_dbm'] : null;
            $watermarkMeta = json_encode($payload['watermark_data'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmtProof = $db->prepare(
                "INSERT INTO mobile_task_proofs
                 (ticket_id, account_id, client_uuid, photo_path, watermark_data, optical_power_dbm,
                  ocr_raw_text, customer_otp_code, otp_verified, otp_verified_at, resolution_notes,
                  materials_json, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 1, NOW(), ?, ?, 'submitted', NOW())"
            );
            $stmtProof->execute([
                $ticketId, $accountId, (string) $payload['client_uuid'], $photoPath, $watermarkMeta,
                $opticalPower, trim((string) ($payload['ocr_raw_text'] ?? '')), $notes,
                json_encode($materials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $updated = $db->prepare(
                "UPDATE portal_tickets SET status = 'resolved', resolved_at = NOW(),
                 resolution_reason = ?, resolution_note = ?,
                 handled_by = ?, updated_at = NOW()
                 WHERE id = ? AND assigned_technician_account_id = ?"
            );
            $updated->execute([
                mb_substr($resolutionReason, 0, 191),
                $notes,
                (string) $user['full_name'],
                $ticketId,
                $accountId,
            ]);
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('Bilet bağlanarkən təyinat dəyişdi.');
            }

            app_mobile_inventory_consume($db, $user, $ticket, $payload);

            $db->prepare('UPDATE mobile_task_otps SET consumed_at = NOW() WHERE id = ?')->execute([(int) $otp['id']]);
            $db->prepare(
                "INSERT INTO portal_ticket_events
                 (ticket_id, event_type, event_note, actor_account_id, actor_name, payload_json)
                 VALUES (?, 'resolved', ?, ?, ?, ?)"
            )->execute([
                $ticketId, 'Mobil tətbiqdən OTP ilə bağlandı.', $accountId, (string) $user['full_name'],
                json_encode([
                    'proof_path' => $photoPath,
                    'otp_id' => (int) $otp['id'],
                    'problem_type' => $problemType,
                    'resolution_reason' => $resolutionReason,
                    'completed_actions' => $completedActions,
                    'equipment' => $equipment,
                    'materials' => $materials,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return ['status_code' => 200, 'data' => [
                'ticket_id' => $ticketId,
                'ticket_no' => $ticket['ticket_no'],
                'status' => 'resolved',
                'optical_power_dbm' => $opticalPower,
                'otp_verified' => true,
                'photo_stored' => true,
                'resolved_at' => date(DATE_ATOM),
                'message' => 'İş uğurla təhvil verildi və bilet bağlandı.',
            ]];
        } catch (Throwable $exception) {
            @unlink($absolutePhotoPath);
            throw $exception;
        }
    }
);

} catch (InvalidArgumentException $e) {
    app_mobile_json_response(null, 422, $e->getMessage());
} catch (Throwable $e) {
    app_mobile_json_response(null, 503, 'Təhvil tamamlanmadı. Yenidən sınayın.');
}

$statusCode = (int) $result['status_code'];
$error = $statusCode >= 400 ? (string) ($result['data']['error'] ?? 'Əməliyyat tamamlanmadı.') : null;
$data = $statusCode >= 400 ? null : $result['data'];
app_mobile_json_response($data, $statusCode, $error, ['is_duplicate' => $result['is_duplicate']]);
