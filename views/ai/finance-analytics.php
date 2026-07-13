<?php
declare(strict_types=1);
$data = (array) ($aiContext['data'] ?? []);
?>
<section class="ai-panel" data-ai-finance-analytics>
    <h2>Maliyye AI analitikasi</h2>
    <?php if (empty($data['available'])): ?>
        <div class="ai-empty"><strong>AI maliyye netice menbeyi hele qosulmayib</strong><p>Sistem maliyye reqemlerini uydurmur ve Finance ledger-i burada tekrarlamir.</p></div>
    <?php endif; ?>
    <p><a class="portal-primary-btn portal-inline-link" href="<?= e((string) ($data['canonical_href'] ?? '#')); ?>">Canonical Maliyye statistikasini ac</a></p>
</section>
