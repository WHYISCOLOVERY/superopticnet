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
<nav class="billing-shell-nav module-group-nav portal-reveal" data-module-group-nav aria-label="<?= e($moduleNavAria); ?>">
    <?php foreach ($moduleNavGroups as $group): ?>
        <?php
        $links = is_array($group['links'] ?? null) ? $group['links'] : [];
        if ($links === []) {
            continue;
        }
        $isActiveGroup = false;
        foreach ($links as $link) {
            if ((string) ($link['key'] ?? '') === $moduleNavActive) {
                $isActiveGroup = true;
                break;
            }
        }
        ?>
        <?php if (count($links) === 1): ?>
            <?php $solo = $links[0]; $soloKey = (string) ($solo['key'] ?? ''); ?>
            <a class="billing-nav-group billing-nav-solo<?= $isActiveGroup ? ' is-active-group' : ''; ?>"
               href="<?= e((string) ($solo['href'] ?? '#')); ?>"
               data-module-section="<?= e($soloKey); ?>"<?= $moduleNavLinkAttribute !== '' ? ' ' . $moduleNavLinkAttribute : ''; ?>>
                <span class="billing-nav-title">
                    <?= billing_nav_icon_svg((string) ($group['icon'] ?? 'fa-circle')); ?>
                    <span><?= e((string) ($group['label'] ?? $solo['label'] ?? 'Bolme')); ?></span>
                </span>
            </a>
        <?php else: ?>
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
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
