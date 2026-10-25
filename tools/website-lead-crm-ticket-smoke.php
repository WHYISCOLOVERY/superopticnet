<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/website-lead-service.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(2);
}

$errors = [];
$ticketNo = '';
$ticketDbId = 0;

try {
    $lead = [
        'full_name' => 'CRM smoke fixture',
        'phone' => '+994501234567',
        'email' => 'crm-smoke@example.invalid',
        'service_type' => 'Smoke ' . bin2hex(random_bytes(4)),
        'area' => 'Baki',
        'message' => 'Temporary CRM transaction smoke.',
        'lang' => 'az',
    ];
    $context = [
        'key_id' => 'smoke-key',
        'correlation_id' => 'crm-smoke-' . bin2hex(random_bytes(6)),
    ];

    $created = app_website_lead_create_crm_ticket($lead, $context);
    $ticketNo = (string) ($created['ticket_no'] ?? '');
    if (empty($created['ok']) || (string) ($created['mode'] ?? '') !== 'created' || $ticketNo === '') {
        $errors[] = 'ticket_create_failed';
    }

    $ticket = $ticketNo !== '' ? app_db_ticket_find($ticketNo) : null;
    if (!is_array($ticket)) {
        $errors[] = 'ticket_read_failed';
    } else {
        $idStatement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
        $idStatement->execute(['ticket_no' => $ticketNo]);
        $ticketDbId = (int) ($idStatement->fetchColumn() ?: 0);
    }

    $duplicate = app_website_lead_create_crm_ticket($lead, $context);
    if (empty($duplicate['ok'])
        || (string) ($duplicate['mode'] ?? '') !== 'duplicate'
        || (string) ($duplicate['ticket_no'] ?? '') !== $ticketNo
        || (int) ($duplicate['assigned_technician_id'] ?? 0) !== (int) ($created['assigned_technician_id'] ?? 0)) {
        $errors[] = 'ticket_dedup_failed';
    }

    $link = is_array($ticket) ? app_portal_ticket_close_link($ticket) : '';
    if (app_environment_secret('PORTAL_TICKET_CLOSE_SECRET') !== '' && $link === '') {
        $errors[] = 'ticket_close_link_failed';
    }

    $metadataStatement = $db->prepare('SELECT metadata_json FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
    $metadataStatement->execute(['ticket_no' => $ticketNo]);
    $metadata = app_db_json_decode((string) ($metadataStatement->fetchColumn() ?: ''), []);
    if (($metadata['source'] ?? '') !== 'superoptic_public_site'
        || ($metadata['correlation_id'] ?? '') !== $context['correlation_id']) {
        $errors[] = 'ticket_metadata_failed';
    }
} catch (Throwable $exception) {
    $errors[] = 'exception:' . get_class($exception) . ':' . mb_substr($exception->getMessage(), 0, 240);
} finally {
    if ($ticketDbId > 0) {
        $deleteEvents = $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id');
        $deleteEvents->execute(['ticket_id' => $ticketDbId]);
    }
    if ($ticketNo !== '') {
        $deleteTicket = $db->prepare('DELETE FROM portal_tickets WHERE ticket_no = :ticket_no');
        $deleteTicket->execute(['ticket_no' => $ticketNo]);
    }
}

echo json_encode([
    'status' => $errors === [] ? 'passed' : 'failed',
    'ticket_created' => $ticketNo !== '',
    'fixture_removed' => $ticketNo === '' || app_db_ticket_find($ticketNo) === null,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
