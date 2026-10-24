<?php
declare(strict_types=1);

$rows = (array) ($aiPage['rows'] ?? []);
?>
<?php if ($rows === []): ?>
    <div class="ai-empty"><strong>Nəticə yoxdur</strong><p>Seçilmiş filtr üzrə AI əməliyyatı tapılmadı.</p></div>
<?php else: ?>
    <div class="ai-table-wrap">
        <table class="portal-table">
            <thead><tr><th>ID</th><th>Əməliyyat</th><th>Obyekt</th><th>Xülasə</th><th>İstifadəçi</th><th>Vəziyyət</th><th>Tarix</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr data-ai-row>
                    <td>#<?= (int) ($row['id'] ?? 0); ?></td>
                    <td><?= e(app_ui_term((string) ($row['action_type'] ?? '-'))); ?></td>
                    <td><?= e(trim(app_ui_term((string) ($row['entity_type'] ?? '')) . ' ' . (string) ($row['entity_id'] ?? '')) ?: '-'); ?></td>
                    <td><?= e((string) ($row['summary'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['actor_name'] ?? '-')); ?></td>
                    <td><span class="ai-status is-<?= e((string) ($row['status'] ?? '')); ?>"><?= e(app_ui_status((string) ($row['status'] ?? ''))); ?></span></td>
                    <td><?= e((string) ($row['created_at'] ?? '-')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_pagination.php'; ?>
