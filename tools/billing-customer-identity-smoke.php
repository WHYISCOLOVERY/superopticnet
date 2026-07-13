<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/billing-customer-identity-lib.php';

$db = app_db();
$errors = [];
$missing = app_billing_validate_customer_identity($db, ['fin_code' => '', 'document_no' => '', 'phone' => '', 'address' => '']);
if (!empty($missing['ok']) || count((array) ($missing['fields'] ?? [])) !== 4) $errors[] = 'required_fields_not_enforced';
$valid = app_billing_validate_customer_identity($db, ['fin_code' => 'abc123d', 'document_no' => 'AA1234567', 'phone' => '+994 50 111 22 33', 'address' => 'Baki']);
if (empty($valid['ok']) || ($valid['data']['fin_code'] ?? '') !== 'ABC123D') $errors[] = 'normalization_failed';
$claims=$db->query("SHOW TABLES LIKE 'billing_customer_fin_claims'")->fetchColumn();
if(!$claims)$errors[]='fin_claim_registry_missing';
$serviceSource=(string)file_get_contents(dirname(__DIR__).'/billing-customer-identity-lib.php');
if(!str_contains($serviceSource,'FOR UPDATE')||!str_contains($serviceSource,'billing_customer_fin_claims'))$errors[]='fin_atomic_claim_missing';
if(!str_contains($serviceSource,'function app_billing_upsert_customer_with_identity'))$errors[]='atomic_account_identity_helper_missing';
$run=date('YmdHis').random_int(10,99);$ids=[];
try{
    $insert=$db->prepare('INSERT INTO portal_accounts(login,password_hash,role,account_type,full_name,is_active,status)VALUES(:login,:hash,"customer","customer",:name,1,"Aktiv")');
    foreach([1,2] as $n){$insert->execute(['login'=>'fin-claim-'.$n.'-'.$run,'hash'=>password_hash('SmokePass123!',PASSWORD_DEFAULT),'name'=>'FIN Claim '.$n]);$ids[]=(int)$db->lastInsertId();}
    $identity=['fin_code'=>'ZZ'.$run,'document_no'=>'AA1234567','phone'=>'+994501112233','address'=>'Baki'];
    $first=app_billing_save_customer_identity($db,$ids[0],$identity);$second=app_billing_save_customer_identity($db,$ids[1],$identity);
    if(empty($first['ok'])||($second['error']??'')!=='duplicate_fin')$errors[]='duplicate_fin_claim_failed';
    $audit=$db->prepare('SELECT before_json,after_json FROM security_audit_ledger WHERE action_type="billing_customer_identity_save" AND entity_id=:entity_id ORDER BY id DESC LIMIT 1');
    $audit->execute(['entity_id'=>(string)$ids[0]]);
    $auditRow=$audit->fetch(PDO::FETCH_ASSOC)?:[];
    if($auditRow===[]||trim((string)($auditRow['before_json']??''))===''||trim((string)($auditRow['after_json']??''))==='')$errors[]='identity_protected_ledger_missing';
    if(function_exists('app_billing_upsert_customer_with_identity')){$before=app_db_account_by_id($ids[1]);$atomic=app_billing_upsert_customer_with_identity($db,['id'=>$ids[1],'login'=>$before['login'],'password'=>'','role'=>'customer','account_type'=>'customer','full_name'=>'Must Roll Back','is_active'=>1],$identity);$after=app_db_account_by_id($ids[1]);if(($atomic['error']??'')!=='duplicate_fin'||($after['full_name']??'')==='Must Roll Back')$errors[]='account_identity_not_atomic';}
}finally{foreach($ids as $id){$db->prepare('DELETE FROM billing_customer_fin_claims WHERE account_id=:id')->execute(['id'=>$id]);$db->prepare('DELETE FROM portal_accounts WHERE id=:id')->execute(['id'=>$id]);}}
foreach (['billing-customers.php', 'billing-customer-edit.php'] as $file) {
    $source = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
    foreach (['name="fin_code"', 'name="document_no"', 'name="phone"', 'name="address"'] as $marker) if (!str_contains($source, $marker)) $errors[] = $file . ':missing:' . $marker;
}
echo json_encode(['status' => $errors === [] ? 'passed' : 'failed', 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors === [] ? 0 : 1);
