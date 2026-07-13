<?php
declare(strict_types=1);
$pageData = is_array($pageData ?? null) ? $pageData : [];
$currentPage = max(1, (int) ($pageData['page'] ?? 1));
$totalPages = max(1, (int) ($pageData['pages'] ?? 1));
$baseParams = (array) ($billingContext['query'] ?? []);
unset($baseParams['page'], $baseParams['billing_section'], $baseParams['billing_fragment']);
$pageUrl = static fn (int $page): string => app_billing_url($billingSection, array_merge($baseParams, ['page' => $page]), $billingLang);
$firstVisible = max(1, $currentPage - 2);
$lastVisible = min($totalPages, $currentPage + 2);
?>
<div class="billing-pagination" data-billing-pagination><strong><?= e((string) ($pageData['start'] ?? 0)); ?>-<?= e((string) ($pageData['end'] ?? 0)); ?> / <?= e(number_format((int) ($pageData['total'] ?? 0), 0, '.', ' ')); ?> netice</strong><div class="billing-page-links"><?php if ($currentPage > 1): ?><a class="portal-ghost-btn portal-inline-link" data-billing-page href="<?= e($pageUrl($currentPage - 1)); ?>">Evvelki</a><?php endif; ?><?php for ($number = $firstVisible; $number <= $lastVisible; $number++): ?><?php if ($number === $currentPage): ?><span class="portal-primary-btn"><?= e((string) $number); ?></span><?php else: ?><a class="portal-ghost-btn portal-inline-link" data-billing-page href="<?= e($pageUrl($number)); ?>"><?= e((string) $number); ?></a><?php endif; ?><?php endfor; ?><?php if ($currentPage < $totalPages): ?><a class="portal-ghost-btn portal-inline-link" data-billing-page href="<?= e($pageUrl($currentPage + 1)); ?>">Novbeti</a><?php endif; ?></div></div>
