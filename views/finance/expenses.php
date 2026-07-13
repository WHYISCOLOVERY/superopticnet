<?php
declare(strict_types=1);

$pageData = (array) ($financeContext['data']['page'] ?? []);
$expenseRows = (array) ($pageData['rows'] ?? []);
$financeBranches = (array) ($financeContext['data']['branches'] ?? []);
$financeEmployees = (array) ($financeContext['data']['employees'] ?? []);
?>
<div data-finance-expenses>
    <div class="portal-card-head">
        <div><strong>Sirket xercleri</strong><small>Manual xerc, kateqoriya, filial, mesul emekdas ve audit statusu.</small></div>
        <button class="portal-primary-btn" type="button" data-finance-dialog-open="expense-create">Yeni xerc</button>
    </div>
    <form class="finance-filter" method="get" action="operations-center.php" data-finance-filter>
        <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="expenses">
        <div class="portal-form-row"><label>Axtaris</label><input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')); ?>" placeholder="Basliq, kateqoriya, audit"></div>
        <span></span><span></span><button class="portal-primary-btn" type="submit">Axtar</button>
    </form>
    <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>ID</th><th>Basliq</th><th>Kateqoriya</th><th>Mebleg</th><th>Filial</th><th>Mesul</th><th>Status</th><th>Tarix</th></tr></thead><tbody>
        <?php foreach ($expenseRows as $row): ?><tr data-finance-row><td>#<?= e((string) ($row['id'] ?? 0)); ?></td><td><?= e((string) ($row['title'] ?? $row['summary'] ?? '-')); ?></td><td><?= e((string) ($row['category'] ?? '-')); ?></td><td><?= e(billing_money($row['amount'] ?? 0)); ?></td><td><?= e((string) ($row['branch_id'] ?? '-')); ?></td><td><?= e((string) ($row['actor_name'] ?? '-')); ?></td><td><?= e((string) ($row['status'] ?? '-')); ?></td><td><?= e((string) ($row['created_at'] ?? '-')); ?></td></tr><?php endforeach; ?>
        <?php if ($expenseRows === []): ?><tr><td colspan="8" class="finance-empty">Xerc qeydi yoxdur.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php require __DIR__ . '/_pagination.php'; ?>

    <div class="finance-dialog" data-finance-dialog="expense-create" hidden>
        <div class="finance-dialog-backdrop" data-finance-dialog-close></div>
        <section class="finance-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="expense-create-title">
            <div class="finance-dialog-head"><div><h3 id="expense-create-title">Yeni xerc</h3><small>Qeyd finance audit ve notification axinina yazilir.</small></div><button class="finance-dialog-close" type="button" data-finance-dialog-close aria-label="Bagla">X</button></div>
            <form class="portal-stacked-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="create_finance_entry"><input type="hidden" name="entry_type" value="expense"><input type="hidden" name="finance_tab" value="expenses">
                <div class="portal-form-row portal-grid-slim"><input type="text" name="title" placeholder="Basliq" required><input type="text" name="category" placeholder="Kateqoriya" required></div>
                <div class="portal-form-row portal-grid-slim"><input type="number" step="0.01" min="0.01" name="amount" placeholder="Mebleg" required><select name="branch_id"><option value="">Filial sec</option><?php foreach ($financeBranches as $branch): ?><option value="<?= e((string) ($branch['id'] ?? '')); ?>"><?= e((string) ($branch['name'] ?? $branch['branchName'] ?? '-')); ?></option><?php endforeach; ?></select></div>
                <?php if ($financeEmployees !== []): ?><div class="portal-form-row"><select name="employee_user_id"><option value="">Mesul emekdas</option><?php foreach ($financeEmployees as $employee): ?><option value="<?= e((string) ($employee['id'] ?? '')); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <div class="portal-form-row"><textarea name="note_text" placeholder="Qeyd"></textarea></div>
                <button class="portal-primary-btn" type="submit">Xerci saxla</button>
            </form>
        </section>
    </div>
</div>
