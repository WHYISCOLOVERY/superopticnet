<?php
declare(strict_types=1);

/**
 * Stage 5 - Unified Agent Workspace contract (audit sections 10, 10.1, 10.2).
 *
 * The agent never writes new business SQL. It orchestrates the existing,
 * already-idempotent, audited and permissioned service layer. This library is
 * the canonical gate that every agent action must pass through:
 *
 *   allowlist -> go-live blocker check -> module permission -> preview ->
 *   confirmation -> (existing service call) -> verification -> audit -> undo
 *
 * It is additive: the working copilot flow in copilot-agent.php keeps its
 * behavior and consults this gate for defense-in-depth.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feature-flags-lib.php';

/**
 * Canonical registry of agent-writable actions (audit 10.2).
 *
 * Each entry declares the module whose service permission the actor must hold,
 * whether confirmation is mandatory, how the action is reversed, and whether it
 * is a go-live blocker that must never be opened to agent write.
 *
 * @return array<string,array<string,mixed>>
 */
function app_agent_action_registry(): array
{
    return [
        // --- allowed, orchestrating existing idempotent/audited services ---
        'create_customer' => [
            'label' => 'Yeni müştəri yarat',
            'modules' => ['billing'],
            'risk' => 'high',
            'confirmation_required' => true,
            'reversible' => true,
            'undo' => 'deactivate_customer',
            'go_live_blocker' => false,
        ],
        'create_ticket' => [
            'label' => 'Bilet yarat',
            // Customer-service and field/NOC roles both raise tickets.
            'modules' => ['messaging', 'network'],
            'risk' => 'low',
            'confirmation_required' => true,
            'reversible' => true,
            'undo' => 'ticket_void',
            'go_live_blocker' => false,
        ],
        'send_customer_message' => [
            'label' => 'Müştəriyə mesaj göndər',
            'modules' => ['messaging'],
            'risk' => 'medium',
            'confirmation_required' => true,
            'reversible' => false,
            'undo' => 'none',
            'go_live_blocker' => false,
        ],
        'inventory_movement' => [
            'label' => 'İnventar hərəkəti',
            'modules' => ['inventory'],
            'risk' => 'medium',
            'confirmation_required' => true,
            'reversible' => true,
            'undo' => 'reverse_movement',
            'go_live_blocker' => false,
        ],
        'radius_refresh_disconnect' => [
            'label' => 'RADIUS disconnect sorğusu',
            'modules' => ['network'],
            'risk' => 'medium',
            'confirmation_required' => true,
            'reversible' => true,
            'undo' => 'cancel_request',
            'go_live_blocker' => false,
        ],
        'ont_restart_request' => [
            'label' => 'ONT restart sorğusu',
            'modules' => ['network'],
            'risk' => 'medium',
            'confirmation_required' => true,
            'reversible' => true,
            'undo' => 'cancel_request',
            'go_live_blocker' => false,
        ],
        'ont_control_request' => [
            'label' => 'ONT idarə sorğusu',
            'modules' => ['network'],
            'risk' => 'medium',
            'confirmation_required' => true,
            'reversible' => true,
            'undo' => 'cancel_request',
            'go_live_blocker' => false,
        ],

        // --- registered but blocked: go-live blockers, never agent-writable ---
        'billing_payment' => [
            'label' => 'Billing ödəniş',
            'modules' => ['billing'],
            'risk' => 'critical',
            'confirmation_required' => true,
            'reversible' => false,
            'undo' => 'compensating_reversal',
            'go_live_blocker' => true,
        ],
        'finance_payout' => [
            'label' => 'Maliyyə ödənişi / writeoff',
            'modules' => ['finance'],
            'risk' => 'critical',
            'confirmation_required' => true,
            'reversible' => false,
            'undo' => 'compensating_reversal',
            'go_live_blocker' => true,
        ],
        'noc_command' => [
            'label' => 'Canlı NOC cihaz əmri',
            'modules' => ['network'],
            'risk' => 'critical',
            'confirmation_required' => true,
            'reversible' => false,
            'undo' => 'none',
            'go_live_blocker' => true,
        ],
        'role_change' => [
            'label' => 'Rol / icazə dəyişikliyi',
            'modules' => ['hr'],
            'risk' => 'critical',
            'confirmation_required' => true,
            'reversible' => true,
            'undo' => 'restore_role',
            'go_live_blocker' => true,
        ],
    ];
}

function app_agent_workspace_enabled(?PDO $db = null, array $actor = []): bool
{
    $db = $db ?? (function_exists('app_db') ? app_db() : null);
    if (!$db instanceof PDO || !function_exists('app_feature_flag_enabled')) {
        return false;
    }
    try {
        return app_feature_flag_enabled($db, 'stage5_agent_workspace', $actor, false);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Central gate for every agent action (audit 10.1 steps 3 and 6).
 *
 * @return array{ok:bool,reason?:string,module?:string,entry?:array<string,mixed>}
 */
function app_agent_action_gate(string $kind, array $actor, ?PDO $db = null): array
{
    $registry = app_agent_action_registry();
    if (!isset($registry[$kind])) {
        return ['ok' => false, 'reason' => 'action_not_in_allowlist'];
    }
    $entry = $registry[$kind];

    if (!empty($entry['go_live_blocker'])) {
        // These stay closed to agent write until their go-live blockers are
        // resolved, regardless of the actor's role.
        return ['ok' => false, 'reason' => 'go_live_blocker_not_agent_writable', 'entry' => $entry];
    }

    $modules = app_agent_action_modules($entry);
    if ($modules === [] || !function_exists('app_portal_can_access_module')) {
        return ['ok' => false, 'reason' => 'module_permission_denied', 'module' => implode(',', $modules), 'entry' => $entry];
    }
    foreach ($modules as $module) {
        if (app_portal_can_access_module($actor, $module)) {
            return ['ok' => true, 'entry' => $entry, 'module' => $module];
        }
    }

    return ['ok' => false, 'reason' => 'module_permission_denied', 'module' => implode(',', $modules), 'entry' => $entry];
}

/**
 * Normalized list of modules that satisfy an action's permission (any one is
 * enough). Accepts both the `modules` list and a legacy single `module` key.
 *
 * @param array<string,mixed> $entry
 * @return list<string>
 */
function app_agent_action_modules(array $entry): array
{
    if (isset($entry['modules']) && is_array($entry['modules'])) {
        return array_values(array_filter(array_map('strval', $entry['modules'])));
    }
    $single = trim((string) ($entry['module'] ?? ''));
    return $single === '' ? [] : [$single];
}

/**
 * Structured operation preview (audit 10.1 step 4): who, what, DB/business
 * impact and external-provider impact, plus whether confirmation is required.
 *
 * @return array<string,mixed>
 */
function app_agent_action_preview(string $kind, array $payload, array $actor): array
{
    $registry = app_agent_action_registry();
    $entry = $registry[$kind] ?? ['label' => $kind, 'modules' => [], 'confirmation_required' => true, 'reversible' => false];

    $externalImpact = match ($kind) {
        'send_customer_message' => 'Xarici SMS/WhatsApp provayderinə növbə yazılır (təsdiqdən sonra).',
        'radius_refresh_disconnect', 'ont_restart_request', 'ont_control_request' => 'Yalnız daxili pending sorğu yaradılır; canlı cihaz əmri ayrıca apply tələb edir.',
        'inventory_movement' => 'Xarici təsir yoxdur; daxili anbar ledgeri.',
        'create_ticket' => 'Xarici təsir yoxdur; daxili ticket qeydi.',
        default => 'Naməlum / bloklanmış.',
    };

    return [
        'kind' => $kind,
        'label' => (string) ($entry['label'] ?? $kind),
        'module' => implode(',', app_agent_action_modules($entry)),
        'risk' => (string) ($entry['risk'] ?? 'medium'),
        'actor' => (string) ($actor['full_name'] ?? $actor['name'] ?? 'agent'),
        'object' => app_agent_action_object_label($kind, $payload),
        'business_impact' => (string) ($payload['summary'] ?? ($entry['label'] ?? $kind)),
        'external_impact' => $externalImpact,
        'confirmation_required' => (bool) ($entry['confirmation_required'] ?? true),
        'reversible' => (bool) ($entry['reversible'] ?? false),
        'undo_strategy' => (string) ($entry['undo'] ?? 'none'),
    ];
}

function app_agent_action_object_label(string $kind, array $payload): string
{
    $subscriber = trim((string) ($payload['subscriber_code'] ?? ''));
    $accountId = (int) ($payload['customer_account_id'] ?? 0);
    if ($subscriber !== '') {
        return 'Müştəri ' . $subscriber;
    }
    if ($accountId > 0) {
        return 'Müştəri #' . $accountId;
    }
    $sku = trim((string) (($payload['movement']['sku'] ?? $payload['sku'] ?? '')));
    if ($sku !== '') {
        return 'SKU ' . $sku;
    }
    return 'Obyekt təyin olunmayıb';
}

/**
 * Detect prompt-injection style directives in untrusted input (audit 10.1
 * step 10). Returns true when the text tries to override instructions or
 * escalate the agent.
 */
function app_agent_detect_injection(string $text): bool
{
    $normalized = mb_strtolower($text);
    $patterns = [
        'ignore previous', 'ignore all previous', 'disregard previous',
        'system prompt', 'you are now', 'act as', 'developer mode',
        'bypass', 'without confirmation', 'təsdiq olmadan', 'təsdiqsiz',
        'admin ol', 'become admin', 'grant me', 'reveal the', 'print your',
        'əvvəlki təlimat', 'göstərişləri unut', 'icazəni artır',
    ];
    foreach ($patterns as $pattern) {
        if (str_contains($normalized, $pattern)) {
            return true;
        }
    }
    return false;
}

/**
 * Redact secrets and PII before any text reaches the model prompt or audit log
 * (audit 10.1 step 10). Removes password/api-key style tokens, full FIN codes
 * and long digit runs.
 */
function app_agent_sanitize_for_prompt(string $text): string
{
    // API keys / bearer tokens / long secrets
    $text = preg_replace('/\b(?:sk|pk|key|token|secret|bearer)[-_ ]?[A-Za-z0-9_\-]{12,}\b/i', '[redacted-secret]', $text) ?? $text;
    // Azerbaijani FIN code (7 alphanumerics) explicitly labelled
    $text = preg_replace('/\bFIN[:\s]*[A-Z0-9]{7}\b/i', 'FIN:[redacted]', $text) ?? $text;
    // password: value
    $text = preg_replace('/\b(pass(?:word)?|parol)\s*[:=]\s*\S+/i', '$1:[redacted]', $text) ?? $text;
    // long digit runs (card / long id) - keep last 4
    $text = preg_replace_callback('/\b\d{10,}\b/', static fn ($m) => '[redacted]' . substr($m[0], -4), $text) ?? $text;
    return $text;
}

/**
 * Idempotency guard (audit Merhele 5: duplicate retry must not double-execute).
 * Returns the existing executed action id when the same idempotency key was
 * already run, otherwise 0.
 */
function app_agent_idempotency_existing_id(PDO $db, string $idempotencyKey): int
{
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        return 0;
    }
    try {
        $statement = $db->prepare(
            'SELECT id FROM mgs_operation_actions
              WHERE action_type = "copilot_action"
                AND status IN ("pending", "executed")
                AND JSON_EXTRACT(payload_json, "$.idempotency_key") = :key
              ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['key' => $idempotencyKey]);
        return (int) ($statement->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Full pre-execution contract check used by the agent before it stages a
 * pending action. Bundles gate + preview + injection + idempotency.
 *
 * @return array{ok:bool,reason?:string,preview?:array<string,mixed>,duplicate_id?:int}
 */
function app_agent_prepare_action(string $kind, array $payload, array $actor, PDO $db): array
{
    // Untrusted free-text fields must not carry injection into a privileged act.
    $freeText = trim((string) ($payload['message'] ?? '') . ' ' . (string) ($payload['message_body'] ?? '') . ' ' . (string) ($payload['summary'] ?? ''));
    if ($freeText !== '' && app_agent_detect_injection($freeText)) {
        return ['ok' => false, 'reason' => 'prompt_injection_blocked'];
    }

    $gate = app_agent_action_gate($kind, $actor, $db);
    if (empty($gate['ok'])) {
        return ['ok' => false, 'reason' => (string) ($gate['reason'] ?? 'gate_denied')];
    }

    $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        $existing = app_agent_idempotency_existing_id($db, $idempotencyKey);
        if ($existing > 0) {
            return ['ok' => false, 'reason' => 'duplicate_action', 'duplicate_id' => $existing];
        }
    }

    return [
        'ok' => true,
        'preview' => app_agent_action_preview($kind, $payload, $actor),
    ];
}

/**
 * Reverse a previously executed reversible action (audit 10.1 step 9). No hard
 * delete: this produces a compensating/void action, never removes the record.
 *
 * @return array{ok:bool,reason?:string,summary?:string}
 */
function app_agent_action_undo(int $actionId, array $actor, PDO $db): array
{
    $statement = $db->prepare('SELECT * FROM mgs_operation_actions WHERE id = :id AND action_type = "copilot_action" LIMIT 1');
    $statement->execute(['id' => $actionId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'reason' => 'action_not_found'];
    }
    if ((string) ($row['status'] ?? '') !== 'executed') {
        return ['ok' => false, 'reason' => 'action_not_executed'];
    }

    $payload = function_exists('app_db_json_decode')
        ? app_db_json_decode((string) ($row['payload_json'] ?? ''), [])
        : (json_decode((string) ($row['payload_json'] ?? ''), true) ?: []);
    $kind = (string) ($payload['kind'] ?? '');
    $entry = app_agent_action_registry()[$kind] ?? [];
    if (empty($entry['reversible'])) {
        return ['ok' => false, 'reason' => 'action_not_reversible'];
    }

    // Permission for the undo mirrors the forward action's module.
    $gate = app_agent_action_gate($kind, $actor, $db);
    if (empty($gate['ok'])) {
        return ['ok' => false, 'reason' => 'undo_permission_denied'];
    }

    $payload['undo'] = [
        'strategy' => (string) ($entry['undo'] ?? 'none'),
        'actor_name' => (string) ($actor['full_name'] ?? $actor['name'] ?? 'agent'),
        'at' => function_exists('app_db_now') ? app_db_now() : date('Y-m-d H:i:s'),
    ];
    $update = $db->prepare(
        'UPDATE mgs_operation_actions
            SET status = "reversed", payload_json = :payload_json
          WHERE id = :id AND status = "executed"'
    );
    $update->execute([
        'payload_json' => function_exists('app_db_json_encode') ? app_db_json_encode($payload) : json_encode($payload),
        'id' => $actionId,
    ]);
    if ($update->rowCount() < 1) {
        return ['ok' => false, 'reason' => 'undo_conflict'];
    }

    return [
        'ok' => true,
        'summary' => 'Əməliyyat geri qaytarıldı (#' . $actionId . ', strategiya: ' . (string) ($entry['undo'] ?? 'none') . ')',
    ];
}
