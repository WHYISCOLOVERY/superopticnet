<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/advisor-agent-lib.php';

app_session_boot();
$user = billing_require_employee(false, 'ai');
$db = app_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $db instanceof PDO) {
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if ($action !== 'prepare_advisor_preview') {
        http_response_code(400);
        exit('Naməlum əməliyyat.');
    }
    if (!app_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['ai_work_queue_flash'] = ['type' => 'error', 'message' => 'Sessiya yoxlaması uğursuz oldu.'];
        header('Location: ai-work-queue.php');
        exit;
    }

    $flash = null;
    try {
        $proposalId = trim((string) ($_POST['proposal_id'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($proposalId === '') {
            throw new InvalidArgumentException('proposal_id_required');
        }

        // Never trust a client-submitted proposal envelope: re-derive the
        // exact same deterministic briefing server-side and match by id, so
        // the signed evidence/risk/next_action a super-admin previews is
        // always freshly sourced, never something the client could shape.
        $proposal = null;
        foreach (app_advisor_all_briefings($db, $user) as $briefing) {
            foreach ((array) ($briefing['proposals'] ?? []) as $candidate) {
                if ((string) ($candidate['id'] ?? '') === $proposalId) {
                    $proposal = $candidate;
                    break 2;
                }
            }
        }
        if ($proposal === null) {
            throw new RuntimeException('proposal_not_found');
        }

        $payload = [
            'sku' => $sku,
            'summary' => $note !== '' ? $note : (string) ($proposal['summary'] ?? ''),
        ];
        $result = app_advisor_prepare_proposal_action($proposal, $payload, $user, $db);
        if (empty($result['ok'])) {
            $reasonLabels = [
                'invalid_proposal_structure' => 'Təklif strukturu düzgün deyil.',
                'proposal_tampered' => 'Təklif imzası doğrulanmadı.',
                'go_live_blocker_not_agent_writable' => 'Bu əməliyyat go-live blocker-dir, agentə açıq deyil.',
                'proposal_not_action_linked' => 'Bu təklif icra kontraktına bağlı deyil.',
                'unregistered_action_kind' => 'Əməliyyat növü qeydiyyatlı deyil.',
                'approval_owner_not_super_admin' => 'Bu təklif üçün təsdiq səlahiyyəti super-admindədir.',
                'actor_not_super_admin' => 'Bu önizləməni yalnız super-admin ala bilər.',
                'prompt_injection_blocked' => 'Qeyddə təhlükəsizlik pozuntusu aşkarlandı.',
                'duplicate_action' => 'Bu əməliyyat artıq mövcuddur (#' . (string) ((int) ($result['duplicate_id'] ?? 0)) . ').',
                'audit_unavailable_fail_closed' => 'Audit jurnalı əlçatan deyil, önizləmə bloklandı.',
            ];
            $flash = ['type' => 'error', 'message' => $reasonLabels[(string) ($result['reason'] ?? '')] ?? ('Önizləmə alınmadı: ' . (string) ($result['reason'] ?? 'naməlum'))];
        } else {
            $preview = (array) ($result['preview'] ?? []);
            $flash = ['type' => 'success', 'message' => 'Önizləmə hazırdır (heç nə icra edilmədi): '
                . (string) ($preview['label'] ?? '') . ' — ' . (string) ($preview['object'] ?? '') . '. '
                . 'Xarici təsir: ' . (string) ($preview['external_impact'] ?? '-') . '. '
                . 'Geri qaytarıla bilər: ' . (!empty($preview['reversible']) ? 'bəli' : 'xeyr') . '.'];
        }
    } catch (InvalidArgumentException|RuntimeException $exception) {
        $messages = [
            'proposal_id_required' => 'Təklif seçilməyib.',
            'proposal_not_found' => 'Təklif tapılmadı (yenilənmiş ola bilər, səhifəni yeniləyin).',
        ];
        $flash = ['type' => 'error', 'message' => $messages[$exception->getMessage()] ?? ('Xəta: ' . $exception->getMessage())];
    }

    $_SESSION['ai_work_queue_flash'] = $flash;
    header('Location: ai-work-queue.php');
    exit;
}

$aiSection = 'work-queue';
require __DIR__ . '/ai-section-page.php';
