<?php
declare(strict_types=1);
$data = (array) ($aiContext['data'] ?? []);
$bridge = (array) ($data['bridge'] ?? []);
$externalModel = (array) ($data['external_model'] ?? []);
?>
<section class="ai-grid" data-ai-model-status>
    <article class="ai-panel"><h3>Superpilot engine</h3><strong><?= e((string) ($data['engine'] ?? '-')); ?></strong><p>Lokal subut ve qayda esasli analiz.</p></article>
    <article class="ai-panel"><h3>Musteri analizatoru</h3><span class="ai-status"><?= !empty($data['customer_analyzer']) ? 'Hazirdir' : 'Qosulmayib'; ?></span></article>
    <article class="ai-panel"><h3>Emeliyyat agenti</h3><span class="ai-status"><?= !empty($data['action_agent']) ? 'Hazirdir' : 'Qosulmayib'; ?></span><p>Deyisiklikler tesdiq merhelesinden kecir.</p></article>
    <article class="ai-panel"><h3>Superpilot API</h3><span class="ai-status"><?= !empty($data['api_token_configured']) ? 'Konfiqurasiya edilib' : 'Token yoxdur'; ?></span></article>
    <article class="ai-panel"><h3>MGS baglantisi</h3><span class="ai-status <?= empty($bridge['online']) ? 'is-failed' : ''; ?>"><?= e(app_ui_status(!empty($bridge['online']) ? 'online' : 'offline')); ?></span><p><?= e((string) ($bridge['message'] ?? '')); ?></p></article>
    <article class="ai-panel">
        <h3>Xarici model provayderi</h3>
        <span class="ai-status <?= empty($externalModel['configured']) ? 'is-pending' : ''; ?>"><?= !empty($externalModel['configured']) ? 'Hazirdir' : 'Qosulmayib'; ?></span>
        <p><?= e((string) ($externalModel['provider'] ?? 'deepseek')); ?> / <?= e((string) ($externalModel['model'] ?? '-')); ?>. Acar yalniz server environment-den oxunur.</p>
    </article>
</section>
