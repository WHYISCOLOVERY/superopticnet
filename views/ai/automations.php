<?php
declare(strict_types=1);
$aiPage = (array) (($aiContext['data']['page'] ?? []));
?>
<section class="ai-panel" data-ai-automations>
    <h2>Avtomatlasdirmalar</h2>
    <p>Planlanan, yoxlama rejiminde olan ve tesdiq gozleyen Superpilot emeliyyatlari. Icra qaydasi movcud tesdiq qapisi ile qorunur.</p>
    <form class="ai-form" method="get" action="ai-automations.php" data-ai-filter><label>Axtaris<input type="search" name="search" value="<?= e((string) (($aiContext['query']['search'] ?? ''))); ?>"></label><button class="portal-primary-btn" type="submit">Axtar</button></form>
    <?php require __DIR__ . '/_actions-table.php'; ?>
</section>
