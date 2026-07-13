<?php
declare(strict_types=1);

$rows = (array) ($context['audit_rows'] ?? []);
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>WhatsApp Monitor audit log</h3><small>Modul daxili audit. Kritik emeliyyatlar umumi emeliyyat jurnalina da dusur.</small></div>
    <?php if (empty($context['can_view_all'])): ?>
        <?php billing_render_empty_state('Icaze yoxdur', 'Audit log yalniz rehber/admin rollari ucundur.', 'fa-lock'); ?>
    <?php elseif ($rows === []): ?>
        <?php billing_render_empty_state('Audit yoxdur', 'Emeliyyat aparildiqda burada gorunecek.', 'fa-shield-halved'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Vaxt</th><th>Istifadeci</th><th>Emeliyyat</th><th>Entity</th><th>Yeni deyer</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['created_at'] ?? '')); ?></td>
                            <td><?= e((string) ($row['actor_name'] ?? $row['user_id'] ?? '-')); ?></td>
                            <td><?= e((string) ($row['action'] ?? '-')); ?></td>
                            <td><?= e((string) ($row['entity_type'] ?? '-')); ?> #<?= e((string) ($row['entity_id'] ?? '')); ?></td>
                            <td><pre class="wa-audit-pre"><?= e((string) ($row['new_values'] ?? '')); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
