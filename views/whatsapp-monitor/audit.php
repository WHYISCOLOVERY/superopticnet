<?php
declare(strict_types=1);

$rows = (array) ($context['audit_rows'] ?? []);
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>WhatsApp Monitor əməliyyat jurnalı</h3><small>Modulun daxili jurnalıdır. Kritik əməliyyatlar ümumi əməliyyat jurnalına da düşür.</small></div>
    <?php if (empty($context['can_view_all'])): ?>
        <?php billing_render_empty_state('İcazə yoxdur', 'Audit log yalnız rəhbər/admin rolları üçündür.', 'fa-lock'); ?>
    <?php elseif ($rows === []): ?>
        <?php billing_render_empty_state('Audit yoxdur', 'Əməliyyat aparıldıqda burada görünəcək.', 'fa-shield-halved'); ?>
    <?php else: ?>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Vaxt</th><th>İstifadəçi</th><th>Əməliyyat</th><th>Obyekt</th><th>Yeni dəyər</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td data-label="Vaxt"><?= e((string) ($row['created_at'] ?? '')); ?></td>
                            <td data-label="İstifadəçi"><?= e((string) ($row['actor_name'] ?? $row['user_id'] ?? '-')); ?></td>
                            <td data-label="Əməliyyat"><?= e((string) ($row['action'] ?? '-')); ?></td>
                            <td data-label="Obyekt"><?= e((string) ($row['entity_type'] ?? '-')); ?> #<?= e((string) ($row['entity_id'] ?? '')); ?></td>
                            <td data-label="Yeni dəyər"><pre class="wa-audit-pre"><?= e((string) ($row['new_values'] ?? '')); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
