<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai-team-lib.php';

app_session_boot();
$user = billing_require_employee(false, 'ai');
$db = app_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $db instanceof PDO) {
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!in_array($action, ['ask_ai_team_bot', 'approve_ai_team_task', 'dismiss_ai_team_task', 'run_daily_agenda'], true)) {
        http_response_code(400);
        exit('Naməlum əməliyyat.');
    }
    if (!app_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['ai_team_flash'] = ['type' => 'error', 'message' => 'Sessiya yoxlaması uğursuz oldu.'];
        header('Location: ai-team.php');
        exit;
    }

    $flash = null;
    try {
        if ($action === 'ask_ai_team_bot') {
            $result = app_ai_team_answer($db, $user, (string) ($_POST['persona_key'] ?? ''), (string) ($_POST['question'] ?? ''));
            $flash = ['type' => 'success', 'message' => 'Sual göndərildi, cavab aşağıda görünür.'];
        } elseif ($action === 'approve_ai_team_task') {
            app_ai_team_task_approve(
                $db,
                $user,
                (int) ($_POST['task_id'] ?? 0),
                (int) ($_POST['assignee_account_id'] ?? 0),
                (string) ($_POST['priority'] ?? 'normal')
            );
            $flash = ['type' => 'success', 'message' => 'Tapşırıq təsdiqləndi və göndərildi.'];
        } elseif ($action === 'dismiss_ai_team_task') {
            app_ai_team_task_dismiss($db, $user, (int) ($_POST['task_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            $flash = ['type' => 'success', 'message' => 'Tapşırıq layihəsi rədd edildi.'];
        } elseif ($action === 'run_daily_agenda') {
            $agenda = app_ai_team_run_daily_agenda_now($db, $user);
            $staged = (int) ($agenda['total_staged'] ?? 0);
            $flash = ['type' => 'success', 'message' => $staged > 0
                ? ('Gündəlik agenda işlədi: ' . $staged . ' yeni tapşırıq layihəsi qeydə alındı (admin təsdiqi gözləyir).')
                : 'Gündəlik agenda işlədi: yeni yüksək-riskli tapşırıq layihəsi yaranmadı (hamısı artıq qeydə alınıb və ya risk aşağıdır).'];
        }
    } catch (Throwable $exception) {
        $errorMessages = [
            'ai_team_forbidden' => 'Bu əməliyyat üçün icazəniz yoxdur.',
            'question_required' => 'Sual bos ola bilməz.',
            'assignee_required' => 'Əməkdaş seçilməlidir.',
            'assignee_not_found' => 'Seçilmiş əməkdaş tapılmadı və ya aktiv deyil.',
            'task_not_found' => 'Tapşırıq tapılmadı.',
            'task_already_resolved' => 'Bu tapşırığa artıq qərar verilib.',
            'reason_required' => 'Rədd səbəbi bos ola bilməz.',
        ];
        $flash = ['type' => 'error', 'message' => $errorMessages[$exception->getMessage()] ?? ('Əməliyyat alınmadı: ' . $exception->getMessage())];
    }

    $_SESSION['ai_team_flash'] = $flash;
    header('Location: ai-team.php');
    exit;
}

$aiSection = 'team';
require __DIR__ . '/ai-section-page.php';
