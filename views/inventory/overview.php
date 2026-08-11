<?php
declare(strict_types=1);
$inventoryStatistics = (array) ($inventoryContext['data']['statistics'] ?? []);
$criticalRows = (array) ($inventoryContext['data']['critical'] ?? []);
$recentRows = (array) ($inventoryContext['data']['recent'] ?? []);
$canWrite = !empty($inventoryContext['data']['can_write']);
?>
<div data-inventory-overview>
    <div class="inventory-kpis"><?php foreach (array_slice((array) ($inventoryStatistics['kpis'] ?? []), 0, 6) as $kpi): ?><article class="inventory-kpi"><span><?= e((string) ($kpi['label'] ?? '-')); ?></span><strong><?= e($inventoryFormat($kpi['value'] ?? 0, (string) ($kpi['kind'] ?? 'quantity'))); ?></strong></article><?php endforeach; ?></div>
    <?php if ($canWrite): ?>
        <section class="inventory-action-panel" data-inventory-quick-actions>
            <div class="inventory-action-panel-head">
                <div>
                    <strong>Əsas anbar əməliyyatları</strong>
                    <small>Mədaxil, lokasiya transferi, təhvil və qaytarma sənədləri uyğun səhifədə modal kimi açılır.</small>
                </div>
                <span class="portal-pill">MDX / TRF / THV / QAY</span>
            </div>
            <div class="inventory-action-grid">
                <a class="inventory-action-card is-primary" data-inventory-nav href="<?= e(app_inventory_url('movements', ['open' => 'stock-intake'], $inventoryLang)); ?>">
                    <i class="fa-solid fa-arrow-down"></i>
                    <strong>Mal mədaxil et</strong>
                    <small>Mövcud məhsulu seçin və ya yeni SKU yaradıb anbara/filiala daxil edin.</small>
                </a>
                <a class="inventory-action-card" data-inventory-nav href="<?= e(app_inventory_url('movements', ['open' => 'branch-transfer'], $inventoryLang)); ?>">
                    <i class="fa-solid fa-right-left"></i>
                    <strong>Filiala transfer et</strong>
                    <small>Mərkəzi anbardan filiala, filialdan filiala və ya filiala qaytarma.</small>
                </a>
                <a class="inventory-action-card" data-inventory-nav href="<?= e(app_inventory_url('handover', ['open' => 'handover-create'], $inventoryLang)); ?>">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                    <strong>Texnikə təhvil ver</strong>
                    <small>Filial balansından işçinin istifadəsinə mal verilir və tarixçə saxlanır.</small>
                </a>
                <a class="inventory-action-card" data-inventory-nav href="<?= e(app_inventory_url('approvals', ['open' => 'external-return'], $inventoryLang)); ?>">
                    <i class="fa-solid fa-rotate-left"></i>
                    <strong>Qaytarma yarat</strong>
                    <small>Xarab, lazımsız və ya yanlış malı şirkətə, quruma və ya şəxsə qaytarın.</small>
                </a>
            </div>
        </section>
    <?php endif; ?>
    <div class="inventory-shortcuts"><?php foreach (['catalog'=>['Məhsul kataloqu','SKU, kateqoriya və stok'], 'devices'=>['Cihazlar','Serial, MAC və istismar dövrü'], 'materials'=>['Sərfiyyat','Konnektor, kabel və alət'], 'cable-drums'=>['Kabel barabanları','Qalıq və lokasiya'], 'technicians'=>['Texnik malları','Texnikdə olan cihazlar'], 'customers'=>['Müştəri cihazları','Aktiv təhkim'], 'handover'=>['Təhvil-təslim','Texnikə mal verilməsi'], 'returns-inspection'=>['Qaytarma','Qəbul və yoxlama'], 'repair-rma'=>['Təmir / RMA','Problemli cihazlar'], 'movements'=>['Hərəkətlər','Stok və istismar dövrü jurnalı'], 'statistics'=>['Statistika','Anbar qrafikləri']] as $key=>$shortcut): ?><a class="inventory-shortcut" data-inventory-nav href="<?= e(app_inventory_url($key, [], $inventoryLang)); ?>"><strong><?= e($shortcut[0]); ?></strong><small><?= e($shortcut[1]); ?></small></a><?php endforeach; ?></div>
    <div class="portal-grid portal-grid-2" style="margin-top:14px"><article class="portal-muted-card"><div class="portal-card-head"><strong>Kritik stok</strong><a data-inventory-nav href="<?= e(app_inventory_url('catalog', ['critical'=>1], $inventoryLang)); ?>">Hamısı</a></div><?php foreach ($criticalRows as $row): ?><div class="spog-list-item"><strong><?= e((string) ($row['item_name'] ?? $row['sku'] ?? '-')); ?></strong><small><?= e($inventoryFormat($row['quantity_on_hand'] ?? 0)); ?> / minimum <?= e($inventoryFormat($row['minimum_quantity'] ?? 0)); ?></small></div><?php endforeach; ?><?php if ($criticalRows === []): ?><p class="inventory-empty">Kritik stok qeydi yoxdur.</p><?php endif; ?></article><article class="portal-muted-card"><div class="portal-card-head"><strong>Son stok hərəkətləri</strong><a data-inventory-nav href="<?= e(app_inventory_url('movements', [], $inventoryLang)); ?>">Hamısı</a></div><?php foreach ($recentRows as $row): ?><div class="spog-list-item"><strong><?= e((string) ($row['item_name'] ?? $row['sku'] ?? '-')); ?></strong><small><?= e(app_ui_label((string) ($row['movement_type'] ?? ''), (string) ($row['movement_type'] ?? '-'))); ?> / <?= e($inventoryFormat($row['quantity'] ?? 0)); ?> / <?= e((string) ($row['created_at'] ?? '-')); ?></small></div><?php endforeach; ?><?php if ($recentRows === []): ?><p class="inventory-empty">Hərəkət qeydi yoxdur.</p><?php endif; ?></article></div>
</div>
