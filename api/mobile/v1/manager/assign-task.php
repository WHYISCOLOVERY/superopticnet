<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    app_mobile_json_response(null, 405, 'Yalnız POST sorğuları dəstəklənir.');
}
$user = app_mobile_authenticate();
app_mobile_require_role($user, [
    'admin', 'manager', 'super_admin', 'director', 'team_lead',
    'operator', 'night_operator', 'support',
]);
$body = app_mobile_request_body();
if (in_array((string) ($user['employee_role'] ?? ''), ['operator', 'night_operator', 'support'], true)) {
    app_mobile_require_recent_location($user);
}

try {
    $result = app_mobile_idempotent_mutation(
        trim((string) ($body['client_uuid'] ?? '')),
        (int) $user['id'],
        'manager_assign_task',
        $body,
        static function (array $payload) use ($user): array {
            $ticketId = (int) ($payload['ticket_id'] ?? 0);
            $technicianId = (int) ($payload['technician_account_id'] ?? 0);
            $priority = trim((string) ($payload['priority'] ?? 'normal'));
            $instructions = trim((string) ($payload['instructions'] ?? ''));
            if ($ticketId <= 0 || $technicianId <= 0) {
                return ['status_code' => 422, 'data' => ['error' => 'ticket_id və technician_account_id tələb olunur.']];
            }

            $db = app_db();
            $stmtTech = $db->prepare(
                "SELECT id,full_name,phone,role,account_type,employee_role,area,is_active FROM portal_accounts
                  WHERE id=? AND role='employee' AND is_active=1 LIMIT 1"
            );
            $stmtTech->execute([$technicianId]);
            $technician = $stmtTech->fetch(PDO::FETCH_ASSOC);
            if (!$technician || !app_mobile_can_assign_employee($user, $technician)) {
                return ['status_code' => 404, 'data' => ['error' => 'Seçilmiş əməkdaş tapılmadı və ya ona tapşırıq vermək səlahiyyətiniz yoxdur.']];
            }

            $stmtTicket = $db->prepare('SELECT * FROM portal_tickets WHERE id=? FOR UPDATE');
            $stmtTicket->execute([$ticketId]);
            $ticket = $stmtTicket->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) return ['status_code' => 404, 'data' => ['error' => 'Bilet tapılmadı.']];
            if (in_array((string) ($ticket['status'] ?? ''), ['resolved','closed','Baglandi','Tesdiqlendi'], true)) {
                return ['status_code' => 409, 'data' => ['error' => 'Bağlanmış bilet yönləndirilə bilməz.']];
            }

            $isGlobalManager = in_array((string) ($user['role'] ?? ''), ['admin','super_admin','director'], true)
                || in_array((string) ($user['employee_role'] ?? ''), ['admin','super_admin','director','operator','night_operator','support'], true);
            if (!$isGlobalManager) {
                $managerAreas = app_db_account_areas((int) $user['id']);
                if ($managerAreas === [] && trim((string) ($user['area'] ?? '')) !== '') $managerAreas[] = trim((string) $user['area']);
                $technicianAreas = app_db_account_areas($technicianId);
                if (trim((string) ($technician['area'] ?? '')) !== '') $technicianAreas[] = trim((string) $technician['area']);
                if ($managerAreas === [] || array_intersect($managerAreas, array_unique($technicianAreas)) === []
                    || !in_array(trim((string) ($ticket['area'] ?? '')), $managerAreas, true)) {
                    return ['status_code' => 403, 'data' => ['error' => 'Texnik və ya bilet sizin idarə etdiyiniz əraziyə aid deyil.']];
                }
            }

            $updated = $db->prepare(
                "UPDATE portal_tickets SET assigned_technician_account_id=?,assigned_technician_name=?,
                 status='assigned',updated_at=NOW() WHERE id=?"
            );
            $updated->execute([$technicianId, (string) $technician['full_name'], $ticketId]);
            app_db_notify_ticket_assignee($db, $technicianId, $ticketId, (string) $ticket['ticket_no'], (string) $ticket['subject'], (string) $ticket['area']);
            $db->prepare(
                "INSERT INTO portal_ticket_events
                 (ticket_id,event_type,event_note,actor_account_id,actor_name,payload_json)
                 VALUES (?,'assigned',?,?,?,?)"
            )->execute([
                $ticketId,
                'Bilet texnikə təyin edildi: ' . $technician['full_name'] . ($instructions !== '' ? " ($instructions)" : ''),
                (int) $user['id'], (string) $user['full_name'],
                json_encode(['technician_account_id' => $technicianId, 'priority' => $priority], JSON_UNESCAPED_UNICODE),
            ]);
            return ['status_code' => 200, 'data' => [
                'assigned' => true, 'ticket_id' => $ticketId, 'ticket_no' => $ticket['ticket_no'],
                'technician' => ['id' => $technicianId, 'name' => $technician['full_name'], 'phone' => $technician['phone']],
                'status' => 'assigned', 'message' => 'Bilet uğurla texnikə təyin edildi.',
            ]];
        }
    );
} catch (Throwable) {
    app_mobile_json_response(null, 503, 'Bilet yönləndirilmədi. Yenidən sınayın.');
}

$statusCode = (int) $result['status_code'];
app_mobile_json_response(
    $statusCode >= 400 ? null : $result['data'], $statusCode,
    $statusCode >= 400 ? (string) ($result['data']['error'] ?? 'Bilet yönləndirilmədi.') : null,
    ['is_duplicate' => $result['is_duplicate']]
);
