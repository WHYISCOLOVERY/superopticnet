<?php declare(strict_types=1); ?>
<section class="ai-panel" data-ai-copilot>
    <h2>Superpilot ile isleyin</h2>
    <p>Musteri, inventar, emekdas ve sebeke konteksti haqqinda sual verin. Deyisiklik yaradan emeliyyatlar movcud tesdiq axinindan kenara cixmir.</p>
    <form class="ai-form" action="api/copilot/agent-chat.php" method="post" data-ai-copilot-form>
        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
        <label>Sual ve ya emr<textarea name="question" required placeholder="Meselen: 2320025 abunecisinin internet problemi nedir?"></textarea></label>
        <button class="portal-primary-btn" type="submit">Analiz et</button>
    </form>
    <div class="ai-result" data-ai-copilot-output aria-live="polite">Cavab burada gosterilecek.</div>
</section>
