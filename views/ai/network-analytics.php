<?php
declare(strict_types=1);
$data = (array) ($aiContext['data'] ?? []);
$summary = (array) ($data['summary'] ?? []);
$bridge = (array) ($data['bridge'] ?? []);
?>
<section class="ai-kpis" data-ai-network-analytics>
    <article class="ai-kpi"><span>Cihaz profili</span><strong><?= (int) ($summary['device_profiles'] ?? 0); ?></strong><small>AI konteksti</small></article>
    <article class="ai-kpi"><span>Catimlidir</span><strong><?= (int) ($summary['devices_reachable'] ?? 0); ?></strong><small>son veziyyet</small></article>
    <article class="ai-kpi"><span>Catimli deyil</span><strong><?= (int) ($summary['devices_unreachable'] ?? 0); ?></strong><small>tesdiqlenmis</small></article>
    <article class="ai-kpi"><span>Bilinmir</span><strong><?= (int) ($summary['devices_unknown'] ?? 0); ?></strong><small>analiz gozleyir</small></article>
    <article class="ai-kpi"><span>Monitorinq yolu</span><strong><?= !empty($data['management_path_ready']) ? 'Hazir' : 'Passiv'; ?></strong><small>VLAN konteksti</small></article>
    <article class="ai-kpi"><span>MGS bridge</span><strong><?= !empty($bridge['online']) ? 'Online' : 'Offline'; ?></strong><small>canli servis</small></article>
</section>
<section class="ai-panel" style="margin-top:14px"><h2>Sebeke analiz serhedi</h2><p>Bu bolme yalniz AI kontekst xulasesini gosterir. Cihaz idareetmesi, alarm ve OLT/ONT emeliyyatlari Sebeke modulunda qalir.</p><a class="portal-primary-btn portal-inline-link" href="noc-devices.php?view=monitor">Sebeke modulunu ac</a></section>
