<?php
declare(strict_types=1);

$pageData = (array) ($inventoryContext['data']['page'] ?? []);
$rows = (array) ($pageData['rows'] ?? []);
$products = (array) ($inventoryContext['data']['products'] ?? []);
$branches = (array) ($inventoryContext['data']['branches'] ?? []);
$canWrite = !empty($inventoryContext['data']['can_write']);
$canApprove = !empty($inventoryContext['data']['can_approve']);
$query = (array) ($inventoryContext['query'] ?? []);
$openDialog = clean_text((string) ($query['open'] ?? ''));
?>
<div data-inventory-approvals>
    <div class="portal-card-head">
        <div>
            <strong>Transfer ve qaytarma senedleri</strong>
            <small>QAY senedi pending yaranir; stok yalniz tesdiqden sonra silinir.</small>
        </div>
        <?php if ($canWrite): ?>
            <button class="portal-primary-btn" type="button" data-inventory-dialog-open="external-return">Qaytarma yarat</button>
        <?php endif; ?>
    </div>

    <div class="portal-table-wrap">
        <table class="portal-table">
            <thead>
                <tr>
                    <th>Sened</th>
                    <th>Tip</th>
                    <th>Status</th>
                    <th>Kimden</th>
                    <th>Kime</th>
                    <th>Setir</th>
                    <th>Tarix</th>
                    <th>Emeliyyat</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr data-inventory-row>
                        <td><strong><?= e((string) ($row['document_no'] ?? '-')); ?></strong></td>
                        <td><?= e(app_ui_term((string) ($row['document_type'] ?? '-'))); ?></td>
                        <td><?= e(app_ui_status((string) ($row['status'] ?? '-'))); ?></td>
                        <td><?= e(app_ui_term((string) ($row['source_location_type'] ?? '-'))); ?> / <?= e((string) ($row['source_location_id'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['destination_name'] ?? $row['destination_id'] ?? '-')); ?></td>
                        <td><?= e((string) ($row['item_count'] ?? 0)); ?></td>
                        <td><?= e((string) ($row['created_at'] ?? '-')); ?></td>
                        <td>
                            <div class="portal-badge-list">
                                <a class="portal-ghost-btn portal-inline-link" href="inventory-document.php?id=<?= e((string) ($row['id'] ?? 0)); ?>" target="_blank">Cap</a>
                                <?php if ($canApprove && in_array((string) ($row['status'] ?? ''), ['draft', 'pending'], true)): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="approve_inventory_request">
                                        <input type="hidden" name="inventory_tab" value="approvals">
                                        <input type="hidden" name="request_id" value="<?= e((string) ($row['id'] ?? 0)); ?>">
                                        <button class="portal-primary-btn">Tesdiq</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="reject_inventory_request">
                                        <input type="hidden" name="inventory_tab" value="approvals">
                                        <input type="hidden" name="request_id" value="<?= e((string) ($row['id'] ?? 0)); ?>">
                                        <input type="hidden" name="rejection_reason" value="Tesdiq edilmedi">
                                        <button class="portal-ghost-btn">Imtina</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="inventory-empty">Sened yoxdur.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php require __DIR__ . '/_pagination.php'; ?>

    <?php if ($canWrite): ?>
        <div class="inventory-dialog" data-inventory-dialog="external-return"<?= $openDialog === 'external-return' ? '' : ' hidden'; ?>>
            <div class="inventory-dialog-backdrop" data-inventory-dialog-close></div>
            <section class="inventory-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="external-return-title">
                <div class="inventory-dialog-head">
                    <div>
                        <h3 id="external-return-title">Qaytarma senedi</h3>
                        <small>Sistem QAY sened nomresini avtomatik verir; tesdiqden evvel stok deyismir.</small>
                    </div>
                    <button class="inventory-dialog-close" type="button" data-inventory-dialog-close aria-label="Bagla">X</button>
                </div>
                <div class="inventory-dialog-body">
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_inventory_external_request">
                        <input type="hidden" name="inventory_tab" value="approvals">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="item_id" required>
                                <option value="">Mehsul sec</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= e((string) ($p['id'] ?? '')); ?>"><?= e((string) ($p['sku'] ?? '-')); ?> - <?= e((string) ($p['item_name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" min="0.01" step="0.01" name="quantity" value="1" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="source_location" required>
                                <option value="">Cixis lokasiyasi</option>
                                <option value="warehouse:MAIN">Merkezi anbar</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="branch:<?= e((string) ($b['id'] ?? '')); ?>">Filial: <?= e((string) ($b['name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="destination_type">
                                <option value="company">Sirket</option>
                                <option value="institution">Qurum</option>
                                <option value="person">Sexs</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input name="destination_name" placeholder="Qebul eden sirket/qurum/sexs" required>
                            <select name="reason">
                                <option value="unneeded">Lazim deyil</option>
                                <option value="defective">Xarabdir</option>
                                <option value="wrong_delivery">Yanlisdir</option>
                                <option value="other">Diger</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="disposition">
                                <option value="return_to_supplier">Tedarukcuya qaytarma</option>
                                <option value="external_transfer">Xarici transfer</option>
                                <option value="writeoff">Silinme</option>
                            </select>
                            <input type="number" min="0" step="0.01" name="unit_price" placeholder="Vahid qiymeti">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input name="serial_number" placeholder="SN varsa">
                            <input name="mac_address" placeholder="MAC varsa">
                        </div>
                        <textarea name="note" placeholder="Qeyd"></textarea>
                        <button class="portal-primary-btn">Tesdiqe gonder</button>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
