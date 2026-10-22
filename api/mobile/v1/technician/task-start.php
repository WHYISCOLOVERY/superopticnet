<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}

$user = app_mobile_authenticate();
app_mobile_require_role($user, ['technician', 'engineer']);

$body = app_mobile_request_body();
$ticketId = (int) ($body['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    app_mobile_json_response(null, 422, 'ticket_id tələb olunur.');
}

$db = app_db();
$accountId = (int) $user['id'];
app_mobile_require_recent_location($user);

$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT * FROM portal_tickets WHERE id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        $db->rollBack();
        app_mobile_json_response(null, 404, 'Bilet tapılmadı.');
    }
    if (!app_mobile_ticket_is_assigned_to($ticket, $accountId)) {
        $db->rollBack();
        app_mobile_json_response(null, 403, 'Bu tapşırıq sizə təyin edilməyib.');
    }
    if (in_array((string) ($ticket['status'] ?? ''), ['resolved', 'closed', 'Baglandi', 'Tesdiqlendi'], true)) {
        $db->rollBack();
        app_mobile_json_response(null, 409, 'Bu tapşırıq artıq bağlanıb.');
    }

    $started = $db->prepare("SELECT COUNT(*) FROM portal_ticket_events WHERE ticket_id=? AND event_type='started'");
    $started->execute([$ticketId]);
    if ((int) $started->fetchColumn() > 0) {
        $db->rollBack();
        app_mobile_json_response(null, 409, 'Bu bilet üzrə iş artıq başladılıb.');
    }

    $db->prepare(
        "UPDATE portal_tickets
            SET status='in_progress', updated_at=NOW()
          WHERE id=? AND assigned_technician_account_id=?"
    )->execute([$ticketId, $accountId]);
    $db->prepare(
        "INSERT INTO portal_ticket_events
         (ticket_id,event_type,event_note,actor_account_id,actor_name,payload_json)
         VALUES (?, 'started', 'Texnik mobil tətbiqdə işə başladı.', ?, ?, ?)"
    )->execute([
        $ticketId,
        $accountId,
        (string) $user['full_name'],
        json_encode(['source' => 'mobile', 'started_at' => app_db_now()], JSON_UNESCAPED_UNICODE),
    ]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

app_mobile_json_response([
    'started' => true,
    'ticket_id' => $ticketId,
    'status' => 'in_progress',
    'started_at' => date(DATE_ATOM),
], 200);
