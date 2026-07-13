<?php
declare(strict_types=1);
$items = (array) (($aiContext['data']['recommendations'] ?? []));
?>
<section class="ai-panel" data-ai-recommendations>
    <h2>Tovsiyeler</h2>
    <?php if ($items === []): ?><div class="ai-empty"><strong>Aktiv tovsiye yoxdur</strong><p>Yeni risk ve ya ugursuz emeliyyat yarandigda burada gorunecek.</p></div><?php endif; ?>
    <div class="ai-result-list"><?php foreach ($items as $item): ?><a class="ai-result-item is-<?= e((string) ($item['level'] ?? 'info')); ?>" href="<?= e((string) ($item['href'] ?? '#')); ?>"><strong><?= e((string) ($item['title'] ?? '')); ?></strong><p><?= e((string) ($item['text'] ?? '')); ?></p></a><?php endforeach; ?></div>
</section>
