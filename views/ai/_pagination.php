<?php
declare(strict_types=1);

$page = max(1, (int) ($aiPage['page'] ?? 1));
$pages = max(1, (int) ($aiPage['pages'] ?? 1));
$query = is_array($aiContext['query'] ?? null) ? $aiContext['query'] : [];
$route = (string) (($aiContext['routes'] ?? [])[$aiSection] ?? 'ai-dashboard.php');
$urlFor = static function (int $target) use ($route, $query): string {
    $query['page'] = $target;
    return $route . '?' . http_build_query($query);
};
?>
<div class="portal-pagination">
    <span><?= e((string) ($aiPage['start'] ?? 0)); ?>-<?= e((string) ($aiPage['end'] ?? 0)); ?> / <?= e((string) ($aiPage['total'] ?? 0)); ?> netice</span>
    <div class="portal-pagination-actions">
        <?php if ($page > 1): ?><a data-ai-page class="portal-ghost-btn portal-inline-link" href="<?= e($urlFor($page - 1)); ?>">Evvelki</a><?php endif; ?>
        <span><?= $page; ?> / <?= $pages; ?></span>
        <?php if ($page < $pages): ?><a data-ai-page class="portal-ghost-btn portal-inline-link" href="<?= e($urlFor($page + 1)); ?>">Novbeti</a><?php endif; ?>
    </div>
</div>
