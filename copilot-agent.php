<?php
declare(strict_types=1);

require_once __DIR__ . '/copilot-engine.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/security-anomaly-lib.php';
require_once __DIR__ . '/customer-intelligence-lib.php';

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
    return trim(strtr($value, $map));
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

    $device = app_mgs_device_learning_profile_by_key($deviceKey, $db);
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

function app_copilot_agent_action_from_text(string $text, array $actor, ?PDO $db = null): ?array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return null;
    }
    $norm = app_copilot_agent_norm($text);

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
        return app_security_anomaly_answer($text, $db);
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
        $modemReply = app_mgs_build_modem_search_reply((string) $modemQuery, true, $db);
        if (!empty($modemReply['ok'])) {
            return $modemReply;
        }
    }
    if ((string) ($modemReply['reason'] ?? '') === 'not_found' && (string) ($modemReply['text'] ?? '') !== '') {
        return ['ok' => false, 'text' => (string) $modemReply['text']];
    }

    return [
        'ok' => false,
        'text' => "Superpilot sorgunu anlasilmadi.\nDaha genis imkan siyahisi ucun yazin: hansi suallara cavab verirsən\nNumuneler:\n- 2320025 niye qirilir?\n- 2320025 odenisi neye cixib?\n- SN123 kimdedir?\n- Samir Abdullayev adinda hansi mallar var?\n- 2320025 ucun bilet ac internet yoxdur\n- 2320025 radius yenile",
    ];
}
