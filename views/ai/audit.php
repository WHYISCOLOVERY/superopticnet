<?php
declare(strict_types=1);
$aiPage = (array) (($aiContext['data']['page'] ?? []));
$status = (string) (($aiContext['query']['status'] ?? ''));
?>
<section class="ai-panel" data-ai-audit>
    <h2>AI audit</h2>
    <form class="ai-form" method="get" action="ai-audit.php" data-ai-filter>
        <label>Status<select name="status"><option value="">Butun neticeler</option><?php foreach (['executed', 'failed', 'skipped'] as $value): ?><option value="<?= e($value); ?>" <?= $status === $value ? 'selected' : ''; ?>><?= e(app_ui_status($value)); ?></option><?php endforeach; ?></select></label>
        <label>Axtaris<input type="search" name="search" value="<?= e((string) (($aiContext['query']['search'] ?? ''))); ?>"></label>
        <button class="portal-primary-btn" type="submit">Tetbiq et</button>
    </form>
    <?php require __DIR__ . '/_actions-table.php'; ?>
</section>
