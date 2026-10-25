<?php
declare(strict_types=1);

function app_ticket_resolution_schema_ready(PDO $db): bool
{
    try {
        $statement = $db->query(
            'SELECT COUNT(*)
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = "portal_tickets" AND COLUMN_NAME = "resolution_verification_status")
                  OR (TABLE_NAME = "ticket_resolution_checks" AND COLUMN_NAME = "idempotency_key"))'
        );
        return (int) ($statement !== false ? $statement->fetchColumn() : 0) === 2;
    } catch (Throwable) {
        return false;
    }
}

function app_ticket_resolution_table_exists(PDO $db, string $table): bool
{
    try {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);
        return (int) $statement->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function app_ticket_resolution_token_payload(string $token): ?array
{
    $secret = app_environment_secret('PORTAL_TICKET_CLOSE_SECRET');
    if ($secret === '') {
        return null;
    }

    return app_portal_verify_token($token, $secret, [
        'iss' => app_config('portal_token_issuer', 'portal.superoptic.net'),
        'aud' => app_config('portal_ticket_close_audience', 'portal.ticket.close'),
        'type' => 'ticket-close',
    ]);
}

function app_ticket_resolution_actor_allowed(array $ticket, array $payload, array $actor): bool
{
    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId <= 0 || (string) ($actor['role'] ?? '') !== 'employee') {
        return false;
    }
    if (app_portal_is_admin($actor)) {
        return true;
    }

    $assignedId = (int) ($ticket['assigned_technician_account_id'] ?? 0);
    $tokenTechnicianId = (int) ($payload['tech_id'] ?? 0);
    return $assignedId > 0 && $actorId === $assignedId && $tokenTechnicianId === $assignedId;
}

/** @return array{optical:bool,signal:bool,temperature:bool,radius:bool} */
function app_ticket_resolution_requirements(array $ticket): array
{
    $metadata = app_db_json_decode((string) ($ticket['metadata_json'] ?? ''), []);
    $fingerprint = mb_strtolower(trim(implode(' ', [
        (string) ($ticket['subject'] ?? ''),
        (string) ($ticket['message'] ?? ''),
        app_db_json_encode(is_array($metadata) ? $metadata : []),
    ])), 'UTF-8');

    $temperature = preg_match('/temperatur|temperature|overheat|q[\x{0131}i]zma|isinma|istilik/u', $fingerprint) === 1;
    $signal = preg_match('/optik|optical|signal|rx[_ -]?power|tx[_ -]?power|los|z[\x{0259}e]if|y[\x{00FC}u]ks[\x{0259}e]k/u', $fingerprint) === 1;
    $optical = $temperature || $signal || preg_match('/\b(?:ont|onu|gpon)\b/u', $fingerprint) === 1;
    $radius = $optical || preg_match(
        '/internet|ba[\x{011F}g]lant|connection|modem|wi-?fi|pppoe|radius|s[\x{00FC}u]r[\x{0259}e]t|speed|ip\s+alm/u',
        $fingerprint
    ) === 1;

    return [
        'optical' => $optical,
        'signal' => $signal,
        'temperature' => $temperature,
        'radius' => $radius,
    ];
}

/** @return array{required:bool,available:bool,passed:bool,reason:string,evidence:array<string,mixed>} */
function app_ticket_resolution_evaluate_optical(array $reply, array $requirements): array
{
    $live = is_array($reply['live'] ?? null) ? $reply['live'] : [];
    $liveOnt = is_array($live['ont'] ?? null) ? $live['ont'] : [];
    $liveAvailable = !empty($reply['ok']) && $live !== [] && $liveOnt !== [];
    $thresholds = function_exists('app_mgs_olt_rule_thresholds') ? app_mgs_olt_rule_thresholds() : [];
    $findings = $liveAvailable && $thresholds !== [] && function_exists('app_mgs_ont_signal_findings')
        ? app_mgs_ont_signal_findings($liveOnt, $thresholds)
        : [];
    $ontState = mb_strtolower(trim((string) ($liveOnt['run_state'] ?? $liveOnt['status'] ?? '')), 'UTF-8');
    $downStates = ['down', 'offline', 'los', 'dying-gasp', 'failed', 'inactive'];
    if ($ontState !== '' && in_array($ontState, $downStates, true)) {
        $findings[] = 'ONT state ' . $ontState;
    }

    $number = static function (mixed $value): ?float {
        return function_exists('app_mgs_signal_number') ? app_mgs_signal_number($value) : null;
    };
    $temperaturePresent = $number($liveOnt['temperature'] ?? null) !== null;
    $signalPresent = $number($liveOnt['ont_rx'] ?? $liveOnt['rx_power'] ?? null) !== null
        || $number($liveOnt['olt_rx'] ?? $liveOnt['olt_rx_power'] ?? null) !== null;
    $missing = [];
    if ($liveAvailable && $ontState === '') {
        $missing[] = 'ont_state';
    }
    if ($liveAvailable && !empty($requirements['temperature']) && !$temperaturePresent) {
        $missing[] = 'temperature';
    }
    if ($liveAvailable && !empty($requirements['signal']) && !$signalPresent) {
        $missing[] = 'rx_signal';
    }
    $measurementsComplete = $missing === [];
    $available = $liveAvailable && $measurementsComplete;

    return [
        'required' => true,
        'available' => $available,
        'passed' => $available && $findings === [],
        'reason' => !$liveAvailable
            ? ('live_ont_probe_required:' . (string) ($reply['live_reason'] ?? $reply['reason'] ?? 'unavailable'))
            : (!$measurementsComplete
                ? 'live_ont_measurement_incomplete:' . implode(',', $missing)
                : ($findings === [] ? 'live_ont_metrics_normal' : 'live_ont_threshold_failed')),
        'evidence' => [
            'device_key' => (string) ($live['device_key'] ?? ''),
            'pon' => (string) ($live['pon'] ?? ''),
            'ont_id' => (string) ($live['ont_id'] ?? ''),
            'ont_status' => (string) ($liveOnt['status'] ?? ''),
            'run_state' => (string) ($liveOnt['run_state'] ?? ''),
            'temperature' => (string) ($liveOnt['temperature'] ?? ''),
            'ont_rx' => (string) ($liveOnt['ont_rx'] ?? ''),
            'ont_tx' => (string) ($liveOnt['ont_tx'] ?? ''),
            'olt_rx' => (string) ($liveOnt['olt_rx'] ?? ''),
            'olt_tx' => (string) ($liveOnt['olt_tx'] ?? ''),
            'thresholds' => $thresholds,
            'findings' => array_values(array_unique($findings)),
            'missing_measurements' => $missing,
            'measured_live' => $liveAvailable,
        ],
    ];
}

/** @return array{method:string,available:bool,passed:bool,reason:string,evidence:array<string,mixed>} */
function app_ticket_resolution_probe(PDO $db, array $ticket, array $actor, ?callable $liveProbe = null): array
{
    $metadata = app_db_json_decode((string) ($ticket['metadata_json'] ?? ''), []);
    $requirements = app_ticket_resolution_requirements($ticket);
    $requiresOptical = !empty($requirements['optical']);
    $requiresRadius = !empty($requirements['radius']);
    if (!$requiresOptical && !$requiresRadius) {
        return [
            'method' => 'manual_review',
            'available' => false,
            'passed' => false,
            'reason' => 'automatic_probe_not_applicable',
            'evidence' => ['subject' => (string) ($ticket['subject'] ?? '')],
        ];
    }

    $components = [];
    $accountId = (int) ($ticket['customer_account_id'] ?? 0);

    if ($requiresRadius) {
        $radius = [
            'required' => true,
            'available' => false,
            'passed' => false,
            'reason' => 'radius_schema_unavailable',
            'evidence' => ['account_id' => $accountId],
        ];
        if ($accountId > 0
            && app_ticket_resolution_table_exists($db, 'billing_radius_services')
            && app_ticket_resolution_table_exists($db, 'radacct')) {
            $serviceStatement = $db->prepare(
                'SELECT username, status, expires_at
                   FROM billing_radius_services
                  WHERE account_id = :account_id
                  ORDER BY (status = "active") DESC, id DESC
                  LIMIT 1'
            );
            $serviceStatement->execute(['account_id' => $accountId]);
            $service = $serviceStatement->fetch(PDO::FETCH_ASSOC);
            if (is_array($service) && trim((string) ($service['username'] ?? '')) !== '') {
                $serviceStatus = strtolower(trim((string) ($service['status'] ?? '')));
                $expiresAt = trim((string) ($service['expires_at'] ?? ''));
                $serviceActive = $serviceStatus === 'active'
                    && ($expiresAt === '' || strtotime($expiresAt) === false || strtotime($expiresAt) >= time());
                $sessionStatement = $db->prepare(
                    'SELECT framedipaddress, nasipaddress,
                            COALESCE(acctupdatetime, acctstarttime) AS last_activity_at
                       FROM radacct
                      WHERE username = :username AND acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)
                      ORDER BY COALESCE(acctupdatetime, acctstarttime) DESC, radacctid DESC
                      LIMIT 1'
                );
                $sessionStatement->execute(['username' => (string) $service['username']]);
                $session = $sessionStatement->fetch(PDO::FETCH_ASSOC);
                $online = is_array($session);
                $radius = [
                    'required' => true,
                    'available' => true,
                    'passed' => $serviceActive && $online,
                    'reason' => $serviceActive && $online
                        ? 'radius_service_active_and_online'
                        : (!$serviceActive ? 'radius_service_inactive' : 'radius_session_offline'),
                    'evidence' => [
                        'account_id' => $accountId,
                        'service_status' => $serviceStatus,
                        'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                        'online' => $online,
                        'framed_ip' => is_array($session) ? (string) ($session['framedipaddress'] ?? '') : '',
                        'nas_ip' => is_array($session) ? (string) ($session['nasipaddress'] ?? '') : '',
                        'last_activity_at' => is_array($session) ? (string) ($session['last_activity_at'] ?? '') : '',
                    ],
                ];
            } else {
                $radius['reason'] = $accountId > 0 ? 'radius_service_not_found' : 'customer_account_not_linked';
            }
        } elseif ($accountId <= 0) {
            $radius['reason'] = 'customer_account_not_linked';
        }
        $components['radius'] = $radius;
    }

    if ($requiresOptical) {
        $probeActor = $actor;
        $probeActor['ticket_resolution_scope'] = true;
        $probeActor['responsible_areas'] = array_values(array_unique(array_filter(array_merge(
            (array) ($actor['responsible_areas'] ?? $actor['areas'] ?? []),
            [(string) ($ticket['area'] ?? '')]
        ))));
        $query = trim((string) ($ticket['subscriber_code'] ?? ''));
        if ($query === '') {
            $query = trim((string) ($metadata['ont_serial'] ?? $metadata['serial'] ?? $ticket['phone'] ?? ''));
        }
        $reply = $query === ''
            ? ['ok' => false, 'reason' => 'ont_identity_missing']
            : ($liveProbe !== null
                ? $liveProbe($query, $ticket, $probeActor, $db)
                : (function_exists('app_mgs_build_modem_search_reply')
                    ? app_mgs_build_modem_search_reply($query, true, $db, $probeActor)
                    : ['ok' => false, 'reason' => 'ont_probe_service_missing']));
        $components['optical'] = app_ticket_resolution_evaluate_optical(
            is_array($reply) ? $reply : ['ok' => false, 'reason' => 'invalid_probe_response'],
            $requirements
        );
        $components['optical']['evidence']['query_type'] = (string) ($ticket['subscriber_code'] ?? '') !== ''
            ? 'subscriber_code'
            : 'ont_identity';
    }

    $unavailable = [];
    $failed = [];
    foreach ($components as $name => $component) {
        if (empty($component['available'])) {
            $unavailable[] = $name . ':' . (string) ($component['reason'] ?? 'unavailable');
        } elseif (empty($component['passed'])) {
            $failed[] = $name . ':' . (string) ($component['reason'] ?? 'failed');
        }
    }
    $available = $unavailable === [];
    $passed = $available && $failed === [];
    return [
        'method' => implode('+', array_keys($components)),
        'available' => $available,
        'passed' => $passed,
        'reason' => $passed
            ? 'all_required_probes_passed'
            : implode(';', $unavailable !== [] ? $unavailable : $failed),
        'evidence' => [
            'required' => $requirements,
            'components' => $components,
        ],
    ];
}

function app_ticket_resolution_queue_transition(string $ticketNo, string $status, string $reason): void
{
    if (!function_exists('app_notification_route_event')) {
        return;
    }
    $resolved = $status === 'verified_closed';
    app_notification_route_event([
        'category' => 'CUSTOMER_TECHNICAL_SUPPORT',
        'event_type' => 'customer_ticket_resolution_verification',
        'source' => 'ticket-resolution',
        'state' => $resolved ? 'resolved' : 'open',
        'title' => $resolved ? 'Bilet yoxlanildi ve baglandi' : 'Bilet baglanmadi, yeniden yoxlanmalidir',
        'message' => 'Bilet ' . $ticketNo . ': ' . $reason,
        'object_type' => 'ticket',
        'object_id' => $ticketNo,
        'dedup_key' => 'ticket-resolution:' . $ticketNo,
        'metadata' => [
            'ticket_no' => $ticketNo,
            'verification_status' => $status,
            'reason' => $reason,
        ],
    ], false, true);
}

/** @return array{reason_code:string,event_type:string,points_delta:int,title:string,idempotency_suffix:string}|null */
function app_ticket_resolution_score_decision(string $reportedState, array $result, array $probe): ?array
{
    $probeVerified = !empty($result['closed'])
        && !empty($probe['available'])
        && !empty($probe['passed'])
        && (string) ($probe['method'] ?? '') !== 'manual_override';
    if ($probeVerified) {
        return [
            'reason_code' => 'ticket_verified',
            'event_type' => 'reward',
            'points_delta' => 5,
            'title' => 'Bilet telemetriya ile tesdiqlendi',
            'idempotency_suffix' => 'verified',
        ];
    }

    $provenFalseClosure = $reportedState === 'resolved'
        && (string) ($result['status'] ?? '') === 'verification_failed'
        && !empty($probe['available'])
        && empty($probe['passed']);
    if ($provenFalseClosure) {
        return [
            'reason_code' => 'ticket_verification_failed',
            'event_type' => 'penalty',
            'points_delta' => -5,
            'title' => 'Hell olunmamis bilet baglanmaga gonderildi',
            'idempotency_suffix' => 'false-close',
        ];
    }

    return null;
}

/** @return array{ok:bool,status:string,reason:string,closed:bool,existing?:bool,evidence?:array<string,mixed>} */
function app_ticket_resolution_request(
    string $token,
    string $reason,
    string $note,
    string $reportedState,
    string $verificationMode,
    array $actor,
    string $requestId,
    array $options = []
): array {
    $db = ($options['db'] ?? null) instanceof PDO ? $options['db'] : app_db();
    $payload = app_ticket_resolution_token_payload($token);
    $reason = clean_text($reason);
    $note = mb_substr(clean_text($note), 0, 1000);
    $reportedState = in_array($reportedState, ['resolved', 'unresolved'], true) ? $reportedState : '';
    $verificationMode = in_array($verificationMode, ['auto', 'manual_override'], true) ? $verificationMode : 'auto';
    $requestId = strtolower(trim($requestId));
    $probeRunner = is_callable($options['probe_runner'] ?? null) ? $options['probe_runner'] : null;
    $dispatchNotifications = !array_key_exists('dispatch_notifications', $options) || !empty($options['dispatch_notifications']);
    $deleteTelegramMessages = !array_key_exists('delete_telegram_messages', $options) || !empty($options['delete_telegram_messages']);
    $recordOutcomeSideEffects = !array_key_exists('record_outcome_side_effects', $options) || !empty($options['record_outcome_side_effects']);

    if (!$db instanceof PDO || !app_ticket_resolution_schema_ready($db)) {
        return ['ok' => false, 'status' => 'schema_not_ready', 'reason' => 'ticket_resolution_schema_not_migrated', 'closed' => false];
    }
    if (!is_array($payload)) {
        return ['ok' => false, 'status' => 'invalid_token', 'reason' => 'ticket_close_token_invalid', 'closed' => false];
    }
    $ticketNo = clean_text((string) ($payload['ticket_id'] ?? ''));
    $jti = clean_text((string) ($payload['jti'] ?? ''));
    if ($ticketNo === '' || $jti === '' || $reportedState === ''
        || preg_match('/^[a-f0-9-]{16,64}$/D', $requestId) !== 1
        || !in_array($reason, app_portal_ticket_close_reasons(), true) || $note === '') {
        return ['ok' => false, 'status' => 'invalid_request', 'reason' => 'resolution_details_required', 'closed' => false];
    }

    $idempotencyKey = mb_substr('ticket-close:' . $ticketNo . ':' . $jti . ':' . $requestId, 0, 191);
    $correlationId = 'ticket-' . substr(hash('sha256', $idempotencyKey), 0, 32);
    $actorName = clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'employee'));
    $telegramMessageIds = [];
    $ticket = [];

    try {
        $db->beginTransaction();
        $ticketStatement = $db->prepare('SELECT * FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1 FOR UPDATE');
        $ticketStatement->execute(['ticket_no' => $ticketNo]);
        $ticket = $ticketStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ticket)) {
            $db->rollBack();
            return ['ok' => false, 'status' => 'not_found', 'reason' => 'ticket_not_found', 'closed' => false];
        }
        if (!app_ticket_resolution_actor_allowed($ticket, $payload, $actor)) {
            $db->rollBack();
            return ['ok' => false, 'status' => 'forbidden', 'reason' => 'ticket_not_assigned_to_actor', 'closed' => false];
        }

        $existingStatement = $db->prepare(
            'SELECT check_status, evidence_json
               FROM ticket_resolution_checks
              WHERE idempotency_key = :idempotency_key
              LIMIT 1'
        );
        $existingStatement->execute(['idempotency_key' => $idempotencyKey]);
        $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            $db->rollBack();
            $existingStatus = (string) ($existing['check_status'] ?? 'verification_processing');
            return [
                'ok' => true,
                'status' => $existingStatus,
                'reason' => 'duplicate_resolution_request',
                'closed' => $existingStatus === 'verified_closed',
                'existing' => true,
                'evidence' => app_db_json_decode((string) ($existing['evidence_json'] ?? ''), []),
            ];
        }
        if ((string) ($ticket['status'] ?? '') === 'Baglandi') {
            $db->rollBack();
            return ['ok' => true, 'status' => 'already_closed', 'reason' => 'ticket_already_closed', 'closed' => true];
        }

        $processingStatement = $db->prepare(
            'SELECT id
               FROM ticket_resolution_checks
              WHERE ticket_id = :ticket_id
                AND check_status = "verification_processing"
                AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
              LIMIT 1'
        );
        $processingStatement->execute(['ticket_id' => (int) $ticket['id']]);
        if ((int) ($processingStatement->fetchColumn() ?: 0) > 0) {
            $db->rollBack();
            return ['ok' => true, 'status' => 'verification_processing', 'reason' => 'ticket_verification_in_progress', 'closed' => false, 'existing' => true];
        }
        $expireStale = $db->prepare(
            'UPDATE ticket_resolution_checks
                SET check_status = "manual_review_required", check_method = "probe_timeout"
              WHERE ticket_id = :ticket_id
                AND check_status = "verification_processing"
                AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
        );
        $expireStale->execute(['ticket_id' => (int) $ticket['id']]);

        $claimEvidence = [
            'reported_state' => $reportedState,
            'verification_mode' => $verificationMode,
            'actor_account_id' => (int) ($actor['id'] ?? 0),
            'requested_at' => app_db_now(),
        ];
        $checkInsert = $db->prepare(
            'INSERT INTO ticket_resolution_checks (
                ticket_id,ticket_no,check_status,check_method,evidence_json,reason,note,
                correlation_id,idempotency_key,actor_account_id,actor_name,warning_dedup_key
             ) VALUES (
                :ticket_id,:ticket_no,"verification_processing","pending",:evidence_json,:reason,:note,
                :correlation_id,:idempotency_key,:actor_account_id,:actor_name,:warning_dedup_key
             )'
        );
        $checkInsert->execute([
            'ticket_id' => (int) $ticket['id'],
            'ticket_no' => $ticketNo,
            'evidence_json' => app_db_json_encode($claimEvidence),
            'reason' => mb_substr($reason, 0, 255),
            'note' => $note,
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
            'actor_account_id' => (int) ($actor['id'] ?? 0) ?: null,
            'actor_name' => $actorName,
            'warning_dedup_key' => 'ticket-resolution:' . $ticketNo,
        ]);
        $checkId = (int) $db->lastInsertId();
        $claimUpdate = $db->prepare(
            'UPDATE portal_tickets
                SET updated_at = NOW(), resolution_reason = :resolution_reason,
                    resolution_note = :resolution_note,
                    resolution_verification_status = "verification_processing",
                    close_requested_at = NOW(), verified_at = NULL,
                    verification_attempts = verification_attempts + 1
              WHERE id = :id'
        );
        $claimUpdate->execute([
            'resolution_reason' => $reason,
            'resolution_note' => $note,
            'id' => (int) $ticket['id'],
        ]);
        $db->commit();
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'status' => 'failed', 'reason' => 'ticket_resolution_claim_failed', 'closed' => false];
    }

    $requirements = app_ticket_resolution_requirements($ticket);
    if ($reportedState === 'unresolved') {
        $probe = [
            'method' => 'employee_report',
            'available' => true,
            'passed' => false,
            'reason' => 'employee_reported_unresolved',
            'evidence' => ['requirements' => $requirements],
        ];
    } else {
        try {
            $probe = $probeRunner !== null
                ? $probeRunner($db, $ticket, $actor)
                : app_ticket_resolution_probe($db, $ticket, $actor);
        } catch (Throwable) {
            $probe = [
                'method' => 'probe_exception',
                'available' => false,
                'passed' => false,
                'reason' => 'automatic_probe_failed_safely',
                'evidence' => [],
            ];
        }
        if (!is_array($probe)) {
            $probe = [
                'method' => 'invalid_probe',
                'available' => false,
                'passed' => false,
                'reason' => 'automatic_probe_invalid_response',
                'evidence' => [],
            ];
        }
    }

    $isAdmin = app_portal_is_admin($actor);
    if ($reportedState === 'unresolved') {
        $finalStatus = 'verification_failed';
        $finalReason = 'employee_reported_unresolved';
    } elseif ($isAdmin) {
        // Admin bağlama qərarını öz mühakiməsinə əsasən verir — avtomatik probun mövcud olub-olmaması
        // (məs. demo/manual bilet, real müştəri hesabına/ONT-ə bağlı olmayan bilet) admin-in bağlama
        // ixtiyarını bloklamamalıdır. Əvvəllər bu yalnız şəbəkə yoxlaması TƏLƏB OLUNMAYAN biletlərdə
        // işləyirdi (!$networkVerificationRequired) — məhz ən çox rast gəlinən internet/şəbəkə
        // biletlərində admin override-ı sükutla rədd edib "manual_review_required"-də saxlayırdı.
        $finalStatus = 'verified_closed';
        $finalReason = 'admin_manual_override';
        $probe = [
            'method' => 'manual_override',
            'available' => true,
            'passed' => true,
            'reason' => $finalReason,
            'evidence' => ['approved_by_account_id' => (int) ($actor['id'] ?? 0)],
        ];
    } elseif (!empty($probe['available'])) {
        $finalStatus = !empty($probe['passed']) ? 'verified_closed' : 'verification_failed';
        $finalReason = (string) ($probe['reason'] ?? 'probe_failed');
    } else {
        $finalStatus = 'manual_review_required';
        $finalReason = (string) ($probe['reason'] ?? 'automatic_probe_unavailable');
    }

    $evidence = [
        'reported_state' => $reportedState,
        'verification_mode' => $verificationMode,
        'requirements' => $requirements,
        'probe' => $probe,
        'actor_account_id' => (int) ($actor['id'] ?? 0),
        'checked_at' => app_db_now(),
    ];
    $telegramMessageIds = app_db_json_decode((string) ($ticket['telegram_message_ids_json'] ?? ''), []);

    try {
        $db->beginTransaction();
        $checkStatement = $db->prepare(
            'SELECT check_status
               FROM ticket_resolution_checks
              WHERE id = :id AND idempotency_key = :idempotency_key
              LIMIT 1 FOR UPDATE'
        );
        $checkStatement->execute(['id' => $checkId, 'idempotency_key' => $idempotencyKey]);
        $currentCheckStatus = (string) ($checkStatement->fetchColumn() ?: '');
        if ($currentCheckStatus !== 'verification_processing') {
            $db->rollBack();
            return [
                'ok' => true,
                'status' => $currentCheckStatus !== '' ? $currentCheckStatus : 'verification_processing',
                'reason' => 'duplicate_resolution_request',
                'closed' => $currentCheckStatus === 'verified_closed',
                'existing' => true,
            ];
        }
        $ticketStatement = $db->prepare('SELECT * FROM portal_tickets WHERE id = :id LIMIT 1 FOR UPDATE');
        $ticketStatement->execute(['id' => (int) $ticket['id']]);
        $lockedTicket = $ticketStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($lockedTicket) || !app_ticket_resolution_actor_allowed($lockedTicket, $payload, $actor)) {
            $db->rollBack();
            return ['ok' => false, 'status' => 'forbidden', 'reason' => 'ticket_scope_changed', 'closed' => false];
        }

        $checkUpdate = $db->prepare(
            'UPDATE ticket_resolution_checks
                SET check_status = :check_status, check_method = :check_method,
                    evidence_json = :evidence_json, warning_dedup_key = :warning_dedup_key
              WHERE id = :id'
        );
        $checkUpdate->execute([
            'check_status' => $finalStatus,
            'check_method' => mb_substr((string) ($probe['method'] ?? 'manual_review'), 0, 32),
            'evidence_json' => app_db_json_encode($evidence),
            'warning_dedup_key' => $finalStatus === 'verified_closed' ? null : ('ticket-resolution:' . $ticketNo),
            'id' => $checkId,
        ]);

        if ($finalStatus === 'verified_closed') {
            $update = $db->prepare(
                'UPDATE portal_tickets
                    SET status = "Baglandi", updated_at = NOW(), resolved_at = NOW(),
                        resolution_reason = :resolution_reason, resolution_note = :resolution_note,
                        handled_by = :handled_by, resolution_verification_status = :verification_status,
                        resolution_check_json = :evidence_json, verified_at = NOW()
                  WHERE id = :id'
            );
            $update->execute([
                'resolution_reason' => $reason,
                'resolution_note' => $note,
                'handled_by' => $actorName,
                'verification_status' => $finalStatus,
                'evidence_json' => app_db_json_encode($evidence),
                'id' => (int) $ticket['id'],
            ]);
        } else {
            $update = $db->prepare(
                'UPDATE portal_tickets
                    SET updated_at = NOW(), resolution_reason = :resolution_reason,
                        resolution_note = :resolution_note,
                        resolution_verification_status = :verification_status,
                        resolution_check_json = :evidence_json, verified_at = NULL
                  WHERE id = :id'
            );
            $update->execute([
                'resolution_reason' => $reason,
                'resolution_note' => $note,
                'verification_status' => $finalStatus,
                'evidence_json' => app_db_json_encode($evidence),
                'id' => (int) $ticket['id'],
            ]);
        }

        $eventType = match ($finalStatus) {
            'verified_closed' => 'resolution_verified',
            'manual_review_required' => 'resolution_review_required',
            default => 'resolution_check_failed',
        };
        $event = $db->prepare(
            'INSERT INTO portal_ticket_events (ticket_id,event_type,event_note,actor_account_id,actor_name,payload_json)
             VALUES (:ticket_id,:event_type,:event_note,:actor_account_id,:actor_name,:payload_json)'
        );
        $event->execute([
            'ticket_id' => (int) $ticket['id'],
            'event_type' => $eventType,
            'event_note' => mb_substr($note, 0, 255),
            'actor_account_id' => (int) ($actor['id'] ?? 0) ?: null,
            'actor_name' => $actorName,
            'payload_json' => app_db_json_encode([
                'verification_status' => $finalStatus,
                'verification_reason' => $finalReason,
                'correlation_id' => $correlationId,
            ]),
        ]);
        $db->commit();
    } catch (Throwable) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'status' => 'failed', 'reason' => 'ticket_resolution_finalize_failed', 'closed' => false];
    }

    $result = [
        'ok' => true,
        'status' => $finalStatus,
        'reason' => $finalReason,
        'closed' => $finalStatus === 'verified_closed',
        'evidence' => $evidence,
        'correlation_id' => $correlationId,
    ];
    if (!$recordOutcomeSideEffects) {
        $result['score_decision'] = app_ticket_resolution_score_decision($reportedState, $result, $probe);
        return $result;
    }

    app_mgs_record_operation_action([
        'action_type' => 'ticket_resolution_verified',
        'entity_type' => 'ticket',
        'entity_id' => $ticketNo,
        'status' => !empty($result['closed']) ? 'executed' : 'failed',
        'actor' => $actor,
        'summary' => 'Ticket resolution status: ' . (string) $result['status'],
        'ticket_no' => $ticketNo,
        'payload' => [
            'verification_status' => (string) $result['status'],
            'verification_reason' => (string) $result['reason'],
            'correlation_id' => $correlationId,
        ],
    ]);
    if ($dispatchNotifications) {
        app_ticket_resolution_queue_transition($ticketNo, (string) $result['status'], (string) $result['reason']);
    }

    $scoreResult = null;
    $scoreDecision = app_ticket_resolution_score_decision($reportedState, $result, $probe);
    if ((int) ($actor['id'] ?? 0) > 0 && is_array($scoreDecision)) {
        $scoreResult = app_mgs_record_hr_score_event([
            'employee_account_id' => (int) $actor['id'],
            'reason_code' => (string) $scoreDecision['reason_code'],
            'event_type' => (string) $scoreDecision['event_type'],
            'points_delta' => (int) $scoreDecision['points_delta'],
            'title' => (string) $scoreDecision['title'],
            'reason' => 'Ticket ' . $ticketNo . ': ' . (string) $result['reason'],
            'note' => $note,
            'source_type' => 'ticket_resolution',
            'source_id' => $ticketNo,
            'idempotency_key' => 'ticket-score:' . $ticketNo . ':' . (string) $scoreDecision['idempotency_suffix'],
        ], $actor, $db);
        $result['score_event'] = [
            'ok' => !empty($scoreResult['ok']),
            'event_id' => (int) ($scoreResult['id'] ?? 0),
            'existing' => !empty($scoreResult['existing']),
        ];
    }

    if (!empty($result['closed'])) {
        if ($deleteTelegramMessages) {
            foreach ($telegramMessageIds as $messageId) {
                app_telegram_delete_message((int) $messageId, 'customer');
            }
        }
        $closedTicket = app_db_ticket_find($ticketNo);
        if (is_array($closedTicket)) {
            app_mgs_record_ticket_close($closedTicket, $reason, $note, $actor);
        }
        app_mgs_rebuild_employee_work_snapshots();
    }

    return $result;
}
