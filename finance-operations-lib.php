<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/finance-schema-lib.php';
require_once __DIR__ . '/stage1-feature-lib.php';

function app_finance_ensure_operational_schema(PDO $db): void
{
    app_finance_require_operational_schema($db);
}

/**
 * @deprecated Schema changes belong to versioned CLI migrations.
 */
function app_finance_create_operational_schema_legacy(PDO $db): void
{
    app_mgs_ensure_schema($db);
    app_audit_ensure_schema($db);
    $db->exec("CREATE TABLE IF NOT EXISTS finance_counterparties (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        display_name VARCHAR(191) NOT NULL,
        normalized_name VARCHAR(191) NOT NULL,
        counterparty_type ENUM('company','institution','person','other') NOT NULL DEFAULT 'company',
        tax_id VARCHAR(64) DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, email VARCHAR(191) DEFAULT NULL,
        address VARCHAR(255) DEFAULT NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active', note TEXT DEFAULT NULL,
        created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_finance_counterparty_name_type (normalized_name, counterparty_type), KEY idx_finance_counterparty_tax (tax_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS finance_entries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entry_type ENUM('income','expense') NOT NULL, title VARCHAR(191) NOT NULL,
        category VARCHAR(96) NOT NULL DEFAULT 'manual', amount DECIMAL(14,2) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'AZN',
        transaction_date DATE NOT NULL, branch_id BIGINT UNSIGNED DEFAULT NULL, counterparty_id BIGINT UNSIGNED DEFAULT NULL,
        payment_source VARCHAR(96) DEFAULT NULL, reference_no VARCHAR(128) DEFAULT NULL, note TEXT DEFAULT NULL,
        status ENUM('posted','void') NOT NULL DEFAULT 'posted', created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
        created_by_name VARCHAR(191) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_finance_entry_type_date (entry_type, transaction_date), KEY idx_finance_entry_counterparty (counterparty_id),
        UNIQUE KEY uq_finance_entry_reference (reference_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $columns=$db->query('SHOW COLUMNS FROM finance_debt_ledger')->fetchAll(PDO::FETCH_COLUMN)?:[];
    if(!in_array('counterparty_id',$columns,true))$db->exec('ALTER TABLE finance_debt_ledger ADD COLUMN counterparty_id BIGINT UNSIGNED DEFAULT NULL AFTER counterparty_type, ADD KEY idx_finance_debt_counterparty_id(counterparty_id)');
}

function app_finance_record_audit(PDO $db,string $actionType,string $entityId,array $actor,string $summary,array $payload=[],string $reason='',?array $before=null,?array $after=null,string $operationClass='write'):void
{
    if(!$db->inTransaction())app_audit_ensure_schema($db);
    app_audit_record($db,[
        'module_key'=>'finance',
        'operation_class'=>$operationClass,
        'action_type'=>$actionType,
        'entity_type'=>'finance',
        'entity_id'=>$entityId,
        'status'=>'executed',
        'actor_account_id'=>(int)($actor['id']??0)?:null,
        'actor_name'=>clean_text((string)($actor['full_name']??$actor['login']??'finance')),
        'summary'=>mb_substr(clean_text($summary),0,255),
        'reason'=>$reason,
        'before'=>$before,
        'after'=>$after,
        'payload'=>$payload,
        'sensitivity'=>in_array($actionType,['finance_entry_updated','finance_entry_voided'],true)?'high':'normal',
    ]);
}

function app_finance_required_reason(mixed $value): string
{
    $reason=mb_substr(clean_text((string)$value),0,255);
    if($reason==='')throw new InvalidArgumentException('reason_required');
    return $reason;
}

function app_finance_actor_can_write(array $actor): bool
{
    return app_portal_is_admin($actor) || strtolower((string) ($actor['employee_role'] ?? '')) === 'finance';
}

function app_finance_stage1_schema_ready(PDO $db): bool
{
    // Called repeatedly per render; the schema cannot change mid-request, so the
    // information_schema probe is memoized for the request.
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = "finance_entries"
                AND COLUMN_NAME IN (
                    "source_type","source_id","employee_account_id","idempotency_key",
                    "correlation_id","payload_json","updated_at"
                )'
        );
        $statement->execute();
        return $ready = ((int) $statement->fetchColumn() === 7);
    } catch (Throwable) {
        return $ready = false;
    }
}

function app_finance_source_actor_can_write(array $actor, string $sourceType): bool
{
    if (app_finance_actor_can_write($actor)) {
        return true;
    }
    return $sourceType === 'hr'
        && strtolower(trim((string) ($actor['employee_role'] ?? ''))) === 'hr';
}

function app_finance_post_source_entry(PDO $db, array $input, array $actor): array
{
    app_finance_ensure_operational_schema($db);
    if (!app_stage1_feature_enabled($db, 'stage1_finance_ledger')) {
        return ['ok' => false, 'error' => 'stage1_finance_ledger_disabled'];
    }
    if (!app_finance_stage1_schema_ready($db)) {
        return ['ok' => false, 'error' => 'stage1_finance_schema_not_migrated'];
    }

    $sourceType = strtolower(clean_text((string) ($input['source_type'] ?? '')));
    $sourceId = clean_text((string) ($input['source_id'] ?? ''));
    $idempotencyKey = clean_text((string) ($input['idempotency_key'] ?? ''));
    if ($sourceType === '' || $sourceType === 'manual'
        || preg_match('/^[a-z0-9_.:-]{2,64}$/', $sourceType) !== 1
        || $sourceId === '' || $idempotencyKey === '') {
        return ['ok' => false, 'error' => 'source_identity_required'];
    }
    if (!app_finance_source_actor_can_write($actor, $sourceType)) {
        return ['ok' => false, 'error' => 'forbidden'];
    }

    $type = strtolower(clean_text((string) ($input['entry_type'] ?? 'expense')));
    $title = clean_text((string) ($input['title'] ?? ''));
    $amount = round((float) ($input['amount'] ?? 0), 2);
    $currency = strtoupper(clean_text((string) ($input['currency'] ?? 'AZN')));
    $date = clean_text((string) ($input['transaction_date'] ?? date('Y-m-d')));
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!in_array($type, ['income', 'expense'], true)
        || $title === '' || $amount <= 0
        || !$parsedDate || $parsedDate->format('Y-m-d') !== $date
        || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
        return ['ok' => false, 'error' => 'invalid_entry'];
    }

    $ownsTransaction = !$db->inTransaction();
    try {
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        $existing = $db->prepare(
            'SELECT * FROM finance_entries
              WHERE (source_type = :source_type AND source_id = :source_id)
                 OR idempotency_key = :idempotency_key
              ORDER BY id
              LIMIT 1
              FOR UPDATE'
        );
        $existing->execute([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
        ]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($existingRow)) {
            if ($ownsTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'id' => (int) $existingRow['id'], 'existing' => true, 'record' => $existingRow];
        }

        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $record = [
            'entry_type' => $type,
            'title' => $title,
            'category' => clean_text((string) ($input['category'] ?? 'system')) ?: 'system',
            'amount' => $amount,
            'currency' => $currency,
            'transaction_date' => $date,
            'branch_id' => (int) ($input['branch_id'] ?? 0) ?: null,
            'counterparty_id' => (int) ($input['counterparty_id'] ?? 0) ?: null,
            'payment_source' => clean_text((string) ($input['payment_source'] ?? $sourceType)) ?: $sourceType,
            'reference_no' => clean_text((string) ($input['reference_no'] ?? '')) ?: null,
            'note' => clean_text((string) ($input['note'] ?? '')) ?: null,
            'source_type' => $sourceType,
            'source_id' => mb_substr($sourceId, 0, 128),
            'employee_account_id' => (int) ($input['employee_account_id'] ?? 0) ?: null,
            'idempotency_key' => mb_substr($idempotencyKey, 0, 191),
            'correlation_id' => mb_substr(
                clean_text((string) ($input['correlation_id'] ?? app_request_correlation_id())),
                0,
                96
            ),
            'payload_json' => app_db_json_encode($payload),
            'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system')),
        ];
        $insert = $db->prepare(
            'INSERT INTO finance_entries (
                entry_type,title,category,amount,currency,transaction_date,branch_id,counterparty_id,
                payment_source,reference_no,note,status,source_type,source_id,employee_account_id,
                idempotency_key,correlation_id,payload_json,created_by_account_id,created_by_name
             ) VALUES (
                :entry_type,:title,:category,:amount,:currency,:transaction_date,:branch_id,:counterparty_id,
                :payment_source,:reference_no,:note,"posted",:source_type,:source_id,:employee_account_id,
                :idempotency_key,:correlation_id,:payload_json,:actor_id,:actor_name
             )'
        );
        $insert->execute($record);
        $id = (int) $db->lastInsertId();
        $after = $db->prepare('SELECT * FROM finance_entries WHERE id = :id');
        $after->execute(['id' => $id]);
        $afterRow = $after->fetch(PDO::FETCH_ASSOC) ?: [];
        app_finance_record_audit(
            $db,
            'finance_source_entry_created',
            (string) $id,
            $actor,
            'Finance source ' . $type . ': ' . $title,
            [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $record['correlation_id'],
                'amount' => $amount,
                'currency' => $currency,
            ],
            '',
            null,
            is_array($afterRow) ? $afterRow : [],
            'entry'
        );
        if ($ownsTransaction) {
            $db->commit();
        }
        return ['ok' => true, 'id' => $id, 'existing' => false, 'record' => $afterRow];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'source_entry_save_failed'];
    }
}

function app_finance_normalized_name(string $value): string
{
    $value = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
    return $value;
}

function app_finance_save_counterparty(PDO $db, array $input, array $actor): array
{
    if (!app_finance_actor_can_write($actor)) return ['ok' => false, 'error' => 'forbidden'];
    app_finance_ensure_operational_schema($db);
    $id = max(0,(int)($input['counterparty_id']??0));
    $name = clean_text((string) ($input['display_name'] ?? ''));
    $type = clean_text((string) ($input['counterparty_type'] ?? 'company'));
    if ($name === '' || !in_array($type, ['company','institution','person','other'], true)) return ['ok' => false, 'error' => 'invalid_counterparty'];
    try {
        $db->beginTransaction();
        $record=[
            'display_name'=>$name,'normalized_name'=>app_finance_normalized_name($name),'counterparty_type'=>$type,
            'tax_id'=>clean_text((string)($input['tax_id']??''))?:null,'phone'=>normalize_phone((string)($input['phone']??''))?:null,
            'email'=>clean_text((string)($input['email']??''))?:null,'address'=>clean_text((string)($input['address']??''))?:null,
            'status'=>($input['status']??'active')==='inactive'?'inactive':'active','note'=>clean_text((string)($input['note']??''))?:null,
            'actor'=>(int)($actor['id']??0)?:null,
        ];
        if($id>0){
            $lock=$db->prepare('SELECT id FROM finance_counterparties WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$id]);if(!$lock->fetchColumn()){ $db->rollBack();return['ok'=>false,'error'=>'counterparty_not_found']; }
            $statement=$db->prepare('UPDATE finance_counterparties SET display_name=:display_name,normalized_name=:normalized_name,counterparty_type=:counterparty_type,tax_id=:tax_id,phone=:phone,email=:email,address=:address,status=:status,note=:note WHERE id=:id');
            unset($record['actor']);$record['id']=$id;$statement->execute($record);
        }else{
            $duplicate=$db->prepare('SELECT id FROM finance_counterparties WHERE normalized_name=:normalized_name AND counterparty_type=:counterparty_type LIMIT 1 FOR UPDATE');
            $duplicate->execute(['normalized_name'=>$record['normalized_name'],'counterparty_type'=>$record['counterparty_type']]);
            if($duplicate->fetchColumn()){$db->rollBack();return['ok'=>false,'error'=>'counterparty_exists'];}
            $statement = $db->prepare('INSERT INTO finance_counterparties (display_name,normalized_name,counterparty_type,tax_id,phone,email,address,status,note,created_by_account_id)
                VALUES (:display_name,:normalized_name,:counterparty_type,:tax_id,:phone,:email,:address,:status,:note,:actor)');
            $statement->execute($record);$id=(int)$db->lastInsertId();
        }
        app_finance_record_audit($db,'finance_counterparty_saved',(string)$id,$actor,'Finance counterparty saved: '.$name,['counterparty_type'=>$type,'tax_id'=>$input['tax_id']??'']);
        $db->commit();
        return ['ok'=>true,'id'=>$id];
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack();try{app_mgs_record_operation_action(['action_type'=>'finance_counterparty_saved','entity_type'=>'finance_counterparty','status'=>'failed','actor'=>$actor,'summary'=>'Finance counterparty save failed','payload'=>['display_name'=>$name,'counterparty_type'=>$type]]);}catch(Throwable){}return ['ok'=>false,'error'=>'counterparty_save_failed']; }
}

function app_finance_save_entry(PDO $db, array $input, array $actor): array
{
    if (!app_finance_actor_can_write($actor)) return ['ok'=>false,'error'=>'forbidden'];
    app_finance_ensure_operational_schema($db);
    $id = max(0, (int) ($input['entry_id'] ?? 0));
    if($id>0){
        try{$reason=app_finance_required_reason($input['reason']??$input['change_reason']??'');}catch(InvalidArgumentException){return['ok'=>false,'error'=>'reason_required'];}
    }else{$reason=mb_substr(clean_text((string)($input['reason']??$input['change_reason']??'')),0,255);}
    $type = clean_text((string)($input['entry_type']??'income'));
    $title = clean_text((string)($input['title']??''));
    $amount = (float)($input['amount']??0);
    $date = clean_text((string)($input['transaction_date']??date('Y-m-d')));
    $currency = strtoupper(clean_text((string) ($input['currency'] ?? 'AZN')));
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!in_array($type,['income','expense'],true) || $title==='' || $amount<=0 || !$parsedDate || $parsedDate->format('Y-m-d') !== $date || !preg_match('/^[A-Z]{3}$/', $currency)) return ['ok'=>false,'error'=>'invalid_entry'];
    try {
        $db->beginTransaction();
        $record=[
            'entry_type'=>$type,'title'=>$title,'category'=>clean_text((string)($input['category']??'manual'))?:'manual','amount'=>$amount,
            'currency'=>$currency,'transaction_date'=>$date,
            'branch_id'=>(int)($input['branch_id']??0)?:null,'counterparty_id'=>(int)($input['counterparty_id']??0)?:null,
            'payment_source'=>clean_text((string)($input['payment_source']??''))?:null,'reference_no'=>clean_text((string)($input['reference_no']??''))?:null,
            'note'=>clean_text((string)($input['note']??''))?:null,'actor_id'=>(int)($actor['id']??0)?:null,'actor_name'=>(string)($actor['full_name']??$actor['login']??'finance'),
        ];
        if ($id > 0) {
            $lock=$db->prepare('SELECT * FROM finance_entries WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$id]);$existing=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$existing){$db->rollBack();return['ok'=>false,'error'=>'entry_not_found'];}
            if(($existing['status']??'')==='void'){$db->rollBack();return['ok'=>false,'error'=>'entry_void'];}
            $statement=$db->prepare('UPDATE finance_entries SET entry_type=:entry_type,title=:title,category=:category,amount=:amount,currency=:currency,transaction_date=:transaction_date,branch_id=:branch_id,counterparty_id=:counterparty_id,payment_source=:payment_source,reference_no=:reference_no,note=:note WHERE id=:id');
            unset($record['actor_id'],$record['actor_name']);$record['id']=$id;$statement->execute($record);$auditType='finance_entry_updated';
            $afterStatement=$db->prepare('SELECT * FROM finance_entries WHERE id=:id');$afterStatement->execute(['id'=>$id]);$after=$afterStatement->fetch(PDO::FETCH_ASSOC)?:[];
        } else {
            // Maker-checker (vision 19.2 "boyuk xerc"): a single actor could
            // otherwise post any-size expense straight into the books. An
            // expense at/above the configurable threshold lands as
            // pending_approval instead and is excluded from every
            // status="posted" total/report until a DIFFERENT admin approves
            // it via app_finance_approve_expense_entry().
            $largeExpenseThreshold = (float) app_business_rule_get($db, 'finance_large_expense_threshold_amount', 1000);
            $record['status'] = ($type === 'expense' && $largeExpenseThreshold > 0 && $amount >= $largeExpenseThreshold)
                ? 'pending_approval'
                : 'posted';

            if (app_finance_stage1_schema_ready($db)
                && app_stage1_feature_enabled($db, 'stage1_finance_ledger')) {
                $idempotencyKey = mb_substr(clean_text((string) ($input['idempotency_key'] ?? '')), 0, 191);
                if ($idempotencyKey !== '') {
                    $duplicate = $db->prepare('SELECT * FROM finance_entries WHERE idempotency_key = :idempotency_key LIMIT 1 FOR UPDATE');
                    $duplicate->execute(['idempotency_key' => $idempotencyKey]);
                    $duplicateRow = $duplicate->fetch(PDO::FETCH_ASSOC);
                    if (is_array($duplicateRow)) {
                        $db->commit();
                        return ['ok'=>true,'id'=>(int)$duplicateRow['id'],'existing'=>true,'record'=>$duplicateRow];
                    }
                }
                $record['idempotency_key'] = $idempotencyKey !== '' ? $idempotencyKey : null;
                $record['employee_account_id'] = (int) ($input['employee_account_id'] ?? 0) ?: null;
                $record['correlation_id'] = mb_substr(app_request_correlation_id(), 0, 96);
                $record['payload_json'] = app_db_json_encode([
                    'source' => 'manual',
                    'category' => $record['category'],
                    'payment_source' => $record['payment_source'],
                ]);
                $statement=$db->prepare('INSERT INTO finance_entries (entry_type,title,category,amount,currency,transaction_date,branch_id,counterparty_id,payment_source,reference_no,note,status,source_type,employee_account_id,idempotency_key,correlation_id,payload_json,created_by_account_id,created_by_name) VALUES (:entry_type,:title,:category,:amount,:currency,:transaction_date,:branch_id,:counterparty_id,:payment_source,:reference_no,:note,:status,"manual",:employee_account_id,:idempotency_key,:correlation_id,:payload_json,:actor_id,:actor_name)');
            } else {
                $statement=$db->prepare('INSERT INTO finance_entries (entry_type,title,category,amount,currency,transaction_date,branch_id,counterparty_id,payment_source,reference_no,note,status,created_by_account_id,created_by_name) VALUES (:entry_type,:title,:category,:amount,:currency,:transaction_date,:branch_id,:counterparty_id,:payment_source,:reference_no,:note,:status,:actor_id,:actor_name)');
            }
            $statement->execute($record);$id=(int)$db->lastInsertId();$auditType='finance_entry_created';
            $existing=null;$afterStatement=$db->prepare('SELECT * FROM finance_entries WHERE id=:id');$afterStatement->execute(['id'=>$id]);$after=$afterStatement->fetch(PDO::FETCH_ASSOC)?:[];
        }
        app_finance_record_audit($db,$auditType,(string)$id,$actor,'Finance '.$type.': '.$title,['entryType'=>$type,'amount'=>$amount,'currency'=>$currency,'transaction_date'=>$date],$reason,is_array($existing)?$existing:null,$after,$id>0?'entry':'entry');
        $db->commit();
        return ['ok'=>true,'id'=>$id];
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack();try{app_mgs_record_operation_action(['action_type'=>'finance_entry_created','entity_type'=>'finance','status'=>'failed','actor'=>$actor,'summary'=>'Finance entry save failed','payload'=>['entryType'=>$type,'title'=>$title,'amount'=>$amount]]);}catch(Throwable){}return ['ok'=>false,'error'=>'entry_save_failed']; }
}

function app_finance_link_legacy_counterparty(PDO $db,array $input,array $actor):array
{
    if(!app_finance_actor_can_write($actor))return['ok'=>false,'error'=>'forbidden'];app_finance_ensure_operational_schema($db);
    $counterpartyId=max(0,(int)($input['counterparty_id']??0));$legacyName=clean_text((string)($input['legacy_name']??''));$legacyType=clean_text((string)($input['legacy_type']??'other'));
    if($counterpartyId<=0||$legacyName==='')return['ok'=>false,'error'=>'invalid_link'];
    try{$db->beginTransaction();$target=$db->prepare('SELECT id,display_name FROM finance_counterparties WHERE id=:id FOR UPDATE');$target->execute(['id'=>$counterpartyId]);$row=$target->fetch(PDO::FETCH_ASSOC);if(!$row){$db->rollBack();return['ok'=>false,'error'=>'counterparty_not_found'];}
        $update=$db->prepare('UPDATE finance_debt_ledger SET counterparty_id=:counterparty_id WHERE counterparty_id IS NULL AND LOWER(TRIM(counterparty_name))=:legacy_name AND counterparty_type=:legacy_type');$update->execute(['counterparty_id'=>$counterpartyId,'legacy_name'=>mb_strtolower(trim($legacyName),'UTF-8'),'legacy_type'=>$legacyType]);$count=$update->rowCount();if($count<=0){$db->rollBack();return['ok'=>false,'error'=>'legacy_rows_not_found'];}
        app_finance_record_audit($db,'finance_counterparty_linked',(string)$counterpartyId,$actor,'Legacy finance records linked to '.$row['display_name'],['legacy_name'=>$legacyName,'legacy_type'=>$legacyType,'rows'=>$count]);$db->commit();return['ok'=>true,'counterparty_id'=>$counterpartyId,'rows'=>$count];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'counterparty_link_failed'];}
}

function app_finance_void_entry(PDO $db, int $id, array $actor, string $reason = ''): array
{
    if (!app_finance_actor_can_write($actor)) return ['ok'=>false,'error'=>'forbidden'];
    app_finance_ensure_operational_schema($db);
    if ($id <= 0) return ['ok'=>false,'error'=>'invalid_entry'];
    try{$reason=app_finance_required_reason($reason);}catch(InvalidArgumentException){return['ok'=>false,'error'=>'reason_required'];}
    try {
        $db->beginTransaction();
        $lock=$db->prepare('SELECT * FROM finance_entries WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$id]);$row=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$row){$db->rollBack();return['ok'=>false,'error'=>'entry_not_found'];}
        if(($row['status']??'')!=='void'){$db->prepare('UPDATE finance_entries SET status="void" WHERE id=:id')->execute(['id'=>$id]);$after=$db->prepare('SELECT * FROM finance_entries WHERE id=:id');$after->execute(['id'=>$id]);app_finance_record_audit($db,'finance_entry_voided',(string)$id,$actor,'Finance entry voided: '.(string)$row['title'],['entryType'=>(string)($row['entry_type']??''),'amount'=>(float)($row['amount']??0),'currency'=>(string)($row['currency']??'AZN')],$reason,$row,$after->fetch(PDO::FETCH_ASSOC)?:[],'entry');}
        $db->commit();return['ok'=>true,'id'=>$id];
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();return['ok'=>false,'error'=>'entry_void_failed'];}
}

// Maker-checker confirm step (vision 19.2 "boyuk xerc"): a DIFFERENT admin
// than the one who entered the expense must approve it before it posts and
// starts counting in any total/report.
function app_finance_approve_expense_entry(PDO $db, int $id, array $actor): array
{
    if (!app_portal_is_admin($actor)) return ['ok'=>false,'error'=>'permission_denied'];
    app_finance_ensure_operational_schema($db);
    if ($id <= 0) return ['ok'=>false,'error'=>'invalid_entry'];
    $actorId = (int) ($actor['id'] ?? 0);

    try {
        $db->beginTransaction();
        $lock = $db->prepare('SELECT * FROM finance_entries WHERE id=:id FOR UPDATE');
        $lock->execute(['id' => $id]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $db->rollBack(); return ['ok'=>false,'error'=>'entry_not_found']; }
        if ((string) ($row['status'] ?? '') !== 'pending_approval') { $db->rollBack(); return ['ok'=>false,'error'=>'not_pending_approval']; }
        if ($actorId > 0 && $actorId === (int) ($row['created_by_account_id'] ?? 0)) { $db->rollBack(); return ['ok'=>false,'error'=>'creator_cannot_approve']; }

        $db->prepare(
            'UPDATE finance_entries SET status="posted", approved_by_account_id=:actor_id, approved_by_name=:actor_name, approved_at=NOW() WHERE id=:id'
        )->execute([
            'actor_id' => $actorId ?: null,
            'actor_name' => clean_text((string) ($actor['full_name'] ?? $actor['login'] ?? 'admin')),
            'id' => $id,
        ]);
        $after = $db->prepare('SELECT * FROM finance_entries WHERE id=:id');
        $after->execute(['id' => $id]);
        app_finance_record_audit($db, 'finance_entry_approved', (string) $id, $actor, 'Large expense approved: ' . (string) ($row['title'] ?? ''), ['entryType' => (string) ($row['entry_type'] ?? ''), 'amount' => (float) ($row['amount'] ?? 0), 'currency' => (string) ($row['currency'] ?? 'AZN')], '', $row, $after->fetch(PDO::FETCH_ASSOC) ?: [], 'entry');
        $db->commit();
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'entry_approve_failed'];
    }
}

function app_finance_manual_income_total(PDO $db, string $startDate, string $endDate, string $currency = 'AZN'): float
{
    app_finance_ensure_operational_schema($db);
    $s=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM finance_entries WHERE entry_type="income" AND status="posted" AND currency=:currency AND transaction_date BETWEEN :start_date AND :end_date');
    $s->execute(['currency'=>strtoupper($currency),'start_date'=>$startDate,'end_date'=>$endDate]);
    return (float)$s->fetchColumn();
}

function app_finance_income_page(PDO $db, array $query): array
{
    app_finance_ensure_operational_schema($db);
    $limit=max(1,min(50,(int)($query['limit']??50))); $page=max(1,(int)($query['page']??1)); $offset=($page-1)*$limit;
    $search=clean_text((string)($query['search']??'')); $where=' WHERE e.entry_type="income"'; $params=[];
    if($search!==''){ $where.=' AND (e.title LIKE :q OR e.category LIKE :q OR e.reference_no LIKE :q OR c.display_name LIKE :q)'; $params['q']='%'.$search.'%'; }
    $c=$db->prepare('SELECT COUNT(*) FROM finance_entries e LEFT JOIN finance_counterparties c ON c.id=e.counterparty_id'.$where); $c->execute($params); $total=(int)$c->fetchColumn();
    $pages=max(1,(int)ceil($total/$limit));$page=min($page,$pages);$offset=($page-1)*$limit;
    $r=$db->prepare('SELECT e.*,c.display_name AS counterparty_name FROM finance_entries e LEFT JOIN finance_counterparties c ON c.id=e.counterparty_id'.$where.' ORDER BY e.transaction_date DESC,e.id DESC LIMIT '.$limit.' OFFSET '.$offset); $r->execute($params);
    return ['rows'=>$r->fetchAll(PDO::FETCH_ASSOC)?:[],'total'=>$total,'page'=>$page,'pages'=>$pages,'limit'=>$limit,'start'=>$total?$offset+1:0,'end'=>min($total,$offset+$limit)];
}

function app_finance_counterparty_page(PDO $db, array $query): array
{
    app_finance_ensure_operational_schema($db);
    $limit=max(1,min(50,(int)($query['limit']??50))); $page=max(1,(int)($query['page']??1)); $offset=($page-1)*$limit;
    $debtAggregate='SELECT counterparty_id,LOWER(TRIM(counterparty_name)) normalized_name,MAX(counterparty_name) display_name,counterparty_type,COUNT(*) document_count,SUM(amount_original) amount_total,SUM(amount_open) amount_open,MAX(updated_at) updated_at FROM finance_debt_ledger GROUP BY counterparty_id,LOWER(TRIM(counterparty_name)),counterparty_type';
    $base='SELECT c.id,c.display_name,c.normalized_name,c.counterparty_type,c.tax_id,c.phone,c.email,c.address,c.status,c.note,c.created_at,c.updated_at,COALESCE(SUM(d.document_count),0) document_count,COALESCE(SUM(d.amount_total),0) amount_total,COALESCE(SUM(d.amount_open),0) amount_open FROM finance_counterparties c LEFT JOIN ('.$debtAggregate.') d ON d.counterparty_id=c.id OR (d.counterparty_id IS NULL AND d.normalized_name=c.normalized_name AND d.counterparty_type=c.counterparty_type) GROUP BY c.id,c.display_name,c.normalized_name,c.counterparty_type,c.tax_id,c.phone,c.email,c.address,c.status,c.note,c.created_at,c.updated_at
        UNION ALL
        SELECT NULL,d.display_name,d.normalized_name,d.counterparty_type,NULL,NULL,NULL,NULL,"historical",NULL,NULL,d.updated_at,d.document_count,d.amount_total,d.amount_open FROM ('.$debtAggregate.') d LEFT JOIN finance_counterparties c ON c.normalized_name=d.normalized_name AND c.counterparty_type=d.counterparty_type WHERE d.counterparty_id IS NULL AND c.id IS NULL';
    $q=clean_text((string)($query['search']??'')); $where=''; $params=[]; if($q!==''){$where=' WHERE x.display_name LIKE :q OR x.tax_id LIKE :q';$params['q']='%'.$q.'%';}
    $c=$db->prepare('SELECT COUNT(*) FROM ('.$base.') x'.$where);$c->execute($params);$total=(int)$c->fetchColumn();$pages=max(1,(int)ceil($total/$limit));$page=min($page,$pages);$offset=($page-1)*$limit;
    $r=$db->prepare('SELECT * FROM ('.$base.') x'.$where.' ORDER BY x.display_name LIMIT '.$limit.' OFFSET '.$offset);$r->execute($params);
    return ['rows'=>$r->fetchAll(PDO::FETCH_ASSOC)?:[],'total'=>$total,'page'=>$page,'pages'=>$pages,'limit'=>$limit,'start'=>$total?$offset+1:0,'end'=>min($total,$offset+$limit)];
}

function app_finance_active_counterparties(PDO $db, int $limit = 500): array
{
    app_finance_ensure_operational_schema($db);
    $limit=max(1,min(1000,$limit));
    $statement=$db->query('SELECT id,display_name,counterparty_type,tax_id FROM finance_counterparties WHERE status="active" ORDER BY display_name,id LIMIT '.$limit);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
