<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/ai-team-lib.php';

app_session_boot();
$lang = app_lang();
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$payload = $token !== '' ? app_ai_team_task_response_token_payload($token) : null;
$taskId = is_array($payload) ? (int) ($payload['task_id'] ?? 0) : 0;
$db = app_db();
$task = ($taskId > 0 && $db instanceof PDO) ? app_ai_team_task_by_id($db, $taskId) : null;
$user = app_portal_current_user();

if (is_array($payload) && $taskId > 0 && $user === null) {
    $_SESSION['portal_login_return_to'] = 'ai-team-task-respond.php?token=' . rawurlencode($token);
    header('Location: staff-login.php?status=auth_required');
    exit;
}

$actorAllowed = is_array($user) && is_array($task)
    && (app_portal_is_admin($user) || (int) ($task['assigned_employee_account_id'] ?? 0) === (int) ($user['id'] ?? 0));

$flash = null;
if ($payload === null || $taskId <= 0) {
    $flash = ['type' => 'error', 'message' => 'Link etibarsızdır və ya vaxtı bitib.'];
} elseif ($task === null) {
    $flash = ['type' => 'error', 'message' => 'Tapşırıq tapılmadı.'];
} elseif ($user !== null && !$actorAllowed) {
    $flash = ['type' => 'error', 'message' => 'Bu tapşırıq yalnız təyin olunmuş əməkdaş və ya admin tərəfindən cavablandırıla bilər.'];
} elseif ((string) ($task['status'] ?? '') === 'completed') {
    $flash = ['type' => 'info', 'message' => 'Bu tapşırığa artıq cavab verilib.'];
} elseif ((string) ($task['status'] ?? '') !== 'approved') {
    $flash = ['type' => 'error', 'message' => 'Bu tapşırıq hazırda cavab gözləmə vəziyyətində deyil.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flash === null) {
    if (!app_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlaması uğursuz oldu.'];
    } else {
        try {
            app_ai_team_task_record_response($db, $taskId, (string) ($_POST['response_note'] ?? ''));
            $flash = ['type' => 'success', 'message' => 'Cavabınız qeydə alındı, təşəkkür edirik.'];
            $task = app_ai_team_task_by_id($db, $taskId);
        } catch (Throwable $exception) {
            $flash = ['type' => 'error', 'message' => 'Cavab yazılmadı: ' . $exception->getMessage()];
        }
    }
}
?>
<!doctype html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tapşırığa cavab</title>
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
<main class="portal-layout" style="max-width:640px;margin:40px auto">
    <div class="portal-card">
        <h2>Tapşırığa cavab</h2>
        <?php if ($flash !== null): ?>
            <div class="portal-alert is-<?= e((string) $flash['type']); ?>"><?= e((string) $flash['message']); ?></div>
        <?php endif; ?>
        <?php if (is_array($task) && $actorAllowed): ?>
            <div class="portal-muted-card" style="margin-top:14px">
                <strong><?= e((string) ($task['title'] ?? '-')); ?></strong>
                <p style="white-space:pre-wrap"><?= e((string) ($task['description'] ?? '-')); ?></p>
                <small>Öncəlik: <?= e((string) ($task['priority'] ?? '-')); ?></small>
            </div>
            <?php if ((string) ($task['status'] ?? '') === 'approved'): ?>
            <form class="portal-stacked-form" method="post" style="margin-top:14px">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <input type="hidden" name="token" value="<?= e($token); ?>">
                <div class="portal-form-row"><label>Cavabınız / nəticə</label><textarea name="response_note" rows="4" required></textarea></div>
                <button class="portal-primary-btn" type="submit">Tamamladım, cavabı göndər</button>
            </form>
            <?php elseif ((string) ($task['status'] ?? '') === 'completed'): ?>
                <div class="portal-muted-card" style="margin-top:14px"><strong>Əvvəlki cavab:</strong><p style="white-space:pre-wrap"><?= e((string) ($task['response_note'] ?? '-')); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
