<?php
declare(strict_types=1);
$data = (array) ($aiContext['data'] ?? []);
$bridge = (array) ($data['bridge'] ?? []);
?>
<section class="ai-grid" data-ai-model-status>
    <article class="ai-panel"><h3>Superpilot engine</h3><strong><?= e((string) ($data['engine'] ?? '-')); ?></strong><p>Lokal subut ve qayda esasli analiz.</p></article>
    <article class="ai-panel"><h3>Müştəri analizatoru</h3><span class="ai-status"><?= !empty($data['customer_analyzer']) ? 'Hazırdır' : 'Qoşulmayıb'; ?></span></article>
    <article class="ai-panel"><h3>Əməliyyat agenti</h3><span class="ai-status"><?= !empty($data['action_agent']) ? 'Hazırdır' : 'Qoşulmayıb'; ?></span><p>Dəyişikliklər təsdiq mərhələsindən keçir.</p></article>
    <article class="ai-panel"><h3>Superpilot API</h3><span class="ai-status"><?= !empty($data['api_token_configured']) ? 'Konfiqurasiya edilib' : 'Token yoxdur'; ?></span></article>
    <article class="ai-panel"><h3>MGS bağlantısı</h3><span class="ai-status <?= empty($bridge['online']) ? 'is-failed' : ''; ?>"><?= e(app_ui_status(!empty($bridge['online']) ? 'online' : 'offline')); ?></span><p><?= e((string) ($bridge['message'] ?? '')); ?></p></article>
    <article class="ai-panel"><h3>Xarici model provayderi</h3><span class="ai-status <?= empty($data['external_model_configured']) ? 'is-pending' : ''; ?>"><?= !empty($data['external_model_configured']) ? 'Qoşulub' : 'Qoşulmayıb'; ?></span><p>Qoşulmadıqda sistem yerli analiz mühərriki ilə işləyir.</p></article>
</section>
