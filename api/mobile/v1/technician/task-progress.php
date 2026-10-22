<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğusu dəstəklənir.');
}
$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);
$body = app_mobile_request_body();
$accountId = (int) $user['id'];

try {
    $result = app_mobile_idempotent_mutation(
        trim((string) ($body['client_uuid'] ?? '')),
        $accountId,
        'task_progress',
        $body,
        static function (array $payload) use ($accountId, $user): array {
            $ticketId = (int) ($payload['ticket_id'] ?? 0);
            $eventType = trim((string) ($payload['event_type'] ?? 'started'));
            if ($ticketId <= 0 || $eventType !== 'started') {
                return ['status_code' => 422, 'data' => ['error' => 'Bilet və “icraya başladım” əməliyyatı tələb olunur.']];
            }
            $db = app_db();
            $ticketStatement = $db->prepare('SELECT * FROM portal_tickets WHERE id=? FOR UPDATE');
            $ticketStatement->execute([$ticketId]);
            $ticket = $ticketStatement->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) return ['status_code' => 404, 'data' => ['error' => 'Bilet tapılmadı.']];
            if (!app_mobile_ticket_is_assigned_to($ticket, $accountId)) {
                return ['status_code' => 403, 'data' => ['error' => 'Bu tapşırıq sizə təyin edilməyib.']];
            }
            if (in_array((string) $ticket['status'], ['resolved', 'closed', 'Baglandi', 'Tesdiqlendi'], true)) {
                return ['status_code' => 409, 'data' => ['error' => 'Bağlanmış bilet icraya götürülə bilməz.']];
            }
            $started = $db->prepare("SELECT COUNT(*) FROM portal_ticket_events WHERE ticket_id=? AND event_type='started'");
            $started->execute([$ticketId]);
            if ((int) $started->fetchColumn() > 0) {
                return ['status_code' => 409, 'data' => ['error' => 'Bu bilet üzrə iş artıq başladılıb.']];
            }
            $db->prepare("UPDATE portal_tickets SET status='in_progress',updated_at=NOW() WHERE id=?")
                ->execute([$ticketId]);
            $db->prepare(
                'INSERT INTO portal_ticket_events
                    (ticket_id,event_type,event_note,actor_account_id,actor_name,payload_json)
                 VALUES (?,"started",?,?,?,?)'
            )->execute([
                $ticketId,
                'Texnik mobil tətbiqdə bileti icraya başladı.',
                $accountId,
                (string) ($user['full_name'] ?? $user['login'] ?? ''),
                json_encode(['source' => 'mobile', 'recorded_at' => app_db_now()], JSON_UNESCAPED_UNICODE),
            ]);
            return ['status_code' => 200, 'data' => [
                'ticket_id' => $ticketId,
                'event_type' => 'started',
                'status' => 'in_progress',
                'recorded_at' => date(DATE_ATOM),
            ]];
        }
    );
} catch (Throwable) {
    app_mobile_json_response(null, 503, 'İşin başlanması qeydə alınmadı.');
}

$statusCode = (int) $result['status_code'];
$error = $statusCode >= 400 ? (string) ($result['data']['error'] ?? 'Əməliyyat tamamlanmadı.') : null;
$data = $statusCode >= 400 ? null : $result['data'];
app_mobile_json_response($data, $statusCode, $error, ['is_duplicate' => $result['is_duplicate']]);
