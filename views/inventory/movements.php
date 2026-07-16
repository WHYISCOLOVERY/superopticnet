<?php
declare(strict_types=1);

$view = (string) ($inventoryContext['data']['view'] ?? 'stock');
$pageData = (array) ($inventoryContext['data']['page'] ?? []);
$rows = (array) ($pageData['rows'] ?? []);
$products = (array) ($inventoryContext['data']['products'] ?? []);
$branches = (array) ($inventoryContext['data']['branches'] ?? []);
$employees = (array) ($inventoryContext['data']['employees'] ?? []);
$categories = (array) ($inventoryContext['data']['categories'] ?? []);
$query = (array) ($inventoryContext['query'] ?? []);
$canWrite = !empty($inventoryContext['data']['can_write']);
$canApprove = !empty($inventoryContext['data']['can_approve']);
$openDialog = clean_text((string) ($query['open'] ?? ''));
?>
<div data-inventory-movements>
    <div class="portal-card-head">
        <div>
            <strong><?= $view === 'lifecycle' ? 'Lifecycle hereketleri' : 'Stok hereketleri'; ?></strong>
            <small>Medaxil, lokasiya transferi ve sebebli stok duzelisleri auditde saxlanir.</small>
        </div>
        <?php if ($canWrite): ?>
            <div class="portal-badge-list">
                <button class="portal-primary-btn" type="button" data-inventory-dialog-open="stock-intake">Mal medaxil et</button>
                <button class="portal-ghost-btn" type="button" data-inventory-dialog-open="branch-transfer">Filiala transfer et</button>
                <?php if ($canApprove): ?>
                    <button class="portal-ghost-btn" type="button" data-inventory-dialog-open="stock-adjustment">Stok duzelisi</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <nav class="inventory-subnav">
        <a class="portal-ghost-btn portal-inline-link<?= $view === 'stock' ? ' is-active' : ''; ?>" data-inventory-nav href="<?= e(app_inventory_url('movements', ['movement_view' => 'stock'], $inventoryLang)); ?>">Stok</a>
        <a class="portal-ghost-btn portal-inline-link<?= $view === 'lifecycle' ? ' is-active' : ''; ?>" data-inventory-nav href="<?= e(app_inventory_url('movements', ['movement_view' => 'lifecycle'], $inventoryLang)); ?>">Lifecycle</a>
    </nav>
    <div class="portal-badge-list" aria-label="Anbar hereket novleri">
        <?php foreach (['Medaxil', 'Texnike verilis', 'Filiala transfer', 'Geri qaytarma', 'Serfiyyat'] as $movementLabel): ?>
            <span class="portal-soft-pill"><?= e($movementLabel); ?></span>
        <?php endforeach; ?>
    </div>

    <form class="inventory-filter" method="get" action="operations-center.php" data-inventory-filter>
        <input type="hidden" name="section" value="inventory">
        <input type="hidden" name="inventory_tab" value="movements">
        <input type="hidden" name="movement_view" value="<?= e($view); ?>">
        <div class="portal-form-row">
            <label>Axtaris</label>
            <input type="search" name="search" value="<?= e((string) ($query['search'] ?? '')); ?>">
        </div>
        <span></span>
        <span></span>
        <button class="portal-primary-btn">Axtar</button>
    </form>

    <div class="portal-table-wrap">
        <table class="portal-table">
            <thead>
                <tr>
                    <th>Hereket</th>
                    <th>Mehsul</th>
                    <th>Tip</th>
                    <th>Miqdar</th>
                    <th>Kimden</th>
                    <th>Kime</th>
                    <th>Operator</th>
                    <th>Tarix</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr data-inventory-row>
                        <td><?= e((string) ($row['movement_no'] ?? $row['id'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['item_name'] ?? $row['sku'] ?? '-')); ?></td>
                        <td><?= e(app_ui_term((string) ($row['movement_type'] ?? '-'))); ?></td>
                        <td><?= e($inventoryFormat($row['quantity'] ?? 0)); ?></td>
                        <td><?= e(app_ui_term((string) ($row['from_location_type'] ?? '-'))); ?> / <?= e((string) ($row['from_location_id'] ?? '-')); ?></td>
                        <td><?= e(app_ui_term((string) ($row['to_location_type'] ?? '-'))); ?> / <?= e((string) ($row['to_location_id'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['actor_name'] ?? $row['performed_by'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['created_at'] ?? '-')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="inventory-empty">Hereket qeydi yoxdur.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require __DIR__ . '/_pagination.php'; ?>

    <?php if ($canWrite): ?>
        <div class="inventory-dialog" data-inventory-dialog="stock-intake"<?= $openDialog === 'stock-intake' ? '' : ' hidden'; ?>>
            <div class="inventory-dialog-backdrop" data-inventory-dialog-close></div>
            <section class="inventory-dialog-panel" role="dialog" aria-modal="true">
                <div class="inventory-dialog-head">
                    <div>
                        <h3>Mal medaxil et</h3>
                        <small>Sistem MDX sened nomresini avtomatik verir; movcud mehsulu secin ve ya yeni mal melumatlarini yazin.</small>
                    </div>
                    <button class="inventory-dialog-close" type="button" data-inventory-dialog-close>X</button>
                </div>
                <div class="inventory-dialog-body">
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_inventory_intake">
                        <input type="hidden" name="inventory_tab" value="movements">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="item_id">
                                <option value="">Movcud mehsul sec</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= e((string) $p['id']); ?>"><?= e((string) ($p['sku'] ?? '-')); ?> - <?= e((string) ($p['item_name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" min="0.01" step="0.01" name="quantity" value="1" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input name="new_sku" placeholder="Yeni SKU">
                            <input name="new_item_name" placeholder="Yeni malin adi">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="new_category">
                                <option value="other">Kateqoriya sec</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e((string) ($category['code'] ?? 'other')); ?>"><?= e((string) ($category['name'] ?? $category['code'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="new_unit">
                                <option value="eded">eded</option>
                                <option value="metr">metr</option>
                                <option value="kq">kq</option>
                                <option value="paket">paket</option>
                                <option value="dest">dest</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="to_location_type">
                                <option value="warehouse">Merkezi anbar</option>
                                <option value="branch">Filial</option>
                            </select>
                            <select name="to_location_id" required>
                                <option value="MAIN">Merkezi anbar</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= e((string) $b['id']); ?>"><?= e((string) ($b['name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" min="0" step="0.01" name="unit_price" placeholder="Vahid qiymeti">
                            <input name="source_name" placeholder="Tedarukcu">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input name="serial_number" placeholder="SN varsa">
                            <input name="mac_address" placeholder="MAC varsa">
                        </div>
                        <textarea name="note" placeholder="Qeyd"></textarea>
                        <button class="portal-primary-btn">Medaxili saxla</button>
                    </form>
                </div>
            </section>
        </div>

        <div class="inventory-dialog" data-inventory-dialog="branch-transfer"<?= $openDialog === 'branch-transfer' ? '' : ' hidden'; ?>>
            <div class="inventory-dialog-backdrop" data-inventory-dialog-close></div>
            <section class="inventory-dialog-panel" role="dialog" aria-modal="true">
                <div class="inventory-dialog-head">
                    <div>
                        <h3>Filiala transfer et</h3>
                        <small>Sistem TRF sened nomresini avtomatik verir; umumi stok deyismir, lokasiya balanslari yenilenir.</small>
                    </div>
                    <button class="inventory-dialog-close" type="button" data-inventory-dialog-close>X</button>
                </div>
                <div class="inventory-dialog-body">
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_inventory_branch_transfer">
                        <input type="hidden" name="inventory_tab" value="movements">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="item_id" required>
                                <option value="">Mehsul sec</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= e((string) $p['id']); ?>"><?= e((string) ($p['item_name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" min="0.01" step="0.01" name="quantity" value="1" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="from_location" required>
                                <option value="">Cixis lokasiyasi</option>
                                <option value="warehouse:MAIN">Merkezi anbar</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="branch:<?= e((string) $b['id']); ?>">Filial: <?= e((string) ($b['name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="to_location" required>
                                <option value="">Qebul lokasiyasi</option>
                                <option value="warehouse:MAIN">Merkezi anbar</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="branch:<?= e((string) $b['id']); ?>">Filial: <?= e((string) ($b['name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <textarea name="note" placeholder="Transfer qeydi"></textarea>
                        <button class="portal-primary-btn">Transferi saxla</button>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($canApprove): ?>
        <div class="inventory-dialog" data-inventory-dialog="stock-adjustment"<?= $openDialog === 'stock-adjustment' ? '' : ' hidden'; ?>>
            <div class="inventory-dialog-backdrop" data-inventory-dialog-close></div>
            <section class="inventory-dialog-panel" role="dialog" aria-modal="true">
                <div class="inventory-dialog-head">
                    <div>
                        <h3>Stok duzelisi</h3>
                        <small>Artim ve azalma sebebli audit yazir; menfi stok bloklanir.</small>
                    </div>
                    <button class="inventory-dialog-close" type="button" data-inventory-dialog-close>X</button>
                </div>
                <div class="inventory-dialog-body">
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="adjust_inventory_stock">
                        <input type="hidden" name="inventory_tab" value="movements">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="item_id" required>
                                <option value="">Mehsul sec</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= e((string) $p['id']); ?>"><?= e((string) ($p['sku'] ?? '-')); ?> - <?= e((string) ($p['item_name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" step="0.01" name="quantity_delta" placeholder="+/- miqdar" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="location_type">
                                <option value="warehouse">Merkezi anbar</option>
                                <option value="branch">Filial</option>
                                <option value="employee">Emekdas</option>
                            </select>
                            <select name="location_id" required>
                                <option value="MAIN">Merkezi anbar</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= e((string) $b['id']); ?>"><?= e((string) ($b['name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= e((string) ($employee['id'] ?? '')); ?>">Emekdas: <?= e((string) ($employee['full_name'] ?? $employee['login'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <textarea name="reason" required placeholder="Duzelis sebebi"></textarea>
                        <button class="portal-primary-btn">Duzelisi saxla</button>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
