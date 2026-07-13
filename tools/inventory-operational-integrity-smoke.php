<?php
declare(strict_types=1);
require dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/mgs-integration-lib.php';
require_once dirname(__DIR__).'/inventory-operations-lib.php';
$db=app_db();$errors=[];$itemId=0;$newItemId=0;$docId=0;$newDocId=0;$documentNo='';$newDocumentNo='';$run=date('YmdHis').random_int(10,99);$actor=['id'=>1,'role'=>'employee','employee_role'=>'warehouse','full_name'=>'Integrity Smoke'];
try{
    app_inventory_ensure_operational_schema($db);
    $s=$db->prepare('INSERT INTO inventory_stock_items(sku,item_name,category,unit,quantity_on_hand,minimum_quantity,status)VALUES(:sku,:name,"other","eded",7,0,"active")');$s->execute(['sku'=>'INT-'.$run,'name'=>'Integrity '.$run]);$itemId=(int)$db->lastInsertId();
    app_inventory_reconcile_unallocated_stock($db);
    if(app_inventory_location_quantity($db,$itemId,'warehouse','MAIN')!==7.0)$errors[]='legacy_stock_not_migrated';
    $badIntake=app_inventory_post_intake($db,['item_id'=>$itemId,'quantity'=>1,'to_location_type'=>'branch','to_location_id'=>'999999'],$actor);if(($badIntake['error']??'')!=='invalid_location')$errors[]='invalid_branch_intake_accepted';
    $badFormat=app_inventory_post_intake($db,['item_id'=>$itemId,'quantity'=>1,'to_location_type'=>'branch','to_location_id'=>'1x'],$actor);if(($badFormat['error']??'')!=='invalid_location')$errors[]='noncanonical_branch_id_accepted';
    $badHandover=app_inventory_handover_employee($db,['item_id'=>$itemId,'quantity'=>1,'source_branch_id'=>'1','employee_id'=>999999],$actor);if(($badHandover['error']??'')!=='invalid_location')$errors[]='invalid_employee_handover_accepted';
    $ok=app_inventory_post_intake($db,['item_id'=>$itemId,'quantity'=>2,'to_location_type'=>'branch','to_location_id'=>'1'],$actor);$docId=(int)($ok['id']??0);$documentNo=(string)($ok['document_no']??'');if(empty($ok['ok']))$errors[]='valid_intake_failed';
    $audit=$db->prepare('SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type="inventory_intake" AND entity_id=:entity');$audit->execute(['entity'=>(string)($ok['document_no']??'')]);if((int)$audit->fetchColumn()!==1)$errors[]='inventory_audit_missing';
    $new=app_inventory_post_intake($db,['item_id'=>0,'new_sku'=>'NEW-'.$run,'new_item_name'=>'New intake '.$run,'new_category'=>'other','new_unit'=>'eded','quantity'=>3,'to_location_type'=>'branch','to_location_id'=>'1'],$actor);$newItemId=(int)($new['item_id']??0);$newDocId=(int)($new['id']??0);$newDocumentNo=(string)($new['document_no']??'');if(empty($new['ok'])||$newItemId<=0||app_inventory_company_quantity($db,$newItemId)!==3.0||app_inventory_location_quantity($db,$newItemId,'branch','1')!==3.0)$errors[]='new_item_intake_failed';
}finally{
    foreach([$docId,$newDocId] as $id){if($id){$db->prepare('DELETE FROM inventory_document_items WHERE document_id=:id')->execute(['id'=>$id]);$db->prepare('DELETE FROM inventory_documents WHERE id=:id')->execute(['id'=>$id]);}}
    foreach([$documentNo,$newDocumentNo] as $no){if($no!=='')$db->prepare('DELETE FROM mgs_operation_actions WHERE entity_id=:no')->execute(['no'=>$no]);}
    if($newItemId){$db->prepare('DELETE FROM inventory_location_balances WHERE item_id=:id')->execute(['id'=>$newItemId]);$db->prepare('DELETE FROM inventory_lifecycle_movements WHERE product_id=:id')->execute(['id'=>$newItemId]);$db->prepare('DELETE FROM inventory_stock_items WHERE id=:id')->execute(['id'=>$newItemId]);}
    if($itemId){$db->prepare('DELETE FROM inventory_location_balances WHERE item_id=:id')->execute(['id'=>$itemId]);$db->prepare('DELETE FROM inventory_lifecycle_movements WHERE product_id=:id')->execute(['id'=>$itemId]);$db->prepare('DELETE FROM inventory_stock_items WHERE id=:id')->execute(['id'=>$itemId]);}
}
echo json_encode(['status'=>$errors===[]?'passed':'failed','errors'=>$errors],JSON_PRETTY_PRINT).PHP_EOL;exit($errors===[]?0:1);
