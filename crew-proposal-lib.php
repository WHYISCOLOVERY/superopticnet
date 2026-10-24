<?php
declare(strict_types=1);

/**
 * CrewAI proposal ingest + read-only queue source.
 *
 * SECURITY CONTRACT (must not be loosened):
 *  - Rows are ALWAYS written with status='proposed'. This file NEVER executes,
 *    approves, sends, or mutates any customer/router/finance/message record.
 *  - It only persists an advisory summary + evidence for a human to review in
 *    the existing agent-ops queue. Approval and execution stay on the existing
 *    human-gated canonical service functions.
 *  - Sensitive modules (money / customer / router / messaging) are clamped to
 *    at least 'high' risk so they can never fall into any future low-risk
 *    auto-approval lane. This enforces "pul/mesaj/router/müştəri = insan təsdiqi".
 */

require_once __DIR__ . '/config.php';

const APP_CREW_PROPOSAL_MAX_SUMMARY = 500;
const APP_CREW_PROPOSAL_MAX_EVIDENCE_BYTES = 8192;
const APP_CREW_PROPOSAL_SENSITIVE_MODULES = ['finance', 'billing', 'crm', 'network', 'messaging'];
const APP_CREW_PROPOSAL_MODULES = ['finance', 'billing', 'crm', 'network', 'messaging', 'inventory', 'hr', 'monitoring', 'engineering', 'marketing', 'audit', 'cybersecurity', 'ai'];

/** Deterministic, idempotent id (date-truncated, like advisor proposals). */
function app_crew_proposal_id(array $payload): string
{
    $reportingDate = substr((string) ($payload['created_at'] ?? app_db_now()), 0, 10);
    $identity = [
        'module' => (string) ($payload['module'] ?? ''),
        'proposal_type' => (string) ($payload['proposal_type'] ?? ''),
        'reporting_date' => $reportingDate,
        'summary' => (string) ($payload['summary'] ?? ''),
        'risk' => (string) ($payload['risk'] ?? ''),
    ];
    return hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}

function app_crew_proposal_clamp_risk(string $module, string $risk): string
{
    $risk = in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'medium';
    if (in_array($module, APP_CREW_PROPOSAL_SENSITIVE_MODULES, true)) {
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        if ($rank[$risk] < $rank['high']) {
            $risk = 'high';
        }
    }
    return $risk;
}

/**
 * Validate + persist one crew proposal as status='proposed'. Idempotent on id.
 * @return array{ok:bool,reason?:string,id?:string,risk?:string,status?:string}
 */
function app_crew_proposal_ingest(PDO $db, array $payload): array
{
    $module = strtolower(trim((string) ($payload['module'] ?? '')));
    if (!in_array($module, APP_CREW_PROPOSAL_MODULES, true)) {
        return ['ok' => false, 'reason' => 'invalid_module'];
    }

    $summary = trim((string) ($payload['summary'] ?? ''));
    if ($summary === '' || mb_strlen($summary) > APP_CREW_PROPOSAL_MAX_SUMMARY) {
        return ['ok' => false, 'reason' => 'invalid_summary'];
    }

    // Reuse the shared external-prompt redactor (secrets/PII) if available.
    if (function_exists('app_ai_external_safe_prompt')) {
        $safe = app_ai_external_safe_prompt($summary);
        if (empty($safe['ok'])) {
            return ['ok' => false, 'reason' => 'unsafe_summary'];
        }
        $summary = (string) $safe['text'];
    }

    $now = app_db_now();
    $evidence = is_array($payload['evidence'] ?? null) ? array_values($payload['evidence']) : [];
    if ($evidence === []) {
        return ['ok' => false, 'reason' => 'evidence_required'];
    }
    // Server-side backstop: callers that do not use the Python governance
    // envelope still receive explicit human-gate fields before persistence.
    $firstEvidence = is_array($evidence[0] ?? null) ? $evidence[0] : [];
    $firstEvidence['source'] = mb_substr((string) ($firstEvidence['source'] ?? ($payload['source_agent'] ?? 'crewai')), 0, 120);
    $firstEvidence['detail'] = mb_substr((string) ($firstEvidence['detail'] ?? $summary), 0, 1200);
    $firstEvidence['observed_at'] = (string) ($firstEvidence['observed_at'] ?? ($payload['generated_at'] ?? $now ?? ''));
    $firstEvidence['freshness'] = (string) ($firstEvidence['freshness'] ?? 'not_provided');
    $firstEvidence['classification'] = (string) ($firstEvidence['classification'] ?? 'proposal_review');
    $firstEvidence['rollback_plan'] = (string) ($firstEvidence['rollback_plan'] ?? 'Tətbiq yoxdur; təsdiqdən əvvəl rollback planı tələb olunur.');
    $firstEvidence['verification_plan'] = (string) ($firstEvidence['verification_plan'] ?? 'Təsdiqdən sonra read-only yoxlama tələb olunur.');
    $firstEvidence['approval_required'] = true;
    $evidence[0] = $firstEvidence;
    $evidenceJson = json_encode(array_values($evidence), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    if (strlen($evidenceJson) > APP_CREW_PROPOSAL_MAX_EVIDENCE_BYTES) {
        return ['ok' => false, 'reason' => 'evidence_too_large'];
    }
    $reason = mb_substr((string) ($evidence[0]['detail'] ?? $summary), 0, APP_CREW_PROPOSAL_MAX_SUMMARY);

    $payload['created_at'] = $now;
    $risk = app_crew_proposal_clamp_risk($module, (string) ($payload['risk'] ?? 'medium'));
    $id = app_crew_proposal_id($payload + ['risk' => $risk]);

    try {
        $stmt = $db->prepare(
            "INSERT INTO crew_proposals
                (id, module, proposal_type, summary, reason, evidence_json, risk,
                 next_action, status, approval_owner, source_agent, created_at, updated_at)
             VALUES
                (:id, :module, :ptype, :summary, :reason, :evidence, :risk,
                 :next_action, 'proposed', 'super_admin', :agent, :created, :created)
             ON DUPLICATE KEY UPDATE
                summary = VALUES(summary), reason = VALUES(reason),
                evidence_json = VALUES(evidence_json), updated_at = VALUES(updated_at)"
        );
        $stmt->execute([
            ':id' => $id,
            ':module' => $module,
            ':ptype' => mb_substr((string) ($payload['proposal_type'] ?? $module . '_crew'), 0, 64),
            ':summary' => $summary,
            ':reason' => $reason,
            ':evidence' => $evidenceJson,
            ':risk' => $risk,
            // Crew proposals are advisory only. Never persist a model supplied
            // executable next_action that a future consumer could misread.
            ':next_action' => 'manual_review',
            ':agent' => mb_substr((string) ($payload['source_agent'] ?? 'crewai'), 0, 120),
            ':created' => $now,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'persist_failed'];
    }

    return ['ok' => true, 'id' => $id, 'risk' => $risk, 'status' => 'proposed'];
}

/**
 * Read-only queue source: surfaces status='proposed' crew rows in the existing
 * agent-ops queue. Registered under module 'ai' in
 * app_agent_ops_queue_sources_registry(). Never writes.
 */
function app_agent_ops_queue_source_crew_proposals(PDO $db, array $actor): array
{
    $linkByModule = [
        'finance' => 'operations-center.php?section=finance',
        'inventory' => 'operations-center.php?section=inventory',
        'network' => 'noc-devices.php?view=monitor',
        'messaging' => 'message-center.php',
        'crm' => 'crm.php',
        'hr' => 'operations-center.php?section=hr',
    ];
    try {
        $stmt = $db->query(
            "SELECT id, module, proposal_type, summary, reason, risk, next_action,
                    source_agent, created_at, updated_at
               FROM crew_proposals
              WHERE status = 'proposed'
           ORDER BY created_at DESC
              LIMIT 200"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return function_exists('app_agent_ops_queue_source_error')
            ? app_agent_ops_queue_source_error('crew_proposals', $e)
            : ['available' => false, 'items' => [], 'error' => 'source_unavailable'];
    }

    $items = [];
    foreach ($rows as $row) {
        $module = (string) $row['module'];
        $items[] = app_agent_ops_queue_build_item([
            'id' => (string) $row['id'],
            'source_module' => $module,
            'source_entity_type' => 'crew_proposal:' . (string) $row['proposal_type'],
            'source_entity_id' => (string) $row['id'],
            'title' => (string) $row['summary'],
            'reason' => (string) $row['reason'],
            'severity' => (string) $row['risk'],
            'status' => 'proposed',
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'data_status' => 'verified',
            'deep_link' => $linkByModule[$module] ?? '#',
            'required_permission' => $module,
            'approval_owner' => 'super_admin',
            'next_action_kind' => 'manual_review', // advisory only -> not confirmable/auto
            'confirmable' => false,
            'rollback_available' => false,
            'owner_role' => 'crew_' . $module,
        ]);
    }
    return ['available' => true, 'items' => $items];
}
