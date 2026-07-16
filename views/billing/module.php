<?php
declare(strict_types=1);
$billingSection = (string) ($billingContext['section'] ?? 'overview');
$billingSections = (array) ($billingContext['sections'] ?? app_billing_sections());
$billingLang = (string) ($billingContext['lang'] ?? 'az');
?>
<section class="portal-card portal-reveal billing-module" data-billing-module data-billing-section="<?= e($billingSection); ?>">
<div class="portal-card-head billing-module-head"><div><div class="billing-breadcrumb"><span>Portal</span><i class="fa-solid fa-chevron-right"></i><span>Billing</span><i class="fa-solid fa-chevron-right"></i><strong><?= e((string) ($billingSections[$billingSection] ?? 'Billing')); ?></strong></div><h2><?= e((string) ($billingSections[$billingSection] ?? 'Billing')); ?></h2><small>Müştəri, paket, balans, ödəniş və xidmət əməliyyatları ayrı iş axınlarında idarə olunur.</small></div></div>
<div data-billing-content><?php require __DIR__ . '/' . $billingSection . '.php'; ?></div>
</section>
