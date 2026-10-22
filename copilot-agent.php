<?php
declare(strict_types=1);

require_once __DIR__ . '/copilot-engine.php';
require_once __DIR__ . '/ai-provider-lib.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/security-anomaly-lib.php';
require_once __DIR__ . '/customer-intelligence-lib.php';
require_once __DIR__ . '/agent-workspace-lib.php';
require_once __DIR__ . '/advisor-agent-lib.php';
require_once __DIR__ . '/ai-local-lib.php';

function app_copilot_agent_norm(string $value): string
{
    $value = app_copilot_normalize_text($value);
    $value = strtr($value, [
        'ə' => 'e', 'ı' => 'i', 'ö' => 'o', 'ü' => 'u', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c',
        'Ə' => 'e', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ü' => 'u', 'Ğ' => 'g', 'Ş' => 's', 'Ç' => 'c',
        'ə' => 'e', 'ı' => 'i', 'ö' => 'o', 'ü' => 'u', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c',
        'Ə' => 'e', 'I' => 'i', 'Ö' => 'o', 'Ü' => 'u', 'Ğ' => 'g', 'Ş' => 's', 'Ç' => 'c',
    ]);
    $map = ['ə' => 'e', 'ı' => 'i', 'ö' => 'o', 'ü' => 'u', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c'];
    $map += [
        'ə' => 'e', 'ı' => 'i', 'ö' => 'o', 'ü' => 'u', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c',
    ];
    $value = mb_strtolower(strtr($value, $map), 'UTF-8');
    // Drop combining diacritical marks (e.g. the dot above from İ -> i̇).
    $value = preg_replace('/[\x{0300}-\x{036f}]/u', '', $value) ?? $value;
    return trim($value);
}

function app_copilot_agent_has_any(string $text, array $needles): bool
{
    $text = app_copilot_agent_norm($text);
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($text, app_copilot_agent_norm((string) $needle))) {
            return true;
        }
    }

    return false;
}

function app_copilot_agent_actor_from_telegram_message(array $message): array
{
    $from = is_array($message['from'] ?? null) ? $message['from'] : [];
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $name = trim(implode(' ', array_filter([
        (string) ($from['first_name'] ?? ''),
        (string) ($from['last_name'] ?? ''),
    ])));
    if ($name === '') {
        $name = (string) ($from['username'] ?? 'telegram_user');
    }

    return [
        'id' => 0,
        'full_name' => $name,
        'telegram_user_id' => (string) ($from['id'] ?? ''),
        'telegram_username' => (string) ($from['username'] ?? ''),
        'telegram_chat_id' => (string) ($chat['id'] ?? ''),
        'source' => 'telegram_modem_bot',
    ];
}

function app_copilot_agent_actor_account(array $actor, ?PDO $db = null): ?array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return null;
    }

    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId > 0) {
        $account = app_db_account_by_id($actorId);
        return is_array($account) && (string) ($account['role'] ?? '') === 'employee' ? $account : null;
    }

    $chatId = trim((string) ($actor['telegram_chat_id'] ?? ''));
    if ($chatId !== '') {
        $statement = $db->prepare(
            'SELECT *
             FROM portal_accounts
             WHERE role = "employee"
               AND is_active = 1
               AND payload_json LIKE :chat_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['chat_id' => '%' . $chatId . '%']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    return null;
}

function app_copilot_agent_action_allowed(string $kind, array $actor, ?PDO $db = null): array
{
    $account = app_copilot_agent_actor_account($actor, $db);
    if ($account === null) {
        return ['ok' => false, 'reason' => 'employee_identity_not_bound'];
    }

    $roleText = strtolower(trim(implode(' ', [
        (string) ($account['account_type'] ?? ''),
        (string) ($account['employee_role'] ?? ''),
    ])));
    $allowed = match ($kind) {
        'create_ticket', 'send_customer_message' => ['admin', 'operator', 'support', 'billing', 'technician', 'noc'],
        'radius_refresh_disconnect', 'ont_restart_request', 'ont_control_request' => ['admin', 'operator', 'noc', 'network', 'gpon', 'engineer', 'technician'],
        'inventory_movement' => ['admin', 'inventory', 'warehouse', 'anbar', 'technician', 'operator'],
        default => ['admin'],
    };

    foreach ($allowed as $needle) {
        if ($needle !== '' && str_contains($roleText, $needle)) {
            return ['ok' => true, 'account' => $account];
        }
    }

    return ['ok' => false, 'reason' => 'action_not_permitted_for_role', 'role' => $roleText];
}

function app_copilot_agent_pending_action(array $payload, array $actor, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable'];
    }

    $kind = clean_text((string) ($payload['kind'] ?? 'operation'));
    $permission = app_copilot_agent_action_allowed($kind, $actor, $db);
    if (empty($permission['ok'])) {
        return [
            'ok' => false,
            'reason' => (string) ($permission['reason'] ?? 'action_not_permitted'),
            'text' => 'Bu Superpilot emeliyyati ucun rol icazesi yoxdur: ' . $kind,
        ];
    }

    // Stage 5 canonical gate (audit 10.1/10.2): allowlist, go-live blocker,
    // module permission, prompt-injection and duplicate-retry guard. Runs on
    // the resolved employee account for defense-in-depth alongside the role
    // check above.
    $gateActor = is_array($permission['account'] ?? null) ? $permission['account'] : $actor;
    $prepared = app_agent_prepare_action($kind, $payload, $gateActor, $db);
    if (empty($prepared['ok'])) {
        $reason = (string) ($prepared['reason'] ?? 'agent_gate_denied');
        $text = match ($reason) {
            'go_live_blocker_not_agent_writable' => 'Bu əməliyyat go-live blocker-dir və agentə yazma üçün açıq deyil: ' . $kind,
            'prompt_injection_blocked' => 'Girişdə təhlükəsizlik pozuntusu aşkarlandı; əməliyyat bloklandı.',
            'duplicate_action' => 'Eyni əməliyyat artıq mövcuddur: #' . (int) ($prepared['duplicate_id'] ?? 0),
            'module_permission_denied' => 'Bu modul üçün service icazəniz yoxdur: ' . $kind,
            'action_not_in_allowlist' => 'Bu əməliyyat agent allowlist-ində deyil: ' . $kind,
            default => 'Agent gate imtina etdi: ' . $reason,
        };
        return ['ok' => false, 'reason' => $reason, 'text' => $text, 'duplicate_id' => (int) ($prepared['duplicate_id'] ?? 0)];
    }
    $payload['agent_preview'] = $prepared['preview'] ?? [];
    $entityType = clean_text((string) ($payload['entity_type'] ?? $kind));
    $entityId = clean_text((string) ($payload['entity_id'] ?? ''));
    $summary = mb_substr(clean_text((string) ($payload['summary'] ?? 'Superpilot action')), 0, 255);
    $payload['requested_by'] = [
        'actor_name' => (string) ($actor['full_name'] ?? $actor['name'] ?? 'telegram_user'),
        'telegram_user_id' => (string) ($actor['telegram_user_id'] ?? ''),
        'telegram_username' => (string) ($actor['telegram_username'] ?? ''),
        'telegram_chat_id' => (string) ($actor['telegram_chat_id'] ?? ''),
    ];
    $payload['confirmation_required'] = true;
    $payload['created_by_copilot_at'] = app_db_now();

    $statement = $db->prepare(
        'INSERT INTO mgs_operation_actions (
            action_type, entity_type, entity_id, status, actor_account_id, actor_name, summary, ticket_no, payload_json
         ) VALUES (
            "copilot_action", :entity_type, :entity_id, "pending", :actor_account_id, :actor_name, :summary, :ticket_no, :payload_json
         )'
    );
    $statement->execute([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'actor_account_id' => (int) ($actor['id'] ?? 0) ?: null,
        'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? 'telegram_user')),
        'summary' => $summary,
        'ticket_no' => clean_text((string) ($payload['ticket_no'] ?? '')) ?: null,
        'payload_json' => app_db_json_encode($payload),
    ]);

    $id = (int) $db->lastInsertId();
    return [
        'ok' => true,
        'action_id' => $id,
        'kind' => $kind,
        'text' => "TESDIQ TELEB OLUNUR #{$id}\n{$summary}\nIcra ucun: TESDIQ {$id}\nLegv ucun: LEGV {$id}",
    ];
}

function app_copilot_agent_load_pending_action(int $id, ?PDO $db = null): ?array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return null;
    }

    $statement = $db->prepare('SELECT * FROM mgs_operation_actions WHERE id = :id AND action_type = "copilot_action" LIMIT 1');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function app_copilot_agent_update_action(PDO $db, int $id, string $status, array $payload): void
{
    if (!in_array($status, ['executed', 'failed', 'skipped'], true)) {
        $status = 'failed';
    }
    $statement = $db->prepare(
        'UPDATE mgs_operation_actions
         SET status = :status, payload_json = :payload_json, executed_at = CASE WHEN :executed = 1 THEN NOW() ELSE executed_at END
         WHERE id = :id'
    );
    $statement->execute([
        'status' => $status,
        'payload_json' => app_db_json_encode($payload),
        'executed' => $status === 'executed' ? 1 : 0,
        'id' => $id,
    ]);
}

function app_copilot_agent_confirm_action(int $id, array $actor, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable', 'text' => 'DB hazir deyil.'];
    }

    $row = app_copilot_agent_load_pending_action($id, $db);
    if ($row === null) {
        return ['ok' => false, 'reason' => 'action_not_found', 'text' => 'Action tapilmadi: #' . $id];
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'reason' => 'action_not_pending', 'text' => 'Action artiq pending deyil: #' . $id . ' status=' . (string) ($row['status'] ?? '')];
    }

    $payload = app_db_json_decode((string) ($row['payload_json'] ?? ''), []);
    $requestedBy = is_array($payload['requested_by'] ?? null) ? $payload['requested_by'] : [];
    $requestedTelegramId = (string) ($requestedBy['telegram_user_id'] ?? '');
    $confirmTelegramId = (string) ($actor['telegram_user_id'] ?? '');
    if ($requestedTelegramId !== '' && $confirmTelegramId !== '' && $requestedTelegramId !== $confirmTelegramId) {
        return ['ok' => false, 'reason' => 'confirmation_actor_mismatch', 'text' => 'Bu action yalniz onu yaradan emekdas terefinden tesdiqlene biler.'];
    }

    $permission = app_copilot_agent_action_allowed((string) ($payload['kind'] ?? ''), $actor, $db);
    if (empty($permission['ok'])) {
        return [
            'ok' => false,
            'reason' => (string) ($permission['reason'] ?? 'action_not_permitted'),
            'text' => 'Bu action ucun rol icazesi yoxdur: #' . $id,
        ];
    }

    $result = app_copilot_agent_execute_payload($payload, $actor, $db);
    $payload['confirmed_by'] = [
        'actor_name' => (string) ($actor['full_name'] ?? $actor['name'] ?? 'telegram_user'),
        'telegram_user_id' => $confirmTelegramId,
        'confirmed_at' => app_db_now(),
    ];
    $payload['execution_result'] = $result;
    app_copilot_agent_update_action($db, $id, !empty($result['ok']) ? 'executed' : 'failed', $payload);

    return [
        'ok' => !empty($result['ok']),
        'result' => $result,
        'text' => !empty($result['ok'])
            ? ('ICRA OLUNDU #' . $id . "\n" . (string) ($result['summary'] ?? 'Action tamamlandi.'))
            : ('ICRA XETASI #' . $id . "\n" . (string) ($result['reason'] ?? 'unknown')),
    ];
}

function app_copilot_agent_cancel_action(int $id, array $actor, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'text' => 'DB hazir deyil.'];
    }
    $row = app_copilot_agent_load_pending_action($id, $db);
    if ($row === null || (string) ($row['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'text' => 'Legv edilecek pending action tapilmadi: #' . $id];
    }
    $payload = app_db_json_decode((string) ($row['payload_json'] ?? ''), []);
    $payload['cancelled_by'] = [
        'actor_name' => (string) ($actor['full_name'] ?? $actor['name'] ?? 'telegram_user'),
        'cancelled_at' => app_db_now(),
    ];
    app_copilot_agent_update_action($db, $id, 'skipped', $payload);
    return ['ok' => true, 'text' => 'LEGV EDILDI #' . $id];
}

function app_copilot_agent_execute_payload(array $payload, array $actor, PDO $db): array
{
    $kind = (string) ($payload['kind'] ?? '');
    if ($kind === 'create_customer') {
        return app_copilot_agent_execute_create_customer($payload, $actor, $db);
    }
    if ($kind === 'create_ticket') {
        return app_copilot_agent_execute_create_ticket($payload, $actor, $db);
    }
    if ($kind === 'radius_refresh_disconnect') {
        return app_copilot_agent_execute_radius_refresh($payload, $actor, $db);
    }
    if ($kind === 'send_customer_message') {
        return app_copilot_agent_execute_customer_message($payload, $actor, $db);
    }
    if ($kind === 'ont_restart_request') {
        return app_copilot_agent_execute_ont_restart_request($payload, $actor, $db);
    }
    if ($kind === 'ont_control_request') {
        return app_copilot_agent_execute_ont_control_request($payload, $actor, $db);
    }
    if ($kind === 'inventory_movement') {
        $movement = is_array($payload['movement'] ?? null) ? $payload['movement'] : [];
        $movement['actor_name'] = (string) ($actor['full_name'] ?? $actor['name'] ?? 'superpilot');
        $movement['actor_account_id'] = (int) ($actor['id'] ?? 0);
        $result = app_mgs_record_inventory_movement($movement, true);
        return [
            'ok' => !empty($result['ok']),
            'summary' => 'Inventar hereketi yazildi: ' . (string) ($movement['sku'] ?? '') . ' ' . (string) ($movement['movement_type'] ?? ''),
            'reason' => (string) ($result['reason'] ?? ''),
            'raw' => $result,
        ];
    }

    return ['ok' => false, 'reason' => 'unsupported_action_kind'];
}

function app_copilot_agent_execute_create_customer(array $payload, array $actor, PDO $db): array
{
    if (!function_exists('app_billing_upsert_customer_with_identity')) {
        require_once __DIR__ . '/billing-customer-identity-lib.php';
    }
    $fullName = trim((string) ($payload['full_name'] ?? ''));
    $phone = trim((string) ($payload['phone'] ?? ''));
    $subscriberCode = trim((string) ($payload['subscriber_code'] ?? '')) ?: ('SP-' . date('ymd') . random_int(100, 999));

    $accountPayload = [
        'login' => $subscriberCode,
        'role' => 'customer',
        'full_name' => $fullName,
        'subscriber_code' => $subscriberCode,
        'phone' => $phone,
        'area' => (string) ($payload['area'] ?? ''),
        'address' => (string) ($payload['address'] ?? ''),
        'status' => 'Aktiv',
        'is_active' => 1,
    ];
    $identityInput = [
        'fin_code' => (string) ($payload['fin_code'] ?? ''),
        'document_no' => (string) ($payload['document_no'] ?? ''),
        'phone' => $phone,
        'address' => (string) ($payload['address'] ?? ''),
    ];

    $result = app_billing_upsert_customer_with_identity($db, $accountPayload, $identityInput, $actor);
    if (empty($result['ok'])) {
        $error = (string) ($result['error'] ?? 'customer_create_failed');
        $fieldNote = is_array($result['fields'] ?? null) && $result['fields'] !== []
            ? (' Çatışmayan/yanlış sahə: ' . implode(', ', array_map('strval', $result['fields'])))
            : '';
        return [
            'ok' => false,
            'reason' => $error,
            'summary' => 'Müştəri yaradılmadı: ' . $error . $fieldNote,
        ];
    }
    $account = is_array($result['account'] ?? null) ? $result['account'] : [];
    return [
        'ok' => true,
        'summary' => 'Müştəri yaradıldı: ' . $fullName . ' / ' . (string) ($account['subscriber_code'] ?? $subscriberCode),
        'subscriber_code' => (string) ($account['subscriber_code'] ?? $subscriberCode),
        'account_id' => (int) ($account['id'] ?? 0),
    ];
}

function app_copilot_agent_execute_create_ticket(array $payload, array $actor, PDO $db): array
{
    $accountId = (int) ($payload['customer_account_id'] ?? 0);
    $account = $accountId > 0 ? app_db_account_by_id($accountId) : null;
    if (!is_array($account)) {
        return ['ok' => false, 'reason' => 'customer_not_found'];
    }

    $ticketNo = 'CP-' . date('YmdHis') . random_int(100, 999);
    $ok = app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'customer_account_id' => (int) ($account['id'] ?? 0),
        'subscriber_code' => (string) ($account['subscriber_code'] ?? ''),
        'contract_no' => (string) ($account['contract_no'] ?? $account['subscriber_code'] ?? ''),
        'customer_name' => (string) ($account['full_name'] ?? ''),
        'phone' => (string) ($account['phone'] ?? ''),
        'address' => (string) ($account['address'] ?? ''),
        'area' => (string) ($account['area'] ?? ''),
        'subject' => (string) ($payload['subject'] ?? 'Superpilot bileti'),
        'message' => (string) ($payload['message'] ?? 'Superpilot terefinden yaradildi.'),
        'status' => 'open',
        'priority' => (string) ($payload['priority'] ?? 'normal'),
        'source' => 'superpilot',
        'created_by_name' => (string) ($actor['full_name'] ?? $actor['name'] ?? 'Superpilot'),
    ]);

    return [
        'ok' => $ok,
        'ticket_no' => $ticketNo,
        'summary' => $ok ? ('Bilet yaradildi: ' . $ticketNo) : 'Bilet yaradilmadi.',
        'reason' => $ok ? '' : 'ticket_insert_failed',
    ];
}

function app_copilot_agent_execute_customer_message(array $payload, array $actor, PDO $db): array
{
    $accountId = (int) ($payload['customer_account_id'] ?? 0);
    $account = $accountId > 0 ? app_db_account_by_id($accountId) : null;
    if (!is_array($account)) {
        return ['ok' => false, 'reason' => 'customer_not_found'];
    }

    $channel = strtolower(trim((string) ($payload['channel'] ?? 'sms')));
    $channel = in_array($channel, ['sms', 'telegram', 'whatsapp', 'app', 'auto'], true) ? $channel : 'sms';
    $body = trim((string) ($payload['message_body'] ?? ''));
    if ($body === '') {
        return ['ok' => false, 'reason' => 'empty_message'];
    }

    $ok = app_message_queue_for_account($account, '', $channel, $body, $actor, false);
    return [
        'ok' => $ok,
        'summary' => $ok
            ? (strtoupper($channel) . ' mesaj queue-ya yazildi: ' . (string) ($account['subscriber_code'] ?? ''))
            : 'Mesaj queue-ya yazilmadi.',
        'reason' => $ok ? '' : 'message_queue_failed',
    ];
}

function app_copilot_agent_execute_ont_restart_request(array $payload, array $actor, PDO $db): array
{
    $accountId = (int) ($payload['customer_account_id'] ?? 0);
    $account = $accountId > 0 ? app_db_account_by_id($accountId) : null;
    if (!is_array($account)) {
        return ['ok' => false, 'reason' => 'customer_not_found'];
    }

    $accountPayload = app_db_account_payload($account);
    $ont = is_array($accountPayload['ont'] ?? null) ? $accountPayload['ont'] : [];
    $ok = app_mgs_record_operation_action([
        'action_type' => 'ont_restart_request',
        'entity_type' => 'customer',
        'entity_id' => (string) ($account['subscriber_code'] ?? $account['id'] ?? ''),
        'status' => 'pending',
        'actor' => $actor,
        'summary' => 'ONT/modem restart request queued for ' . (string) ($account['subscriber_code'] ?? ''),
        'payload' => [
            'customer_account_id' => (int) ($account['id'] ?? 0),
            'subscriber_code' => (string) ($account['subscriber_code'] ?? ''),
            'customer_name' => (string) ($account['full_name'] ?? ''),
            'ont' => $ont,
            'requested_by' => $actor,
            'live_apply' => false,
            'safety_note' => 'Canli ONT restart yalniz VLAN/OLT target tesdiqinden sonra ayrica apply edilir.',
        ],
    ]);

    return [
        'ok' => $ok,
        'summary' => $ok
            ? 'ONT/modem restart sorgusu queue-ya yazildi. Canli restart avtomatik icra edilmedi.'
            : 'ONT/modem restart sorgusu yazilmadi.',
        'reason' => $ok ? '' : 'restart_queue_failed',
    ];
}

function app_copilot_agent_execute_ont_control_request(array $payload, array $actor, PDO $db): array
{
    $deviceKey = trim((string) ($payload['device_key'] ?? ''));
    $operation = trim((string) ($payload['operation'] ?? ''));
    $pon = trim((string) ($payload['pon'] ?? ''));
    $ontId = trim((string) ($payload['ont_id'] ?? ''));
    if ($deviceKey === '' || $operation === '' || $pon === '' || $ontId === '') {
        return ['ok' => false, 'reason' => 'missing_ont_control_target'];
    }

    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db, $actor);
    if (!is_array($device)) {
        return ['ok' => false, 'reason' => 'device_profile_not_found'];
    }

    $result = app_mgs_execute_hsgq_ont_control($device, $operation, $pon, $ontId, $actor, $db);
    return [
        'ok' => !empty($result['ok']),
        'summary' => !empty($result['ok'])
            ? ('ONT control icra olundu: ' . $operation . ' PON ' . $pon . ' ONT ' . $ontId . ' / ' . (string) ($device['device_name'] ?? $deviceKey))
            : ('ONT control icra olunmadi: ' . (string) ($result['reason'] ?? 'unknown')),
        'reason' => (string) ($result['reason'] ?? ''),
        'raw' => $result,
    ];
}

function app_copilot_agent_execute_radius_refresh(array $payload, array $actor, PDO $db): array
{
    $accountId = (int) ($payload['customer_account_id'] ?? 0);
    $account = $accountId > 0 ? app_db_account_by_id($accountId) : null;
    if (!is_array($account)) {
        return ['ok' => false, 'reason' => 'customer_not_found'];
    }

    $username = trim((string) ($payload['username'] ?? ''));
    if ($username === '') {
        $accountPayload = app_db_account_payload($account);
        $billing = is_array($accountPayload['billing'] ?? null) ? $accountPayload['billing'] : [];
        $services = is_array($accountPayload['internet_services'] ?? null) ? array_values(array_filter($accountPayload['internet_services'], 'is_array')) : [];
        $username = trim((string) ($services[0]['username'] ?? $billing['pppoe_username'] ?? $account['subscriber_code'] ?? ''));
    }

    $synced = app_billing_sync_radius_account($account);
    $session = ['account_id' => (int) ($account['id'] ?? 0), 'username' => $username];
    if ($username !== '') {
        $statement = $db->prepare(
            'SELECT username, framedipaddress, nasipaddress, nasportid, callingstationid
             FROM radacct
             WHERE username = :username AND acctstoptime IS NULL
             ORDER BY COALESCE(acctupdatetime, acctstarttime) DESC, radacctid DESC
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $latest = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($latest)) {
            $session += $latest;
        }
    }

    $requestId = $username !== ''
        ? app_radius_request_disconnect($db, $session, $actor + ['id' => (int) ($actor['id'] ?? 0)], 'Superpilot confirmed refresh/disconnect')
        : 0;

    return [
        'ok' => $synced && $username !== '',
        'request_id' => $requestId,
        'summary' => $requestId > 0
            ? ('RADIUS sync edildi, disconnect queue #' . $requestId . ' yaradildi.')
            : ('RADIUS sync edildi, online sessiya tapilmadi. Username: ' . ($username !== '' ? $username : '-')),
        'reason' => $synced ? '' : 'radius_sync_failed',
    ];
}

function app_copilot_agent_resolve_employee(string $text, ?PDO $db = null): ?array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return null;
    }
    $norm = app_copilot_agent_norm($text);
    $rows = $db->query('SELECT * FROM portal_accounts WHERE role = "employee" AND is_active = 1 ORDER BY full_name LIMIT 300')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $full = app_copilot_agent_norm((string) ($row['full_name'] ?? ''));
        $login = app_copilot_agent_norm((string) ($row['login'] ?? ''));
        if (($full !== '' && str_contains($norm, $full)) || ($login !== '' && str_contains($norm, $login))) {
            return $row;
        }
        foreach (array_filter(explode(' ', $full)) as $part) {
            if (mb_strlen($part) >= 4 && str_contains($norm, $part)) {
                return $row;
            }
        }
    }

    return null;
}

function app_copilot_agent_inventory_search(string $query, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return [];
    }
    preg_match_all('/[A-Za-z0-9][A-Za-z0-9._:-]{2,63}/', $query, $matches);
    $tokens = array_values(array_unique(array_filter(array_map(
        static fn (string $token): string => trim($token, " \t\n\r\0\x0B.,;!?()[]{}<>\"'"),
        $matches[0] ?? []
    ))));
    $tokens = array_values(array_filter($tokens, static function (string $token): bool {
        $normalized = app_copilot_agent_norm($token);
        return mb_strlen($normalized) >= 3
            && !in_array($normalized, ['kimdedir', 'kimdedir?', 'hansi', 'hansidir', 'mal', 'modem', 'router'], true);
    }));
    if ($tokens === []) {
        $tokens = [$query];
    }
    $needle = '%' . app_copilot_agent_norm((string) $tokens[0]) . '%';
    $statement = $db->prepare(
        'SELECT m.*, i.sku, i.item_name, i.category, i.unit, i.quantity_on_hand,
                tech.full_name AS technician_name, cust.full_name AS customer_name, cust.subscriber_code AS customer_code
         FROM inventory_stock_movements m
         JOIN inventory_stock_items i ON i.id = m.item_id
         LEFT JOIN portal_accounts tech ON tech.id = m.technician_account_id
         LEFT JOIN portal_accounts cust ON cust.id = m.customer_account_id
         WHERE LOWER(i.sku) LIKE :needle
            OR LOWER(i.item_name) LIKE :needle
            OR LOWER(COALESCE(m.note, "")) LIKE :needle
            OR LOWER(COALESCE(m.metadata_json, "")) LIKE :needle
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 10'
    );
    $statement->execute(['needle' => $needle]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_copilot_agent_employee_inventory(array $employee, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return [];
    }
    $statement = $db->prepare(
        'SELECT i.sku, i.item_name, i.category, i.unit,
                SUM(CASE WHEN m.movement_type IN ("issue","out") THEN m.quantity WHEN m.movement_type IN ("return","in") THEN -m.quantity ELSE 0 END) AS net_quantity,
                MAX(m.created_at) AS last_movement_at
         FROM inventory_stock_movements m
         JOIN inventory_stock_items i ON i.id = m.item_id
         WHERE m.technician_account_id = :employee_id AND m.status = "posted"
         GROUP BY i.id, i.sku, i.item_name, i.category, i.unit
         HAVING net_quantity <> 0
         ORDER BY last_movement_at DESC
         LIMIT 20'
    );
    $statement->execute(['employee_id' => (int) ($employee['id'] ?? 0)]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_copilot_agent_inventory_answer(string $text, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'text' => 'DB hazir deyil.'];
    }

    $employee = app_copilot_agent_resolve_employee($text, $db);
    if ($employee !== null && app_copilot_agent_has_any($text, ['hansi mal', 'hansi mallar', 'neler var', 'adinda', 'uzerinde'])) {
        $rows = app_copilot_agent_employee_inventory($employee, $db);
        if ($rows === []) {
            return ['ok' => true, 'text' => (string) ($employee['full_name'] ?? 'Emekdas') . " uzerinde aktiv inventar qaligi tapilmadi."];
        }
        $lines = ['EMEKDAS INVENTARI', 'Emekdas: ' . (string) ($employee['full_name'] ?? '-')];
        foreach ($rows as $row) {
            $lines[] = '- ' . (string) ($row['sku'] ?? '-') . ' / ' . (string) ($row['item_name'] ?? '-') . ': ' . (string) ($row['net_quantity'] ?? '0') . ' ' . (string) ($row['unit'] ?? '');
        }
        return ['ok' => true, 'text' => implode("\n", $lines), 'rows' => $rows];
    }

    $rows = app_copilot_agent_inventory_search($text, $db);
    if ($rows === []) {
        return ['ok' => false, 'text' => 'Inventarda bu sorguya uygun mal/SN/MAC tapilmadi. SKU, SN ve ya MAC daha deqiq yazin.'];
    }
    $latest = $rows[0];
    $lines = [
        'INVENTAR AXTARIS NETICESI',
        'Mal: ' . (string) ($latest['sku'] ?? '-') . ' / ' . (string) ($latest['item_name'] ?? '-'),
        'Son hereket: ' . (string) ($latest['movement_type'] ?? '-') . ' ' . (string) ($latest['quantity'] ?? '-') . ' ' . (string) ($latest['unit'] ?? '') . ' / ' . (string) ($latest['created_at'] ?? '-'),
        'Emekdas: ' . ((string) ($latest['technician_name'] ?? '') !== '' ? (string) $latest['technician_name'] : '-'),
        'Musteri: ' . ((string) ($latest['customer_name'] ?? '') !== '' ? (string) $latest['customer_name'] . ' / ' . (string) ($latest['customer_code'] ?? '') : '-'),
        'Ticket: ' . ((string) ($latest['ticket_no'] ?? '') !== '' ? (string) $latest['ticket_no'] : '-'),
        'Qeyd: ' . ((string) ($latest['note'] ?? '') !== '' ? (string) $latest['note'] : '-'),
    ];

    return ['ok' => true, 'text' => implode("\n", $lines), 'rows' => $rows];
}

function app_copilot_agent_resolve_hsgq_device_from_text(string $text, ?PDO $db = null): ?array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return null;
    }

    $norm = app_copilot_agent_norm($text);
    $rows = $db->query(
        'SELECT *
         FROM device_learning_profiles
         WHERE device_type = "olt" AND vendor = "HSGQ"
         ORDER BY monitoring_enabled DESC, id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ip = (string) ($row['ip_address'] ?? '');
        $lastOctet = '';
        if (preg_match('/\.(\d{1,3})$/', $ip, $match) === 1) {
            $lastOctet = (string) $match[1];
        }
        $candidates = array_filter([
            (string) ($row['device_key'] ?? ''),
            (string) ($row['device_name'] ?? ''),
            $ip,
            $lastOctet !== '' ? ('olt ' . $lastOctet) : '',
            $lastOctet !== '' ? ('hsgq ' . $lastOctet) : '',
            $lastOctet !== '' ? ('kurdexani ' . $lastOctet) : '',
        ]);
        foreach ($candidates as $candidate) {
            $candidateNorm = app_copilot_agent_norm($candidate);
            if ($candidateNorm !== '' && str_contains($norm, $candidateNorm)) {
                return $row;
            }
        }
    }

    return null;
}

function app_copilot_agent_extract_ont_control_target(string $text, ?PDO $db = null): ?array
{
    $norm = app_copilot_agent_norm($text);
    if (!app_copilot_agent_has_any($norm, ['ont', 'onu'])) {
        return null;
    }

    $operation = null;
    if (app_copilot_agent_has_any($norm, ['restart', 'reboot', 'yeniden baslat'])) {
        $operation = 'restart';
    } elseif (app_copilot_agent_has_any($norm, ['sondur', 'disable', 'shutdown', 'bagla'])) {
        $operation = 'disable';
    } elseif (app_copilot_agent_has_any($norm, ['yandir', 'enable', 'start', 'aktiv et', 'ac'])) {
        $operation = 'enable';
    } elseif (app_copilot_agent_has_any($norm, ['sil', 'delete', 'remove'])) {
        $operation = 'delete';
    } elseif (app_copilot_agent_has_any($norm, ['yeniden online', 'reonline', 'online dussun', 'aktivden sil'])) {
        $operation = 'reonline';
    }
    if ($operation === null) {
        return null;
    }

    $pon = '';
    $ontId = '';
    if (preg_match('/\bpon\s*0*(\d{1,2})\b/iu', $text, $match) === 1) {
        $pon = (string) (int) $match[1];
    }
    if (preg_match('/\b(?:ont|onu)\s*0*(\d{1,4})\b/iu', $text, $match) === 1) {
        $ontId = (string) (int) $match[1];
    }
    if (($pon === '' || $ontId === '') && preg_match('/\bpon\s*0*(\d{1,2})\D+(?:ont|onu)?\s*0*(\d{1,4})\b/iu', $text, $match) === 1) {
        $pon = $pon !== '' ? $pon : (string) (int) $match[1];
        $ontId = $ontId !== '' ? $ontId : (string) (int) $match[2];
    }

    $device = app_copilot_agent_resolve_hsgq_device_from_text($text, $db);
    if ($device === null || $pon === '' || $ontId === '') {
        return [
            'ok' => false,
            'reason' => 'missing_olt_pon_ont',
            'text' => 'ONT emri ucun OLT, PON ve ONT ID deqiq yazilmalidir. Numune: kurdexani 21 PON03 ONT032 restart et',
        ];
    }

    return [
        'ok' => true,
        'device' => $device,
        'operation' => $operation,
        'pon' => $pon,
        'ont_id' => $ontId,
    ];
}

function app_copilot_agent_help_answer(): array
{
    return [
        'ok' => true,
        'text' => implode("\n", [
            'SUPERPILOT NELERE CAVAB VERE BILIR',
            '',
            'Musteri analizi:',
            '- 2320025 niye qirilir?',
            '- Serdar Abdullayev odenisi neye cixib?',
            '- 2320025 gunu ne vaxt bitir, balans ve paket veziyyeti nedir?',
            '- 2320025 activity score, payment score ve churn riski nedir?',
            '- 2320025 sirket ucun ne qeder gelir itkisi yaradib?',
            '- Musterinin son ticketleri, SMS tarixcesi ve radius loglarinda ne gorunur?',
            '',
            'RADIUS ve internet sessiyasi:',
            '- 2320025 online-dir?',
            '- 2320025 IP/MAC/NAS/VLAN melumati nedir?',
            '- 2320025 radius yenile / aktivden silib yeniden qos',
            '',
            'ONT, modem ve optik:',
            '- SN123 kimdedir?',
            '- Bu modem hansi musteridedir?',
            '- Musterinin ONT temperaturu, RX/TX, OLT/PON yeri necedir?',
            '- Tez-tez sonub-yanma veya zeif siqnal sebebi nedir?',
            '- kurdexani 21 PON03 ONT032 restart et',
            '- HSGQ 172.16.5.20 PON01 ONT12 sondur / yandir / sil / yeniden online et',
            '',
            'Ticket ve emeliyyat:',
            '- 2320025 ucun bilet ac internet yoxdur',
            '- Musterinin aciq ticketleri hansilardir?',
            '- Eyni PON-da oxsar problemler var?',
            '',
            'SMS ve bildiris:',
            '- 2320025 sms gonder odenisiniz qebul edildi',
            '- Musteriye ferdi SMS/app/Telegram/WhatsApp mesaji queue-ya yazir.',
            '- 2320025 modem restart et yazanda canli restart etmeden evvel tesdiq isteyir ve restart queue/audit yaradir.',
            '',
            'Anbar ve emekdas:',
            '- Samir adinda hansi mallar var?',
            '- Bu modem kimdedir?',
            '- SN/MAC uzre mal hansi emekdasa veya musteriye verilib?',
            '- Modem ver / geri al yazanda Superpilot tesdiq isteyib inventar hereketi yaradir.',
            '',
            'NOC ve analiz:',
            '- Hansı cihazlar offline-dir?',
            '- Hansı zona risklidir?',
            '- Sebekeni kim doldurur, virus/abuse ehtimali var?',
            '- Bos trafik saatlari hansilardir?',
            '- Traffic monetization ucun hansi kampaniya teklif olunur?',
            '- Afternoon Boost / Night Gamer paketlerinden ne qeder gelir cixa biler?',
            '- Top talker, NAT fanout, session storm ve yuksek upload risklerini analiz edir.',
            '- Alarm/ticket/ONT/RADIUS melumatlarini birlesdirib ehtimal olunan sebebi ve novbeti addimi yazir.',
            '',
            'Qayda: icra emri (bilet acmaq, radius yenilemek, mal vermek/almaq) yazanda Superpilot evvel TESDIQ isteyir, tesdiqsiz DB-de emeliyyat etmir.',
        ]),
    ];
}

function app_copilot_agent_customer_intelligence_text(array $account, PDO $db): array
{
    try {
        $intel = app_customer_activity_intelligence($db, $account);
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'text' => 'Customer Activity Intelligence hesablana bilmedi: ' . $exception->getMessage(),
            'intelligence' => null,
        ];
    }

    $lines = [
        'Customer Activity Intelligence:',
        '- Activity score: ' . (string) ($intel['activity_score'] ?? 0) . '/100',
        '- Payment score: ' . (string) ($intel['payment_score'] ?? 0) . '/100',
        '- Ilde aktiv/passiv gun: ' . (string) ($intel['active_days_year'] ?? 0) . ' / ' . (string) ($intel['inactive_days_year'] ?? 0),
        '- Cari deaktiv gun: ' . (string) ($intel['current_inactive_days'] ?? 0),
        '- Orta/maks gecikme: ' . (string) ($intel['average_late_days'] ?? 0) . ' / ' . (string) ($intel['max_late_days'] ?? 0) . ' gun',
        '- Gelir itkisi: ' . number_format((float) ($intel['revenue_loss'] ?? 0), 2) . ' AZN',
        '- Churn riski: ' . (string) ($intel['churn_risk'] ?? '-') . ' (' . (string) ($intel['churn_risk_percent'] ?? 0) . '%)',
        '- Tovsiye: ' . (string) ($intel['recommendation'] ?? '-'),
    ];

    if ((string) ($intel['ai_summary'] ?? '') !== '') {
        $lines[] = '- AI xulase: ' . (string) $intel['ai_summary'];
    }

    return [
        'ok' => true,
        'text' => implode("\n", $lines),
        'intelligence' => $intel,
    ];
}

function app_copilot_agent_traffic_monetization_answer(PDO $db): array
{
    try {
        $traffic = app_traffic_monetization($db);
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'text' => 'Traffic monetization analizi hesablana bilmedi: ' . $exception->getMessage(),
        ];
    }

    $packages = [];
    foreach (array_slice(is_array($traffic['recommended_packages'] ?? null) ? $traffic['recommended_packages'] : [], 0, 3) as $package) {
        if (!is_array($package)) {
            continue;
        }
        $packages[] = '- ' . (string) ($package['name'] ?? 'Paket') . ': '
            . (string) ($package['window'] ?? '-') . ', '
            . (string) ($package['speed'] ?? '-') . ', '
            . (string) ($package['price'] ?? '-') . '. '
            . (string) ($package['reason'] ?? '');
    }

    return [
        'ok' => true,
        'kind' => 'traffic_monetization',
        'analysis' => $traffic,
        'text' => implode("\n", [
            'AI Revenue Optimization & Traffic Monetization',
            (string) ($traffic['executive_insight'] ?? ''),
            '',
            'Metrikler:',
            '- Capacity: ' . (string) ($traffic['capacity_mbps'] ?? 0) . ' Mbps',
            '- Ortalama/pik istifade: ' . (string) ($traffic['average_mbps'] ?? 0) . ' / ' . (string) ($traffic['peak_mbps'] ?? 0) . ' Mbps',
            '- Bos capacity: ' . (string) ($traffic['unused_capacity_percent'] ?? 0) . '% (' . (string) ($traffic['unused_mbps'] ?? 0) . ' Mbps)',
            '- Potensial musteri: ' . (string) ($traffic['potential_customers'] ?? 0),
            '- Proqnoz gelir: ' . number_format((float) ($traffic['projected_monthly_revenue'] ?? 0), 2) . ' AZN/ay',
            '- Confidence: ' . (string) ($traffic['confidence'] ?? 0) . '%',
            '',
            'Tovsiye olunan paket/kampaniyalar:',
            $packages !== [] ? implode("\n", $packages) : '- Hazirda kifayet qeder trafik sample yoxdur; accounting datasi artdiqca teklifler deqiqlestirilecek.',
            '',
            'Novbeti addim: uygun segmentleri secib kampaniya sehifesinden test SMS/app kampaniyasi yaradilsin.',
        ]),
    ];
}

/**
 * Typo-tolerant intent family classifier. Returns the best-guess capability
 * family and the stems that matched, so the agent can guide instead of failing.
 *
 * @return array{intent:string,score:int,matched:list<string>}
 */
function app_copilot_agent_classify_intent(string $text): array
{
    $norm = app_copilot_agent_norm($text);
    $families = [
        'customer_create' => ['musteri yarat', 'musteri yarad', 'musteri ac', 'muster yarat', 'musder yarat', 'mustri yarat', 'mstr yarat', 'yeni musteri', 'yeni abuneci', 'yeni abunec', 'yeni client', 'yeni klient', 'yeni internet qos', 'abuneci ac', 'client elave', 'musteri elave', 'musteri qeydiyyat'],
        'customer_show' => ['musteri melumat', 'musteri goster', 'kimdir', 'profil goster', 'borcunu goster', 'borc goster', 'borcunu', 'odenis tarixce', 'odenis tarix', 'aktiv xidmet', 'muqavile goster', 'muqavile', 'tarixce goster'],
        'customer_edit' => ['musteri redakte', 'musterini deyis', 'paket deyis', 'tarif deyis', 'musterini deaktiv', 'deaktiv et'],
        'inventory' => ['mal ver', 'medaxil', 'mexaric', 'anbara', 'anbardan', 'tehvil ver', 'geri al', 'transfer', 'stok', 'qaliq', 'barkod', 'seriya', 'inventar', ' ver'],
        'ticket' => ['bilet ac', 'ticket ac', 'tapsiriq yarat', 'tapsiriq ver', 'muraciet yarat', 'is yarat', 'problem ac'],
        'report' => ['hesabat', 'gunluk hesabat', 'ayliq hesabat', 'hefteli hesabat', 'illik hesabat', 'statistika'],
        'performance' => ['performans', 'en cox isleyen', 'en az isleyen', 'geciken isci', 'orta icra', 'en yaxsi texnik'],
        'analytics' => ['hansi avadanliq', 'hansi rayon', 'hansi texnik', 'riskli musteri', 'hansi mehsul', 'hansi tarif', 'hansi xidmet'],
    ];
    // Longest matched stem wins; customer_create breaks ties (most specific write).
    $best = 'unknown';
    $bestLen = 0;
    $matched = [];
    foreach ($families as $intent => $stems) {
        foreach ($stems as $stem) {
            $needle = app_copilot_agent_norm($stem);
            if ($needle !== '' && str_contains($norm, $needle)) {
                $matched[] = $stem;
                $len = strlen($needle);
                if ($len > $bestLen || ($len === $bestLen && $intent === 'customer_create')) {
                    $best = $intent;
                    $bestLen = $len;
                }
            }
        }
    }
    return ['intent' => $best, 'score' => $bestLen, 'matched' => array_values(array_unique($matched))];
}

/**
 * Match a service area name inside free text (typo/suffix tolerant).
 */
function app_copilot_agent_extract_area(string $text): string
{
    $norm = app_copilot_agent_norm($text);
    $areas = function_exists('app_coverage_points') ? app_coverage_points() : [];
    foreach ($areas as $area) {
        $name = is_array($area) ? (string) ($area['name'] ?? '') : (string) $area;
        $needle = app_copilot_agent_norm($name);
        if ($needle !== '' && strlen($needle) >= 4 && str_contains($norm, $needle)) {
            return $name;
        }
    }
    return '';
}

/**
 * Extract labelled customer fields from a free-text create request.
 *
 * @return array<string,string>
 */
function app_copilot_agent_parse_customer_fields(string $text): array
{
    $fields = [];
    $area = app_copilot_agent_extract_area($text);
    if ($area !== '') {
        $fields['area'] = $area;
    }
    $digitsOnly = preg_replace('/[\s\-]/', '', $text) ?? $text;
    if (preg_match('/(?<!\d)(0\d{9}|\+994\d{9})(?!\d)/', $digitsOnly, $m) === 1) {
        $fields['phone'] = $m[1];
    }
    if (preg_match('/\bfin[:\s]*([A-Za-z0-9]{7})\b/iu', $text, $m) === 1) {
        $fields['fin_code'] = strtoupper($m[1]);
    }
    if (preg_match('/\b(?:sened|sənəd|document|vesiqe)[:\s№]*([A-Za-z]{1,3}\d{6,8})\b/iu', $text, $m) === 1) {
        $fields['document_no'] = strtoupper($m[1]);
    }
    if (preg_match('/\b(?:unvan|ünvan|address|adres)[:\s]*([^,]{4,80})/iu', $text, $m) === 1) {
        $fields['address'] = trim($m[1]);
    }
    if (preg_match('/\b(?:ad soyad|ad|name)[:\s]+([A-Za-zƏəÇçĞğİıÖöŞşÜü]+\s+[A-Za-zƏəÇçĞğİıÖöŞşÜü]+)/u', $text, $m) === 1) {
        $fields['full_name'] = trim($m[1]);
    } elseif (preg_match('/(?:musteri|müştəri|mustəri)\s*:?\s*([A-Za-zƏəÇçĞğİıÖöŞşÜü]+\s+[A-Za-zƏəÇçĞğİıÖöŞşÜü]+)/u', $text, $m) === 1) {
        $fields['full_name'] = trim($m[1]);
    }
    return $fields;
}

/**
 * Guided customer-create flow (audit-friendly, never a generic error).
 * Recognises the intent, extracts what it can, and either stages a
 * confirmation-gated pending action or asks specifically for the missing
 * identity fields.
 */
function app_copilot_agent_customer_create_flow(string $text, array $actor, PDO $db): array
{
    $fields = app_copilot_agent_parse_customer_fields($text);
    $required = ['full_name', 'phone', 'fin_code', 'document_no', 'address'];
    $missing = array_values(array_filter($required, static fn (string $key): bool => trim((string) ($fields[$key] ?? '')) === ''));
    $areaNote = isset($fields['area']) ? (' (' . $fields['area'] . ' rayonu)') : '';

    if ($missing !== []) {
        $labels = [
            'full_name' => 'ad soyad',
            'phone' => 'telefon',
            'fin_code' => 'FIN',
            'document_no' => 'sənəd №',
            'address' => 'ünvan',
        ];
        $missingLabels = array_map(static fn (string $k): string => $labels[$k] ?? $k, $missing);
        return [
            'ok' => true,
            'kind' => 'customer_create_guided',
            'text' => 'Yeni müştəri yaradıram' . $areaNote . '. Davam etmək üçün bunlar lazımdır: '
                . implode(', ', $missingLabels) . ".\n"
                . "Nümunə: Yeni müştəri: Əli Vəliyev, telefon 0501234567, FIN 1ABC2DE, sənəd AA1234567, ünvan "
                . ($fields['area'] ?? 'Astara') . " ş. Heydər Əliyev 10, tarif 100Mbit",
            'collected' => $fields,
        ];
    }

    $subscriberCode = 'SP-' . date('ymd') . random_int(100, 999);
    return app_copilot_agent_pending_action([
        'kind' => 'create_customer',
        'entity_type' => 'customer',
        'entity_id' => $subscriberCode,
        'subscriber_code' => $subscriberCode,
        'full_name' => (string) $fields['full_name'],
        'phone' => (string) $fields['phone'],
        'fin_code' => (string) $fields['fin_code'],
        'document_no' => (string) $fields['document_no'],
        'address' => (string) $fields['address'],
        'area' => (string) ($fields['area'] ?? ''),
        'summary' => 'Yeni müştəri yaradılsın: ' . (string) $fields['full_name'] . ' / ' . (string) $fields['phone'] . $areaNote,
    ], $actor, $db);
}

/**
 * Warehouse intake (mədaxil): RECEIVE goods into stock. Unlike issue/return,
 * this does not require the item to pre-exist — it creates/increments stock.
 * Extracts quantity, item name, unit price and supplier from free text.
 */
/**
 * Deterministic answer to "what are my permissions / role" (spec: role, modules,
 * what the actor can do). Never routed to the LLM.
 */
function app_copilot_agent_permissions_answer(array $actor, PDO $db): array
{
    $account = app_copilot_agent_actor_account($actor, $db);
    $account = is_array($account) ? $account : $actor;
    $isAdmin = function_exists('app_portal_is_admin') && app_portal_is_admin($account);
    $role = trim((string) ($account['employee_role'] ?? $account['account_type'] ?? 'employee'));
    if ($role === '') {
        $role = 'employee';
    }
    $modules = function_exists('app_portal_employee_module_keys') ? app_portal_employee_module_keys($account) : [];
    $catalog = function_exists('app_portal_module_catalog') ? app_portal_module_catalog('az') : [];
    $labels = [];
    foreach ($modules as $moduleKey) {
        $labels[] = (string) ($catalog[$moduleKey]['label'] ?? $moduleKey);
    }

    $name = trim((string) ($account['full_name'] ?? $actor['full_name'] ?? ''));
    $lines = [];
    $lines[] = ($name !== '' ? $name . ', ' : '') . 'rolunuz: ' . ($isAdmin ? 'Admin (tam icazə)' : $role);
    $lines[] = $labels === []
        ? 'Modul icazəniz təyin olunmayıb — admin ilə əlaqə saxlayın.'
        : ('Giriş icazəniz olan modullar: ' . implode(', ', $labels));
    $lines[] = 'Agent vasitəsilə: müştəri sorğu/analiz/yaratma, bilet, anbar mədaxil/təhvil, RADIUS/ONT — yalnız icazəniz olan modullarda. Pul, silmə və kritik əməliyyatlar təsdiq tələb edir.';

    return ['ok' => true, 'kind' => 'permissions', 'text' => implode("\n", $lines)];
}

/**
 * Matches free text to a single advisor key, or 'all' for a generic
 * "advisor briefing" request, or null when the text is not an advisor query.
 */
function app_copilot_agent_match_advisor_intent(string $text): ?string
{
    $norm = app_copilot_agent_norm($text);
    $perAdvisor = [
        'ceo' => ['ceo meslehetcisi', 'ceo hesabati', 'bas direktor meslehetci', 'icraci direktor meslehetci'],
        'finance' => ['maliyye meslehetcisi', 'maliyye advisor', 'fin meslehetci', 'maliyye meslehetci hesabati'],
        'noc' => ['noc meslehetcisi', 'sebeke muhendisi meslehetci', 'noc muhendisi meslehetci', 'network advisor'],
        'techops' => ['texniki emeliyyatlar meneceri', 'techops meslehetci', 'texniki emeliyyat meslehetci'],
        'marketing' => ['marketinq meslehetcisi', 'marketing advisor', 'marketinq meslehetci hesabati'],
    ];
    foreach ($perAdvisor as $key => $stems) {
        foreach ($stems as $stem) {
            if (str_contains($norm, app_copilot_agent_norm($stem))) {
                return $key;
            }
        }
    }
    if (app_copilot_agent_has_any($text, ['meslehetci hesabati', 'meslehetci briefing', 'agent ekosistemi', 'advisor briefing', 'meslehetcilerin hesabati'])) {
        return 'all';
    }
    return null;
}

/**
 * Read-only advisor briefing answer. Routes through the canonical
 * `app_advisor_council_run()` entry point (advisor-agent-lib.php) so access
 * gating, prompt-injection handling and the append-only audit record are the
 * real enforced contract, not just this file's own logic. Never stages or
 * executes a write — every underlying proposal only carries a `next_action`
 * describing what a super-admin could do next. Advisors the actor cannot see
 * are silently omitted rather than reported as denied, so the response never
 * confirms which private cross-module data exists.
 */
function app_copilot_agent_advisor_briefing_answer(string $advisorKey, string $objective, array $actor, PDO $db): array
{
    $account = app_copilot_agent_actor_account($actor, $db);
    $account = is_array($account) ? $account : $actor;

    $council = app_advisor_council_run($db, $account, $objective);
    if (empty($council['ok'])) {
        return match ((string) ($council['reason'] ?? '')) {
            'prompt_injection_blocked' => [
                'ok' => true,
                'kind' => 'advisor_injection_blocked',
                'text' => 'Sorğuda təhlükəsizlik siyasətinə zidd göstəriş aşkarlandı; məsləhətçi hesabatı göstərilmir.',
            ],
            default => [
                'ok' => true,
                'kind' => 'advisor_unavailable',
                'text' => 'Məsləhətçi hesabatı hazırda əlçatan deyil (audit qeydi yaradıla bilmədi).',
            ],
        };
    }

    $roster = app_advisor_agent_roster();
    $requested = $advisorKey === 'all' ? array_keys($roster) : [$advisorKey];
    $briefings = is_array($council['advisors'] ?? null) ? $council['advisors'] : [];
    $visible = array_intersect_key($briefings, array_flip($requested));

    if ($visible === []) {
        return [
            'ok' => true,
            'kind' => 'advisor_access_denied',
            'text' => 'Bu məsləhətçi hesabatına baxmaq üçün lazımi modul icazəniz yoxdur, yalnız super-admin/müvafiq modul sahibi görə bilər.',
        ];
    }

    $lines = [];
    foreach ($visible as $key => $briefing) {
        $advisor = $briefing['advisor'];
        $lines[] = '## ' . (string) ($advisor['label'] ?? $key);
        $lines[] = (string) ($advisor['mission'] ?? '');
        foreach ($briefing['proposals'] as $proposal) {
            $lines[] = sprintf(
                '- [%s/%s] %s (təsdiq sahibi: %s, növbəti addım: %s)',
                strtoupper((string) ($proposal['risk'] ?? 'medium')),
                (string) ($proposal['data_status'] ?? 'verified'),
                (string) ($proposal['summary'] ?? ''),
                (string) ($proposal['approval_owner'] ?? 'super_admin'),
                (string) ($proposal['next_action'] ?? 'manual_review')
            );
            foreach ($proposal['evidence'] as $evidence) {
                $lines[] = '  · dəlil: ' . (string) ($evidence['detail'] ?? '') . ' (' . (string) ($evidence['source'] ?? '') . ', ' . (string) ($evidence['observed_at'] ?? '') . ')';
            }
        }
        $lines[] = '';
    }

    return [
        'ok' => true,
        'kind' => 'advisor_briefing',
        'text' => trim(implode("\n", $lines)),
        'advisors' => $visible,
    ];
}

/**
 * Warehouse intake (mədaxil): RECEIVE goods into stock. Unlike issue/return,
 * this does not require the item to pre-exist — it creates/increments stock.
 * Extracts quantity, item name, unit price and supplier from free text.
 */
function app_copilot_agent_intake_flow(string $text, array $actor, PDO $db): array
{
    $norm = app_copilot_agent_norm($text);

    $qty = 1;
    if (preg_match('/(\d+)\s*(?:eded|dene|dənə|denə|ədəd|ed)\b/u', $norm, $m) === 1) {
        $qty = max(1, (int) $m[1]);
    }
    $price = 0.0;
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:azn|manat)/iu', $text, $m) === 1) {
        $price = (float) str_replace(',', '.', $m[1]);
    }
    $supplier = '';
    if (preg_match('/(?:sirket|şirkət|firma|tedarukcu|təchizatçı)\s+([\p{L}\d .\-]{2,40})/iu', $text, $m) === 1) {
        $supplier = trim($m[1]);
    } elseif (preg_match('/geldiyi\s+([\p{L}\d .\-]{2,40})/iu', $text, $m) === 1) {
        $supplier = trim(preg_replace('/^(?:sirket|şirkət)\s+/iu', '', trim($m[1])) ?? trim($m[1]));
    }
    $itemName = '';
    if (preg_match('/(?:eded|dene|dənə|denə|ədəd)\s+(.+?)\s+(?:qiymet|qiymət|azn|manat|geldi|sirket|şirkət|firma)/iu', $text, $m) === 1) {
        $itemName = trim($m[1]);
    }
    if ($itemName === '') {
        $itemName = trim((string) preg_replace([
            '/anbara?\s*/iu', '/\bmal(lar)?\b/iu', '/\bm[əe]daxil\b/iu', '/\bet\b/iu',
            '/\d+\s*(eded|dene|dənə|ədəd|azn|manat)?/iu', '/qiym[əe]t[i]?/iu', '/geldiyi.*/iu', '/\bsirk[əe]t\b.*/iu',
        ], ' ', $text));
        $itemName = trim(preg_replace('/\s+/', ' ', $itemName) ?? $itemName);
    }
    if ($itemName === '') {
        $itemName = 'Anbar malı';
    }
    $sku = strtoupper(trim((string) preg_replace('/[^a-z0-9]+/i', '-', app_copilot_agent_norm($itemName)), '-'));
    if ($sku === '') {
        $sku = 'INTAKE-' . date('ymdHis');
    }

    $summary = 'Anbara mədaxil: ' . $qty . ' ədəd ' . $itemName
        . ($price > 0 ? (' / vahid ' . rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.') . ' AZN') : '')
        . ($supplier !== '' ? (' / təchizatçı: ' . $supplier) : '');

    return app_copilot_agent_pending_action([
        'kind' => 'inventory_movement',
        'entity_type' => 'inventory',
        'entity_id' => $sku,
        'summary' => $summary,
        'movement' => [
            'sku' => $sku,
            'item_name' => $itemName,
            'category' => 'general',
            'unit' => 'eded',
            'quantity' => $qty,
            'movement_type' => 'in',
            'unit_price' => $price,
            'supplier' => $supplier,
            'note' => 'Superpilot mədaxil: ' . mb_substr($text, 0, 200),
        ],
    ], $actor, $db);
}

function app_copilot_agent_action_from_text(string $text, array $actor, ?PDO $db = null): ?array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return null;
    }
    $norm = app_copilot_agent_norm($text);

    if (app_copilot_agent_classify_intent($text)['intent'] === 'customer_create') {
        return app_copilot_agent_customer_create_flow($text, $actor, $db);
    }

    $ontTarget = app_copilot_agent_extract_ont_control_target($text, $db);
    if (is_array($ontTarget)) {
        if (empty($ontTarget['ok'])) {
            return ['ok' => false, 'text' => (string) ($ontTarget['text'] ?? 'ONT target tapilmadi.')];
        }
        $device = is_array($ontTarget['device'] ?? null) ? $ontTarget['device'] : [];
        return app_copilot_agent_pending_action([
            'kind' => 'ont_control_request',
            'entity_type' => 'ont',
            'entity_id' => (string) ($device['device_key'] ?? '') . '/PON' . (string) ($ontTarget['pon'] ?? '') . '/ONT' . (string) ($ontTarget['ont_id'] ?? ''),
            'device_key' => (string) ($device['device_key'] ?? ''),
            'operation' => (string) ($ontTarget['operation'] ?? ''),
            'pon' => (string) ($ontTarget['pon'] ?? ''),
            'ont_id' => (string) ($ontTarget['ont_id'] ?? ''),
            'summary' => 'Real ONT command: ' . (string) ($ontTarget['operation'] ?? '') . ' / ' . (string) ($device['device_name'] ?? '') . ' PON ' . (string) ($ontTarget['pon'] ?? '') . ' ONT ' . (string) ($ontTarget['ont_id'] ?? ''),
        ], $actor, $db);
    }

    if (app_copilot_agent_has_any($norm, ['sms gonder', 'sms at', 'mesaj gonder', 'bildiris gonder', 'whatsapp gonder', 'telegram gonder'])) {
        $account = app_copilot_resolve_customer($text, $db);
        if (!is_array($account)) {
            return ['ok' => false, 'text' => 'Mesaj gondermek ucun musteri kodu/adini deqiq yazin.'];
        }
        $channel = 'sms';
        if (app_copilot_agent_has_any($norm, ['whatsapp'])) {
            $channel = 'whatsapp';
        } elseif (app_copilot_agent_has_any($norm, ['telegram'])) {
            $channel = 'telegram';
        } elseif (app_copilot_agent_has_any($norm, ['app'])) {
            $channel = 'app';
        }
        $messageBody = trim(preg_replace('/\b(sms|mesaj|bildiris|whatsapp|telegram|app)\s+(gonder|at)\b/iu', '', $text) ?? $text);
        $messageBody = trim(str_replace((string) ($account['subscriber_code'] ?? ''), '', $messageBody));
        if ($messageBody === '') {
            $messageBody = 'SuperOptic bildiris mesaji.';
        }

        return app_copilot_agent_pending_action([
            'kind' => 'send_customer_message',
            'entity_type' => 'message',
            'entity_id' => (string) ($account['subscriber_code'] ?? $account['id'] ?? ''),
            'customer_account_id' => (int) ($account['id'] ?? 0),
            'channel' => $channel,
            'message_body' => $messageBody,
            'summary' => strtoupper($channel) . ' mesaj gonderilsin: ' . (string) ($account['full_name'] ?? '') . ' / ' . (string) ($account['subscriber_code'] ?? ''),
        ], $actor, $db);
    }

    if (app_copilot_agent_has_any($norm, ['bilet ac', 'ticket ac', 'muraciet yarat', 'problem ac'])) {
        $account = app_copilot_resolve_customer($text, $db);
        if (!is_array($account)) {
            return ['ok' => false, 'text' => 'Bilet acmaq ucun musteri kodu/adini deqiq yazin.'];
        }
        return app_copilot_agent_pending_action([
            'kind' => 'create_ticket',
            'entity_type' => 'ticket',
            'entity_id' => (string) ($account['subscriber_code'] ?? $account['id'] ?? ''),
            'customer_account_id' => (int) ($account['id'] ?? 0),
            'subject' => 'Superpilot: ' . mb_substr($text, 0, 80),
            'message' => $text,
            'priority' => app_copilot_agent_has_any($norm, ['qiril', 'yoxdur', 'offline', 'tehlukeli']) ? 'high' : 'normal',
            'summary' => 'Musteri ucun bilet acilacaq: ' . (string) ($account['full_name'] ?? '') . ' / ' . (string) ($account['subscriber_code'] ?? ''),
        ], $actor, $db);
    }

    if (app_copilot_agent_has_any($norm, ['modem restart', 'ont restart', 'onu restart', 'restart et', 'reboot et', 'modemi yeniden baslat'])) {
        $account = app_copilot_resolve_customer($text, $db);
        if (!is_array($account)) {
            return ['ok' => false, 'text' => 'Restart ucun musteri kodu/adini deqiq yazin.'];
        }
        return app_copilot_agent_pending_action([
            'kind' => 'ont_restart_request',
            'entity_type' => 'customer',
            'entity_id' => (string) ($account['subscriber_code'] ?? $account['id'] ?? ''),
            'customer_account_id' => (int) ($account['id'] ?? 0),
            'summary' => 'ONT/modem restart sorgusu queue-ya yazilsin: ' . (string) ($account['full_name'] ?? '') . ' / ' . (string) ($account['subscriber_code'] ?? ''),
        ], $actor, $db);
    }

    if (app_copilot_agent_has_any($norm, ['radius yenile', 'yenile', 'disconnect', 'aktivden sil', 'tezeden online', 'coa'])) {
        $account = app_copilot_resolve_customer($text, $db);
        if (!is_array($account)) {
            return null;
        }
        return app_copilot_agent_pending_action([
            'kind' => 'radius_refresh_disconnect',
            'entity_type' => 'radius',
            'entity_id' => (string) ($account['subscriber_code'] ?? $account['id'] ?? ''),
            'customer_account_id' => (int) ($account['id'] ?? 0),
            'summary' => 'RADIUS sync + disconnect queue yaradilsin: ' . (string) ($account['full_name'] ?? '') . ' / ' . (string) ($account['subscriber_code'] ?? ''),
        ], $actor, $db);
    }

    if (app_copilot_agent_has_any($norm, ['medaxil', 'anbara daxil', 'mal daxil', 'mal geldi', 'mallar geldi', 'mal gelib', 'mal aldiq', 'stok artir', 'anbara al'])) {
        return app_copilot_agent_intake_flow($text, $actor, $db);
    }

    if (app_copilot_agent_has_any($norm, ['mal ver', 'tehvil ver', 'anbardan ver', 'modem ver', 'router ver', 'onu ver', 'geri al', 'tehvil al', 'qaytar'])) {
        $movementType = app_copilot_agent_has_any($norm, ['geri al', 'tehvil al', 'qaytar']) ? 'return' : 'issue';
        $employee = app_copilot_agent_resolve_employee($text, $db);
        preg_match('/\b[A-Za-z0-9][A-Za-z0-9._-]{2,63}\b/', $text, $tokenMatch);
        $sku = clean_text((string) ($tokenMatch[0] ?? 'MODEM-GENERAL'));
        return app_copilot_agent_pending_action([
            'kind' => 'inventory_movement',
            'entity_type' => 'inventory',
            'entity_id' => $sku,
            'summary' => 'Inventar hereketi yazilsin: ' . $movementType . ' / ' . $sku . ($employee ? (' / ' . (string) ($employee['full_name'] ?? '')) : ''),
            'movement' => [
                'sku' => $sku,
                'item_name' => app_copilot_agent_has_any($norm, ['onu']) ? 'ONU/ONT modem' : 'Modem/router',
                'category' => 'modem',
                'unit' => 'eded',
                'quantity' => 1,
                'movement_type' => $movementType,
                'technician_account_id' => $employee ? (int) ($employee['id'] ?? 0) : 0,
                'note' => 'Superpilot: ' . $text,
                'allow_negative_stock' => true,
            ],
        ], $actor, $db);
    }

    return null;
}

function app_copilot_agent_handle_message(string $text, array $actor = [], ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return ['ok' => false, 'text' => 'DB hazir deyil.'];
    }
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'text' => 'Sorgu bosdur.'];
    }

    if (app_copilot_agent_has_any($text, [
        'sen hansi suallara cavab',
        'sen hansi suallara',
        'hansi suallara cavab verirsen',
        'hansi suallara cavab verirsən',
        'hansi suallara cavab',
        'hansi suallara',
        'hansi suallari cavablandirirsan',
        'hansi suallari cavablandirirsan',
        'hansi suallar',
        'sualla cavab verirsen',
        'sualla cavab',
        'sual cavab',
        'ne sual vere',
        'ne sual verim',
        'ne sorusa biler',
        'senden ne sorusa bilerem',
        'senden ne sorusum',
        'neleri bilirsen',
        'neleri bilirsən',
        'neleri bacarirsan',
        'neleri bacarirsan',
        'ne ede bilirsen',
        'ne ede bilirsən',
        'ne ede bilir',
        'superpilot ne ede bilir',
        'superpilot neleri bilir',
        'superpilot imkanlari',
        'imkanlarin nedir',
        'komanda siyahisi',
        'komandalar',
        'help',
        'yardim',
        'yardım',
    ])) {
        return app_copilot_agent_help_answer();
    }

    if (preg_match('/^(tesdiq|təsdiq|confirm)\s+#?(\d+)$/iu', $text, $match) === 1) {
        return app_copilot_agent_confirm_action((int) $match[2], $actor, $db);
    }
    if (preg_match('/^(legv|ləğv|cancel)\s+#?(\d+)$/iu', $text, $match) === 1) {
        return app_copilot_agent_cancel_action((int) $match[2], $actor, $db);
    }

    // Permissions / role query — answered deterministically (never the slow LLM).
    if (app_copilot_agent_has_any($text, ['selahiyet', 'səlahiyyət', 'icazem', 'icazələrim', 'hansi icaze', 'hansi modul', 'menim rolum', 'rolum nedir', 'hansi huquq', 'nelere icaz', 'nə edə bilərəm', 'ne ede bilerem men'])) {
        return app_copilot_agent_permissions_answer($actor, $db);
    }

    // Advisor ecosystem briefing (CEO / Finance / NOC / Techops / Marketing) —
    // read-only, routed through the canonical app_advisor_council_run() gate
    // (access, injection handling, audit); never executes a write itself.
    // Answered deterministically, never the slow LLM.
    $advisorIntent = app_copilot_agent_match_advisor_intent($text);
    if ($advisorIntent !== null) {
        return app_copilot_agent_advisor_briefing_answer($advisorIntent, $text, $actor, $db);
    }

    // Pure greeting — short deterministic intro instead of a slow LLM reply.
    $greetNorm = app_copilot_agent_norm($text);
    if (preg_match('/^(salam(inaleykum|lar)?|salam superpilot|hello|hi|hey)[\s!.,]*$/u', $greetNorm) === 1) {
        return [
            'ok' => true,
            'kind' => 'greeting',
            'text' => "Salam! Mən Superpilotam — Super Optik Tech daxili köməkçiniz.\n"
                . "Edə bilərəm: müştəri (yarat/analiz/borc/ödəniş), anbar (mədaxil/təhvil/qalıq), bilet, RADIUS/ONT, hesabat.\n"
                . "Məsələn: \"2320025 kimdir?\", \"anbara 3 ədəd HG8145 mədaxil et\", \"yeni müştəri yarat\", \"menim səlahiyyətim nədir?\"",
        ];
    }

    $pending = app_copilot_agent_action_from_text($text, $actor, $db);
    if (is_array($pending)) {
        return $pending;
    }

    if (app_copilot_agent_has_any($text, ['kimdedir', 'kimdədir', 'hansi emekdas', 'hansi mallar', 'neler var', 'adinda', 'uzerinde', 'inventar', 'anbar'])) {
        return app_copilot_agent_inventory_answer($text, $db);
    }

    if (app_copilot_agent_has_any($text, [
        'virus',
        'abuse',
        'ddos',
        'botnet',
        'sebeke dolu',
        'sebekeni doldurur',
        'kim doldurur',
        'top talker',
        'cox trafik',
        'anormal trafik',
        'session storm',
        'nat fanout',
        'port scan',
    ])) {
        return app_security_anomaly_answer($text, $db, $actor);
    }

    if (app_copilot_agent_has_any($text, [
        'bos trafik',
        'unused capacity',
        'traffic monetization',
        'monetization',
        'gelir optimizasiya',
        'revenue optimization',
        'afternoon boost',
        'night gamer',
        'weekend turbo',
        'kampaniya teklif',
        'trafikden gelir',
    ])) {
        return app_copilot_agent_traffic_monetization_answer($db);
    }

    $account = app_copilot_resolve_customer($text, $db);
    if (is_array($account)) {
        $analysis = app_copilot_customer_analysis($text, $db);
        $intel = app_copilot_agent_customer_intelligence_text($account, $db);
        $answer = (string) ($analysis['answer'] ?? 'Musteri analizi alina bilmedi.');
        if (!empty($intel['ok']) && (string) ($intel['text'] ?? '') !== '') {
            $answer .= "\n\n" . (string) $intel['text'];
            $analysis['customer_activity_intelligence'] = $intel['intelligence'];
        }
        return [
            'ok' => !empty($analysis['ok']),
            'text' => $answer,
            'analysis' => $analysis,
        ];
    }

    $modemQueries = [$text];
    if (preg_match_all('/(?:[0-9A-Fa-f]{2}[:\-.]){5}[0-9A-Fa-f]{2}|[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}|\b[A-Za-z]{2,5}[0-9A-Fa-f]{6,}\b|\b[0-9A-Fa-f]{12}\b/', $text, $tokenMatches) > 0) {
        $modemQueries = array_merge(array_values(array_unique($tokenMatches[0])), $modemQueries);
    }
    $modemReply = ['ok' => false, 'reason' => 'not_found'];
    foreach ($modemQueries as $modemQuery) {
        $modemReply = app_mgs_build_modem_search_reply((string) $modemQuery, true, $db, $actor);
        if (!empty($modemReply['ok'])) {
            return $modemReply;
        }
    }

    // On-premise LLM first (local Ollama/OpenAI-compatible runtime). Only falls
    // back to an external provider if the operator explicitly configured one.
    $llmReply = app_ai_agent_chat($text);
    if (!empty($llmReply['ok'])) {
        return [
            'ok' => true,
            'text' => (string) ($llmReply['text'] ?? ''),
            'kind' => (string) ($llmReply['provider'] ?? '') === 'local' ? 'read_only_local_answer' : 'read_only_external_answer',
            'analysis' => [
                'provider' => (string) ($llmReply['provider'] ?? 'local'),
                'model' => (string) ($llmReply['model'] ?? ''),
                'metrics' => (array) ($llmReply['metrics'] ?? []),
            ],
        ];
    }

    return app_copilot_agent_guided_fallback($text);
}

/**
 * Intent-aware guided response. Never a generic "anlasilmadi": detects the
 * closest capability family and asks a specific next step, following the
 * agent spec.
 */
function app_copilot_agent_guided_fallback(string $text): array
{
    $classification = app_copilot_agent_classify_intent($text);
    $intent = (string) $classification['intent'];

    $guides = [
        'customer_show' => 'Müştəri məlumatını göstərmək üçün müştəri kodunu və ya adını yazın. Məsələn: "2320025 kimdir?" və ya "2320025 borcunu göstər".',
        'customer_edit' => 'Müştəri dəyişikliyi üçün müştəri kodunu və nə dəyişəcəyini yazın. Məsələn: "2320025 tarifini 100Mbit et" və ya "2320025 deaktiv et".',
        'inventory' => 'Anbar əməliyyatı üçün model/SKU və əməkdaşı yazın. Məsələn: "Əliyə HG8145X6 ver" və ya "20 ədəd HG8145X6 mədaxil et".',
        'ticket' => 'Tapşırıq/bilet üçün müştəri kodu və problemi yazın. Məsələn: "2320025 üçün bilet aç internet yoxdur".',
        'report' => 'Hesabat üçün dövrü yazın. Məsələn: "bu ayın satış hesabatını hazırla" və ya "bu həftənin performans hesabatı".',
        'performance' => 'Performans üçün nəyi görmək istədiyinizi yazın. Məsələn: "ən çox işləyən texnik kimdir?" və ya "bu ay gecikən işçilər".',
        'analytics' => 'Analiz üçün konkret sual yazın. Məsələn: "hansı rayonda internet problemi çoxdur?" və ya "hansı avadanlıq daha çox sıradan çıxır?".',
    ];

    if (isset($guides[$intent])) {
        return ['ok' => false, 'kind' => 'guided_clarify', 'text' => $guides[$intent]];
    }

    // Truly unrecognised: offer the capability guide, not a generic error.
    return [
        'ok' => false,
        'kind' => 'guided_help',
        'text' => "Nə etmək istədiyinizi bir az dəqiqləşdirim. Bacardıqlarım: müştəri (yarat/göstər/dəyiş), anbar (mədaxil/təhvil/qalıq), tapşırıq/bilet, hesabat, performans və analiz.\n"
            . "Məsələn:\n- Yeni müştəri: Əli Vəliyev 0501234567 Astara\n- 2320025 kimdir?\n- Əliyə HG8145X6 ver\n- 2320025 üçün bilet aç internet yoxdur\n- bu ayın satış hesabatı",
    ];
}
