<?php
declare(strict_types=1);
$pageData = is_array($pageData ?? null) ? $pageData : [];
$currentPage = max(1, (int) ($pageData['page'] ?? 1));
$totalPages = max(1, (int) ($pageData['pages'] ?? 1));
$baseParams = is_array($inventoryContext['query'] ?? null) ? $inventoryContext['query'] : [];
unset($baseParams['section'], $baseParams['tab'], $baseParams['inventory_tab'], $baseParams['page']);
$pageUrl = static fn (int $page): string => app_inventory_url($inventoryTab, array_merge($baseParams, ['page' => $page]), $inventoryLang);
$firstVisible = max(1, $currentPage - 2);
$lastVisible = min($totalPages, $currentPage + 2);
?>
<div class="inventory-pagination" data-inventory-pagination><strong><?= e((string) ($pageData['start'] ?? 0)); ?>-<?= e((string) ($pageData['end'] ?? 0)); ?> / <?= e(number_format((int) ($pageData['total'] ?? 0), 0, '.', ' ')); ?> netice</strong><div class="inventory-page-links"><?php if ($currentPage > 1): ?><a class="portal-ghost-btn portal-inline-link" data-inventory-nav href="<?= e($pageUrl($currentPage - 1)); ?>">Evvelki</a><?php endif; ?><?php for ($pageNumber = $firstVisible; $pageNumber <= $lastVisible; $pageNumber++): ?><?php if ($pageNumber === $currentPage): ?><span class="portal-primary-btn"><?= e((string) $pageNumber); ?></span><?php else: ?><a class="portal-ghost-btn portal-inline-link" data-inventory-nav href="<?= e($pageUrl($pageNumber)); ?>"><?= e((string) $pageNumber); ?></a><?php endif; ?><?php endfor; ?><?php if ($currentPage < $totalPages): ?><a class="portal-ghost-btn portal-inline-link" data-inventory-nav href="<?= e($pageUrl($currentPage + 1)); ?>">Novbeti</a><?php endif; ?></div></div>
