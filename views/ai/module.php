<?php
declare(strict_types=1);

$sections = (array) ($aiContext['sections'] ?? []);
$routes = (array) ($aiContext['routes'] ?? []);
?>
<section class="ai-module" data-ai-module data-ai-section="<?= e($aiSection); ?>">
    <header class="ai-module-head">
        <div>
            <span class="portal-kicker" data-ai-kicker>AI / <?= e((string) ($sections[$aiSection] ?? '')); ?></span>
            <h1 data-ai-title><?= e((string) ($sections[$aiSection] ?? 'AI')); ?></h1>
            <p>Superpilot netice, risk, tovsiye ve tesdiq olunan emeliyyat kontekstini bir yerde saxlayir.</p>
        </div>
        <div class="ai-actions">
            <?php if (isset($sections['copilot'])): ?>
                <a class="portal-primary-btn portal-inline-link" data-ai-nav data-module-section="copilot" href="<?= e((string) ($routes['copilot'] ?? 'ai-copilot.php')); ?>"><i class="fa-solid fa-brain"></i> Superpilot ac</a>
            <?php endif; ?>
        </div>
    </header>
    <div class="ai-command-strip" data-ai-command-strip>
        <div>
            <strong>Gunluk AI emeliyyatlari</strong>
            <small>Analiz, risk ve nezaret axinlarina sehifeni tam yenilemeden kecin.</small>
        </div>
        <div class="ai-command-actions">
            <?php if (isset($sections['copilot'])): ?><a class="portal-primary-btn portal-inline-link" data-ai-nav data-module-section="copilot" href="<?= e((string) ($routes['copilot'] ?? 'ai-copilot.php')); ?>"><i class="fa-solid fa-brain"></i> Superpilot sorus</a><?php endif; ?>
            <?php if (isset($sections['customer-analytics'])): ?><a class="portal-ghost-btn portal-inline-link" data-ai-nav data-module-section="customer-analytics" href="<?= e((string) ($routes['customer-analytics'] ?? 'ai-customer-analytics.php')); ?>"><i class="fa-solid fa-user-check"></i> Musteri analizi</a><?php endif; ?>
            <?php if (isset($sections['network-analytics'])): ?><a class="portal-ghost-btn portal-inline-link" data-ai-nav data-module-section="network-analytics" href="<?= e((string) ($routes['network-analytics'] ?? 'ai-network-analytics.php')); ?>"><i class="fa-solid fa-network-wired"></i> Sebeke analizi</a><?php endif; ?>
            <?php if (isset($sections['risks'])): ?><a class="portal-ghost-btn portal-inline-link" data-ai-nav data-module-section="risks" href="<?= e((string) ($routes['risks'] ?? 'ai-risks.php')); ?>"><i class="fa-solid fa-triangle-exclamation"></i> Risk novbesi</a><?php endif; ?>
            <?php if (isset($sections['work-queue'])): ?><a class="portal-ghost-btn portal-inline-link" data-ai-nav data-module-section="work-queue" href="<?= e((string) ($routes['work-queue'] ?? 'ai-work-queue.php')); ?>"><i class="fa-solid fa-list-check"></i> Təsdiq Mərkəzi</a><?php endif; ?>
        </div>
    </div>
    <?php
    $aiNavLink = static fn (string $key): array => [
        'key' => $key,
        'label' => (string) ($sections[$key] ?? $key),
        'href' => (string) ($routes[$key] ?? 'ai-dashboard.php'),
    ];
    $aiNavLinksFor = static function (array $keys) use ($sections, $aiNavLink): array {
        $links = [];
        foreach ($keys as $key) {
            if (isset($sections[$key])) {
                $links[] = $aiNavLink($key);
            }
        }
        return $links;
    };
    $moduleNavGroups = [];
    $everydayLinks = $aiNavLinksFor(['overview', 'copilot', 'customer-analytics', 'network-analytics', 'finance-analytics', 'risks', 'recommendations', 'work-queue']);
    if ($everydayLinks !== []) {
        $moduleNavGroups[] = ['label' => 'Gunluk AI aletleri', 'icon' => 'fa-bolt', 'links' => $everydayLinks];
    }
    $adminLinks = $aiNavLinksFor(['automations', 'audit', 'model-status']);
    if ($adminLinks !== []) {
        $moduleNavGroups[] = ['label' => 'Admin texniki aletleri', 'icon' => 'fa-shield-halved', 'links' => $adminLinks];
    }
    $moduleNavActive = $aiSection;
    $moduleNavAria = 'AI bolmeleri';
    $moduleNavName = 'ai-module-nav';
    $moduleNavLinkAttribute = 'data-ai-nav';
    require __DIR__ . '/../_module-group-nav.php';
    ?>
    <div data-ai-status aria-live="polite"></div>
    <div data-ai-content><?php require __DIR__ . '/' . $aiSection . '.php'; ?></div>
</section>
