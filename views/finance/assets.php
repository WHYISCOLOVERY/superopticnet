<?php
declare(strict_types=1);

$pageData = (array) ($financeContext['data']['page'] ?? []);
$assetRows = (array) ($pageData['rows'] ?? []);
$assetView = (string) ($pageData['view'] ?? 'depreciation');
$depreciationAssets = (array) ($financeContext['data']['assets'] ?? []);
$stockItems = (array) ($financeContext['data']['stock_items'] ?? []);
?>
<div data-finance-assets data-finance-asset-depreciation>
    <div class="portal-card-head">
        <div><strong>Esas vesaitler ve amortizasiya</strong><small>Anbar datasinin read-only maliyye deyerleri, emekdas emlaki ve il sonu silinme statusu.</small></div>
        <div class="portal-badge-list"><button class="portal-ghost-btn" type="button" data-finance-print onclick="window.print()">Cap et</button><button class="portal-primary-btn" type="button" data-finance-dialog-open="depreciation-create">Amortizasiya hesabla</button></div>
    </div>
    <nav class="finance-subnav" aria-label="Esas vesait gorunusleri">
        <?php foreach (['depreciation' => 'Amortizasiya', 'inventory' => 'Avadanliq siyahisi', 'employees' => 'Emekdas emlaki'] as $viewKey => $viewLabel): ?>
            <a class="portal-ghost-btn portal-inline-link<?= $assetView === $viewKey ? ' is-active' : ''; ?>" data-finance-nav<?= $viewKey === 'inventory' ? ' data-finance-printable-inventory' : ''; ?><?= $viewKey === 'employees' ? ' data-finance-printable-employee-assets' : ''; ?> href="<?= e(app_finance_url('assets', ['asset_view' => $viewKey], $financeLang)); ?>"><?= e($viewLabel); ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="finance-filter" method="get" action="operations-center.php" data-finance-filter>
        <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="assets"><input type="hidden" name="asset_view" value="<?= e($assetView); ?>">
        <div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')); ?>" placeholder="Asset, serial, MAC, SKU"></div><span></span><span></span><button class="portal-primary-btn" type="submit">Axtar</button>
    </form>
    <div class="portal-table-wrap"><table class="portal-table">
        <?php if ($assetView === 'depreciation'): ?>
            <thead><tr><th>Asset</th><th>Il</th><th>Ilkin qiymet</th><th>Faiz</th><th>Amortizasiya</th><th>Net deyer</th><th>Silinme</th></tr></thead><tbody>
            <?php foreach ($assetRows as $row): ?><tr data-finance-row><td><?= e((string) ($row['asset_label'] ?? '-')); ?></td><td><?= e((string) ($row['fiscal_year'] ?? '-')); ?></td><td><?= e(billing_money($row['acquisition_cost'] ?? 0)); ?></td><td><?= e((string) ($row['depreciation_rate'] ?? 0)); ?>%</td><td><?= e(billing_money($row['depreciation_amount'] ?? 0)); ?></td><td><?= e(billing_money($row['net_book_value'] ?? 0)); ?></td><td><?= e((string) ($row['writeoff_status'] ?? '-')); ?></td></tr><?php endforeach; ?>
            <?php if ($assetRows === []): ?><tr><td colspan="7" class="finance-empty">Amortizasiya qeydi yoxdur.</td></tr><?php endif; ?></tbody>
        <?php else: ?>
            <thead><tr><th>Asset</th><th>Mehsul</th><th>Serial/MAC</th><th>Yer</th><th>Veziyyet</th><th>Net deyer</th><th>Silinme</th></tr></thead><tbody>
            <?php foreach ($assetRows as $row): ?><tr data-finance-row><td><?= e((string) ($row['asset_code'] ?? '-')); ?></td><td><?= e(trim((string) (($row['vendor'] ?? '') . ' ' . ($row['model'] ?? $row['item_name'] ?? '')))); ?></td><td><?= e((string) (($row['serial_number'] ?? '') ?: ($row['mac_address'] ?? '-'))); ?></td><td><?= e((string) ($row['current_location_id'] ?? $row['current_location_type'] ?? '-')); ?></td><td><?= e((string) ($row['condition_status'] ?? '-')); ?></td><td><?= e(billing_money($row['net_book_value'] ?? 0)); ?></td><td><?= e((string) ($row['writeoff_status'] ?? '-')); ?></td></tr><?php endforeach; ?>
            <?php if ($assetRows === []): ?><tr><td colspan="7" class="finance-empty">Asset tapilmadi.</td></tr><?php endif; ?></tbody>
        <?php endif; ?>
    </table></div>
    <?php require __DIR__ . '/_pagination.php'; ?>

    <div class="finance-dialog" data-finance-dialog="depreciation-create" hidden><div class="finance-dialog-backdrop" data-finance-dialog-close></div><section class="finance-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="depreciation-title"><div class="finance-dialog-head"><div><h3 id="depreciation-title">Amortizasiya hesabi</h3><small>Net deyer threshold-a uyarsa silinme statusu hesablanir.</small></div><button class="finance-dialog-close" type="button" data-finance-dialog-close aria-label="Bagla">X</button></div><form class="portal-stacked-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="save_asset_depreciation"><input type="hidden" name="finance_tab" value="assets">
        <div class="portal-form-row portal-grid-slim"><select name="depreciation_asset_id"><option value="">Asset sec</option><?php foreach ($depreciationAssets as $row): ?><option value="<?= e((string) ($row['asset_id'] ?? 0)); ?>"><?= e((string) ($row['asset_code'] ?? '-')); ?> / <?= e((string) ($row['item_name'] ?? $row['model'] ?? '-')); ?></option><?php endforeach; ?></select><select name="depreciation_stock_item_id"><option value="">Stok mehsulu sec</option><?php foreach ($stockItems as $row): ?><option value="<?= e((string) ($row['id'] ?? 0)); ?>"><?= e((string) ($row['sku'] ?? '-')); ?> / <?= e((string) ($row['item_name'] ?? '-')); ?></option><?php endforeach; ?></select></div>
        <div class="portal-form-row"><input type="text" name="depreciation_asset_label" placeholder="Asset adi (secim yoxdursa)"></div>
        <div class="portal-form-row portal-grid-slim"><input type="number" step="0.01" min="0" name="acquisition_cost" placeholder="Ilkin qiymet" required><input type="number" step="0.01" min="0" max="100" name="depreciation_rate" placeholder="Faiz" required></div>
        <div class="portal-form-row portal-grid-slim"><input type="number" min="2000" max="2100" name="fiscal_year" value="<?= e(date('Y')); ?>"><input type="number" step="0.01" min="0" name="writeoff_threshold" placeholder="Silinme threshold"></div>
        <label class="portal-checkbox-row"><input type="checkbox" name="request_writeoff" value="1"> Net deyer icaze verirse silinme yaz</label><div class="portal-form-row"><textarea name="depreciation_note" placeholder="Qaime, akt ve qeyd"></textarea></div><button class="portal-primary-btn" type="submit">Hesabla ve saxla</button>
    </form></section></div>
</div>
