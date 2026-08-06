<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/ticket-resolution-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function ticket_resolution_smoke_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function ticket_resolution_smoke_token(string $ticketNo, int $technicianId): string
{
    $secret = app_environment_secret('PORTAL_TICKET_CLOSE_SECRET');
    if ($secret === '') {
        ticket_resolution_smoke_fail('ticket_secret_missing', 2);
    }
    $now = time();
    return app_portal_sign_token([
        'iss' => app_config('portal_token_issuer', 'portal.superoptic.net'),
        'aud' => app_config('portal_ticket_close_audience', 'portal.ticket.close'),
        'type' => 'ticket-close',
        'ticket_id' => $ticketNo,
        'tech_id' => (string) $technicianId,
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + 600,
        'jti' => bin2hex(random_bytes(8)),
    ], $secret);
}

$db = app_db();
if (!$db instanceof PDO) {
    ticket_resolution_smoke_fail('db_unavailable', 2);
}
if (!app_ticket_resolution_schema_ready($db)) {
    ticket_resolution_smoke_fail('migration_20260802_220_not_applied', 2);
}

$stamp = date('YmdHis') . bin2hex(random_bytes(2));
$employeeLogin = 'smoke.ticket.' . strtolower($stamp);
$ticketNos = [
    'TRS-' . substr($stamp, -14) . '-1',
    'TRS-' . substr($stamp, -14) . '-2',
];
$employeeId = 0;
$ticketIds = [];
$reason = (string) (app_portal_ticket_close_reasons()[0] ?? 'Problem aradan qaldirildi');
$probeTransactionStates = [];

try {
    $employee = app_db_upsert_account([
        'login' => $employeeLogin,
        'password' => bin2hex(random_bytes(16)),
        'role' => 'employee',
        'account_type' => 'technician',
        'employee_role' => 'technician',
        'job_title' => 'Smoke technician',
        'full_name' => 'Ticket Resolution Smoke',
        'status' => 'Aktiv',
        'is_active' => 1,
        'area' => 'Smoke area',
    ], false);
    $employeeId = (int) ($employee['id'] ?? 0);
    if ($employeeId <= 0) {
        ticket_resolution_smoke_fail('employee_fixture_failed', 3);
    }
    $actor = app_db_account_by_id($employeeId);
    if (!is_array($actor)) {
        ticket_resolution_smoke_fail('employee_fixture_read_failed', 3);
    }

    foreach ($ticketNos as $ticketNo) {
        $created = app_db_insert_ticket([
            'ticket_no' => $ticketNo,
            'subscriber_code' => 'SMOKE-' . $stamp,
            'customer_name' => 'Ticket Resolution Customer',
            'area' => 'Smoke area',
            'subject' => 'ONT temperaturu yuksekdir ve optik signal zeifdir',
            'message' => 'RADIUS ve canli ONT telemetriyasi ile yoxlanmalidir.',
            'status' => 'Aciqdir',
            'assigned_technician_account_id' => $employeeId,
            'assigned_technician_name' => 'Ticket Resolution Smoke',
            'created_by_role' => 'system_test',
            'created_by_name' => 'Ticket resolution smoke',
            'metadata' => ['test_fixture' => true, 'ont_serial' => 'SMOKEONT' . $stamp],
        ]);
        if (!$created) {
            ticket_resolution_smoke_fail('ticket_fixture_failed:' . $ticketNo, 3);
        }
        $statement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
        $statement->execute(['ticket_no' => $ticketNo]);
        $ticketIds[] = (int) $statement->fetchColumn();
    }

    $token = ticket_resolution_smoke_token($ticketNos[0], $employeeId);
    $failedRequestId = bin2hex(random_bytes(16));
    $failed = app_ticket_resolution_request(
        $token,
        $reason,
        'Signal hele normada deyil.',
        'resolved',
        'auto',
        $actor,
        $failedRequestId,
        [
            'db' => $db,
            'dispatch_notifications' => false,
            'delete_telegram_messages' => false,
            'probe_runner' => static function (PDO $probeDb) use (&$probeTransactionStates): array {
                $probeTransactionStates[] = $probeDb->inTransaction();
                return [
                    'method' => 'radius+optical',
                    'available' => true,
                    'passed' => false,
                    'reason' => 'optical:live_ont_threshold_failed',
                    'evidence' => ['ont_rx' => '-29.5', 'temperature' => '71'],
                ];
            },
        ]
    );
    if (empty($failed['ok']) || !empty($failed['closed']) || (string) ($failed['status'] ?? '') !== 'verification_failed') {
        ticket_resolution_smoke_fail('failed_probe_did_not_keep_ticket_open', 4);
    }
    if (empty($failed['score_event']['ok'])) {
        ticket_resolution_smoke_fail('failed_probe_penalty_not_persisted', 4);
    }

    $duplicate = app_ticket_resolution_request(
        $token,
        $reason,
        'Signal hele normada deyil.',
        'resolved',
        'auto',
        $actor,
        $failedRequestId,
        [
            'db' => $db,
            'dispatch_notifications' => false,
            'delete_telegram_messages' => false,
            'probe_runner' => static function (): array {
                throw new RuntimeException('duplicate_request_must_not_probe');
            },
        ]
    );
    if (empty($duplicate['existing']) || (string) ($duplicate['status'] ?? '') !== 'verification_failed') {
        ticket_resolution_smoke_fail('idempotent_retry_failed', 4);
    }

    $passed = app_ticket_resolution_request(
        $token,
        $reason,
        'Signal ve temperatur normallasdi, PPPoE sessiyasi aktivdir.',
        'resolved',
        'auto',
        $actor,
        bin2hex(random_bytes(16)),
        [
            'db' => $db,
            'dispatch_notifications' => false,
            'delete_telegram_messages' => false,
            'probe_runner' => static function (PDO $probeDb) use (&$probeTransactionStates): array {
                $probeTransactionStates[] = $probeDb->inTransaction();
                return [
                    'method' => 'radius+optical',
                    'available' => true,
                    'passed' => true,
                    'reason' => 'all_required_probes_passed',
                    'evidence' => ['ont_rx' => '-20.1', 'temperature' => '43', 'radius_online' => true],
                ];
            },
        ]
    );
    if (empty($passed['ok']) || empty($passed['closed']) || (string) ($passed['status'] ?? '') !== 'verified_closed') {
        ticket_resolution_smoke_fail('passed_probe_did_not_close_ticket', 4);
    }
    if (empty($passed['score_event']['ok'])) {
        ticket_resolution_smoke_fail('passed_probe_reward_not_persisted', 4);
    }
    if (in_array(true, $probeTransactionStates, true) || count($probeTransactionStates) !== 2) {
        ticket_resolution_smoke_fail('probe_ran_inside_db_transaction', 4);
    }

    $checkCount = $db->prepare('SELECT COUNT(*) FROM ticket_resolution_checks WHERE ticket_no = :ticket_no');
    $checkCount->execute(['ticket_no' => $ticketNos[0]]);
    if ((int) $checkCount->fetchColumn() !== 2) {
        ticket_resolution_smoke_fail('resolution_check_count_mismatch', 4);
    }
    $ticketState = $db->prepare(
        'SELECT status, resolution_verification_status, verification_attempts
           FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1'
    );
    $ticketState->execute(['ticket_no' => $ticketNos[0]]);
    $closedTicket = $ticketState->fetch(PDO::FETCH_ASSOC);
    if (!is_array($closedTicket)
        || (string) ($closedTicket['status'] ?? '') !== 'Baglandi'
        || (string) ($closedTicket['resolution_verification_status'] ?? '') !== 'verified_closed'
        || (int) ($closedTicket['verification_attempts'] ?? 0) !== 2) {
        ticket_resolution_smoke_fail('closed_ticket_state_mismatch', 4);
    }
    $scoreState = $db->prepare(
        'SELECT COUNT(*) AS event_count, COALESCE(SUM(points_delta), 0) AS point_total,
                COUNT(DISTINCT idempotency_key) AS idempotency_count
           FROM hr_score_events
          WHERE source_type = "ticket_resolution" AND source_id = :ticket_no'
    );
    $scoreState->execute(['ticket_no' => $ticketNos[0]]);
    $scoreRow = $scoreState->fetch(PDO::FETCH_ASSOC);
    if (!is_array($scoreRow)
        || (int) ($scoreRow['event_count'] ?? 0) !== 2
        || (float) ($scoreRow['point_total'] ?? 999) !== 0.0
        || (int) ($scoreRow['idempotency_count'] ?? 0) !== 2) {
        ticket_resolution_smoke_fail('score_ledger_or_idempotency_mismatch', 4);
    }

    $manualToken = ticket_resolution_smoke_token($ticketNos[1], $employeeId);
    $manualReview = app_ticket_resolution_request(
        $manualToken,
        $reason,
        'Canli OLT yolu elcatmazdir.',
        'resolved',
        'auto',
        $actor,
        bin2hex(random_bytes(16)),
        [
            'db' => $db,
            'dispatch_notifications' => false,
            'delete_telegram_messages' => false,
            'probe_runner' => static fn (): array => [
                'method' => 'radius+optical',
                'available' => false,
                'passed' => false,
                'reason' => 'management_path_down',
                'evidence' => [],
            ],
        ]
    );
    if ((string) ($manualReview['status'] ?? '') !== 'manual_review_required'
        || !empty($manualReview['closed'])
        || isset($manualReview['score_event'])) {
        ticket_resolution_smoke_fail('unavailable_probe_must_not_close_or_score', 4);
    }

    echo json_encode([
        'status' => 'passed',
        'same_link_recheck' => true,
        'idempotent_retry' => true,
        'probe_outside_transaction' => true,
        'failed_probe_kept_open' => true,
        'verified_probe_closed' => true,
        'failed_probe_score' => -5,
        'verified_probe_score' => 5,
        'unavailable_probe_score' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    foreach ($ticketIds as $ticketId) {
        if ($ticketId <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM ticket_resolution_checks WHERE ticket_id = :ticket_id')->execute(['ticket_id' => $ticketId]);
        $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id')->execute(['ticket_id' => $ticketId]);
        $db->prepare('DELETE FROM portal_tickets WHERE id = :ticket_id')->execute(['ticket_id' => $ticketId]);
    }
    foreach ($ticketNos as $ticketNo) {
        $db->prepare(
            'DELETE FROM hr_score_events WHERE source_type = "ticket_resolution" AND source_id = :ticket_no'
        )->execute(['ticket_no' => $ticketNo]);
        $db->prepare(
            'DELETE FROM mgs_operation_actions WHERE ticket_no = :ticket_no OR (entity_type = "ticket" AND entity_id = :ticket_no)'
        )->execute(['ticket_no' => $ticketNo]);
    }
    if ($employeeId > 0) {
        $db->prepare('DELETE FROM employee_work_snapshots WHERE employee_account_id = :id')->execute(['id' => $employeeId]);
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id AND login = :login')->execute([
            'id' => $employeeId,
            'login' => $employeeLogin,
        ]);
    }
}
