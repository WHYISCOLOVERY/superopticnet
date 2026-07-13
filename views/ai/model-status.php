<?php
declare(strict_types=1);
$data = (array) ($aiContext['data'] ?? []);
$bridge = (array) ($data['bridge'] ?? []);
?>
<section class="ai-grid" data-ai-model-status>
    <article class="ai-panel"><h3>Superpilot engine</h3><strong><?= e((string) ($data['engine'] ?? '-')); ?></strong><p>Lokal subut ve qayda esasli analiz.</p></article>
    <article class="ai-panel"><h3>Musteri analizatoru</h3><span class="ai-status"><?= !empty($data['customer_analyzer']) ? 'Hazir' : 'Yoxdur'; ?></span></article>
    <article class="ai-panel"><h3>Emeliyyat agenti</h3><span class="ai-status"><?= !empty($data['action_agent']) ? 'Hazir' : 'Yoxdur'; ?></span><p>Deyisiklikler tesdiq qapisi teleb edir.</p></article>
    <article class="ai-panel"><h3>Superpilot API</h3><span class="ai-status"><?= !empty($data['api_token_configured']) ? 'Konfiqurasiya edilib' : 'Token yoxdur'; ?></span></article>
    <article class="ai-panel"><h3>MGS bridge</h3><span class="ai-status <?= empty($bridge['online']) ? 'is-failed' : ''; ?>"><?= !empty($bridge['online']) ? 'Online' : 'Offline'; ?></span><p><?= e((string) ($bridge['message'] ?? '')); ?></p></article>
    <article class="ai-panel"><h3>Xarici model provider</h3><span class="ai-status <?= empty($data['external_model_configured']) ? 'is-pending' : ''; ?>"><?= !empty($data['external_model_configured']) ? 'Qosulub' : 'Qosulmayib'; ?></span><p>Qosulmayibsa sistem lokal engine ile isleyir.</p></article>
</section>
