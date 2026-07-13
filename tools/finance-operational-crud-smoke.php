<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/mgs-integration-lib.php';
require_once dirname(__DIR__) . '/finance-operations-lib.php';

$db = app_db();
$run = date('YmdHis') . random_int(100, 999);
$actor = ['id' => 1, 'role' => 'employee', 'employee_role' => 'finance', 'account_type' => 'operator', 'full_name' => 'Finance Smoke'];
$counterpartyId = 0;
$extraCounterpartyId = 0;
$entryId = 0;
$debtId = 0;
$errors = [];
try {
    $counterparty = app_finance_save_counterparty($db, ['display_name' => 'Smoke Supplier ' . $run, 'counterparty_type' => 'company', 'tax_id' => 'V' . $run, 'phone' => '+994501112233'], $actor);
    $counterpartyId = (int) ($counterparty['id'] ?? 0);
    if (empty($counterparty['ok']) || $counterpartyId <= 0) $errors[] = 'counterparty_create_failed';
    $editedName='Smoke Supplier Updated '.$run;$edited=app_finance_save_counterparty($db,['counterparty_id'=>$counterpartyId,'display_name'=>$editedName,'counterparty_type'=>'company','tax_id'=>'V'.$run,'phone'=>'+994507778899','address'=>'Updated address'],$actor);$extraCounterpartyId=(int)($edited['id']??0);$editedRow=$db->prepare('SELECT display_name,phone,address FROM finance_counterparties WHERE id=:id');$editedRow->execute(['id'=>$counterpartyId]);$editedData=$editedRow->fetch(PDO::FETCH_ASSOC);if(empty($edited['ok'])||$extraCounterpartyId!==$counterpartyId||($editedData['display_name']??'')!==$editedName||($editedData['address']??'')!=='Updated address')$errors[]='counterparty_update_failed';
    $entry = app_finance_save_entry($db, ['entry_type' => 'income', 'title' => 'Smoke income', 'category' => 'other', 'amount' => 123.45, 'currency' => 'AZN', 'transaction_date' => date('Y-m-d'), 'counterparty_id' => $counterpartyId, 'reference_no' => 'INC-' . $run], $actor);
    $entryId = (int) ($entry['id'] ?? 0);
    if (empty($entry['ok']) || $entryId <= 0) $errors[] = 'income_create_failed';
    if (app_finance_manual_income_total($db, date('Y-m-d'), date('Y-m-d')) < 123.45) $errors[] = 'income_total_missing';
    $updatedEntry=app_finance_save_entry($db,['entry_id'=>$entryId,'entry_type'=>'income','title'=>'Smoke income updated','category'=>'service','amount'=>150.25,'currency'=>'USD','transaction_date'=>date('Y-m-d'),'counterparty_id'=>$counterpartyId,'reference_no'=>'INC-'.$run,'reason'=>'Smoke update correction'],$actor);
    $entryRow=$db->prepare('SELECT title,amount,currency,status FROM finance_entries WHERE id=:id');$entryRow->execute(['id'=>$entryId]);$entryData=$entryRow->fetch(PDO::FETCH_ASSOC);
    if(empty($updatedEntry['ok'])||($entryData['title']??'')!=='Smoke income updated'||(float)($entryData['amount']??0)!==150.25||($entryData['currency']??'')!=='USD')$errors[]='income_update_failed';
    $voided=app_finance_void_entry($db,$entryId,$actor,'Smoke void correction');$entryRow->execute(['id'=>$entryId]);$entryData=$entryRow->fetch(PDO::FETCH_ASSOC);
    if(empty($voided['ok'])||($entryData['status']??'')!=='void')$errors[]='income_void_failed';
    $legacyName = 'Legacy Counterparty ' . $run;
    $legacy = $db->prepare('INSERT INTO finance_debt_ledger(ledger_type,counterparty_name,counterparty_type,amount_original,amount_open,currency,status,created_by_name)VALUES("payable",:name,"company",50,50,"AZN","open","Finance Smoke")');
    $legacy->execute(['name'=>$legacyName]);$debtId=(int)$db->lastInsertId();
    $companyPage=app_finance_counterparty_page($db,['search'=>$legacyName,'limit'=>50]);
    if(!in_array($legacyName,array_column((array)($companyPage['rows']??[]),'display_name'),true))$errors[]='legacy_counterparty_omitted';
    $linked=app_finance_link_legacy_counterparty($db,['counterparty_id'=>$counterpartyId,'legacy_name'=>$legacyName,'legacy_type'=>'company'],$actor);$linkCheck=$db->prepare('SELECT counterparty_id FROM finance_debt_ledger WHERE id=:id');$linkCheck->execute(['id'=>$debtId]);if(empty($linked['ok'])||(int)$linkCheck->fetchColumn()!==$counterpartyId)$errors[]='legacy_counterparty_link_failed';
    $forbidden = app_finance_save_entry($db, ['entry_type' => 'income', 'title' => 'Denied', 'amount' => 1, 'transaction_date' => date('Y-m-d')], ['role' => 'employee', 'employee_role' => 'support']);
    if (($forbidden['error'] ?? '') !== 'forbidden') $errors[] = 'finance_permission_not_enforced';
    $source = (string) file_get_contents(dirname(__DIR__) . '/views/finance/income.php');
    foreach (['data-finance-income', 'Yeni gelir', 'create_finance_income','data-finance-income-edit','void_finance_income','name="currency"'] as $marker) if (!str_contains($source, $marker)) $errors[] = 'ui_marker_missing:' . $marker;
    $serviceSource=(string)file_get_contents(dirname(__DIR__).'/finance-operations-lib.php');if(!str_contains($serviceSource,'app_finance_record_audit($db'))$errors[]='transactional_finance_audit_missing';
    $companyView=(string)file_get_contents(dirname(__DIR__).'/views/finance/companies.php');foreach(['data-finance-counterparty-edit','data-finance-legacy-link','link_finance_counterparty','name="counterparty_id"'] as $marker)if(!str_contains($companyView,$marker))$errors[]='counterparty_edit_ui_missing:'.$marker;
} finally {
    if ($debtId > 0) $db->prepare('DELETE FROM finance_debt_ledger WHERE id=:id')->execute(['id'=>$debtId]);
    if ($entryId > 0) $db->prepare('DELETE FROM finance_entries WHERE id=:id')->execute(['id' => $entryId]);
    if ($counterpartyId > 0) $db->prepare('DELETE FROM finance_counterparties WHERE id=:id')->execute(['id' => $counterpartyId]);
    if ($extraCounterpartyId > 0 && $extraCounterpartyId !== $counterpartyId) $db->prepare('DELETE FROM finance_counterparties WHERE id=:id')->execute(['id'=>$extraCounterpartyId]);
    $db->prepare('DELETE FROM mgs_operation_actions WHERE actor_name="Finance Smoke" OR entity_id LIKE :run')->execute(['run' => '%' . $run . '%']);
}
echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
