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
        'manager_create_task',
        $body,
        static function (array $payload) use ($user): array {
            $subject = trim((string) ($payload['subject'] ?? ''));
            $message = trim((string) ($payload['message'] ?? ''));
            $area = trim((string) ($payload['area'] ?? ''));
            $technicianId = (int) ($payload['technician_account_id'] ?? 0);
            $priority = trim((string) ($payload['priority'] ?? 'normal'));
            $dueDate = trim((string) ($payload['due_date'] ?? ''));
            if ($subject === '' || $message === '' || $area === '') {
                return ['status_code' => 422, 'data' => ['error' => 'Başlıq, izah və ərazi tələb olunur.']];
            }
            if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) {
                return ['status_code' => 422, 'data' => ['error' => 'Prioritet etibarsızdır.']];
            }
            if ($dueDate !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate) === false) {
                return ['status_code' => 422, 'data' => ['error' => 'Son tarix YYYY-MM-DD formatında olmalıdır.']];
            }

            $db = app_db();
            $technician = null;
            if ($technicianId > 0) {
                $statement = $db->prepare(
                    "SELECT id,full_name,role,account_type,employee_role,area,is_active FROM portal_accounts
                      WHERE id=? AND role='employee' AND is_active=1 LIMIT 1"
                );
                $statement->execute([$technicianId]);
                $technician = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($technician === null || !app_mobile_can_assign_employee($user, $technician)) {
                    return ['status_code' => 404, 'data' => ['error' => 'Seçilmiş əməkdaş tapılmadı.']];
                }
            }

            $ticketNo = app_db_next_ticket_no();
            $ok = app_db_insert_ticket([
                'ticket_no' => $ticketNo,
                'customer_name' => trim((string) ($payload['customer_name'] ?? 'Daxili əməliyyat')),
                'phone' => trim((string) ($payload['phone'] ?? '')),
                'address' => trim((string) ($payload['address'] ?? '')),
                'area' => $area,
                'subject' => mb_substr($subject, 0, 191),
                'message' => mb_substr($message, 0, 160),
                'status' => $technician ? 'assigned' : 'Aciqdir',
                'opened_at' => app_db_now(),
                'updated_at' => app_db_now(),
                'assigned_technician_account_id' => $technicianId,
                'assigned_technician_name' => (string) ($technician['full_name'] ?? ''),
                'created_by_account_id' => (int) $user['id'],
                'created_by_role' => (string) ($user['role'] ?? ''),
                'created_by_name' => (string) ($user['full_name'] ?? ''),
                'metadata' => [
                    'source' => 'mobile_manager', 'priority' => $priority,
                    'due_date' => $dueDate ?: null, 'client_uuid' => (string) $payload['client_uuid'],
                ],
            ]);
            if (!$ok) throw new RuntimeException('Tapşırıq yaradıla bilmədi.');
            $idStatement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no=? LIMIT 1');
            $idStatement->execute([$ticketNo]);
            $ticketId = (int) $idStatement->fetchColumn();
            $eventStatement = $db->prepare(
                'INSERT INTO portal_ticket_events
                 (ticket_id,event_type,event_note,actor_account_id,actor_name,payload_json)
                 VALUES (?,?,?,?,?,?)'
            );
            $eventStatement->execute([
                $ticketId, 'created', 'Mobil paneldən tapşırıq yaradıldı',
                (int) $user['id'], (string) $user['full_name'],
                json_encode(['priority' => $priority, 'due_date' => $dueDate ?: null], JSON_UNESCAPED_UNICODE),
            ]);
            if ($technician !== null) {
                $eventStatement->execute([
                    $ticketId, 'assigned', 'Tapşırıq əməkdaşa verildi: ' . $technician['full_name'],
                    (int) $user['id'], (string) $user['full_name'],
                    json_encode(['technician_account_id' => $technicianId, 'priority' => $priority], JSON_UNESCAPED_UNICODE),
                ]);
            }
            return ['status_code' => 201, 'data' => [
                'created' => true, 'ticket_id' => $ticketId, 'ticket_no' => $ticketNo,
                'assigned_to' => $technician['full_name'] ?? null,
            ]];
        }
    );
} catch (Throwable) {
    app_mobile_json_response(null, 503, 'Tapşırıq yaradıla bilmədi. Yenidən sınayın.');
}

$statusCode = (int) $result['status_code'];
app_mobile_json_response(
    $statusCode >= 400 ? null : $result['data'], $statusCode,
    $statusCode >= 400 ? (string) ($result['data']['error'] ?? 'Tapşırıq yaradılmadı.') : null,
    ['is_duplicate' => $result['is_duplicate']]
);
