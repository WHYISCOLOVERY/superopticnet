<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/ui-language-lib.php';

$view=(string)($inventoryContext['data']['view']??'returns'); $pageData=(array)($inventoryContext['data']['page']??[]); $rows=(array)($pageData['rows']??[]); $query=(array)($inventoryContext['query']??[]); $canWrite=!empty($inventoryContext['data']['can_write']);
?>
<div data-inventory-returns-inspection><div class="portal-card-head"><div><strong><?= $view==='inspection'?e(app_ui_label('inspection_results')):'Geri qaytarmalar'; ?></strong><small>Qaytarilan cihaz qebul ve yoxlama tamamlanmadan yeniden istifade olunmur.</small></div></div>
<?php if($view==='returns'&&$canWrite): ?>
<section class="portal-card"><div class="portal-card-head"><div><strong>Yeni qaytarma qeydiyyati</strong><small>Seriya nomreli cihazi anbara qebul edin; yoxlama neticesi sonra ayrica saxlanir.</small></div></div>
<form method="post" action="operations-center.php" class="portal-stacked-form">
<input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
<input type="hidden" name="action" value="receive_inventory_asset_return">
<input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(16))); ?>">
<div class="billing-filter-grid">
<div class="portal-form-row"><label>Asset ID</label><input type="number" name="asset_id" min="1" required placeholder="Siyahida gorunen #ID"></div>
<div class="portal-form-row"><label>Musteri kodu</label><input type="text" name="customer_code" maxlength="64"></div>
<div class="portal-form-row"><label>Texnik kodu</label><input type="text" name="technician_code" maxlength="64"></div>
<div class="portal-form-row"><label>Bilet</label><input type="text" name="ticket_no" maxlength="64"></div>
<div class="portal-form-row"><label>Sebeb</label><input type="text" name="reason" maxlength="255" required placeholder="Qaytarma sebebi"></div>
</div>
<button class="portal-primary-btn" type="submit">Qebul et (karantin)</button>
</form>
</section>
<?php endif; ?>
<nav class="inventory-subnav"><a class="portal-ghost-btn portal-inline-link<?= $view==='returns'?' is-active':''; ?>" data-inventory-nav href="<?= e(app_inventory_url('returns-inspection',['return_view'=>'returns'],$inventoryLang)); ?>">Qaytarmalar</a><a class="portal-ghost-btn portal-inline-link<?= $view==='inspection'?' is-active':''; ?>" data-inventory-nav href="<?= e(app_inventory_url('returns-inspection',['return_view'=>'inspection'],$inventoryLang)); ?>"><?= e(app_ui_label('inspection')); ?></a></nav><form class="inventory-filter" method="get" action="operations-center.php" data-inventory-filter><input type="hidden" name="section" value="inventory"><input type="hidden" name="inventory_tab" value="returns-inspection"><input type="hidden" name="return_view" value="<?= e($view); ?>"><div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string)($query['search']??'')); ?>" placeholder="Avadanliq, serial, musteri, bilet"></div><span></span><span></span><button class="portal-primary-btn" type="submit">Axtar</button></form><div class="portal-table-wrap"><table class="portal-table"><thead><?php if($view==='inspection'): ?><tr><th>ID</th><th><?= e(app_ui_label('asset')); ?></th><th><?= e(app_ui_label('serial_mac')); ?></th><th><?= e(app_ui_label('return_ticket')); ?></th><th>Netice</th><th>Yoxlayan</th><th>Qeyd</th><th>Tarix</th></tr><?php else: ?><tr><th>ID</th><th><?= e(app_ui_label('asset')); ?></th><th>Musteri</th><th>Texnik</th><th><?= e(app_ui_label('return_ticket')); ?></th><th>Sebeb</th><th><?= e(app_ui_label('status')); ?></th><th>Qebul</th><th>Emeliyyat</th></tr><?php endif; ?></thead><tbody><?php foreach($rows as $row): ?><tr data-inventory-row><?php if($view==='inspection'): ?><td>#<?= e((string)($row['id']??0)); ?></td><td><?= e((string)($row['asset_code']??'-')); ?></td><td><?= e((string)($row['serial_number']??'-')); ?> / <?= e((string)($row['mac_address']??'-')); ?></td><td><?= e((string)($row['return_ticket_no']??'-')); ?></td><td><strong><?= e(app_ui_status((string)($row['inspection_status']??'-'))); ?></strong></td><td><?= e((string)($row['inspected_by']??'-')); ?></td><td><?= e((string)($row['notes']??'-')); ?></td><td><?= e((string)($row['inspected_at']??'-')); ?></td><?php else: ?><td>#<?= e((string)($row['id']??0)); ?></td><td><?= e((string)($row['asset_code']??'-')); ?><br><small><?= e((string)($row['serial_number']??'-')); ?></small></td><td><?= e((string)($row['customer_code']??'-')); ?></td><td><?= e((string)($row['technician_code']??'-')); ?></td><td><?= e((string)($row['return_ticket_no']??'-')); ?></td><td><?= e((string)($row['return_reason']??'-')); ?></td><td><?= e(app_ui_status((string)($row['return_status']??'-'))); ?></td><td><?= e((string)($row['received_at']??'-')); ?></td><td><?php if($canWrite&&(string)($row['return_status']??'')==='received'): ?><details class="inventory-inline-action"><summary class="portal-ghost-btn portal-inline-link">Yoxla</summary><form method="post" action="operations-center.php" class="portal-stacked-form">
<input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
<input type="hidden" name="action" value="inspect_inventory_asset_return">
<input type="hidden" name="return_id" value="<?= e((string)($row['id']??0)); ?>">
<input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(16))); ?>">
<div class="portal-form-row"><label>Netice</label><select name="inspection_status" required>
<option value="">Secin</option>
<option value="new">Yeni (saglam)</option>
<option value="used_working">Islek (yeniden istifade)</option>
<option value="repair">Temire gonder</option>
<option value="rma">RMA - techizatciya qaytar</option>
<option value="scrap">Yararsiz - silinsin</option>
</select></div>
<div class="portal-form-row"><label>Sebeb</label><input type="text" name="reason" maxlength="255" required placeholder="Yoxlama neticesinin sebebi"></div>
<div class="portal-form-row"><label>Qeyd</label><input type="text" name="notes" maxlength="255"></div>
<button class="portal-primary-btn" type="submit">Yoxlamani saxla</button>
</form></details><?php else: ?>-<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?><?php if($rows===[]): ?><tr><td colspan="<?= $view==='returns'?9:8; ?>" class="inventory-empty">Qeyd tapilmadi.</td></tr><?php endif; ?></tbody></table></div><?php require __DIR__.'/_pagination.php'; ?></div>
