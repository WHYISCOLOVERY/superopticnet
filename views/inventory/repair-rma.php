<?php
declare(strict_types=1);
$pageData=(array)($inventoryContext['data']['page']??[]); $rows=(array)($pageData['rows']??[]); $query=(array)($inventoryContext['query']??[]); $canWrite=!empty($inventoryContext['data']['can_write']);
$repairTransitions=['pending'=>['in_repair','cancelled'],'in_repair'=>['repaired','rma','scrapped','cancelled'],'repaired'=>[],'rma'=>[],'scrapped'=>[],'cancelled'=>[]];
?>
<div data-inventory-repair-rma><div class="portal-card-head"><div><strong>Temir / RMA novbesi</strong><small>Defective, repair, fault ve RMA statuslu serialized cihazlar.</small></div></div><form class="inventory-filter" method="get" action="operations-center.php" data-inventory-filter><input type="hidden" name="section" value="inventory"><input type="hidden" name="inventory_tab" value="repair-rma"><div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string)($query['search']??'')); ?>" placeholder="Asset, serial, MAC, model"></div><span></span><span></span><button class="portal-primary-btn" type="submit">Axtar</button></form><div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>Asset</th><th>Mehsul / model</th><th>Serial</th><th>MAC</th><th>Temir statusu</th><th>Techizatci / istinad</th><th>Sebeb</th><th>Tamamlanma</th><th>Lokasiya</th><th>Emeliyyat</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr data-inventory-row><td><strong><?= e((string)($row['asset_code']??'-')); ?></strong></td><td><?= e(trim((string)(($row['vendor']??'').' '.($row['model']??'')))?: (string)($row['item_name']??'-')); ?></td><td><?= e((string)($row['serial_number']??'-')); ?></td><td><?= e((string)($row['mac_address']??'-')); ?></td><td><span class="portal-pill"><?= e(app_ui_status((string)($row['repair_status']??'-'))); ?></span></td><td><?= e((string)($row['repair_vendor']??'-')); ?><?php if((string)($row['external_reference']??'')!==''): ?> / <?= e((string)($row['external_reference'])); ?><?php endif; ?></td><td><?= e((string)($row['repair_reason']??'-')); ?></td><td><?= e((string)($row['completed_at']??'-')); ?></td><td><?= e((string)($row['current_location_type']??'-')); ?> / <?= e((string)($row['current_location_id']??'-')); ?></td><td><?php
$repairId=(int)($row['repair_id']??0); $currentStatus=(string)($row['repair_status']??''); $nextStatuses=$repairTransitions[$currentStatus]??[];
if($canWrite&&$repairId>0&&$nextStatuses!==[]): ?><details class="inventory-inline-action"><summary class="portal-ghost-btn portal-inline-link">Status deyis</summary><form method="post" action="operations-center.php" class="portal-stacked-form">
<input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
<input type="hidden" name="action" value="update_inventory_repair_status">
<input type="hidden" name="repair_id" value="<?= e((string)$repairId); ?>">
<input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(16))); ?>">
<div class="portal-form-row"><label>Yeni status</label><select name="repair_status" required>
<option value="">Secin</option>
<?php foreach($nextStatuses as $nextStatus): ?><option value="<?= e($nextStatus); ?>"><?= e(app_ui_status($nextStatus)); ?></option><?php endforeach; ?>
</select></div>
<div class="portal-form-row"><label>Sebeb</label><input type="text" name="reason" maxlength="255" required placeholder="Status deyisikliyinin sebebi"></div>
<button class="portal-primary-btn" type="submit">Saxla</button>
</form></details><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?><?php if($rows===[]): ?><tr><td colspan="10" class="inventory-empty">Temir/RMA novbesinde cihaz yoxdur.</td></tr><?php endif; ?></tbody></table></div><?php require __DIR__.'/_pagination.php'; ?></div>
