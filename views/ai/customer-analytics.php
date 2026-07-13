<?php
declare(strict_types=1);
$data = (array) ($aiContext['data'] ?? []);
$analysis = is_array($data['analysis'] ?? null) ? $data['analysis'] : null;
?>
<section class="ai-panel" data-ai-customer-analytics>
    <h2>Musteri analizi</h2>
    <form class="ai-form" method="get" action="ai-customer-analytics.php" data-ai-filter>
        <label>Musteri kodu, login, ad ve ya problem<input type="search" name="q" value="<?= e((string) ($data['question'] ?? '')); ?>" placeholder="2320025 internet yoxdur" required></label>
        <button class="portal-primary-btn" type="submit">Analiz et</button>
    </form>
    <?php if ($analysis !== null): ?>
        <?php if (empty($analysis['ok'])): ?>
            <div class="ai-result-item is-warning"><strong>Analiz tamamlanmadi</strong><p><?= e((string) ($analysis['answer'] ?? $analysis['reason'] ?? 'Musteri tapilmadi.')); ?></p></div>
        <?php else: ?>
            <div class="ai-result"><strong><?= e((string) (($analysis['customer']['full_name'] ?? 'Musteri'))); ?></strong> · Etibar <?= (int) round(((float) ($analysis['confidence'] ?? 0)) * 100); ?>%</div>
            <div class="ai-result-list">
                <?php foreach ((array) ($analysis['findings'] ?? []) as $finding): ?>
                    <article class="ai-result-item is-<?= e((string) ($finding['severity'] ?? 'info')); ?>"><strong><?= e((string) ($finding['type'] ?? 'finding')); ?></strong><p><?= e((string) ($finding['summary'] ?? '')); ?></p></article>
                <?php endforeach; ?>
                <?php foreach ((array) ($analysis['recommendations'] ?? []) as $recommendation): ?>
                    <article class="ai-result-item"><strong>Tovsiye</strong><p><?= e((string) $recommendation); ?></p></article>
                <?php endforeach; ?>
            </div>
            <?php if ((int) ($analysis['customer']['id'] ?? 0) > 0): ?><p><a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?id=<?= (int) $analysis['customer']['id']; ?>">Canonical musteri profilini ac</a></p><?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
