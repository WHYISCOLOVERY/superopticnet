<?php
declare(strict_types=1);

require_once __DIR__ . '/mgs-integration-lib.php';

function app_website_lead_normalize(array $payload): array
{
    $fullName = clean_text((string) ($payload['full_name'] ?? ''));
    $phone = normalize_phone((string) ($payload['phone'] ?? ''));
    $email = clean_text((string) ($payload['email'] ?? ''));
    $serviceType = clean_text((string) ($payload['service_type'] ?? ''));
    $area = clean_text((string) ($payload['area'] ?? ''));
    $message = clean_text((string) ($payload['message'] ?? ''));
    $lang = strtolower(clean_text((string) ($payload['lang'] ?? 'az')));

    if (!in_array($lang, app_supported_langs(), true)) {
        $lang = 'az';
    }
    if ($fullName === '' || $phone === '' || $serviceType === '' || $area === '') {
        return ['ok' => false, 'reason' => 'required_fields_missing'];
    }
    if (!preg_match('/^\+994(10|50|51|55|60|70|77)\d{7}$/', $phone)) {
        return ['ok' => false, 'reason' => 'phone_invalid'];
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'reason' => 'email_invalid'];
    }
    if ($message === '') {
        $message = 'Yeni qosulma ve ya xidmet sorgusu';
    }

    return [
        'ok' => true,
        'lead' => [
            'full_name' => mb_substr($fullName, 0, 191),
            'phone' => $phone,
            'email' => mb_substr($email, 0, 191),
            'service_type' => mb_substr($serviceType, 0, 191),
            'area' => mb_substr($area, 0, 191),
            'message' => mb_substr($message, 0, 1500),
            'lang' => $lang,
        ],
    ];
}

function app_website_lead_create_crm_ticket(array $lead, array $context = []): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'reason' => 'db_unavailable', 'ticket_no' => '', 'mode' => 'failed'];
    }

    $dedupKey = hash('sha256', implode('|', [
        'website_lead',
        (string) ($lead['phone'] ?? ''),
        (string) ($lead['service_type'] ?? ''),
        (string) ($lead['area'] ?? ''),
        date('YmdHi'),
    ]));
    $existing = $db->prepare(
        'SELECT ticket_no, assigned_technician_account_id FROM portal_tickets
          WHERE status NOT IN ("closed", "resolved", "Baglandi")
            AND metadata_json LIKE :dedup
          ORDER BY id DESC LIMIT 1'
    );
    $existing->execute(['dedup' => '%' . $dedupKey . '%']);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
    $existingTicket = is_array($existingRow)
        ? clean_text((string) ($existingRow['ticket_no'] ?? ''))
        : '';
    if ($existingTicket !== '') {
        return [
            'ok' => true,
            'reason' => '',
            'ticket_no' => $existingTicket,
            'mode' => 'duplicate',
            'assigned_technician_id' => (int) ($existingRow['assigned_technician_account_id'] ?? 0),
        ];
    }

    $technician = app_portal_assign_technician((string) ($lead['area'] ?? ''));
    $ticketNo = app_db_next_ticket_no();
    $message = clean_text((string) ($lead['service_type'] ?? 'Muraciet')) . ': '
        . clean_text((string) ($lead['message'] ?? ''));
    $metadata = [
        'source' => 'superoptic_public_site',
        'dedup_key' => $dedupKey,
        'email' => (string) ($lead['email'] ?? ''),
        'service_type' => (string) ($lead['service_type'] ?? ''),
        'lang' => (string) ($lead['lang'] ?? 'az'),
        'ingress_key_id' => clean_text((string) ($context['key_id'] ?? '')),
        'correlation_id' => clean_text((string) ($context['correlation_id'] ?? app_request_correlation_id())),
    ];
    $ok = app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'customer_account_id' => null,
        'subscriber_code' => '',
        'contract_no' => '',
        'customer_name' => (string) ($lead['full_name'] ?? ''),
        'phone' => (string) ($lead['phone'] ?? ''),
        'address' => '',
        'area' => (string) ($lead['area'] ?? ''),
        'subject' => 'Texniki dəstək',
        'message' => mb_substr($message, 0, 160),
        'status' => 'Aciqdir',
        'opened_at' => app_db_now(),
        'updated_at' => app_db_now(),
        'resolved_at' => null,
        'assigned_technician_account_id' => (int) ($technician['id'] ?? 0) ?: null,
        'assigned_technician_name' => (string) ($technician['name'] ?? 'Teyin edilmeyib'),
        'resolution_reason' => '',
        'resolution_note' => '',
        'handled_by' => '',
        'created_by_account_id' => null,
        'created_by_role' => 'public_ingress',
        'created_by_name' => 'SuperOptic public site',
        'telegram_message_ids_json' => '[]',
        'metadata_json' => app_db_json_encode($metadata),
    ]);

    return [
        'ok' => $ok,
        'reason' => $ok ? '' : 'crm_ticket_create_failed',
        'ticket_no' => $ok ? $ticketNo : '',
        'mode' => $ok ? 'created' : 'failed',
        'assigned_technician_id' => (int) ($technician['id'] ?? 0),
    ];
}

function app_website_lead_process(array $payload, array $context = []): array
{
    $normalized = app_website_lead_normalize($payload);
    if (empty($normalized['ok'])) {
        return $normalized;
    }

    $lead = (array) $normalized['lead'];
    $crmTicket = app_website_lead_create_crm_ticket($lead, $context);
    $ticketNo = clean_text((string) ($crmTicket['ticket_no'] ?? ''));
    $closeLink = $ticketNo !== '' ? app_portal_ticket_close_link([
        'id' => $ticketNo,
        'assigned_technician_id' => (int) ($crmTicket['assigned_technician_id'] ?? 0),
    ]) : '';
    $telegramMessage = implode("\n", [
        'Yeni sayt muracieti',
        'Bilet: ' . ($ticketNo !== '' ? $ticketNo : 'Yaranmadi'),
        'Ad ve soyad: ' . $lead['full_name'],
        'Telefon: ' . $lead['phone'],
        'E-mail: ' . ($lead['email'] !== '' ? $lead['email'] : 'Qeyd edilmeyib'),
        'Xidmet: ' . $lead['service_type'],
        'Erazi: ' . $lead['area'],
        'Qeyd: ' . $lead['message'],
        'Tarix: ' . date('d.m.Y H:i:s'),
        $closeLink !== '' ? 'Bagla: ' . $closeLink : '',
    ]);

    $telegramRoute = app_notification_route_event([
        'category' => 'CUSTOMER_REQUEST',
        'event_type' => 'website_lead',
        'source' => 'superoptic_public_site',
        'title' => 'Yeni sayt muracieti',
        'message' => $telegramMessage,
        'dedup_key' => hash('sha256', implode('|', [
            'website_lead',
            $lead['phone'],
            $lead['service_type'],
            $lead['area'],
            date('YmdHi'),
        ])),
        'template_code' => 'website_customer_request',
        'metadata' => [
            'customer_name' => $lead['full_name'],
            'phone' => $lead['phone'],
            'service_type' => $lead['service_type'],
            'area' => $lead['area'],
            'ingress_key_id' => clean_text((string) ($context['key_id'] ?? '')),
            'correlation_id' => clean_text((string) ($context['correlation_id'] ?? app_request_correlation_id())),
            'ticket_no' => $ticketNo,
        ],
    ], false, true);
    $telegramQueued = !empty($telegramRoute['queued']);

    $billingQueued = false;
    $billingUrl = app_config('billing_ticket_url');
    if (app_portal_mode() !== 'mock' && $billingUrl !== '') {
        $billingQueued = http_post($billingUrl, [
            'ticket_zone' => $lead['area'],
            'ticket_employee' => app_config('billing_employee_id', '54'),
            'open_employee' => $lead['full_name'],
            'ticket_type' => $lead['service_type'] . ' / Sayt',
            'ticket_number' => $lead['phone'],
            'ticket_company' => 'Super Optik Tech',
            'ticket_end_date' => date('d.m.Y H:i:s'),
            'ticket_comment' => $lead['message'] . ($lead['email'] !== '' ? ' | Email: ' . $lead['email'] : ''),
        ]);
    }

    $db = app_db();
    if ($db instanceof PDO && function_exists('app_audit_record')) {
        try {
            app_audit_record($db, [
                'module_key' => 'messaging',
                'operation_class' => 'public_ingress',
                'action_type' => 'website_lead_received',
                'entity_type' => 'website_lead',
                'entity_id' => hash('sha256', $lead['phone'] . '|' . date('Y-m-d H:i')),
                'status' => !empty($crmTicket['ok']) ? 'executed' : 'failed',
                'actor_account_id' => null,
                'actor_name' => 'Public website',
                'summary' => 'Signed public website lead received',
                'reason' => '',
                'after' => [
                    'phone_masked' => substr($lead['phone'], 0, 7) . '****' . substr($lead['phone'], -2),
                    'service_type' => $lead['service_type'],
                    'area' => $lead['area'],
                    'telegram_queued' => $telegramQueued,
                    'billing_queued' => $billingQueued,
                    'crm_ticket_no' => $ticketNo,
                    'crm_ticket_mode' => (string) ($crmTicket['mode'] ?? 'failed'),
                    'correlation_id' => clean_text((string) ($context['correlation_id'] ?? '')),
                ],
                'sensitivity' => 'normal',
            ]);
        } catch (Throwable $exception) {
            app_runtime_error_reference('website_lead_audit', $exception);
        }
    }

    return [
        'ok' => !empty($crmTicket['ok']),
        'reason' => !empty($crmTicket['ok']) ? '' : (string) ($crmTicket['reason'] ?? 'crm_ticket_create_failed'),
        'telegram_queued' => $telegramQueued,
        'billing_queued' => $billingQueued,
        'crm_ticket_no' => $ticketNo,
        'crm_ticket_mode' => (string) ($crmTicket['mode'] ?? 'failed'),
        'message_id' => (int) ($telegramRoute['message_id'] ?? 0),
        'correlation_id' => clean_text((string) ($context['correlation_id'] ?? app_request_correlation_id())),
    ];
}
