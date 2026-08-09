<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/advisor-agent-lib.php';
require_once __DIR__ . '/ai-local-lib.php';
require_once __DIR__ . '/mgs-integration-lib.php';

/**
 * "AI Komanda" -- a persona-per-department Q&A + agenda-drafting hub built on
 * top of advisor-agent-lib.php's existing evidence-based briefings. This file
 * adds NO new data-gathering: every persona's factual grounding is the SAME
 * app_advisor_*_briefing() function the existing "Təsdiq Mərkəzi" already
 * uses, so this can never disagree with what that page shows.
 *
 * Finance has its own dedicated pilot (finance-ai-bot-lib.php, its own tab)
 * and is deliberately NOT one of the personas here.
 *
 * Hard rule: a persona can DRAFT a task (pending_admin_review) but never
 * assign it to an employee or send a Telegram message itself -- only
 * app_ai_team_task_approve(), called by an admin, does that.
 */
function app_ai_team_roster(): array
{
    return [
        'ceo' => [
            'label' => 'CEO',
            'persona_frame' => '30 illik ISP sektorunda çalışmış CEO',
            'advisor_key' => 'ceo',
            'telegram_bot_key' => null,
        ],
        'crm_manager' => [
            'label' => 'CRM meneceri',
            'persona_frame' => '30 illik təcrübəli CRM/müştəri xidmətləri meneceri',
            'advisor_key' => 'crm',
            'telegram_bot_key' => 'customer',
        ],
        'network_engineer' => [
            'label' => 'Şəbəkə mühəndisi',
            'persona_frame' => '20 illik təcrübəli şəbəkə (ISP/NOC) mühəndisi',
            'advisor_key' => 'noc',
            'telegram_bot_key' => 'network',
        ],
        'warehouse_keeper' => [
            'label' => 'Anbardar',
            'persona_frame' => '20 illik təcrübəli anbardar',
            'advisor_key' => 'techops',
            'telegram_bot_key' => 'warehouse',
        ],
        'marketing_specialist' => [
            'label' => 'Marketinq mütəxəssisi',
            'persona_frame' => '30 illik təcrübəli marketinq mütəxəssisi',
            'advisor_key' => 'marketing',
            'telegram_bot_key' => null,
        ],
    ];
}

function app_ai_team_persona(string $personaKey): ?array
{
    $roster = app_ai_team_roster();
    return $roster[$personaKey] ?? null;
}

function app_ai_team_require_schema(PDO $db): void
{
    $missing = [];
    foreach (['ai_team_conversations', 'ai_team_tasks'] as $table) {
        $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $statement->execute(['table' => $table]);
        if ((int) $statement->fetchColumn() === 0) {
            $missing[] = $table;
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('ai_team_schema_not_migrated:' . implode(',', $missing));
    }
}

function app_ai_team_access_allowed(string $personaKey, array $actor): bool
{
    $persona = app_ai_team_persona($personaKey);
    if ($persona === null) {
        return false;
    }
    return app_advisor_access_allowed((string) $persona['advisor_key'], $actor);
}

/**
 * Reuses the exact same evidence-based briefing the "Təsdiq Mərkəzi" (agent-
 * ops-queue-lib.php) already renders for this advisor key -- never a second,
 * possibly-diverging query path.
 */
function app_ai_team_context_bundle(string $personaKey, PDO $db): array
{
    $persona = app_ai_team_persona($personaKey);
    $advisorKey = (string) ($persona['advisor_key'] ?? '');
    $proposals = match ($advisorKey) {
        'ceo' => app_advisor_ceo_briefing($db),
        'noc' => app_advisor_noc_briefing($db),
        'techops' => app_advisor_techops_briefing($db),
        'marketing' => app_advisor_marketing_briefing($db),
        'crm' => app_advisor_crm_briefing($db),
        default => [],
    };

    return [
        'as_of' => date('Y-m-d H:i'),
        'briefing' => $proposals,
    ];
}

function app_ai_team_system_prompt(string $personaKey, array $bundle): string
{
    $persona = app_ai_team_persona($personaKey);
    $frame = (string) ($persona['persona_frame'] ?? 'daxili AI köməkçi');
    $facts = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return "Sən SuperOptic ISP şirkətinin " . $frame . " AI-köməkçisisən. "
        . "Yalnız aşağıda JSON kimi verilmiş REAL sistem faktlarına əsaslanaraq qısa, konkret, Azərbaycan dilində cavab ver. "
        . "Rəqəm uydurmaq QADAĞANDIR -- yalnız verilən faktları istifadə et, əskik məlumat varsa açıq de. "
        . "Sən heç bir əməliyyatı özün icra etmirsən, heç bir işçiyə birbaşa mesaj göndərmirsən; yalnız təhlil, tövsiyə və gündəlik təklifi verirsən, "
        . "real təyinat və bildiriş YALNIZ insan admin təsdiqi ilə göndərilir.\n\n"
        . "SİSTEM FAKTLARI:\n" . $facts;
}

function app_ai_team_detect_agenda_intent(string $question): bool
{
    $normalized = mb_strtolower($question);
    foreach (['gündəlik', 'gundelik', 'tapşırıq', 'tapsiriq', 'iş icrası', 'is icrasi', 'nə etməliyik', 'ne etmeliyik', 'agenda'] as $marker) {
        if (str_contains($normalized, $marker)) {
            return true;
        }
    }
    return false;
}

/**
 * Answers a free-form question for one persona, grounded in that persona's
 * advisor briefing. When the question asks for an agenda/task list, every
 * high/critical-risk item in the CURRENT briefing is drafted as a
 * pending_admin_review row in ai_team_tasks -- never sent, never assigned,
 * until app_ai_team_task_approve() runs.
 */
function app_ai_team_answer(PDO $db, array $actor, string $personaKey, string $question, ?callable $transport = null): array
{
    if (!app_ai_team_access_allowed($personaKey, $actor)) {
        throw new RuntimeException('ai_team_forbidden');
    }
    app_ai_team_require_schema($db);

    $question = trim(clean_text($question));
    if ($question === '') {
        throw new InvalidArgumentException('question_required');
    }

    $bundle = app_ai_team_context_bundle($personaKey, $db);
    $chat = app_ai_local_chat($question, [
        'system' => app_ai_team_system_prompt($personaKey, $bundle),
        'max_tokens' => 500,
    ], $transport);

    $llmOk = !empty($chat['ok']);
    if ($llmOk) {
        $answer = (string) $chat['text'];
    } else {
        $highlights = [];
        foreach ((array) $bundle['briefing'] as $proposal) {
            if (in_array((string) ($proposal['risk'] ?? ''), ['high', 'critical'], true)) {
                $highlights[] = (string) ($proposal['summary'] ?? '');
            }
        }
        $answer = 'Lokal AI hazırda əlçatan deyil (' . (string) ($chat['reason'] ?? 'naməlum səbəb') . '). '
            . ($highlights !== [] ? 'Yüksək riskli faktlar: ' . implode(' ', $highlights) : 'Hazırda yüksək riskli məqam qeydə alınmayıb.');
    }

    $stagedTaskIds = [];
    if (app_ai_team_detect_agenda_intent($question)) {
        foreach ((array) $bundle['briefing'] as $proposal) {
            if (!in_array((string) ($proposal['risk'] ?? ''), ['high', 'critical'], true)) {
                continue;
            }
            $summary = (string) ($proposal['summary'] ?? '');
            if ($summary === '') {
                continue;
            }
            $evidenceLines = [];
            foreach ((array) ($proposal['evidence'] ?? []) as $evidence) {
                $evidenceLines[] = (string) ($evidence['source'] ?? '') . ': ' . (string) ($evidence['detail'] ?? '');
            }
            $insert = $db->prepare(
                'INSERT INTO ai_team_tasks
                    (persona_key, proposal_type, title, description, reasoning, risk, status, created_by_account_id, created_by_name)
                 VALUES (:persona_key, :proposal_type, :title, :description, :reasoning, :risk, "pending_admin_review", :actor_id, :actor_name)'
            );
            $insert->execute([
                'persona_key' => $personaKey,
                'proposal_type' => (string) ($proposal['proposal_type'] ?? ''),
                'title' => mb_substr($summary, 0, 191),
                'description' => $summary . ("\n\n" . implode("\n", $evidenceLines)),
                'reasoning' => mb_substr($summary, 0, 255),
                'risk' => (string) ($proposal['risk'] ?? 'medium'),
                'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
                'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? '') ?: null,
            ]);
            $stagedTaskIds[] = (int) $db->lastInsertId();
        }
        if ($stagedTaskIds !== []) {
            $answer .= "\n\n[" . count($stagedTaskIds) . ' tapşırıq gündəliyə qeydə alındı (#' . implode(', #', $stagedTaskIds) . "). Təyinat üçün başqa admin təsdiqi lazımdır.]";
        }
    }

    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $actorName = (string) ($actor['full_name'] ?? $actor['login'] ?? '');
    $logInsert = $db->prepare(
        'INSERT INTO ai_team_conversations
            (persona_key, question, answer, llm_ok, llm_reason, context_bundle_json, staged_task_ids_json, actor_account_id, actor_name)
         VALUES (:persona_key, :question, :answer, :llm_ok, :llm_reason, :context_bundle_json, :staged_task_ids_json, :actor_id, :actor_name)'
    );
    $logInsert->execute([
        'persona_key' => $personaKey,
        'question' => $question,
        'answer' => $answer,
        'llm_ok' => $llmOk ? 1 : 0,
        'llm_reason' => $llmOk ? null : (string) ($chat['reason'] ?? ''),
        'context_bundle_json' => json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'staged_task_ids_json' => $stagedTaskIds !== [] ? json_encode($stagedTaskIds) : null,
        'actor_id' => $actorId,
        'actor_name' => $actorName !== '' ? $actorName : null,
    ]);

    return [
        'ok' => true,
        'answer' => $answer,
        'llm_ok' => $llmOk,
        'staged_task_ids' => $stagedTaskIds,
    ];
}

/**
 * Signed, time-bound link an employee opens to respond to an approved task
 * -- mirrors app_portal_ticket_close_link()'s exact HMAC pattern
 * (app_portal_sign_token/app_portal_verify_token) with its own dedicated
 * secret/audience so it can never be confused with a ticket-close token.
 */
function app_ai_team_task_response_link(array $task): string
{
    $secret = app_environment_secret('AI_TEAM_TASK_RESPONSE_SECRET');
    if ($secret === '') {
        return '';
    }
    $now = time();
    $token = app_portal_sign_token([
        'iss' => app_config('portal_token_issuer', 'portal.superoptic.net'),
        'aud' => 'portal.ai-team.task-response',
        'type' => 'ai-team-task-response',
        'task_id' => (string) ($task['id'] ?? ''),
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + 7 * 86400,
        'jti' => bin2hex(random_bytes(8)),
    ], $secret);

    $base = rtrim(app_config('ai_team_task_response_base_url', app_config('ticket_close_base_url', '')), '/');
    if ($base === '' || $token === '') {
        return '';
    }
    return $base . '/ai-team-task-respond.php?token=' . rawurlencode($token);
}

function app_ai_team_task_response_token_payload(string $token): ?array
{
    $secret = app_environment_secret('AI_TEAM_TASK_RESPONSE_SECRET');
    if ($secret === '') {
        return null;
    }
    return app_portal_verify_token($token, $secret, [
        'iss' => app_config('portal_token_issuer', 'portal.superoptic.net'),
        'aud' => 'portal.ai-team.task-response',
        'type' => 'ai-team-task-response',
    ]);
}

/**
 * Admin-only: reviews an AI-drafted task, assigns it to a real employee, and
 * -- if the persona has a configured Telegram bot -- notifies that bot's
 * group with the task text and a signed response link. This is the ONLY
 * function in this file that can make a task visible to an employee or send
 * a live message.
 */
function app_ai_team_task_approve(PDO $db, array $actor, int $taskId, int $assigneeAccountId, string $priority = 'normal'): array
{
    if (!app_portal_is_admin($actor)) {
        throw new RuntimeException('ai_team_forbidden');
    }
    app_ai_team_require_schema($db);
    if ($assigneeAccountId <= 0) {
        throw new InvalidArgumentException('assignee_required');
    }

    $taskStatement = $db->prepare('SELECT * FROM ai_team_tasks WHERE id = :id');
    $taskStatement->execute(['id' => $taskId]);
    $task = $taskStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($task)) {
        throw new RuntimeException('task_not_found');
    }
    if ((string) $task['status'] !== 'pending_admin_review') {
        throw new RuntimeException('task_already_resolved');
    }

    $employeeStatement = $db->prepare('SELECT id, full_name FROM portal_accounts WHERE id = :id AND role = "employee" AND is_active = 1');
    $employeeStatement->execute(['id' => $assigneeAccountId]);
    $employee = $employeeStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($employee)) {
        throw new RuntimeException('assignee_not_found');
    }

    $priority = in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal';
    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $actorName = (string) ($actor['full_name'] ?? $actor['login'] ?? '');

    $update = $db->prepare(
        'UPDATE ai_team_tasks
            SET status = "approved", assigned_employee_account_id = :assignee_id, assigned_employee_name = :assignee_name,
                priority = :priority, approved_by_account_id = :actor_id, approved_by_name = :actor_name, approved_at = NOW()
          WHERE id = :id'
    );
    $update->execute([
        'assignee_id' => (int) $employee['id'],
        'assignee_name' => (string) $employee['full_name'],
        'priority' => $priority,
        'actor_id' => $actorId,
        'actor_name' => $actorName !== '' ? $actorName : null,
        'id' => $taskId,
    ]);
    $task['assigned_employee_name'] = (string) $employee['full_name'];

    $telegramSent = false;
    $telegramReason = 'no_bot_for_persona';
    $persona = app_ai_team_persona((string) $task['persona_key']);
    $botKey = $persona['telegram_bot_key'] ?? null;
    if (is_string($botKey) && $botKey !== '') {
        $responseLink = app_ai_team_task_response_link($task + ['id' => $taskId]);
        $text = "Yeni tapşırıq (" . ($persona['label'] ?? $task['persona_key']) . "):\n"
            . (string) $task['title'] . "\n\n" . (string) $task['description']
            . "\nTəyin olunan: " . (string) $employee['full_name']
            . "\nÖncəlik: " . $priority
            . ($responseLink !== '' ? ("\n\nCavab vermək üçün: " . $responseLink) : '');
        $sendResult = app_mgs_telegram_bot_send_message($botKey, $text);
        $telegramSent = !empty($sendResult['ok']);
        $telegramReason = $telegramSent ? '' : (string) ($sendResult['reason'] ?? 'send_failed');
    }

    $db->prepare('UPDATE ai_team_tasks SET telegram_sent = :sent, telegram_sent_reason = :reason WHERE id = :id')
        ->execute(['sent' => $telegramSent ? 1 : 0, 'reason' => $telegramReason !== '' ? $telegramReason : null, 'id' => $taskId]);

    return ['ok' => true, 'id' => $taskId, 'telegram_sent' => $telegramSent, 'telegram_reason' => $telegramReason];
}

function app_ai_team_task_dismiss(PDO $db, array $actor, int $taskId, string $reason): array
{
    if (!app_portal_is_admin($actor)) {
        throw new RuntimeException('ai_team_forbidden');
    }
    app_ai_team_require_schema($db);
    $reason = mb_substr(clean_text($reason), 0, 255);
    if ($reason === '') {
        throw new InvalidArgumentException('reason_required');
    }

    $taskStatement = $db->prepare('SELECT status FROM ai_team_tasks WHERE id = :id');
    $taskStatement->execute(['id' => $taskId]);
    $status = $taskStatement->fetchColumn();
    if ($status === false) {
        throw new RuntimeException('task_not_found');
    }
    if ((string) $status !== 'pending_admin_review') {
        throw new RuntimeException('task_already_resolved');
    }

    $db->prepare('UPDATE ai_team_tasks SET status = "dismissed", dismissed_reason = :reason, approved_by_account_id = :actor_id, approved_by_name = :actor_name, approved_at = NOW() WHERE id = :id')
        ->execute([
            'reason' => $reason,
            'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
            'actor_name' => (string) ($actor['full_name'] ?? $actor['login'] ?? '') ?: null,
            'id' => $taskId,
        ]);

    return ['ok' => true, 'id' => $taskId];
}

/**
 * Called from ai-team-task-respond.php (token-gated, not staff-login-gated
 * -- the employee opens this from a Telegram link on their phone).
 */
function app_ai_team_task_record_response(PDO $db, int $taskId, string $responseNote): array
{
    app_ai_team_require_schema($db);
    $taskStatement = $db->prepare('SELECT status FROM ai_team_tasks WHERE id = :id');
    $taskStatement->execute(['id' => $taskId]);
    $status = $taskStatement->fetchColumn();
    if ($status === false) {
        throw new RuntimeException('task_not_found');
    }
    if ((string) $status !== 'approved') {
        throw new RuntimeException('task_not_awaiting_response');
    }

    $db->prepare('UPDATE ai_team_tasks SET status = "completed", response_note = :note, responded_at = NOW() WHERE id = :id')
        ->execute(['note' => mb_substr(clean_text($responseNote), 0, 2000), 'id' => $taskId]);

    return ['ok' => true, 'id' => $taskId];
}

function app_ai_team_task_by_id(PDO $db, int $taskId): ?array
{
    app_ai_team_require_schema($db);
    $statement = $db->prepare('SELECT * FROM ai_team_tasks WHERE id = :id');
    $statement->execute(['id' => $taskId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function app_ai_team_pending_tasks(PDO $db, int $limit = 30): array
{
    app_ai_team_require_schema($db);
    $statement = $db->prepare('SELECT * FROM ai_team_tasks WHERE status = "pending_admin_review" ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_ai_team_approved_tasks(PDO $db, int $limit = 30): array
{
    app_ai_team_require_schema($db);
    $statement = $db->prepare('SELECT * FROM ai_team_tasks WHERE status = "approved" ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_ai_team_recent_conversations(PDO $db, ?string $personaKey = null, int $limit = 10): array
{
    app_ai_team_require_schema($db);
    if ($personaKey !== null) {
        $statement = $db->prepare('SELECT * FROM ai_team_conversations WHERE persona_key = :persona_key ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
        $statement->execute(['persona_key' => $personaKey]);
    } else {
        $statement = $db->prepare('SELECT * FROM ai_team_conversations ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
        $statement->execute();
    }
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_ai_team_active_employees(PDO $db): array
{
    $statement = $db->query('SELECT id, full_name, employee_role FROM portal_accounts WHERE role = "employee" AND is_active = 1 ORDER BY full_name, id LIMIT 200');
    return $statement !== false ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
