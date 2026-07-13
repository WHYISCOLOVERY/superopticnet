<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/mgs-integration-lib.php';

function app_inventory_ensure_operational_schema(PDO $db): void
{
    app_mgs_ensure_schema($db);
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_location_balances(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NOT NULL,location_type ENUM('warehouse','branch','employee','external') NOT NULL,location_id VARCHAR(128) NOT NULL,available_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,reserved_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_inventory_location_balance(item_id,location_type,location_id),KEY idx_inventory_location_lookup(location_type,location_id),CONSTRAINT fk_inventory_location_item FOREIGN KEY(item_id) REFERENCES inventory_stock_items(id) ON DELETE CASCADE)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_document_sequences(document_type VARCHAR(16) NOT NULL,fiscal_year SMALLINT UNSIGNED NOT NULL,next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,PRIMARY KEY(document_type,fiscal_year))ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_operational_locks(lock_name VARCHAR(64) PRIMARY KEY,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO inventory_operational_locks(lock_name)VALUES('location_reconcile')");
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_documents(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,document_no VARCHAR(64) NOT NULL,document_type VARCHAR(32) NOT NULL,status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',entity_item_id BIGINT UNSIGNED DEFAULT NULL,source_location_type VARCHAR(32) DEFAULT NULL,source_location_id VARCHAR(128) DEFAULT NULL,destination_type VARCHAR(32) DEFAULT NULL,destination_id VARCHAR(128) DEFAULT NULL,destination_name VARCHAR(191) DEFAULT NULL,reason VARCHAR(96) DEFAULT NULL,disposition VARCHAR(64) DEFAULT NULL,note TEXT DEFAULT NULL,rejection_reason TEXT DEFAULT NULL,created_by_account_id BIGINT UNSIGNED DEFAULT NULL,created_by_name VARCHAR(191) DEFAULT NULL,approved_by_account_id BIGINT UNSIGNED DEFAULT NULL,approved_by_name VARCHAR(191) DEFAULT NULL,approved_at DATETIME DEFAULT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_inventory_document_no(document_no),KEY idx_inventory_document_status(status,created_at))ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_document_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,document_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED DEFAULT NULL,line_no INT UNSIGNED NOT NULL,item_name VARCHAR(191) NOT NULL,quantity DECIMAL(14,2) NOT NULL,unit VARCHAR(32) NOT NULL DEFAULT 'eded',unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,mac_address VARCHAR(32) DEFAULT NULL,serial_number VARCHAR(128) DEFAULT NULL,note VARCHAR(255) DEFAULT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_inventory_document_item(document_id,line_no),KEY idx_inventory_document_stock(item_id),CONSTRAINT fk_inventory_document_line FOREIGN KEY(document_id) REFERENCES inventory_documents(id) ON DELETE CASCADE)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    app_inventory_reconcile_unallocated_stock($db);
}
function app_inventory_actor_can_write(array $a):bool{return app_portal_is_admin($a)||in_array(strtolower((string)($a['employee_role']??'')),['warehouse','warehouse_manager'],true);}
function app_inventory_actor_can_approve(array $a):bool{return app_portal_is_admin($a)||strtolower((string)($a['employee_role']??''))==='warehouse_manager';}
function app_inventory_location_exists(PDO $db,string $type,string $id):bool
{
    if($type==='warehouse')return $id==='MAIN';
    if(!preg_match('/^[1-9][0-9]*$/D',$id))return false;
    if($type==='branch'){$s=$db->prepare('SELECT 1 FROM company_branches WHERE id=:id AND status="active"');$s->execute(['id'=>(int)$id]);return (bool)$s->fetchColumn();}
    if($type==='employee'){$s=$db->prepare('SELECT 1 FROM portal_accounts WHERE id=:id AND role="employee" AND is_active=1');$s->execute(['id'=>(int)$id]);return (bool)$s->fetchColumn();}
    return false;
}

function app_inventory_normalize_location_pair(array $input,string $prefix,string $defaultType='branch',string $defaultId=''):array
{
    $compound=clean_text((string)($input[$prefix.'_location']??''));
    if($compound!==''&&str_contains($compound,':')){
        [$type,$id]=array_pad(explode(':',$compound,2),2,'');
        return [clean_text($type),clean_text($id)];
    }
    $type=clean_text((string)($input[$prefix.'_location_type']??$defaultType));
    $id=clean_text((string)($input[$prefix.'_location_id']??$input[$prefix.'_branch_id']??$defaultId));
    return [$type,$id];
}
function app_inventory_reconcile_unallocated_stock(PDO $db):void
{
    if($db->inTransaction())return;
    try{
        $db->beginTransaction();
        $db->query('SELECT lock_name FROM inventory_operational_locks WHERE lock_name="location_reconcile" FOR UPDATE')->fetchColumn();
        $db->exec("INSERT INTO inventory_location_balances(item_id,location_type,location_id,available_quantity)
            SELECT s.id,'warehouse','MAIN',s.quantity_on_hand-COALESCE(SUM(b.available_quantity+b.reserved_quantity),0)
            FROM inventory_stock_items s LEFT JOIN inventory_location_balances b ON b.item_id=s.id
            GROUP BY s.id,s.quantity_on_hand HAVING s.quantity_on_hand-COALESCE(SUM(b.available_quantity+b.reserved_quantity),0)>0
            ON DUPLICATE KEY UPDATE available_quantity=available_quantity+VALUES(available_quantity)");
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function app_inventory_record_audit(PDO $db,string $actionType,string $documentNo,string $status,array $actor,array $payload=[]):void
{
    $s=$db->prepare('INSERT INTO mgs_operation_actions(action_type,entity_type,entity_id,status,actor_account_id,actor_name,summary,payload_json,executed_at)VALUES(:action_type,"inventory",:entity_id,:status,:actor_id,:actor_name,:summary,:payload,:executed_at)');
    $s->execute(['action_type'=>$actionType,'entity_id'=>$documentNo,'status'=>$status,'actor_id'=>(int)($actor['id']??0)?:null,'actor_name'=>clean_text((string)($actor['full_name']??$actor['login']??'operator')),'summary'=>mb_substr($actionType.': '.$documentNo,0,255),'payload'=>app_db_json_encode($payload),'executed_at'=>$status==='executed'?app_db_now():null]);
}
function app_inventory_required_reason(mixed $value): string
{
    $reason = clean_text((string) $value);
    if ($reason === '') {
        throw new InvalidArgumentException('reason_required');
    }
    return mb_substr($reason, 0, 255);
}
function app_inventory_actor_id(array $actor): ?int
{
    $id = (int) ($actor['id'] ?? $actor['account_id'] ?? 0);
    return $id > 0 ? $id : null;
}
function app_inventory_actor_name(array $actor): string
{
    return mb_substr(clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'operator')), 0, 191);
}
function app_inventory_update_item(PDO $db,array $input,array $actor):array
{
    if(!app_inventory_actor_can_write($actor))return['ok'=>false,'error'=>'forbidden'];
    try{$reason=app_inventory_required_reason($input['reason']??'');}catch(InvalidArgumentException){return['ok'=>false,'error'=>'reason_required'];}
    app_inventory_ensure_operational_schema($db);app_audit_ensure_schema($db);
    $itemId=(int)($input['item_id']??0);$name=clean_text((string)($input['item_name']??''));$category=clean_text((string)($input['category']??'other'))?:'other';$unit=clean_text((string)($input['unit']??'eded'))?:'eded';$minimum=max(0,(float)($input['minimum_quantity']??0));
    if($itemId<=0||$name===''||!in_array($unit,['eded','metr','kq','paket','dest'],true))return['ok'=>false,'error'=>'invalid_item'];
    try{$db->beginTransaction();$select=$db->prepare('SELECT * FROM inventory_stock_items WHERE id=:id FOR UPDATE');$select->execute(['id'=>$itemId]);$before=$select->fetch(PDO::FETCH_ASSOC);if(!$before){$db->rollBack();return['ok'=>false,'error'=>'not_found'];}
        $db->prepare('UPDATE inventory_stock_items SET item_name=:name,category=:category,unit=:unit,minimum_quantity=:minimum WHERE id=:id')->execute(['name'=>$name,'category'=>$category,'unit'=>$unit,'minimum'=>$minimum,'id'=>$itemId]);
        $select->execute(['id'=>$itemId]);$after=$select->fetch(PDO::FETCH_ASSOC)?:[];
        app_audit_record($db,['module_key'=>'inventory','operation_class'=>'catalog','action_type'=>'inventory_item_updated','entity_type'=>'inventory_item','entity_id'=>(string)$itemId,'status'=>'executed','actor_account_id'=>app_inventory_actor_id($actor),'actor_name'=>app_inventory_actor_name($actor),'summary'=>'Inventory catalog item updated','reason'=>$reason,'before'=>$before,'after'=>$after,'sensitivity'=>'critical']);
        $db->commit();return['ok'=>true,'item_id'=>$itemId];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'update_failed'];}
}
function app_inventory_deactivate_item(PDO $db,array $input,array $actor):array
{
    if(!app_inventory_actor_can_write($actor))return['ok'=>false,'error'=>'forbidden'];
    try{$reason=app_inventory_required_reason($input['reason']??'');}catch(InvalidArgumentException){return['ok'=>false,'error'=>'reason_required'];}
    app_inventory_ensure_operational_schema($db);app_audit_ensure_schema($db);
    $itemId=(int)($input['item_id']??0);if($itemId<=0)return['ok'=>false,'error'=>'invalid_item'];
    try{$db->beginTransaction();$select=$db->prepare('SELECT * FROM inventory_stock_items WHERE id=:id FOR UPDATE');$select->execute(['id'=>$itemId]);$before=$select->fetch(PDO::FETCH_ASSOC);if(!$before){$db->rollBack();return['ok'=>false,'error'=>'not_found'];}
        $db->prepare('UPDATE inventory_stock_items SET status="disabled" WHERE id=:id')->execute(['id'=>$itemId]);
        $select->execute(['id'=>$itemId]);$after=$select->fetch(PDO::FETCH_ASSOC)?:[];
        app_audit_record($db,['module_key'=>'inventory','operation_class'=>'catalog','action_type'=>'inventory_item_deactivated','entity_type'=>'inventory_item','entity_id'=>(string)$itemId,'status'=>'executed','actor_account_id'=>app_inventory_actor_id($actor),'actor_name'=>app_inventory_actor_name($actor),'summary'=>'Inventory catalog item deactivated','reason'=>$reason,'before'=>$before,'after'=>$after,'sensitivity'=>'critical']);
        $db->commit();return['ok'=>true,'item_id'=>$itemId];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'deactivate_failed'];}
}
function app_inventory_adjust_stock(PDO $db,array $input,array $actor):array
{
    if(!app_inventory_actor_can_approve($actor))return['ok'=>false,'error'=>'forbidden'];
    try{$reason=app_inventory_required_reason($input['reason']??'');}catch(InvalidArgumentException){return['ok'=>false,'error'=>'reason_required'];}
    app_inventory_ensure_operational_schema($db);app_audit_ensure_schema($db);
    $itemId=(int)($input['item_id']??0);$type=clean_text((string)($input['location_type']??'warehouse'));$loc=clean_text((string)($input['location_id']??'MAIN'));$delta=(float)($input['quantity_delta']??0);
    if($itemId<=0||abs($delta)<0.00001||!in_array($type,['warehouse','branch','employee'],true)||$loc==='')return['ok'=>false,'error'=>'invalid_adjustment'];
    if(!app_inventory_location_exists($db,$type,$loc))return['ok'=>false,'error'=>'invalid_location'];
    try{$db->beginTransaction();$stock=$db->prepare('SELECT * FROM inventory_stock_items WHERE id=:id FOR UPDATE');$stock->execute(['id'=>$itemId]);$beforeItem=$stock->fetch(PDO::FETCH_ASSOC);if(!$beforeItem){$db->rollBack();return['ok'=>false,'error'=>'not_found'];}
        $balance=app_inventory_lock_location_balance($db,$itemId,$type,$loc);$companyBefore=(float)($beforeItem['quantity_on_hand']??0);$locationBefore=(float)($balance['available_quantity']??0);$companyAfter=$companyBefore+$delta;$locationAfter=$locationBefore+$delta;
        if($companyAfter<0||$locationAfter<0){$db->rollBack();return['ok'=>false,'error'=>'insufficient_stock'];}
        $docNo=app_inventory_next_document_no($db,'ADJ');
        $db->prepare('UPDATE inventory_stock_items SET quantity_on_hand=:quantity WHERE id=:id')->execute(['quantity'=>$companyAfter,'id'=>$itemId]);
        $db->prepare('UPDATE inventory_location_balances SET available_quantity=:quantity WHERE id=:id')->execute(['quantity'=>$locationAfter,'id'=>$balance['id']]);
        $movement=$db->prepare('INSERT INTO inventory_stock_movements(item_id,movement_type,quantity,actor_account_id,actor_name,note,status,metadata_json)VALUES(:item,"adjust",:quantity,:actor_id,:actor_name,:note,"posted",:metadata)');
        $movement->execute(['item'=>$itemId,'quantity'=>$delta,'actor_id'=>app_inventory_actor_id($actor),'actor_name'=>app_inventory_actor_name($actor),'note'=>$reason,'metadata'=>app_db_json_encode(['document_no'=>$docNo,'location_type'=>$type,'location_id'=>$loc,'company_before'=>$companyBefore,'company_after'=>$companyAfter,'location_before'=>$locationBefore,'location_after'=>$locationAfter])]);
        app_inventory_lifecycle($db,'stock_adjustment',$itemId,abs($delta),$delta<0?$type:'external',$delta<0?$loc:'manual_adjustment',$delta<0?'external':$type,$delta<0?'manual_adjustment':$loc,$reason,$actor,$docNo);
        $stock->execute(['id'=>$itemId]);$afterItem=$stock->fetch(PDO::FETCH_ASSOC)?:[];
        app_audit_record($db,['module_key'=>'inventory','operation_class'=>'adjustment','action_type'=>'inventory_stock_adjusted','entity_type'=>'inventory_item','entity_id'=>(string)$itemId,'status'=>'executed','actor_account_id'=>app_inventory_actor_id($actor),'actor_name'=>app_inventory_actor_name($actor),'summary'=>'Inventory stock manually adjusted','reason'=>$reason,'before'=>['item'=>$beforeItem,'location'=>['type'=>$type,'id'=>$loc,'available_quantity'=>$locationBefore]],'after'=>['item'=>$afterItem,'location'=>['type'=>$type,'id'=>$loc,'available_quantity'=>$locationAfter],'document_no'=>$docNo,'delta'=>$delta],'sensitivity'=>'critical']);
        $db->commit();return['ok'=>true,'item_id'=>$itemId,'document_no'=>$docNo,'quantity_delta'=>$delta];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'adjustment_failed'];}
}
function app_inventory_location_quantity(PDO $db,int $itemId,string $type,string $id):float{$s=$db->prepare('SELECT available_quantity FROM inventory_location_balances WHERE item_id=:item AND location_type=:type AND location_id=:loc');$s->execute(['item'=>$itemId,'type'=>$type,'loc'=>$id]);return (float)($s->fetchColumn()?:0);}
function app_inventory_company_quantity(PDO $db,int $itemId):float{$s=$db->prepare('SELECT quantity_on_hand FROM inventory_stock_items WHERE id=:id');$s->execute(['id'=>$itemId]);return (float)($s->fetchColumn()?:0);}
function app_inventory_lock_location_balance(PDO $db,int $itemId,string $type,string $id):array{$db->prepare('INSERT IGNORE INTO inventory_location_balances(item_id,location_type,location_id)VALUES(:item,:type,:loc)')->execute(['item'=>$itemId,'type'=>$type,'loc'=>$id]);$s=$db->prepare('SELECT * FROM inventory_location_balances WHERE item_id=:item AND location_type=:type AND location_id=:loc FOR UPDATE');$s->execute(['item'=>$itemId,'type'=>$type,'loc'=>$id]);return $s->fetch(PDO::FETCH_ASSOC)?:[];}
function app_inventory_next_document_no(PDO $db,string $prefix):string{$year=(int)date('Y');$db->prepare('INSERT IGNORE INTO inventory_document_sequences(document_type,fiscal_year,next_value)VALUES(:type,:year,1)')->execute(['type'=>$prefix,'year'=>$year]);$s=$db->prepare('SELECT next_value FROM inventory_document_sequences WHERE document_type=:type AND fiscal_year=:year FOR UPDATE');$s->execute(['type'=>$prefix,'year'=>$year]);$next=(int)$s->fetchColumn();$db->prepare('UPDATE inventory_document_sequences SET next_value=:next WHERE document_type=:type AND fiscal_year=:year')->execute(['next'=>$next+1,'type'=>$prefix,'year'=>$year]);return sprintf('%s-%d-%06d',$prefix,$year,$next);}
function app_inventory_write_document(PDO $db,string $prefix,string $type,string $status,array $header,array $items,array $actor):array{
    $no=app_inventory_next_document_no($db,$prefix);$first=(int)($items[0]['item_id']??0)?:null;$s=$db->prepare('INSERT INTO inventory_documents(document_no,document_type,status,entity_item_id,source_location_type,source_location_id,destination_type,destination_id,destination_name,reason,disposition,note,created_by_account_id,created_by_name,approved_by_account_id,approved_by_name,approved_at)VALUES(:no,:type,:status,:entity,:source_type,:source_id,:dest_type,:dest_id,:dest_name,:reason,:disposition,:note,:actor_id,:actor_name,:approved_id,:approved_name,:approved_at)');
    $approved=$status==='approved';$s->execute(['no'=>$no,'type'=>$type,'status'=>$status,'entity'=>$first,'source_type'=>$header['source_location_type']??null,'source_id'=>$header['source_location_id']??null,'dest_type'=>$header['destination_type']??null,'dest_id'=>$header['destination_id']??null,'dest_name'=>$header['destination_name']??null,'reason'=>$header['reason']??null,'disposition'=>$header['disposition']??null,'note'=>$header['note']??null,'actor_id'=>(int)($actor['id']??0)?:null,'actor_name'=>(string)($actor['full_name']??$actor['login']??'operator'),'approved_id'=>$approved?((int)($actor['id']??0)?:null):null,'approved_name'=>$approved?(string)($actor['full_name']??'operator'):null,'approved_at'=>$approved?date('Y-m-d H:i:s'):null]);$id=(int)$db->lastInsertId();
    $line=$db->prepare('INSERT INTO inventory_document_items(document_id,item_id,line_no,item_name,quantity,unit,unit_price,mac_address,serial_number,note)VALUES(:doc,:item,:line,:name,:quantity,:unit,:price,:mac,:serial,:note)');$n=0;foreach($items as $item){$n++;$itemId=(int)($item['item_id']??0);$stock=$db->prepare('SELECT item_name,unit FROM inventory_stock_items WHERE id=:id');$stock->execute(['id'=>$itemId]);$row=$stock->fetch(PDO::FETCH_ASSOC)?:[];$line->execute(['doc'=>$id,'item'=>$itemId?:null,'line'=>$n,'name'=>clean_text((string)($item['item_name']??$row['item_name']??'Mal')),'quantity'=>max(.01,(float)($item['quantity']??0)),'unit'=>clean_text((string)($item['unit']??$row['unit']??'eded'))?:'eded','price'=>max(0,(float)($item['unit_price']??0)),'mac'=>clean_text((string)($item['mac_address']??''))?:null,'serial'=>clean_text((string)($item['serial_number']??''))?:null,'note'=>clean_text((string)($item['note']??''))?:null]);}
    return ['id'=>$id,'document_no'=>$no];
}
function app_inventory_lifecycle(PDO $db,string $type,int $itemId,float $qty,string $fromType,string $fromId,string $toType,string $toId,string $reason,array $actor,string $docNo,?string $technicianCode=null,?string $customerCode=null,?string $ticketNo=null):void
{
    $s=$db->prepare('INSERT INTO inventory_lifecycle_movements(movement_no,movement_type,product_id,quantity,from_location_type,from_location_id,to_location_type,to_location_id,customer_code,technician_code,ticket_no,reason,performed_by,idempotency_key)VALUES(:no,:type,:item,:qty,:from_type,:from_id,:to_type,:to_id,:customer_code,:technician_code,:ticket_no,:reason,:actor,:key)');
    $s->execute(['no'=>$docNo.'-L','type'=>$type,'item'=>$itemId,'qty'=>$qty,'from_type'=>$fromType,'from_id'=>$fromId,'to_type'=>$toType,'to_id'=>$toId,'customer_code'=>$customerCode?:null,'technician_code'=>$technicianCode?:null,'ticket_no'=>$ticketNo?:null,'reason'=>$reason,'actor'=>(string)($actor['full_name']??'operator'),'key'=>$docNo.'-L']);
}
function app_inventory_post_intake(PDO $db,array $input,array $actor):array
{
    if(!app_inventory_actor_can_write($actor))return['ok'=>false,'error'=>'forbidden'];
    app_inventory_ensure_operational_schema($db);
    $item=(int)($input['item_id']??0);
    $newSku=strtoupper(clean_text((string)($input['new_sku']??'')));
    $newName=clean_text((string)($input['new_item_name']??''));
    $newCategory=clean_text((string)($input['new_category']??'other'))?:'other';
    $newUnit=clean_text((string)($input['new_unit']??'eded'))?:'eded';
    $qty=(float)($input['quantity']??0);
    $type=clean_text((string)($input['to_location_type']??'warehouse'));
    $loc=clean_text((string)($input['to_location_id']??'MAIN'));
    $createItem=$item<=0;
    if($qty<=0||!in_array($type,['warehouse','branch'],true)||$loc===''||($createItem&&($newSku===''||$newName==='')))return['ok'=>false,'error'=>'invalid_intake'];
    if(!preg_match('/^[A-Z0-9._-]{2,64}$/D',$newSku)&&$createItem)return['ok'=>false,'error'=>'invalid_sku'];
    if(!in_array($newUnit,['eded','metr','kq','paket','dest'],true))return['ok'=>false,'error'=>'invalid_unit'];
    if(!app_inventory_location_exists($db,$type,$loc))return['ok'=>false,'error'=>'invalid_location'];
    try{
        $db->beginTransaction();
        if($createItem)$item=app_mgs_inventory_upsert_stock_item($db,$newSku,$newName,$newCategory,$newUnit);
        $stock=$db->prepare('SELECT * FROM inventory_stock_items WHERE id=:id FOR UPDATE');$stock->execute(['id'=>$item]);$stockRow=$stock->fetch(PDO::FETCH_ASSOC);if(!$stockRow)throw new RuntimeException('item');
        $bal=app_inventory_lock_location_balance($db,$item,$type,$loc);
        $db->prepare('UPDATE inventory_stock_items SET quantity_on_hand=quantity_on_hand+:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$item]);
        $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity+:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$bal['id']]);
        $doc=app_inventory_write_document($db,'MDX','intake','approved',['destination_type'=>$type,'destination_id'=>$loc,'note'=>clean_text((string)($input['note']??''))],[['item_id'=>$item,'quantity'=>$qty,'unit_price'=>$input['unit_price']??0,'serial_number'=>$input['serial_number']??'','mac_address'=>$input['mac_address']??'']],$actor);
        app_inventory_lifecycle($db,'intake',$item,$qty,'external',(string)($input['source_name']??'supplier'),$type,$loc,'intake',$actor,$doc['document_no']);
        app_inventory_record_audit($db,'inventory_intake',$doc['document_no'],'executed',$actor,['item_id'=>$item,'sku'=>$stockRow['sku']??$newSku,'quantity'=>$qty,'destination_type'=>$type,'destination_id'=>$loc,'created_item'=>$createItem]);
        $db->commit();
        return['ok'=>true,'item_id'=>$item]+$doc;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'intake_failed'];}
}
function app_inventory_transfer_branch(PDO $db,array $input,array $actor):array{
    if(!app_inventory_actor_can_write($actor))return['ok'=>false,'error'=>'forbidden'];app_inventory_ensure_operational_schema($db);$item=(int)($input['item_id']??0);$qty=(float)($input['quantity']??0);[$fromType,$from]=app_inventory_normalize_location_pair($input,'from','branch',(string)($input['from_branch_id']??''));[$toType,$to]=app_inventory_normalize_location_pair($input,'to','branch',(string)($input['to_branch_id']??''));
    if($item<=0||$qty<=0||$from===''||$to===''||!in_array($fromType,['warehouse','branch'],true)||!in_array($toType,['warehouse','branch'],true)||($fromType===$toType&&$from===$to))return['ok'=>false,'error'=>'invalid_transfer'];
    if(!app_inventory_location_exists($db,$fromType,$from)||!app_inventory_location_exists($db,$toType,$to))return['ok'=>false,'error'=>'invalid_location'];
    try{$db->beginTransaction();$src=app_inventory_lock_location_balance($db,$item,$fromType,$from);if((float)$src['available_quantity']<$qty){$db->rollBack();return['ok'=>false,'error'=>'insufficient_stock'];}$dst=app_inventory_lock_location_balance($db,$item,$toType,$to);$db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity-:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$src['id']]);$db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity+:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$dst['id']]);$docType=$fromType==='branch'&&$toType==='branch'?'branch_transfer':'location_transfer';$doc=app_inventory_write_document($db,'TRF',$docType,'approved',['source_location_type'=>$fromType,'source_location_id'=>$from,'destination_type'=>$toType,'destination_id'=>$to,'note'=>clean_text((string)($input['note']??''))],[['item_id'=>$item,'quantity'=>$qty,'unit_price'=>$input['unit_price']??0]],$actor);app_inventory_lifecycle($db,$docType,$item,$qty,$fromType,$from,$toType,$to,'location transfer',$actor,$doc['document_no']);app_inventory_record_audit($db,'inventory_location_transfer',$doc['document_no'],'executed',$actor,['item_id'=>$item,'quantity'=>$qty,'from_type'=>$fromType,'from_id'=>$from,'to_type'=>$toType,'to_id'=>$to]);$db->commit();return['ok'=>true]+$doc;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'transfer_failed'];}}
function app_inventory_handover_employee(PDO $db,array $input,array $actor):array
{
    if(!app_inventory_actor_can_write($actor))return['ok'=>false,'error'=>'forbidden'];
    app_inventory_ensure_operational_schema($db);
    $item=(int)($input['item_id']??0);$employee=(int)($input['employee_id']??0);$qty=(float)($input['quantity']??0);$branch=(string)($input['source_branch_id']??'');
    $requireConfirmation=!empty($input['require_confirmation']);
    if($item<=0||$employee<=0||$qty<=0||$branch==='')return['ok'=>false,'error'=>'invalid_handover'];
    if(!app_inventory_location_exists($db,'branch',$branch)||!app_inventory_location_exists($db,'employee',(string)$employee))return['ok'=>false,'error'=>'invalid_location'];
    try{
        $db->beginTransaction();
        $src=app_inventory_lock_location_balance($db,$item,'branch',$branch);
        if((float)$src['available_quantity']<$qty){$db->rollBack();return['ok'=>false,'error'=>'insufficient_stock'];}
        $status=$requireConfirmation?'pending':'approved';
        $doc=app_inventory_write_document($db,'THV','handover',$status,['source_location_type'=>'branch','source_location_id'=>$branch,'destination_type'=>'employee','destination_id'=>(string)$employee,'note'=>clean_text((string)($input['note']??''))],[['item_id'=>$item,'quantity'=>$qty,'serial_number'=>$input['serial_number']??'','mac_address'=>$input['mac_address']??'','note'=>$input['item_note']??'']],$actor);
        if(!$requireConfirmation){
            $dst=app_inventory_lock_location_balance($db,$item,'employee',(string)$employee);
            $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity-:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$src['id']]);
            $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity+:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$dst['id']]);
            $employeeName=app_mgs_hr_employee_name($db,$employee,(string)$employee);
            app_inventory_lifecycle($db,'handover',$item,$qty,'branch',$branch,'employee',(string)$employee,'employee handover',$actor,$doc['document_no'],$employeeName);
        }
        app_inventory_record_audit($db,'inventory_handover',$doc['document_no'],$requireConfirmation?'pending':'executed',$actor,['item_id'=>$item,'quantity'=>$qty,'branch_id'=>$branch,'employee_id'=>$employee,'confirmation_required'=>$requireConfirmation]);
        $db->commit();
        return['ok'=>true,'confirmation_required'=>$requireConfirmation]+$doc;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'handover_failed','detail'=>$e->getMessage()];}
}

function app_inventory_confirm_employee_handover(PDO $db,int $documentId,array $actor):array
{
    app_inventory_ensure_operational_schema($db);
    try{
        $db->beginTransaction();
        $select=$db->prepare('SELECT * FROM inventory_documents WHERE id=:id FOR UPDATE');$select->execute(['id'=>$documentId]);$doc=$select->fetch(PDO::FETCH_ASSOC);
        if(!$doc||($doc['document_type']??'')!=='handover'){$db->rollBack();return['ok'=>false,'error'=>'handover_not_found'];}
        if(($doc['status']??'')!=='pending'){$db->rollBack();return['ok'=>false,'error'=>'handover_not_pending'];}
        $employeeId=(int)($doc['destination_id']??0);$actorId=(int)($actor['id']??0);
        if($actorId!==$employeeId&&!app_portal_is_admin($actor)){$db->rollBack();return['ok'=>false,'error'=>'forbidden'];}
        $employeeName=app_mgs_hr_employee_name($db,$employeeId,(string)$employeeId);
        $lines=$db->prepare('SELECT * FROM inventory_document_items WHERE document_id=:id ORDER BY line_no');$lines->execute(['id'=>$documentId]);$rows=$lines->fetchAll(PDO::FETCH_ASSOC)?:[];
        if($rows===[]){$db->rollBack();return['ok'=>false,'error'=>'items_required'];}
        foreach($rows as $line){
            $item=(int)($line['item_id']??0);$qty=(float)($line['quantity']??0);
            $src=app_inventory_lock_location_balance($db,$item,(string)$doc['source_location_type'],(string)$doc['source_location_id']);
            if((float)($src['available_quantity']??0)<$qty){$db->rollBack();return['ok'=>false,'error'=>'insufficient_stock'];}
            $dst=app_inventory_lock_location_balance($db,$item,'employee',(string)$employeeId);
            $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity-:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$src['id']]);
            $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity+:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$dst['id']]);
            app_inventory_lifecycle($db,'handover',$item,$qty,(string)$doc['source_location_type'],(string)$doc['source_location_id'],'employee',(string)$employeeId,'employee confirmed handover',$actor,(string)$doc['document_no'].'-'.$line['line_no'],$employeeName);
            $serial=clean_text((string)($line['serial_number']??''));$mac=clean_text((string)($line['mac_address']??''));
            if($serial!==''||$mac!==''){
                $assetUpdate=$db->prepare('UPDATE inventory_assets SET current_location_type="technician",current_location_id=:employee_name,lifecycle_status="assigned",updated_at=NOW() WHERE product_id=:item AND ((:serial<>"" AND serial_number=:serial) OR (:mac<>"" AND mac_address=:mac))');
                $assetUpdate->execute(['employee_name'=>$employeeName,'item'=>$item,'serial'=>$serial,'mac'=>$mac]);
            }
        }
        $db->prepare('UPDATE inventory_documents SET status="approved",approved_by_account_id=:actor,approved_by_name=:name,approved_at=NOW() WHERE id=:id')->execute(['actor'=>$actorId?:null,'name'=>app_inventory_actor_name($actor),'id'=>$documentId]);
        app_inventory_record_audit($db,'inventory_handover_confirmed',(string)$doc['document_no'],'executed',$actor,['document_id'=>$documentId,'employee_id'=>$employeeId]);
        $db->commit();return['ok'=>true,'id'=>$documentId,'document_no'=>(string)$doc['document_no']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'handover_confirmation_failed','detail'=>$e->getMessage()];}
}

function app_inventory_consume_employee_stock(PDO $db,array $input,array $actor):array
{
    app_inventory_ensure_operational_schema($db);
    $item=(int)($input['item_id']??0);$employee=(int)($input['employee_id']??0);$qty=(float)($input['quantity']??0);
    $customer=clean_text((string)($input['customer_code']??''));$ticket=clean_text((string)($input['ticket_no']??''));
    $actorId=(int)($actor['id']??0);
    if($item<=0||$employee<=0||$qty<=0||$customer===''||($actorId!==$employee&&!app_inventory_actor_can_write($actor)))return['ok'=>false,'error'=>'invalid_consumption'];
    try{
        $db->beginTransaction();$source=app_inventory_lock_location_balance($db,$item,'employee',(string)$employee);
        if((float)($source['available_quantity']??0)<$qty){$db->rollBack();return['ok'=>false,'error'=>'insufficient_employee_stock'];}
        $company=$db->prepare('SELECT quantity_on_hand FROM inventory_stock_items WHERE id=:id FOR UPDATE');$company->execute(['id'=>$item]);
        if((float)$company->fetchColumn()<$qty){$db->rollBack();return['ok'=>false,'error'=>'insufficient_company_stock'];}
        $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity-:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$source['id']]);
        $db->prepare('UPDATE inventory_stock_items SET quantity_on_hand=quantity_on_hand-:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$item]);
        $employeeName=app_mgs_hr_employee_name($db,$employee,(string)$employee);
        $doc=app_inventory_write_document($db,'IST','customer_install','approved',['source_location_type'=>'employee','source_location_id'=>(string)$employee,'destination_type'=>'customer','destination_id'=>$customer,'destination_name'=>$customer,'reason'=>'customer_install','note'=>clean_text((string)($input['note']??''))],[['item_id'=>$item,'quantity'=>$qty,'serial_number'=>$input['serial_number']??'','mac_address'=>$input['mac_address']??'']],$actor);
        app_inventory_lifecycle($db,'customer_install',$item,$qty,'employee',(string)$employee,'customer',$customer,'customer installation',$actor,$doc['document_no'],$employeeName,$customer,$ticket);
        $movement=$db->prepare('INSERT INTO inventory_stock_movements(item_id,movement_type,quantity,ticket_no,customer_account_id,technician_account_id,actor_account_id,actor_name,note,status,metadata_json)VALUES(:item,"customer_install",:qty,:ticket,:customer_id,:employee,:actor_id,:actor_name,:note,"posted",:metadata)');
        $movement->execute(['item'=>$item,'qty'=>$qty,'ticket'=>$ticket?:null,'customer_id'=>(int)($input['customer_account_id']??0)?:null,'employee'=>$employee,'actor_id'=>$actorId?:null,'actor_name'=>app_inventory_actor_name($actor),'note'=>clean_text((string)($input['note']??'')),'metadata'=>app_db_json_encode($input)]);
        app_inventory_record_audit($db,'inventory_employee_consumed',$doc['document_no'],'executed',$actor,['item_id'=>$item,'quantity'=>$qty,'employee_id'=>$employee,'customer_code'=>$customer,'ticket_no'=>$ticket]);
        $db->commit();return['ok'=>true]+$doc;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'employee_consumption_failed','detail'=>$e->getMessage()];}
}

function app_inventory_return_employee_stock(PDO $db,array $input,array $actor):array
{
    if(!app_inventory_actor_can_write($actor))return['ok'=>false,'error'=>'forbidden'];
    app_inventory_ensure_operational_schema($db);
    $item=(int)($input['item_id']??0);$employee=(int)($input['employee_id']??0);$qty=(float)($input['quantity']??0);
    $destinationType=clean_text((string)($input['destination_type']??'warehouse'));$destinationId=clean_text((string)($input['destination_id']??'MAIN'));
    if($item<=0||$employee<=0||$qty<=0||!in_array($destinationType,['warehouse','branch'],true)||!app_inventory_location_exists($db,$destinationType,$destinationId))return['ok'=>false,'error'=>'invalid_return'];
    try{
        $db->beginTransaction();$source=app_inventory_lock_location_balance($db,$item,'employee',(string)$employee);
        if((float)($source['available_quantity']??0)<$qty){$db->rollBack();return['ok'=>false,'error'=>'insufficient_employee_stock'];}
        $destination=app_inventory_lock_location_balance($db,$item,$destinationType,$destinationId);
        $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity-:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$source['id']]);
        $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity+:q WHERE id=:id')->execute(['q'=>$qty,'id'=>$destination['id']]);
        $employeeName=app_mgs_hr_employee_name($db,$employee,(string)$employee);
        $doc=app_inventory_write_document($db,'QBL','employee_return','approved',['source_location_type'=>'employee','source_location_id'=>(string)$employee,'destination_type'=>$destinationType,'destination_id'=>$destinationId,'reason'=>'end_of_day_return','note'=>clean_text((string)($input['note']??''))],[['item_id'=>$item,'quantity'=>$qty,'serial_number'=>$input['serial_number']??'','mac_address'=>$input['mac_address']??'']],$actor);
        app_inventory_lifecycle($db,'return_to_warehouse',$item,$qty,'employee',(string)$employee,$destinationType,$destinationId,'end of day return',$actor,$doc['document_no'],$employeeName);
        $serial=clean_text((string)($input['serial_number']??''));$mac=clean_text((string)($input['mac_address']??''));
        if($serial!==''||$mac!==''){$asset=$db->prepare('UPDATE inventory_assets SET current_location_type=:location_type,current_location_id=:location_id,lifecycle_status="in_warehouse",updated_at=NOW() WHERE product_id=:item AND ((:serial<>"" AND serial_number=:serial) OR (:mac<>"" AND mac_address=:mac))');$asset->execute(['location_type'=>$destinationType,'location_id'=>$destinationId,'item'=>$item,'serial'=>$serial,'mac'=>$mac]);}
        app_inventory_record_audit($db,'inventory_employee_returned',$doc['document_no'],'executed',$actor,['item_id'=>$item,'quantity'=>$qty,'employee_id'=>$employee,'destination_type'=>$destinationType,'destination_id'=>$destinationId]);
        $db->commit();return['ok'=>true]+$doc;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'employee_return_failed','detail'=>$e->getMessage()];}
}

function app_inventory_employee_balances(PDO $db,int $limit=500):array
{
    app_inventory_ensure_operational_schema($db);$limit=max(1,min(1000,$limit));
    $sql='SELECT b.item_id,b.location_id AS employee_id,b.available_quantity,b.updated_at,i.sku,i.item_name,i.unit,a.full_name,a.employee_role FROM inventory_location_balances b INNER JOIN inventory_stock_items i ON i.id=b.item_id LEFT JOIN portal_accounts a ON a.id=CAST(b.location_id AS UNSIGNED) WHERE b.location_type="employee" AND b.available_quantity>0 ORDER BY a.full_name,i.item_name LIMIT '.$limit;
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];
}
function app_inventory_create_external_request(PDO $db, array $input, array $actor): array
{
    if (!app_inventory_actor_can_write($actor)) return ['ok'=>false,'error'=>'forbidden'];
    app_inventory_ensure_operational_schema($db);
    $sourceType = clean_text((string) ($input['source_location_type'] ?? 'branch'));
    $sourceId = clean_text((string) ($input['source_location_id'] ?? ''));
    $destinationType = clean_text((string) ($input['destination_type'] ?? 'company'));
    $destinationName = clean_text((string) ($input['destination_name'] ?? ''));
    $reason = clean_text((string) ($input['reason'] ?? 'other'));
    $disposition = clean_text((string) ($input['disposition'] ?? 'return_to_supplier'));
    $items = array_values(array_filter((array) ($input['items'] ?? []), 'is_array'));
    if (!in_array($sourceType, ['warehouse','branch'], true) || $sourceId === ''
        || !in_array($destinationType, ['company','institution','person'], true) || $destinationName === ''
        || !in_array($reason, ['unneeded','defective','wrong_delivery','other'], true)
        || !in_array($disposition, ['return_to_supplier','external_transfer','writeoff'], true) || $items === []) {
        return ['ok'=>false,'error'=>'invalid_request'];
    }
    if (!app_inventory_location_exists($db, $sourceType, $sourceId)) return ['ok'=>false,'error'=>'invalid_location'];
    foreach ($items as $item) {
        if ((int) ($item['item_id'] ?? 0) <= 0 || (float) ($item['quantity'] ?? 0) <= 0) {
            return ['ok'=>false,'error'=>'invalid_request'];
        }
    }
    $status = ($input['status'] ?? 'pending') === 'draft' ? 'draft' : 'pending';
    try {
        $db->beginTransaction();
        $itemCheck = $db->prepare('SELECT id FROM inventory_stock_items WHERE id=:id FOR UPDATE');
        foreach ($items as $item) {
            $itemCheck->execute(['id'=>(int) $item['item_id']]);
            if (!$itemCheck->fetchColumn()) throw new RuntimeException('invalid_item');
        }
        $doc = app_inventory_write_document($db, 'QAY', 'external_return', $status, [
            'source_location_type'=>$sourceType,
            'source_location_id'=>$sourceId,
            'destination_type'=>$destinationType,
            'destination_id'=>$input['destination_id']??'',
            'destination_name'=>$destinationName,
            'reason'=>$reason,
            'disposition'=>$disposition,
            'note'=>clean_text((string)($input['note']??'')),
        ], $items, $actor);
        app_inventory_record_audit($db, 'inventory_external_request', $doc['document_no'], 'pending', $actor, ['source_type'=>$sourceType,'source_id'=>$sourceId,'destination_type'=>$destinationType,'destination_name'=>$destinationName,'reason'=>$reason,'disposition'=>$disposition,'items'=>$items]);
        $db->commit();
        return ['ok'=>true]+$doc;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok'=>false,'error'=>'request_create_failed'];
    }
}

function app_inventory_approve_request(PDO $db, int $id, array $actor): array
{
    if (!app_inventory_actor_can_approve($actor)) return ['ok'=>false,'error'=>'forbidden'];
    app_inventory_ensure_operational_schema($db);
    try {
        $db->beginTransaction();
        $statement = $db->prepare('SELECT * FROM inventory_documents WHERE id=:id FOR UPDATE');
        $statement->execute(['id'=>$id]);
        $doc = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { $db->rollBack(); return ['ok'=>false,'error'=>'not_found']; }
        if (($doc['document_type'] ?? '') !== 'external_return') { $db->rollBack(); return ['ok'=>false,'error'=>'invalid_document_type']; }
        if (($doc['status'] ?? '') !== 'pending') { $db->rollBack(); return ['ok'=>false,'error'=>in_array($doc['status']??'', ['approved','rejected'], true)?'already_finalized':'not_pending']; }

        $lineStatement = $db->prepare('SELECT * FROM inventory_document_items WHERE document_id=:id ORDER BY line_no');
        $lineStatement->execute(['id'=>$id]);
        $lines = $lineStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($lines === []) { $db->rollBack(); return ['ok'=>false,'error'=>'items_required']; }

        $stockStatement = $db->prepare('SELECT quantity_on_hand FROM inventory_stock_items WHERE id=:id FOR UPDATE');
        $stockUpdate = $db->prepare('UPDATE inventory_stock_items SET quantity_on_hand=quantity_on_hand-:quantity WHERE id=:id');
        $locationUpdate = $db->prepare('UPDATE inventory_location_balances SET available_quantity=available_quantity-:quantity WHERE id=:id');
        foreach ($lines as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);
            if ($itemId <= 0 || $quantity <= 0) { $db->rollBack(); return ['ok'=>false,'error'=>'invalid_request']; }
            $stockStatement->execute(['id'=>$itemId]);
            $companyQuantity = $stockStatement->fetchColumn();
            if ($companyQuantity === false || (float) $companyQuantity < $quantity) {
                $db->rollBack();
                return ['ok'=>false,'error'=>'insufficient_company_stock'];
            }
            $source = app_inventory_lock_location_balance($db, $itemId, (string) $doc['source_location_type'], (string) $doc['source_location_id']);
            if ((float) ($source['available_quantity'] ?? 0) < $quantity) {
                $db->rollBack();
                return ['ok'=>false,'error'=>'insufficient_stock'];
            }
            $locationUpdate->execute(['quantity'=>$quantity,'id'=>$source['id']]);
            $stockUpdate->execute(['quantity'=>$quantity,'id'=>$itemId]);
            app_inventory_lifecycle($db, 'external_return', $itemId, $quantity, (string) $doc['source_location_type'], (string) $doc['source_location_id'], 'external', (string) ($doc['destination_name'] ?? ''), (string) ($doc['reason'] ?? ''), $actor, (string) $doc['document_no'].'-'.$line['line_no']);
        }
        $db->prepare('UPDATE inventory_documents SET status="approved",approved_by_account_id=:actor,approved_by_name=:name,approved_at=NOW() WHERE id=:id')->execute([
            'actor'=>(int)($actor['id']??0)?:null,
            'name'=>(string)($actor['full_name']??'manager'),
            'id'=>$id,
        ]);
        app_inventory_record_audit($db, 'inventory_external_approved', (string) $doc['document_no'], 'executed', $actor, ['document_id'=>$id,'destination_name'=>$doc['destination_name']??'','reason'=>$doc['reason']??'']);
        $db->commit();
        return ['ok'=>true,'id'=>$id];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok'=>false,'error'=>'approval_failed'];
    }
}
function app_inventory_reject_request(PDO $db,int $id,string $reason,array $actor):array
{
    if(!app_inventory_actor_can_approve($actor))return['ok'=>false,'error'=>'forbidden'];
    app_inventory_ensure_operational_schema($db);
    try{
        $db->beginTransaction();
        $select=$db->prepare('SELECT document_no,status FROM inventory_documents WHERE id=:id FOR UPDATE');$select->execute(['id'=>$id]);$doc=$select->fetch(PDO::FETCH_ASSOC);
        if(!$doc){$db->rollBack();return['ok'=>false,'error'=>'not_found'];}
        if(($doc['status']??'')!=='pending'){$db->rollBack();return['ok'=>false,'error'=>in_array($doc['status']??'', ['approved','rejected'],true)?'already_finalized':'not_pending'];}
        $s=$db->prepare('UPDATE inventory_documents SET status="rejected",rejection_reason=:reason,approved_by_account_id=:actor,approved_by_name=:name,approved_at=NOW() WHERE id=:id');
        $s->execute(['reason'=>clean_text($reason),'actor'=>(int)($actor['id']??0)?:null,'name'=>(string)($actor['full_name']??'manager'),'id'=>$id]);
        app_inventory_record_audit($db,'inventory_external_rejected',(string)$doc['document_no'],'executed',$actor,['document_id'=>$id,'reason'=>clean_text($reason)]);
        $db->commit();return['ok'=>true,'id'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'rejection_failed'];}
}
function app_inventory_document(PDO $db,int $id):?array{app_inventory_ensure_operational_schema($db);$s=$db->prepare('SELECT * FROM inventory_documents WHERE id=:id');$s->execute(['id'=>$id]);$doc=$s->fetch(PDO::FETCH_ASSOC);if(!$doc)return null;$l=$db->prepare('SELECT * FROM inventory_document_items WHERE document_id=:id ORDER BY line_no');$l->execute(['id'=>$id]);$doc['items']=$l->fetchAll(PDO::FETCH_ASSOC)?:[];return $doc;}
function app_inventory_document_page(PDO $db,array $query):array{app_inventory_ensure_operational_schema($db);$limit=max(1,min(50,(int)($query['limit']??50)));$page=max(1,(int)($query['page']??1));$offset=($page-1)*$limit;$status=clean_text((string)($query['status']??''));$where='';$params=[];if($status!==''){$where=' WHERE status=:status';$params['status']=$status;}$c=$db->prepare('SELECT COUNT(*) FROM inventory_documents'.$where);$c->execute($params);$total=(int)$c->fetchColumn();$s=$db->prepare('SELECT d.*,(SELECT COUNT(*) FROM inventory_document_items i WHERE i.document_id=d.id) item_count FROM inventory_documents d'.$where.' ORDER BY d.created_at DESC,d.id DESC LIMIT '.$limit.' OFFSET '.$offset);$s->execute($params);return['rows'=>$s->fetchAll(PDO::FETCH_ASSOC)?:[],'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit)),'limit'=>$limit,'start'=>$total?$offset+1:0,'end'=>min($total,$offset+$limit)];}
