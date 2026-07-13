<?php
declare(strict_types=1);
require dirname(__DIR__).'/config.php';
$db=app_db();$id=0;$errors=[];$run=date('YmdHis').random_int(10,99);$login='preserve-'.$run;
try{
    $created=app_db_upsert_account(['login'=>$login,'password'=>'SmokePass123!','role'=>'employee','account_type'=>'operator','employee_role'=>'support','full_name'=>'Preserve Smoke','is_active'=>1,'responsible_areas'=>['Baki','Astara']],false);$id=(int)($created['id']??0);
    $updated=app_db_upsert_account(['id'=>$id,'login'=>$login,'password'=>'','role'=>'employee','account_type'=>'operator','employee_role'=>'support','full_name'=>'Preserve Smoke Updated','is_active'=>1],false);
    if($updated===null)$errors[]='account_update_failed';
    if(app_db_account_areas($id)!==['Astara','Baki'])$errors[]='responsible_areas_cleared';
}finally{if($id){$db->prepare('DELETE FROM portal_account_areas WHERE account_id=:id')->execute(['id'=>$id]);$db->prepare('DELETE FROM portal_accounts WHERE id=:id')->execute(['id'=>$id]);}}
echo json_encode(['status'=>$errors===[]?'passed':'failed','errors'=>$errors],JSON_PRETTY_PRINT).PHP_EOL;exit($errors===[]?0:1);
