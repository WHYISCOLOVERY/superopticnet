<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);
$confirmed = in_array('--confirm=TEST-TICKET-CLOSE', $_SERVER['argv'] ?? [], true);
$db = app_db();
$customerProfile = app_telegram_provider_profile('customer');
$probeLink = app_portal_ticket_close_link([
    'id' => 'TEST-TICKET-CLOSE-READINESS',
    'assigned_technician_id' => '',
]);
$probeParts = $probeLink !== '' ? parse_url($probeLink) : [];
$readiness = [
    'db' => $db instanceof PDO,
    'curl' => function_exists('curl_init'),
    'ticket_secret' => app_environment_secret('PORTAL_TICKET_CLOSE_SECRET') !== '',
    'customer_bot_token' => (string) ($customerProfile['token'] ?? '') !== '',
    'customer_chat' => (string) ($customerProfile['chat_id'] ?? '') !== '',
    'https_link' => ($probeParts['scheme'] ?? '') === 'https',
    'expected_host' => ($probeParts['host'] ?? '') === 'mgt.superoptic.net',
];
$ready = !in_array(false, $readiness, true);

if (!$apply || !$confirmed || !$ready || !$db instanceof PDO) {
    echo json_encode([
        'status' => $ready ? 'ready' : 'blocked',
        'mode' => 'dry-run',
        'readiness' => $readiness,
        'reason' => !$apply
            ? 'dry_run_no_ticket_or_provider_request'
            : (!$confirmed ? 'confirmation_missing' : (!$ready ? 'readiness_failed' : '')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ready && !$apply ? 0 : 2);
}

$ticketNo = 'TEST-CLOSE-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
$note = 'Telegram close link HTTP smoke completed.';
$reason = (string) (app_portal_ticket_close_reasons()[0] ?? '');
$messageIds = [];
$cookieFile = tempnam(sys_get_temp_dir(), 'ticket-close-cookie-');
$ticketDbId = 0;

try {
    if ($reason === '' || !is_string($cookieFile)) {
        throw new RuntimeException('smoke_prerequisite_failed');
    }

    $created = app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'subscriber_code' => '',
        'contract_no' => '',
        'customer_name' => 'Integration Test',
        'phone' => '',
        'address' => '',
        'area' => 'Baki',
        'subject' => '[TEST] Telegram close link',
        'message' => 'Temporary production smoke ticket. It will be removed after verification.',
        'status' => 'Aciqdir',
        'assigned_technician_name' => 'Integration Test',
        'created_by_role' => 'system_test',
        'created_by_name' => 'Ticket close smoke',
        'telegram_message_ids' => [],
        'metadata' => ['test_fixture' => true, 'cleanup_required' => true],
    ]);
    if (!$created) {
        throw new RuntimeException('ticket_create_failed');
    }

    $ticket = app_db_ticket_find($ticketNo);
    if (!is_array($ticket)) {
        throw new RuntimeException('ticket_read_failed');
    }
    $ticketRow = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
    $ticketRow->execute(['ticket_no' => $ticketNo]);
    $ticketDbId = (int) $ticketRow->fetchColumn();

    $closeLink = app_portal_ticket_close_link($ticket);
    if ($closeLink === '') {
        throw new RuntimeException('close_link_not_generated');
    }

    $summaryResponse = app_telegram_send_message(app_portal_ticket_summary($ticket), 'customer');
    $summaryMessageId = (int) ($summaryResponse['result']['message_id'] ?? 0);
    if ($summaryMessageId <= 0) {
        throw new RuntimeException('telegram_summary_send_failed');
    }
    $messageIds[] = $summaryMessageId;

    $linkResponse = app_telegram_send_message('Bagla: ' . $closeLink, 'customer');
    $linkMessageId = (int) ($linkResponse['result']['message_id'] ?? 0);
    if ($linkMessageId <= 0) {
        throw new RuntimeException('telegram_link_send_failed');
    }
    $messageIds[] = $linkMessageId;

    $saveIds = $db->prepare(
        'UPDATE portal_tickets
            SET telegram_message_ids_json = :message_ids
          WHERE ticket_no = :ticket_no'
    );
    $saveIds->execute([
        'message_ids' => app_db_json_encode($messageIds),
        'ticket_no' => $ticketNo,
    ]);

    $http = curl_init($closeLink);
    if ($http === false) {
        throw new RuntimeException('close_link_get_init_failed');
    }
    curl_setopt_array($http, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'SuperOptic-Ticket-Close-Smoke/1.0',
    ]);
    $getBody = curl_exec($http);
    $getStatus = (int) curl_getinfo($http, CURLINFO_RESPONSE_CODE);
    $getError = curl_error($http);
    if ($getStatus !== 200 || !is_string($getBody)) {
        throw new RuntimeException('close_link_get_failed:' . $getStatus . ':' . $getError);
    }
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $getBody, $csrfMatch) !== 1) {
        throw new RuntimeException('csrf_token_missing');
    }
    $csrfToken = html_entity_decode((string) $csrfMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

    curl_setopt_array($http, [
        CURLOPT_URL => $closeLink,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'token' => (string) (parse_url($closeLink, PHP_URL_QUERY) !== null
                ? (function (string $query): string {
                    parse_str($query, $params);
                    return (string) ($params['token'] ?? '');
                })((string) parse_url($closeLink, PHP_URL_QUERY))
                : ''),
            'csrf_token' => $csrfToken,
            'reason' => $reason,
            'note' => $note,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'SuperOptic-Ticket-Close-Smoke/1.0',
    ]);
    $postBody = curl_exec($http);
    $postStatus = (int) curl_getinfo($http, CURLINFO_RESPONSE_CODE);
    $postError = curl_error($http);
    curl_close($http);
    if ($postStatus !== 302) {
        $failureStage = is_string($postBody) && str_contains($postBody, 'Sessiya yoxlamasi ugursuz oldu.')
            ? 'csrf_failed'
            : (is_string($postBody) && str_contains($postBody, 'Bilet tapilmadi ve ya link vaxti bitib.')
                ? 'token_or_ticket_failed'
                : 'unknown_response');
        throw new RuntimeException('close_link_post_failed:' . $postStatus . ':' . $failureStage . ':' . $postError);
    }

    $closed = app_db_ticket_find($ticketNo);
    $closedOk = is_array($closed)
        && (string) ($closed['status'] ?? '') === 'Baglandi'
        && (string) ($closed['resolution_note'] ?? '') === $note;
    if (!$closedOk) {
        throw new RuntimeException('ticket_not_closed');
    }

    parse_str((string) parse_url($closeLink, PHP_URL_QUERY), $linkQuery);
    $replayRejected = !app_portal_close_ticket_by_token(
        (string) ($linkQuery['token'] ?? ''),
        $reason,
        'Replay must be rejected.'
    );
    if (!$replayRejected) {
        throw new RuntimeException('token_replay_not_rejected');
    }

    $auditStatement = $db->prepare(
        'SELECT COUNT(*)
           FROM mgs_operation_actions
          WHERE action_type = "ticket_closed"
            AND ticket_no = :ticket_no'
    );
    $auditStatement->execute(['ticket_no' => $ticketNo]);
    $operationLogged = (int) $auditStatement->fetchColumn() > 0;
    if (!$operationLogged) {
        throw new RuntimeException('ticket_close_operation_not_logged');
    }

    if ($ticketDbId > 0) {
        $deleteEvents = $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id');
        $deleteEvents->execute(['ticket_id' => $ticketDbId]);
    }
    $deleteTicket = $db->prepare('DELETE FROM portal_tickets WHERE ticket_no = :ticket_no');
    $deleteTicket->execute(['ticket_no' => $ticketNo]);

    echo json_encode([
        'status' => 'passed',
        'mode' => 'apply',
        'ticket_no' => $ticketNo,
        'telegram_messages_sent' => count($messageIds),
        'get_status' => $getStatus,
        'post_status' => $postStatus,
        'ticket_closed' => true,
        'token_replay_rejected' => true,
        'operation_logged' => true,
        'test_ticket_removed' => $deleteTicket->rowCount() === 1,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    foreach ($messageIds as $messageId) {
        app_telegram_delete_message((int) $messageId, 'customer');
    }
    if ($ticketDbId > 0) {
        $deleteEvents = $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id');
        $deleteEvents->execute(['ticket_id' => $ticketDbId]);
    }
    $deleteTicket = $db->prepare('DELETE FROM portal_tickets WHERE ticket_no = :ticket_no');
    $deleteTicket->execute(['ticket_no' => $ticketNo]);
    echo json_encode([
        'status' => 'failed',
        'mode' => 'apply',
        'ticket_no' => $ticketNo,
        'reason' => $exception->getMessage(),
        'test_ticket_removed' => $deleteTicket->rowCount() <= 1,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(3);
} finally {
    if (is_string($cookieFile) && is_file($cookieFile)) {
        @unlink($cookieFile);
    }
}
