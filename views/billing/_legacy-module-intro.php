<?php
declare(strict_types=1);

$billingLegacySection = app_billing_section(['billing_section' => (string) ($billingLegacySection ?? 'overview')]);
$billingLegacyTitle = (string) ($billingLegacyTitle ?? (app_billing_sections()[$billingLegacySection] ?? 'Billing'));
$billingLegacyDescription = (string) ($billingLegacyDescription ?? 'Billing emeliyyatlari aid oldugu bolmede idare olunur.');
$billingLegacyActions = is_array($billingLegacyActions ?? null) ? $billingLegacyActions : [];
$billingLegacyLang = (string) ($lang ?? app_lang());
?>
<style>
.billing-legacy-module{margin-bottom:14px;min-width:0}.billing-module-head{align-items:flex-start}.billing-module-head h1{margin:2px 0 5px;font-size:1.45rem}.billing-breadcrumb{display:flex;gap:7px;align-items:center;color:var(--portal-muted);font-size:.78rem;margin-bottom:6px}.billing-tabs{display:flex;gap:8px;overflow-x:auto;padding:4px 0 12px;margin:10px 0 14px;scrollbar-width:thin}.billing-tabs a{flex:0 0 auto}.billing-tabs .is-active{background:var(--portal-text);color:#fff;border-color:var(--portal-text)}.billing-actions{display:flex;gap:8px;flex-wrap:wrap}.billing-payment-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.billing-kpi{min-width:0;border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:14px;background:#fff}.billing-kpi span,.billing-kpi small{display:block;color:var(--portal-muted);font-size:.78rem}.billing-kpi strong{display:block;min-width:0;margin:6px 0 4px;font-size:clamp(1rem,1.7vw,1.25rem);line-height:1.18;overflow-wrap:anywhere;word-break:break-word}
@media(max-width:900px){.billing-payment-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.billing-payment-kpis{grid-template-columns:1fr}.billing-module-head{display:grid}.billing-module-head>a{justify-self:start}}
</style>
<section class="portal-card portal-reveal billing-legacy-module" data-billing-legacy-module data-billing-section="<?= e($billingLegacySection); ?>">
    <div class="portal-card-head billing-module-head">
        <div>
            <div class="billing-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>Billing</span><i class="fa-solid fa-chevron-right"></i><strong><?= e($billingLegacyTitle); ?></strong></div>
            <h1><?= e($billingLegacyTitle); ?></h1>
            <small><?= e($billingLegacyDescription); ?></small>
        </div>
        <a class="portal-ghost-btn portal-inline-link" href="portal.php"><i class="fa-solid fa-arrow-left"></i> Esas portal</a>
    </div>
    <?php if ($billingLegacyActions !== []): ?>
        <div class="billing-actions">
            <?php foreach ($billingLegacyActions as $action): ?>
                <a class="<?= e((string) ($action['class'] ?? 'portal-ghost-btn portal-inline-link')); ?>" href="<?= e((string) ($action['href'] ?? '#')); ?>">
                    <?php if (($action['icon'] ?? '') !== ''): ?><i class="fa-solid <?= e((string) $action['icon']); ?>"></i><?php endif; ?>
                    <?= e((string) ($action['label'] ?? 'Ac')); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
