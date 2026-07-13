<?php
declare(strict_types=1);

$pageData = is_array($pageData ?? null) ? $pageData : [];
$currentPage = max(1, (int) ($pageData['page'] ?? 1));
$totalPages = max(1, (int) ($pageData['pages'] ?? 1));
$baseParams = is_array($financeContext['query'] ?? null) ? $financeContext['query'] : [];
unset($baseParams['section'], $baseParams['tab'], $baseParams['finance_tab'], $baseParams['page']);
$pageUrl = static function (int $page) use ($baseParams, $financeTab, $financeLang): string {
    return app_finance_url($financeTab, array_merge($baseParams, ['page' => $page]), $financeLang);
};
$firstVisible = max(1, $currentPage - 2);
$lastVisible = min($totalPages, $currentPage + 2);
?>
<div class="finance-pagination" data-finance-pagination>
    <strong><?= e((string) ($pageData['start'] ?? 0)); ?>-<?= e((string) ($pageData['end'] ?? 0)); ?> / <?= e(number_format((int) ($pageData['total'] ?? 0), 0, '.', ' ')); ?> netice</strong>
    <div class="finance-page-links">
        <?php if ($currentPage > 1): ?><a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e($pageUrl($currentPage - 1)); ?>">Evvelki</a><?php endif; ?>
        <?php for ($pageNumber = $firstVisible; $pageNumber <= $lastVisible; $pageNumber++): ?>
            <?php if ($pageNumber === $currentPage): ?>
                <span class="portal-primary-btn"><?= e((string) $pageNumber); ?></span>
            <?php else: ?>
                <a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e($pageUrl($pageNumber)); ?>"><?= e((string) $pageNumber); ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($currentPage < $totalPages): ?><a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e($pageUrl($currentPage + 1)); ?>">Novbeti</a><?php endif; ?>
    </div>
</div>
