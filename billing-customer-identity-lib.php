<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/customer-operator-policy.php';
require_once __DIR__ . '/mgs-integration-lib.php';

function app_billing_identity_audit(PDO $db,string $status,int $accountId,array $actor,string $summary,array $payload=[],?array $before=null,?array $after=null):void
{
    if(!$db->inTransaction())app_audit_ensure_schema($db);
    app_audit_record($db,[
        'module_key'=>'billing',
        'operation_class'=>'customer_identity',
        'action_type'=>'billing_customer_identity_save',
        'entity_type'=>'customer',
        'entity_id'=>(string)$accountId,
        'status'=>$status,
        'actor_account_id'=>app_audit_actor_id($actor),
        'actor_name'=>app_audit_actor_name($actor),
        'summary'=>mb_substr(clean_text($summary),0,255),
        'reason'=>clean_text((string)($payload['reason']??'Customer identity validation/change')),
        'before'=>$before,
        'after'=>$after,
        'payload'=>$payload,
        'sensitivity'=>'high',
    ]);
}

function app_billing_ensure_identity_schema(PDO $db): void
{
    $columns=$db->query('SHOW COLUMNS FROM portal_accounts')->fetchAll(PDO::FETCH_COLUMN)?:[];
    $indexes=$db->query('SHOW INDEX FROM portal_accounts')->fetchAll(PDO::FETCH_ASSOC)?:[];
    $names=array_column($indexes,'Key_name');
    $ready=in_array('fin_code',$columns,true)
        && in_array('document_no',$columns,true)
        && (in_array('idx_portal_accounts_fin',$names,true)||in_array('idx_portal_accounts_fin_code',$names,true))
        && app_db_table_exists($db,'billing_customer_fin_claims');
    if(!$ready){
        throw new RuntimeException('billing_identity_schema_not_migrated');
    }
}

function app_billing_normalize_fin(string $value): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/','',$value)??'');
}

function app_billing_sensitive_change_paths(): array
{
    return [
        'tariff','tariff_id','plan','status','radius_status','is_active','active',
        'default_days','billing_days','expires_at','stable_due_day',
        'profile.plan','profile.status','profile.stable_due_day',
        'billing.service_type','billing.tariff','billing.default_days','billing.billing_days',
        'billing.expires_at','billing.status','billing.radius_status','billing.stable_due_day',
        'service.service_type','service.tariff','service.default_days','service.expires_at',
        'service.status','service.stable_due_day',
        'account.plan','account.status','account.is_active',
    ];
}

function app_billing_sensitive_read(array $source, string $path): mixed
{
    $current = $source;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return null;
        }
        $current = $current[$segment];
    }
    return $current;
}

function app_billing_sensitive_normalize(mixed $value): string
{
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_numeric($value)) return rtrim(rtrim((string) (float) $value, '0'), '.');
    return trim(mb_strtolower((string) $value, 'UTF-8'));
}

function app_billing_validate_sensitive_change(array $before, array $after, string $reason): array
{
    $changes = [];
    foreach (app_billing_sensitive_change_paths() as $path) {
        $beforeValue = app_billing_sensitive_read($before, $path);
        $afterValue = app_billing_sensitive_read($after, $path);
        if (app_billing_sensitive_normalize($beforeValue) !== app_billing_sensitive_normalize($afterValue)) {
            $changes[] = ['field' => $path, 'before' => $beforeValue, 'after' => $afterValue];
        }
    }

    $reason = mb_substr(clean_text($reason), 0, 255);
    if ($changes !== [] && $reason === '') {
        return ['ok' => false, 'error' => 'reason_required', 'sensitive' => true, 'changes' => $changes];
    }

    return ['ok' => true, 'sensitive' => $changes !== [], 'reason' => $reason, 'changes' => $changes];
}

function app_billing_validate_customer_identity(PDO $db,array $input,int $excludeAccountId=0,bool $ensureSchema=true): array
{
    if($ensureSchema){app_billing_ensure_identity_schema($db);app_mgs_ensure_schema($db);}
    $data=['fin_code'=>app_billing_normalize_fin((string)($input['fin_code']??'')),'document_no'=>strtoupper(clean_text((string)($input['document_no']??''))),'phone'=>normalize_phone((string)($input['phone']??'')),'address'=>clean_text((string)($input['address']??''))];
    $fields=[];foreach($data as $key=>$value)if($value==='')$fields[]=$key;
    if($fields!==[])return ['ok'=>false,'error'=>'required_identity_fields','fields'=>$fields,'data'=>$data];
    $s=$db->prepare('SELECT id FROM portal_accounts WHERE role="customer" AND is_active=1 AND fin_code=:fin AND id<>:id LIMIT 1');$s->execute(['fin'=>$data['fin_code'],'id'=>$excludeAccountId]);
    if($s->fetchColumn())return ['ok'=>false,'error'=>'duplicate_fin','fields'=>['fin_code'],'data'=>$data];
    return ['ok'=>true,'fields'=>[],'data'=>$data];
}

function app_billing_save_customer_identity(PDO $db,int $accountId,array $input,bool $ensureSchema=true,array $actor=[]): array
{
    if($ensureSchema)app_billing_ensure_identity_schema($db);
    $started=!$db->inTransaction();
    try{
        if($started)$db->beginTransaction();
        $lock=$db->prepare('SELECT * FROM portal_accounts WHERE id=:id AND role="customer" FOR UPDATE');$lock->execute(['id'=>$accountId]);$row=$lock->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)){if($started)$db->rollBack();return['ok'=>false,'error'=>'customer_not_found'];}
        $beforeIdentity=['fin_code'=>app_billing_normalize_fin((string)($row['fin_code']??'')),'document_no'=>(string)($row['document_no']??''),'phone'=>(string)($row['phone']??''),'address'=>(string)($row['address']??'')];
        $validation=app_billing_validate_customer_identity($db,$input,$accountId,false);if(empty($validation['ok'])){if($started)$db->rollBack();return $validation;}
        $payload=app_db_account_payload($row);$profile=is_array($payload['profile']??null)?$payload['profile']:[];$profile['fin_code']=$validation['data']['fin_code'];$profile['document_no']=$validation['data']['document_no'];$profile['phone']=$validation['data']['phone'];$profile['address']=$validation['data']['address'];$payload['profile']=$profile;
        $fin=$validation['data']['fin_code'];
        if((int)($row['is_active']??0)===1){
            $db->prepare('DELETE FROM billing_customer_fin_claims WHERE account_id=:account AND fin_code<>:fin')->execute(['account'=>$accountId,'fin'=>$fin]);
            $db->prepare('INSERT IGNORE INTO billing_customer_fin_claims(fin_code,account_id)VALUES(:fin,:account)')->execute(['fin'=>$fin,'account'=>$accountId]);
            $claim=$db->prepare('SELECT account_id FROM billing_customer_fin_claims WHERE fin_code=:fin FOR UPDATE');$claim->execute(['fin'=>$fin]);$claimedBy=(int)$claim->fetchColumn();
            if($claimedBy!==$accountId){
                $owner=$db->prepare('SELECT is_active,fin_code FROM portal_accounts WHERE id=:id FOR UPDATE');$owner->execute(['id'=>$claimedBy]);$ownerRow=$owner->fetch(PDO::FETCH_ASSOC);
                if(is_array($ownerRow)&&(int)($ownerRow['is_active']??0)===1&&app_billing_normalize_fin((string)($ownerRow['fin_code']??''))===$fin){if($started)$db->rollBack();return ['ok'=>false,'error'=>'duplicate_fin','fields'=>['fin_code'],'data'=>$validation['data']];}
                $db->prepare('UPDATE billing_customer_fin_claims SET account_id=:account WHERE fin_code=:fin')->execute(['account'=>$accountId,'fin'=>$fin]);
            }
        }else{
            $db->prepare('DELETE FROM billing_customer_fin_claims WHERE account_id=:account')->execute(['account'=>$accountId]);
        }
        $s=$db->prepare('UPDATE portal_accounts SET fin_code=:fin,document_no=:document,phone=:phone,address=:address,payload_json=:payload,updated_at=NOW() WHERE id=:id AND role="customer"');
        $ok=$s->execute(['fin'=>$fin,'document'=>$validation['data']['document_no'],'phone'=>$validation['data']['phone'],'address'=>$validation['data']['address'],'payload'=>app_db_json_encode($payload),'id'=>$accountId]);
        if(!$ok)throw new RuntimeException('identity_update_failed');
        $verify=$db->prepare('SELECT fin_code,document_no,phone,address FROM portal_accounts WHERE id=:id AND role="customer"');$verify->execute(['id'=>$accountId]);$saved=$verify->fetch(PDO::FETCH_ASSOC);
        if(!is_array($saved)||app_billing_normalize_fin((string)($saved['fin_code']??''))!==$fin||(string)($saved['document_no']??'')!==$validation['data']['document_no'])throw new RuntimeException('identity_update_not_persisted');
        app_billing_identity_audit($db,'executed',$accountId,$actor,'Billing customer identity saved',['fin_code'=>$fin,'document_no'=>$validation['data']['document_no'],'changed_fields'=>array_keys($validation['data'])],$beforeIdentity,$validation['data']);
        if($started)$db->commit();
        return ['ok'=>true,'data'=>$validation['data']];
    }catch(Throwable $e){if($started&&$db->inTransaction())$db->rollBack();if($started){try{app_billing_identity_audit($db,'failed',$accountId,$actor,'Billing customer identity save failed',['error'=>'identity_save_failed']);}catch(Throwable){}}return ['ok'=>false,'error'=>'identity_save_failed'];}
}

function app_billing_upsert_customer_with_identity(PDO $db,array $accountPayload,array $identityInput,array $actor=[],?string $radiusPassword=null):array
{
    $existingId = (int) ($accountPayload['id'] ?? 0);
    if ($existingId > 0) {
        $existing = app_db_account_by_id($existingId);
        if ($existing && !app_customer_identity_change_allowed($existing, array_intersect_key($accountPayload, array_flip(['full_name','address'])) + array_intersect_key($identityInput, array_flip(['fin_code'])), $actor)) {
            return ['ok' => false, 'error' => 'identity_super_admin_required'];
        }
    }
    app_billing_ensure_identity_schema($db);
    app_mgs_ensure_schema($db);
    if($db->inTransaction())return ['ok'=>false,'error'=>'transaction_already_active'];
    $savedId=0;
    $sensitiveAudit=is_array($accountPayload['sensitive_change_audit']??null)?(array)$accountPayload['sensitive_change_audit']:[];
    if($sensitiveAudit!==[]){
        $sensitiveValidation=app_billing_validate_sensitive_change(is_array($sensitiveAudit['before']??null)?$sensitiveAudit['before']:[],is_array($sensitiveAudit['after']??null)?$sensitiveAudit['after']:[],(string)($sensitiveAudit['reason']??''));
        if(empty($sensitiveValidation['ok']))return $sensitiveValidation;
        $sensitiveAudit['sensitive']=!empty($sensitiveValidation['sensitive']);
        $sensitiveAudit['reason']=(string)($sensitiveValidation['reason']??'');
        $sensitiveAudit['changes']=(array)($sensitiveValidation['changes']??[]);
    }
    try{
        $db->beginTransaction();
        $targetId=max(0,(int)($accountPayload['id']??0));$preValidation=app_billing_validate_customer_identity($db,$identityInput,$targetId,false);
        if(empty($preValidation['ok'])){$db->rollBack();try{app_billing_identity_audit($db,'failed',$targetId,$actor,'Billing customer identity validation failed',['error'=>(string)($preValidation['error']??'validation_failed')]);}catch(Throwable){}return $preValidation;}
        $saved=app_db_upsert_account($accountPayload,false);
        if(!is_array($saved))throw new RuntimeException('account_save_failed');
        $savedId=(int)$saved['id'];$identity=app_billing_save_customer_identity($db,$savedId,$identityInput,false,$actor);
        if(empty($identity['ok'])){$db->rollBack();try{app_billing_identity_audit($db,'failed',$savedId,$actor,'Billing customer identity save rejected',['error'=>(string)($identity['error']??'validation_failed')]);}catch(Throwable){}return $identity;}
        $accountStatement=$db->prepare('SELECT * FROM portal_accounts WHERE id=:id');$accountStatement->execute(['id'=>(int)$saved['id']]);$account=$accountStatement->fetch(PDO::FETCH_ASSOC);
        if(!is_array($account)||!app_billing_sync_radius_account($account,$radiusPassword,$db))throw new RuntimeException('radius_sync_failed');
        if(!empty($sensitiveAudit['sensitive'])){
            app_audit_record($db,[
                'module_key'=>'billing',
                'operation_class'=>'customer_edit',
                'action_type'=>'billing_customer_sensitive_changed',
                'entity_type'=>'customer',
                'entity_id'=>(string)$savedId,
                'status'=>'executed',
                'actor_account_id'=>app_audit_actor_id($actor),
                'actor_name'=>app_audit_actor_name($actor),
                'summary'=>'Billing customer sensitive fields changed',
                'reason'=>(string)($sensitiveAudit['reason']??''),
                'before'=>is_array($sensitiveAudit['before']??null)?$sensitiveAudit['before']:[],
                'after'=>is_array($sensitiveAudit['after']??null)?$sensitiveAudit['after']:[],
                'payload'=>['changes'=>(array)($sensitiveAudit['changes']??[])],
                'sensitivity'=>'high',
            ]);
        }
        $db->commit();
        return ['ok'=>true,'account'=>app_db_account_by_id((int)$saved['id']),'identity'=>$identity['data']??[],'radius_synced'=>true];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage()==='radius_sync_failed'?'radius_sync_failed':'account_identity_save_failed';try{app_billing_identity_audit($db,'failed',$savedId,$actor,'Billing customer save failed',['error'=>$error]);}catch(Throwable){}return ['ok'=>false,'error'=>$error];}
}
