<?php
declare(strict_types=1);

$personas = (array) ($aiContext['data']['personas'] ?? []);
$pendingTasks = (array) ($aiContext['data']['pending_tasks'] ?? []);
$approvedTasks = (array) ($aiContext['data']['approved_tasks'] ?? []);
$employees = (array) ($aiContext['data']['employees'] ?? []);
$lastAgendaRun = $aiContext['data']['last_agenda_run'] ?? null;
$isAdmin = !empty($aiContext['is_admin']);
$aiTeamFlash = $_SESSION['ai_team_flash'] ?? null;
unset($_SESSION['ai_team_flash']);
?>
<div data-ai-team>
    <?php if (is_array($aiTeamFlash)): ?>
        <div class="portal-alert is-<?= e((string) ($aiTeamFlash['type'] ?? 'info')); ?>"><?= e((string) ($aiTeamFlash['message'] ?? '')); ?></div>
    <?php endif; ?>
    <p><small>Hər persona öz sahəsinin real faktlarına (advisor-agent-lib.php) əsaslanır. Sual "gündəlik/tapşırıq" sözlərini ehtiva edirsə, yüksək-riskli faktlar tapşırıq layihəsi kimi qeydə alınır -- heç vaxt birbaşa göndərilmir, YALNIZ admin təsdiqindən sonra əməkdaşa Telegram vasitəsilə göndərilir.</small></p>

    <?php if ($isAdmin): ?>
    <div class="portal-card portal-section-gap" data-ai-team-agenda>
        <div class="portal-card-head">
            <strong>Gündəlik agenda</strong>
            <small>Bütün personalar üçün yüksək-riskli faktları avtomatik tapşırıq layihəsinə çevirir. Fon işçisi (cron) hər gün özü işlədir; buradan əl ilə də işlədə bilərsiniz.</small>
        </div>
        <div class="portal-card-body">
            <?php if (is_array($lastAgendaRun)): ?>
                <p class="ai-team-agenda-status" style="margin:0 0 10px">
                    <strong>Son icra:</strong>
                    <?= e((string) ($lastAgendaRun['created_at'] ?? '-')); ?>
                    ·
                    <?= ((string) ($lastAgendaRun['triggered_by'] ?? '') === 'admin') ? 'əl ilə' : 'avtomatik (cron)'; ?>
                    <?php $runActor = trim((string) ($lastAgendaRun['actor_name'] ?? '')); ?>
                    <?php if ($runActor !== ''): ?> · <?= e($runActor); ?><?php endif; ?>
                    <br>
                    <small>
                        Qeydə alınan yeni tapşırıq: <strong><?= e((string) ((int) ($lastAgendaRun['total_staged'] ?? 0))); ?></strong>
                        · rejim: <?= e((string) ($lastAgendaRun['mode'] ?? '-')); ?>
                        · müddət: <?= e((string) ((int) ($lastAgendaRun['duration_ms'] ?? 0))); ?> ms
                    </small>
                </p>
            <?php else: ?>
                <p class="finance-empty" style="margin:0 0 10px">Agenda hələ heç işlədilməyib (nə cron, nə əl ilə).</p>
            <?php endif; ?>
            <form class="portal-inline-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="run_daily_agenda">
                <button class="portal-primary-btn" type="submit">Gündəlik agendanı indi işlət</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin && $pendingTasks !== []): ?>
    <div class="portal-card portal-section-gap">
        <div class="portal-card-head"><strong>Gözləyən tapşırıq layihələri (admin təsdiqi lazımdır)</strong></div>
        <div class="spog-list">
            <?php foreach ($pendingTasks as $task): ?>
                <div class="spog-list-item">
                    <strong><?= e((string) ($task['title'] ?? '-')); ?></strong>
                    <small><?= e((string) ($task['persona_key'] ?? '-')); ?> · risk: <?= e((string) ($task['risk'] ?? '-')); ?></small>
                    <p style="white-space:pre-wrap"><?= e((string) ($task['description'] ?? '-')); ?></p>
                    <form class="portal-inline-form" method="post" >
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="approve_ai_team_task">
                        <input type="hidden" name="task_id" value="<?= e((string) ($task['id'] ?? 0)); ?>">
                        <select name="assignee_account_id" required>
                            <option value="">Əməkdaş seç</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?> (<?= e((string) ($employee['employee_role'] ?? '-')); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <select name="priority"><option value="low">Aşağı</option><option value="normal" selected>Normal</option><option value="high">Yüksək</option><option value="urgent">Təcili</option></select>
                        <button class="portal-primary-btn" type="submit">Təsdiqlə və göndər</button>
                    </form>
                    <form class="portal-inline-form" method="post"  style="margin-top:6px">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="dismiss_ai_team_task">
                        <input type="hidden" name="task_id" value="<?= e((string) ($task['id'] ?? 0)); ?>">
                        <input type="text" name="reason" placeholder="Rədd səbəbi" required style="width:200px">
                        <button class="portal-ghost-btn" type="submit">Rədd et</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin && $approvedTasks !== []): ?>
    <div class="portal-card portal-section-gap">
        <div class="portal-card-head"><strong>Göndərilmiş, cavab gözlənilən tapşırıqlar</strong></div>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Başlıq</th><th>Təyin olunan</th><th>Telegram</th><th>Tarix</th></tr></thead>
                <tbody>
                    <?php foreach ($approvedTasks as $task): ?>
                        <tr><td><?= e((string) ($task['title'] ?? '-')); ?></td><td><?= e((string) ($task['assigned_employee_name'] ?? '-')); ?></td><td><?= empty($task['telegram_sent']) ? 'göndərilmədi (' . e((string) ($task['telegram_sent_reason'] ?? '-')) . ')' : 'göndərildi'; ?></td><td><?= e((string) ($task['approved_at'] ?? '-')); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="ai-team-persona-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;margin-top:16px">
        <?php foreach ($personas as $personaKey => $persona): ?>
            <div class="portal-card">
                <div class="portal-card-head"><strong><?= e((string) ($persona['label'] ?? $personaKey)); ?></strong><small><?= e((string) ($persona['persona_frame'] ?? '')); ?></small></div>
                <form class="portal-stacked-form" method="post" >
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="ask_ai_team_bot">
                    <input type="hidden" name="persona_key" value="<?= e((string) $personaKey); ?>">
                    <div class="portal-form-row"><textarea name="question" rows="2" required placeholder="Sual ver (məs: bugünkü vəziyyət nədir? / gündəlik tapşırıqları hazırla)"></textarea></div>
                    <button class="portal-primary-btn" type="submit">Soruş</button>
                </form>
                <div class="spog-list" style="margin-top:10px">
                    <?php foreach ((array) ($persona['recent'] ?? []) as $conversation): ?>
                        <div class="spog-list-item">
                            <strong><?= e((string) ($conversation['question'] ?? '-')); ?></strong>
                            <p style="white-space:pre-wrap"><?= e((string) ($conversation['answer'] ?? '-')); ?></p>
                            <small><?= empty($conversation['llm_ok']) ? 'fakta-əsaslı xülasə (AI əlçatmaz idi)' : 'AI cavabı'; ?> · <?= e((string) ($conversation['created_at'] ?? '-')); ?></small>
                        </div>
                    <?php endforeach; ?>
                    <?php if (($persona['recent'] ?? []) === []): ?><p class="finance-empty">Hələ sual verilməyib.</p><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($personas === []): ?><p class="finance-empty">Bu rolda heç bir AI persona üçün icazəniz yoxdur.</p><?php endif; ?>
    </div>
</div>
