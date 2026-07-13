<?php
declare(strict_types=1);

$sections = (array) ($aiContext['sections'] ?? []);
$routes = (array) ($aiContext['routes'] ?? []);
?>
<style>
    .ai-module{display:grid;gap:18px;min-width:0}.ai-module.is-loading [data-ai-content]{opacity:.55;pointer-events:none}
    .ai-module-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.ai-module-head h1{margin:4px 0 6px}.ai-module-head p{margin:0;color:#63738e;max-width:780px}
    .ai-command-strip{display:grid;grid-template-columns:minmax(220px,.72fr) minmax(0,1.28fr);gap:16px;align-items:center;padding:16px;border:1px solid #dfe6ee;border-radius:8px;background:linear-gradient(135deg,#f8fcfd,#fff);box-shadow:0 18px 36px rgba(16,42,75,.06)}
    .ai-command-strip strong{display:block;font-size:18px;color:#102a4b}.ai-command-strip small{display:block;margin-top:4px;color:#63738e;line-height:1.45}
    .ai-command-actions{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.ai-command-actions a{min-width:0;justify-content:center;text-align:center;white-space:normal;line-height:1.25}
    .ai-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.ai-kpi{min-width:0;padding:14px;border:1px solid #dfe6ee;border-radius:8px;background:#fff;overflow-wrap:anywhere}.ai-kpi span,.ai-kpi small{display:block;color:#677894;overflow-wrap:anywhere}.ai-kpi strong{display:block;font-size:clamp(18px,2vw,24px);margin:5px 0;color:#102a4b;overflow-wrap:anywhere}
    .ai-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.ai-panel{padding:18px;border:1px solid #dfe6ee;border-radius:8px;background:#fff;min-width:0}.ai-panel h2,.ai-panel h3{margin-top:0}.ai-panel-wide{grid-column:1/-1}
    .ai-bars{display:grid;gap:10px}.ai-bar-row{display:grid;grid-template-columns:minmax(110px,auto) minmax(80px,1fr) auto;align-items:center;gap:10px}.ai-bar-track{height:10px;border-radius:8px;background:#edf1f5;overflow:hidden}.ai-bar-fill{display:block;height:100%;background:#148d96}.ai-bar-fill.is-warning{background:#ff944d}.ai-bar-fill.is-danger{background:#e65b68}
    .ai-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.ai-form label{display:grid;gap:6px;flex:1 1 260px;font-weight:800}.ai-form input,.ai-form select,.ai-form textarea{width:100%;box-sizing:border-box;border:1px solid #d7e0e8;border-radius:8px;padding:11px 12px;background:#fff;color:#162d4b}.ai-form textarea{min-height:110px;resize:vertical}
    .ai-result{margin-top:14px;padding:15px;border-radius:8px;background:#f2f7f8;white-space:pre-wrap;color:#17304f}.ai-result-list{display:grid;gap:10px;margin-top:14px}.ai-result-item{padding:13px;border-left:4px solid #148d96;background:#f6f9fb}.ai-result-item.is-warning{border-left-color:#ff944d}.ai-result-item.is-danger{border-left-color:#e65b68}
    .ai-status{display:inline-flex;align-items:center;padding:5px 9px;border-radius:8px;background:#eaf2f4;color:#165869;font-size:12px;font-weight:900}.ai-status.is-failed{background:#ffe9ec;color:#a52f43}.ai-status.is-pending,.ai-status.is-planned,.ai-status.is-dry_run{background:#fff0e3;color:#8a4b19}
    .ai-table-wrap{width:100%;overflow-x:auto}.ai-table-wrap table{min-width:760px}.ai-empty{padding:28px;text-align:center;border:1px dashed #cbd6e1;border-radius:8px;background:#fafcfd}.ai-actions{display:flex;gap:9px;flex-wrap:wrap}
    @media(max-width:1100px){.ai-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.ai-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ai-command-strip{grid-template-columns:1fr}.ai-command-actions{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:680px){.ai-module-head{display:grid}.ai-kpis,.ai-grid{grid-template-columns:1fr 1fr}.ai-panel-wide{grid-column:1/-1}}
    @media(max-width:480px){.ai-kpis,.ai-grid{grid-template-columns:1fr}.ai-panel-wide{grid-column:auto}.ai-form{display:grid}.ai-table-wrap{overflow:visible}.ai-table-wrap table{min-width:0}}
</style>
<section class="ai-module" data-ai-module data-ai-section="<?= e($aiSection); ?>">
    <header class="ai-module-head">
        <div>
            <span class="portal-kicker" data-ai-kicker>AI / <?= e((string) ($sections[$aiSection] ?? '')); ?></span>
            <h1 data-ai-title><?= e((string) ($sections[$aiSection] ?? 'AI')); ?></h1>
            <p>Superpilot netice, risk, tovsiye ve tesdiq olunan emeliyyat kontekstini bir yerde saxlayir.</p>
        </div>
        <div class="ai-actions">
            <a class="portal-primary-btn portal-inline-link" href="ai-copilot.php"><i class="fa-solid fa-brain"></i> Superpilot ac</a>
        </div>
    </header>
    <div class="ai-command-strip" data-ai-command-strip>
        <div>
            <strong>AI emeliyyatlari</strong>
            <small>En cox istifade olunan analiz ve nezaret axinlari bir yerdedir; kecidler sehifeni tam yenilemeden acilir.</small>
        </div>
        <div class="ai-command-actions">
            <a class="portal-primary-btn portal-inline-link" data-ai-nav data-module-section="copilot" href="<?= e((string) ($routes['copilot'] ?? 'ai-copilot.php')); ?>"><i class="fa-solid fa-brain"></i> Superpilot sorus</a>
            <a class="portal-ghost-btn portal-inline-link" data-ai-nav data-module-section="customer-analytics" href="<?= e((string) ($routes['customer-analytics'] ?? 'ai-customer-analytics.php')); ?>"><i class="fa-solid fa-user-check"></i> Musteri analizi</a>
            <a class="portal-ghost-btn portal-inline-link" data-ai-nav data-module-section="network-analytics" href="<?= e((string) ($routes['network-analytics'] ?? 'ai-network-analytics.php')); ?>"><i class="fa-solid fa-network-wired"></i> Sebeke analizi</a>
            <a class="portal-ghost-btn portal-inline-link" data-ai-nav data-module-section="risks" href="<?= e((string) ($routes['risks'] ?? 'ai-risks.php')); ?>"><i class="fa-solid fa-triangle-exclamation"></i> Risk novbesi</a>
        </div>
    </div>
    <?php
    $aiNavLink = static fn (string $key): array => [
        'key' => $key,
        'label' => (string) ($sections[$key] ?? $key),
        'href' => (string) ($routes[$key] ?? 'ai-dashboard.php'),
    ];
    $moduleNavGroups = [
        ['label'=>'Umumi baxis ve Superpilot','icon'=>'fa-brain','links'=>[$aiNavLink('overview'),$aiNavLink('copilot')]],
        ['label'=>'Musteri ve sebeke analitikasi','icon'=>'fa-chart-line','links'=>[$aiNavLink('customer-analytics'),$aiNavLink('network-analytics')]],
        ['label'=>'Maliyye ve risk','icon'=>'fa-coins','links'=>[$aiNavLink('finance-analytics'),$aiNavLink('risks'),$aiNavLink('recommendations')]],
        ['label'=>'Avtomatlashdirma ve audit','icon'=>'fa-shield-halved','links'=>[$aiNavLink('automations'),$aiNavLink('audit'),$aiNavLink('model-status')]],
    ];
    $moduleNavActive = $aiSection;
    $moduleNavAria = 'AI bolmeleri';
    $moduleNavName = 'ai-module-nav';
    $moduleNavLinkAttribute = 'data-ai-nav';
    require __DIR__ . '/../_module-group-nav.php';
    ?>
    <div data-ai-status aria-live="polite"></div>
    <div data-ai-content><?php require __DIR__ . '/' . $aiSection . '.php'; ?></div>
</section>
