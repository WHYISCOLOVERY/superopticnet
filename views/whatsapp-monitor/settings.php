<?php
declare(strict_types=1);

$settings = (array) ($context['settings'] ?? []);
?>
<section class="portal-muted-card portal-section-gap">
    <div class="portal-card-head"><h3>Monitorinq qaydalari</h3><small>Bu ayarlari yalniz super admin deyise biler.</small></div>
    <?php if (empty($context['can_manage'])): ?>
        <?php billing_render_empty_state('Yalniz baxış', 'Qaydalari deyismek ucun super admin lazimdir.', 'fa-lock'); ?>
    <?php else: ?>
        <form method="post" class="portal-stacked-form">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <div class="wa-settings-grid">
                <?php foreach ($settings as $key => $setting): ?>
                    <label>
                        <?= e((string) ($setting['description'] ?? $key)); ?>
                        <input name="<?= e((string) $key); ?>" value="<?= e((string) ($setting['setting_value'] ?? '')); ?>">
                        <small><?= e((string) $key); ?></small>
                    </label>
                <?php endforeach; ?>
            </div>
            <button class="portal-primary-btn portal-section-gap" type="submit">Qaydalari saxla</button>
        </form>
    <?php endif; ?>
</section>
