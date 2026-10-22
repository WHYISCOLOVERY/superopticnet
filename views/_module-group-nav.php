<?php
declare(strict_types=1);

$moduleNavGroups = is_array($moduleNavGroups ?? null) ? $moduleNavGroups : [];
$moduleNavActive = (string) ($moduleNavActive ?? 'overview');
$moduleNavAria = (string) ($moduleNavAria ?? 'Modul bolmeleri');
$moduleNavName = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($moduleNavName ?? 'module-nav')) ?: 'module-nav';
$allowedAttributes = ['data-finance-nav', 'data-hr-nav', 'data-inventory-nav', 'data-ai-nav', 'data-message-nav', 'data-noc-nav'];
$moduleNavLinkAttribute = in_array((string) ($moduleNavLinkAttribute ?? ''), $allowedAttributes, true)
    ? (string) $moduleNavLinkAttribute
    : '';
?>
<style>
.module-group-nav.billing-shell-nav{width:100%;margin:10px 0 16px;padding:2px 0 10px;z-index:70}.module-group-nav .billing-nav-group{border-radius:8px}.module-group-nav .billing-nav-links{border-radius:8px}
@media(max-width:760px){.module-group-nav.billing-shell-nav{width:100%!important;margin:8px 0 14px!important}}
</style>
<nav class="billing-shell-nav module-group-nav portal-reveal" data-module-group-nav aria-label="<?= e($moduleNavAria); ?>">
    <?php foreach ($moduleNavGroups as $group): ?>
        <?php
        $links = is_array($group['links'] ?? null) ? $group['links'] : [];
        $isActiveGroup = false;
        foreach ($links as $link) {
            if ((string) ($link['key'] ?? '') === $moduleNavActive) {
                $isActiveGroup = true;
                break;
            }
        }
        ?>
        <?php if (count($links) === 1): ?>
            <?php $only = $links[0]; $onlyKey = (string) ($only['key'] ?? ''); ?>
            <a class="billing-nav-direct<?= $isActiveGroup ? ' is-active-group' : ''; ?>" href="<?= e((string) ($only['href'] ?? '#')); ?>" data-module-section="<?= e($onlyKey); ?>"<?= $moduleNavLinkAttribute !== '' ? ' ' . $moduleNavLinkAttribute : ''; ?><?= $isActiveGroup ? ' aria-current="page"' : ''; ?>>
                <?= billing_nav_icon_svg((string) ($group['icon'] ?? 'fa-circle')); ?>
                <span><?= e((string) ($group['label'] ?? $only['label'] ?? 'Bolme')); ?></span>
            </a>
            <?php continue; ?>
        <?php endif; ?>
        <details class="billing-nav-group<?= $isActiveGroup ? ' is-active-group' : ''; ?>" name="<?= e($moduleNavName); ?>" data-module-nav-group>
            <summary class="billing-nav-title">
                <?= billing_nav_icon_svg((string) ($group['icon'] ?? 'fa-circle')); ?>
                <span><?= e((string) ($group['label'] ?? 'Bolme')); ?></span>
            </summary>
            <div class="billing-nav-links">
                <?php foreach ($links as $link): ?>
                    <?php $linkKey = (string) ($link['key'] ?? ''); ?>
                    <a class="<?= $moduleNavActive === $linkKey ? 'is-active' : ''; ?>" href="<?= e((string) ($link['href'] ?? '#')); ?>" data-module-section="<?= e($linkKey); ?>"<?= $moduleNavLinkAttribute !== '' ? ' ' . $moduleNavLinkAttribute : ''; ?>><?= e((string) ($link['label'] ?? 'Ac')); ?></a>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
</nav>
