<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/hr-position-service.php';

function app_hr_employee_schema_ready(PDO $db): bool
{
    return app_db_column_exists($db, 'portal_accounts', 'job_title')
        && app_hr_position_schema_ready($db);
}

function app_hr_ensure_employee_schema(PDO $db): void
{
    if (!app_hr_employee_schema_ready($db)) {
        throw new RuntimeException('HR employee schema is not ready. Run portal migrations.');
    }
}

function app_hr_actor_can_create_employee(array $actor): bool
{
    return app_portal_is_admin($actor) || strtolower((string)($actor['employee_role']??''))==='hr';
}

function app_hr_record_audit(PDO $db,int $accountId,string $name,string $jobTitle,array $actor):void
{
    $s=$db->prepare('INSERT INTO mgs_operation_actions(action_type,entity_type,entity_id,status,actor_account_id,actor_name,summary,payload_json,executed_at)VALUES("hr_employee_created","employee",:entity_id,"executed",:actor_id,:actor_name,:summary,:payload,:executed_at)');
    $s->execute(['entity_id'=>(string)$accountId,'actor_id'=>(int)($actor['id']??0)?:null,'actor_name'=>clean_text((string)($actor['full_name']??$actor['login']??'HR')),'summary'=>mb_substr('HR employee created: '.$name,0,255),'payload'=>app_db_json_encode(['job_title'=>$jobTitle,'access_pending'=>true]),'executed_at'=>app_db_now()]);
}

function app_hr_create_employee(PDO $db,array $input,array $actor): array
{
    if(!app_hr_actor_can_create_employee($actor)) return ['ok'=>false,'error'=>'forbidden'];
    try {
        app_mgs_ensure_schema($db);
        app_hr_ensure_employee_schema($db);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'schema_not_ready'];
    }
    $name=clean_text((string)($input['full_name']??'')); $login=clean_text((string)($input['login']??''));
    $positionId=max(0,(int)($input['position_id']??0));
    $position=app_hr_position_by_id($db,$positionId,true);
    $job=clean_text((string)($position['title']??''));
    if($name===''||$login===''||$position===null||$job==='') return ['ok'=>false,'error'=>$position===null?'position_required':'required_fields'];
    $exists=$db->prepare('SELECT id FROM portal_accounts WHERE login=:login LIMIT 1');$exists->execute(['login'=>$login]);if($exists->fetchColumn())return ['ok'=>false,'error'=>'duplicate_login'];
    $salaryMode=($input['salary_mode']??'gross')==='net'?'net':'gross';$gross=max(0,(float)($input['gross_salary']??0));$net=max(0,(float)($input['net_salary']??0));
    if(($gross>0&&$net>0)||($salaryMode==='gross'&&$gross<=0&&$net>0)||($salaryMode==='net'&&$net<=0&&$gross>0))return ['ok'=>false,'error'=>'salary_value_mismatch'];
    $payload=['employee'=>['branch_id'=>clean_text((string)($input['branch_id']??'')),'hire_date'=>clean_text((string)($input['hire_date']??'')),'position_id'=>$positionId,'position_title'=>$job,'access_pending'=>true,'created_by_hr'=>(int)($actor['id']??0)]];
    try{
        $db->beginTransaction();
        $insert=$db->prepare('INSERT INTO portal_accounts (external_id,login,password_hash,role,account_type,employee_role,job_title,full_name,email,phone,area,address,status,is_active,payload_json,created_at,updated_at) VALUES (NULL,:login,:password_hash,"employee","employee","",:job_title,:full_name,:email,:phone,:area,:address,"Passiv",0,:payload,NOW(),NOW())');
        $insert->execute(['login'=>$login,'password_hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),'job_title'=>$job,'full_name'=>$name,'email'=>clean_text((string)($input['email']??''))?:null,'phone'=>normalize_phone((string)($input['phone']??''))?:null,'area'=>clean_text((string)($input['area']??''))?:null,'address'=>clean_text((string)($input['address']??''))?:null,'payload'=>app_db_json_encode($payload)]);
        $accountId=(int)$db->lastInsertId();
        if($gross>0||$net>0){
            $effective=clean_text((string)($input['effective_from']??$input['hire_date']??date('Y-m-d')))?:date('Y-m-d');
            $calc=$salaryMode==='net'?app_mgs_hr_estimate_gross_from_net($net):app_mgs_hr_payroll_preview(['gross_salary'=>$gross]);
            $comp=$db->prepare('INSERT INTO hr_employee_compensations (employee_account_id,employee_name,salary_mode,gross_salary,net_salary,employee_total_deductions,employer_total_cost,effective_from,status,calculation_json,note,created_by_account_id,created_by_name) VALUES (:id,:name,:mode,:gross,:net,:deductions,:total_cost,:effective,"active",:calculation,"HR employee create",:actor,:actor_name)');
            $comp->execute(['id'=>$accountId,'name'=>$name,'mode'=>$salaryMode,'gross'=>$calc['gross_salary'],'net'=>$calc['net_salary'],'deductions'=>$calc['employee_total_deductions'],'total_cost'=>$calc['employer_total_cost'],'effective'=>$effective,'calculation'=>app_db_json_encode(['input'=>$input,'preview'=>$calc]),'actor'=>(int)($actor['id']??0)?:null,'actor_name'=>(string)($actor['full_name']??'HR')]);
        }
        app_hr_record_audit($db,$accountId,$name,$job,$actor);
        $db->commit();
        return ['ok'=>true,'account_id'=>$accountId];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();try{app_mgs_record_operation_action(['action_type'=>'hr_employee_created','entity_type'=>'employee','status'=>'failed','actor'=>$actor,'summary'=>'HR employee create failed','payload'=>['login'=>$login,'position_id'=>$positionId,'job_title'=>$job]]);}catch(Throwable){}return ['ok'=>false,'error'=>'employee_create_failed'];}
}

function app_hr_update_employee(PDO $db, array $input, array $actor): array
{
    if (!app_hr_actor_can_create_employee($actor)) return ['ok'=>false,'error'=>'forbidden'];
    try {
        app_mgs_ensure_schema($db);
        app_hr_ensure_employee_schema($db);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'schema_not_ready'];
    }
    $id=max(0,(int)($input['employee_account_id']??0));$name=clean_text((string)($input['full_name']??''));$login=clean_text((string)($input['login']??''));
    $positionId=max(0,(int)($input['position_id']??0));
    $position=app_hr_position_by_id($db,$positionId,true);
    $job=clean_text((string)($position['title']??''));
    if($id<=0||$name===''||$login===''||$position===null||$job==='')return['ok'=>false,'error'=>$position===null?'position_required':'required_fields'];
    try{
        $db->beginTransaction();
        $lock=$db->prepare('SELECT id,payload_json FROM portal_accounts WHERE id=:id AND role="employee" FOR UPDATE');$lock->execute(['id'=>$id]);$existing=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$existing){$db->rollBack();return['ok'=>false,'error'=>'employee_not_found'];}
        $duplicate=$db->prepare('SELECT id FROM portal_accounts WHERE login=:login AND id<>:id LIMIT 1');$duplicate->execute(['login'=>$login,'id'=>$id]);if($duplicate->fetchColumn()){$db->rollBack();return['ok'=>false,'error'=>'duplicate_login'];}
        $payload=json_decode((string)($existing['payload_json']??'{}'),true);if(!is_array($payload))$payload=[];if(!isset($payload['employee'])||!is_array($payload['employee']))$payload['employee']=[];
        $payload['employee']['branch_id']=clean_text((string)($input['branch_id']??''));$payload['employee']['hire_date']=clean_text((string)($input['hire_date']??''));$payload['employee']['position_id']=$positionId;$payload['employee']['position_title']=$job;
        $update=$db->prepare('UPDATE portal_accounts SET login=:login,full_name=:full_name,job_title=:job_title,email=:email,phone=:phone,area=:area,address=:address,payload_json=:payload,updated_at=NOW() WHERE id=:id');
        $update->execute(['login'=>$login,'full_name'=>$name,'job_title'=>$job,'email'=>clean_text((string)($input['email']??''))?:null,'phone'=>normalize_phone((string)($input['phone']??''))?:null,'area'=>clean_text((string)($input['area']??''))?:null,'address'=>clean_text((string)($input['address']??''))?:null,'payload'=>app_db_json_encode($payload),'id'=>$id]);
        $audit=$db->prepare('INSERT INTO mgs_operation_actions(action_type,entity_type,entity_id,status,actor_account_id,actor_name,summary,payload_json,executed_at)VALUES("hr_employee_updated","employee",:entity_id,"executed",:actor_id,:actor_name,:summary,:payload,:executed_at)');
        $audit->execute(['entity_id'=>(string)$id,'actor_id'=>(int)($actor['id']??0)?:null,'actor_name'=>clean_text((string)($actor['full_name']??$actor['login']??'HR')),'summary'=>mb_substr('HR employee updated: '.$name,0,255),'payload'=>app_db_json_encode(['position_id'=>$positionId,'job_title'=>$job,'login'=>$login]),'executed_at'=>app_db_now()]);
        $db->commit();return['ok'=>true,'account_id'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'employee_update_failed'];}
}
