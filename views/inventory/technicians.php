<?php
declare(strict_types=1);
$pageData = (array) ($inventoryContext['data']['page'] ?? []);
$rows = (array) ($pageData['rows'] ?? []);
$balances = (array) ($inventoryContext['data']['balances'] ?? []);
$branches = (array) ($inventoryContext['data']['branches'] ?? []);
$canWrite = !empty($inventoryContext['data']['can_write']);
$query = (array) ($inventoryContext['query'] ?? []);
?>
<div data-inventory-technicians>
    <div class="portal-card-head">
        <div><strong>Emekdaslarin uzerinde olan mallar</strong><small>Seriyali cihazlar ve balans mehsullari birlikde gosterilir.</small></div>
        <a class="portal-primary-btn portal-inline-link" data-inventory-nav href="<?= e(app_inventory_url('handover', [], $inventoryLang)); ?>">Yeni tehvil</a>
    </div>
    <form class="inventory-filter" method="get" action="operations-center.php" data-inventory-filter>
        <input type="hidden" name="section" value="inventory">
        <input type="hidden" name="inventory_tab" value="technicians">
        <div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string) ($query['search'] ?? '')); ?>" placeholder="Texnik, asset, serial, MAC"></div>
        <span></span><span></span><button class="portal-primary-btn" type="submit">Axtar</button>
    </form>

    <div class="portal-table-wrap">
        <table class="portal-table">
            <thead><tr><th>Emekdas</th><th>Mal</th><th>Qaliq</th><th>Vahid</th><th>Son hereket</th><th>Emeliyyat</th></tr></thead>
            <tbody>
            <?php foreach ($balances as $balance): ?>
                <tr data-inventory-balance-row>
                    <td><strong><?= e((string) ($balance['full_name'] ?? ('#' . ($balance['employee_id'] ?? '-')))); ?></strong></td>
                    <td><?= e((string) ($balance['sku'] ?? '-')); ?> - <?= e((string) ($balance['item_name'] ?? '-')); ?></td>
                    <td><strong><?= e($inventoryFormat($balance['available_quantity'] ?? 0)); ?></strong></td>
                    <td><?= e((string) ($balance['unit'] ?? '-')); ?></td>
                    <td><?= e((string) ($balance['updated_at'] ?? '-')); ?></td>
                    <td>
                        <?php if ($canWrite): ?>
                            <form method="post" class="portal-badge-list">
                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                <input type="hidden" name="action" value="return_inventory_from_employee">
                                <input type="hidden" name="inventory_tab" value="technicians">
                                <input type="hidden" name="return_item_id" value="<?= e((string) ($balance['item_id'] ?? 0)); ?>">
                                <input type="hidden" name="return_employee_id" value="<?= e((string) ($balance['employee_id'] ?? 0)); ?>">
                                <input type="number" name="return_quantity" min="0.01" step="0.01" max="<?= e((string) ($balance['available_quantity'] ?? 0)); ?>" value="<?= e((string) ($balance['available_quantity'] ?? 0)); ?>" required>
                                <select name="return_destination_id"><option value="MAIN">Merkezi anbar</option></select>
                                <input type="hidden" name="return_destination_type" value="warehouse">
                                <button class="portal-ghost-btn" type="submit">Geri qebul et</button>
                            </form>
                        <?php else: ?>-
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($balances === []): ?><tr><td colspan="6" class="inventory-empty">Emekdas balansinda mal yoxdur.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="portal-card-head"><div><strong>Seriyali cihazlar</strong><small>Cari lokasiyasi texnik olan modem ve inventarlar.</small></div></div>
    <div class="portal-table-wrap">
        <table class="portal-table"><thead><tr><th>Texnik</th><th>Asset</th><th>Mehsul / model</th><th>Serial</th><th>MAC</th><th>Lifecycle</th><th>Veziyyet</th><th>Yenilenib</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr data-inventory-row><td><strong><?= e((string) ($row['current_location_id'] ?? '-')); ?></strong></td><td><?= e((string) ($row['asset_code'] ?? '-')); ?></td><td><?= e(trim((string) (($row['vendor'] ?? '') . ' ' . ($row['model'] ?? ''))) ?: (string) ($row['item_name'] ?? '-')); ?></td><td><?= e((string) ($row['serial_number'] ?? '-')); ?></td><td><?= e((string) ($row['mac_address'] ?? '-')); ?></td><td><?= e((string) ($row['lifecycle_status'] ?? '-')); ?></td><td><?= e((string) ($row['condition_status'] ?? '-')); ?></td><td><?= e((string) ($row['updated_at'] ?? '-')); ?></td></tr><?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="8" class="inventory-empty">Texnikde serialized cihaz tapilmadi.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <?php require __DIR__ . '/_pagination.php'; ?>
</div>
