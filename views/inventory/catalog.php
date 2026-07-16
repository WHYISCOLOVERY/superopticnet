<?php
declare(strict_types=1);

$pageData = (array) ($inventoryContext['data']['page'] ?? []);
$rows = (array) ($pageData['rows'] ?? []);
$categories = (array) ($inventoryContext['data']['categories'] ?? []);
$query = (array) ($inventoryContext['query'] ?? []);
$canWrite = !empty($inventoryContext['data']['can_write']);
$catalogColspan = $canWrite ? 9 : 8;
$unitOptions = ['eded', 'metr', 'kq', 'paket', 'dest'];
?>
<div data-inventory-catalog>
    <div class="portal-card-head">
        <div>
            <strong>Mehsul kataloqu</strong>
            <small>SKU, kateqoriya, vahid, cari ve minimum stok.</small>
        </div>
        <?php if ($canWrite): ?>
            <button class="portal-primary-btn" type="button" data-inventory-dialog-open="category-create">Kateqoriya elave et</button>
        <?php endif; ?>
    </div>

    <form class="inventory-filter" method="get" action="operations-center.php" data-inventory-filter>
        <input type="hidden" name="section" value="inventory">
        <input type="hidden" name="inventory_tab" value="catalog">
        <div class="portal-form-row">
            <label>Axtaris</label>
            <input type="search" name="search" value="<?= e((string) ($query['search'] ?? '')); ?>" placeholder="SKU, mehsul, kateqoriya">
        </div>
        <div class="portal-form-row">
            <label>Kateqoriya</label>
            <select name="category">
                <option value="">Butun kateqoriyalar</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e((string) ($category['code'] ?? '')); ?>"<?= ($query['category'] ?? '') === ($category['code'] ?? '') ? ' selected' : ''; ?>>
                        <?= e((string) ($category['name'] ?? '-')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <span></span>
        <button class="portal-primary-btn" type="submit">Tetbiq et</button>
    </form>

    <div class="portal-table-wrap">
        <table class="portal-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Mehsul</th>
                    <th>Kateqoriya</th>
                    <th>Vahid</th>
                    <th>Cari stok</th>
                    <th>Minimum</th>
                    <th>Status</th>
                    <th>Yenilenib</th>
                    <?php if ($canWrite): ?><th>Emeliyyat</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $itemId = (string) ($row['id'] ?? '');
                    $currentUnit = (string) ($row['unit'] ?? 'eded');
                    $currentCategory = (string) ($row['category'] ?? 'other');
                    ?>
                    <tr data-inventory-row>
                        <td><strong><?= e((string) ($row['sku'] ?? '-')); ?></strong></td>
                        <td><?= e((string) ($row['item_name'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['category_name'] ?? $row['category'] ?? '-')); ?></td>
                        <td><?= e($currentUnit); ?></td>
                        <td><?= e($inventoryFormat($row['quantity_on_hand'] ?? 0)); ?></td>
                        <td><?= e($inventoryFormat($row['minimum_quantity'] ?? 0)); ?></td>
                        <td><?= e(app_ui_status((string) ($row['status'] ?? 'unknown'))); ?></td>
                        <td><?= e((string) ($row['updated_at'] ?? '-')); ?></td>
                        <?php if ($canWrite): ?>
                            <td>
                                <div class="admin-users-actions">
                                    <button class="portal-ghost-btn portal-inline-link" type="button" data-inventory-dialog-open="catalog-edit-<?= e($itemId); ?>">Redakte et</button>
                                    <?php if ((string) ($row['status'] ?? '') === 'active'): ?>
                                        <button class="portal-ghost-btn portal-inline-link" type="button" data-inventory-dialog-open="catalog-disable-<?= e($itemId); ?>">Deaktiv et</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= e((string) $catalogColspan); ?>" class="inventory-empty">Mehsul tapilmadi.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require __DIR__ . '/_pagination.php'; ?>

    <?php if ($canWrite): ?>
        <?php foreach ($rows as $row): ?>
            <?php
            $itemId = (string) ($row['id'] ?? '');
            $currentUnit = (string) ($row['unit'] ?? 'eded');
            $currentCategory = (string) ($row['category'] ?? 'other');
            ?>
            <div class="inventory-dialog" data-inventory-dialog="catalog-edit-<?= e($itemId); ?>" hidden>
                <div class="inventory-dialog-backdrop" data-inventory-dialog-close></div>
                <section class="inventory-dialog-panel" role="dialog" aria-modal="true">
                    <div class="inventory-dialog-head">
                        <div>
                            <h3>Mehsulu redakte et</h3>
                            <small><?= e((string) ($row['sku'] ?? '-')); ?> karti ucun sebebli deyisiklik.</small>
                        </div>
                        <button class="inventory-dialog-close" type="button" data-inventory-dialog-close>X</button>
                    </div>
                    <div class="inventory-dialog-body">
                        <form class="portal-stacked-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="update_inventory_item">
                            <input type="hidden" name="inventory_tab" value="catalog">
                            <input type="hidden" name="item_id" value="<?= e($itemId); ?>">
                            <div class="portal-form-row">
                                <label>Mehsul adi</label>
                                <input name="item_name" value="<?= e((string) ($row['item_name'] ?? '')); ?>" required>
                            </div>
                            <div class="portal-form-row portal-grid-slim">
                                <select name="category" required>
                                    <?php foreach ($categories as $category): ?>
                                        <?php $categoryCode = (string) ($category['code'] ?? 'other'); ?>
                                        <option value="<?= e($categoryCode); ?>"<?= $currentCategory === $categoryCode ? ' selected' : ''; ?>>
                                            <?= e((string) ($category['name'] ?? $categoryCode)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="unit" required>
                                    <?php foreach ($unitOptions as $unit): ?>
                                        <option value="<?= e($unit); ?>"<?= $currentUnit === $unit ? ' selected' : ''; ?>><?= e($unit); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="portal-form-row">
                                <label>Minimum stok</label>
                                <input type="number" min="0" step="0.01" name="minimum_quantity" value="<?= e((string) ($row['minimum_quantity'] ?? 0)); ?>">
                            </div>
                            <textarea name="reason" required placeholder="Deyisiklik sebebi"></textarea>
                            <button class="portal-primary-btn">Yadda saxla</button>
                        </form>
                    </div>
                </section>
            </div>

            <div class="inventory-dialog" data-inventory-dialog="catalog-disable-<?= e($itemId); ?>" hidden>
                <div class="inventory-dialog-backdrop" data-inventory-dialog-close></div>
                <section class="inventory-dialog-panel" role="dialog" aria-modal="true">
                    <div class="inventory-dialog-head">
                        <div>
                            <h3>Mehsulu deaktiv et</h3>
                            <small>Mehsul silinmir; tarixce ve sened referanslari qalir.</small>
                        </div>
                        <button class="inventory-dialog-close" type="button" data-inventory-dialog-close>X</button>
                    </div>
                    <div class="inventory-dialog-body">
                        <form class="portal-stacked-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="deactivate_inventory_item">
                            <input type="hidden" name="inventory_tab" value="catalog">
                            <input type="hidden" name="item_id" value="<?= e($itemId); ?>">
                            <textarea name="reason" required placeholder="Deaktiv sebebi"></textarea>
                            <button class="portal-primary-btn">Deaktiv et</button>
                        </form>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>

        <div class="inventory-dialog" data-inventory-dialog="category-create" hidden>
            <div class="inventory-dialog-backdrop" data-inventory-dialog-close></div>
            <section class="inventory-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="category-create-title">
                <div class="inventory-dialog-head">
                    <div>
                        <h3 id="category-create-title">Yeni kateqoriya</h3>
                        <small>Movcud stok ve emlak mehsullari ucun vahid kateqoriya yaradilir.</small>
                    </div>
                    <button class="inventory-dialog-close" type="button" data-inventory-dialog-close aria-label="Bagla">X</button>
                </div>
                <div class="inventory-dialog-body">
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_inventory_category">
                        <input type="hidden" name="inventory_tab" value="catalog">
                        <div class="portal-form-row portal-grid-slim">
                            <input type="text" name="category_name" placeholder="Kateqoriya adi" required>
                            <input type="text" name="category_code" placeholder="Kod bos qalarsa avtomatik">
                        </div>
                        <div class="portal-form-row">
                            <input type="text" name="category_description" placeholder="Qisa izah">
                        </div>
                        <button class="portal-primary-btn" type="submit">Kateqoriya yarat</button>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
