<?php
declare(strict_types=1);
$data = (array) ($aiContext['data'] ?? []);
$aiPage = (array) ($data['page'] ?? []);
?>
<section class="ai-panel" data-ai-risks>
    <h2>Aktiv riskler</h2>
    <p>Tesdiq gozleyen ve ugursuz AI emeliyyatlari. Bu siyahi ayrica biznes-domain cedveli deyil.</p>
    <form class="ai-form" method="get" action="ai-risks.php" data-ai-filter><label>Axtaris<input type="search" name="search" value="<?= e((string) (($aiContext['query']['search'] ?? ''))); ?>"></label><button class="portal-primary-btn" type="submit">Axtar</button></form>
    <?php require __DIR__ . '/_actions-table.php'; ?>
</section>
