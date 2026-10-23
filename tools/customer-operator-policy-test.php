<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/customer-operator-policy.php';
function app_portal_is_admin(array $a): bool { return in_array($a['employee_role'] ?? '', ['admin','super_admin'], true); }
function app_portal_is_super_admin(array $a): bool { return ($a['employee_role'] ?? '') === 'super_admin'; }
function app_audit_ensure_schema(PDO $db): void {}
function app_audit_record(PDO $db, array $e): array { return $e; }
function app_db_account_payload(array $r): array { return json_decode($r['payload_json'], true); }
function app_db_json_encode(array $v): string { return json_encode($v, JSON_THROW_ON_ERROR); }
function normalize_phone(string $v): string { return preg_replace('/[\s()-]/', '', $v); }
function app_billing_parse_money_amount(mixed $v): float { return (float) $v; }
function app_billing_payload_primary_expires_at(array $p, array $b): string { return $b['expires_at'] ?? ''; }
function app_billing_refresh_payload_totals(array $a, array $p, ?array $t): array { $p['billing']['internet_monthly_fee'] = number_format((float) ($t['monthly_fee'] ?? 0), 2, '.', '') . ' AZN'; return $p; }
function app_billing_sync_radius_account(array $r, mixed $p, PDO $db): bool { return empty($GLOBALS['fail_sync']); }
class PolicyTestDb extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','NOW()'], ['',"datetime('now')"], $query), $options);
    }
}
$db = new PolicyTestDb('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE portal_accounts(id INTEGER PRIMARY KEY,role TEXT,phone TEXT,plan TEXT,payload_json TEXT,updated_at TEXT,status TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE billing_tariffs(id INTEGER PRIMARY KEY,name TEXT,service_type TEXT,monthly_fee REAL,status TEXT)');
$db->exec('INSERT INTO billing_tariffs VALUES(5,"Test 100","pppoe",25.00,"active")');
$db->prepare('INSERT INTO portal_accounts VALUES(1,"customer","+994501234567","Old plan",?,NULL,"Aktiv",1)')->execute([json_encode(['billing'=>['service_type'=>'pppoe','tariff'=>'Old plan','expires_at'=>'2020-01-01 00:00:00'],'profile'=>['full_name'=>'Original','address'=>'Original','fin_code'=>'ABC1234'],'internet_services'=>[['service_type'=>'pppoe','tariff'=>'Old plan']]])]);
$actor = ['id'=>2,'role'=>'employee','employee_role'=>'operator'];
$count = 0;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); $GLOBALS['count']++; }
function change(array $input): array { static $seq=0; return app_customer_operator_change($GLOBALS['db'],1,$GLOBALS['actor'],$input + ['reason'=>'Test reason','request_id'=>'policy_request_'.str_pad((string) ++$seq,8,'0',STR_PAD_LEFT)]); }
check(!change(['action'=>'borrow_days','days'=>4])['ok'],'4 days rejected');
$GLOBALS['fail_sync']=true;
check(!change(['action'=>'borrow_days','days'=>3])['ok'],'sync failure rolls back');
$GLOBALS['fail_sync']=false;
check(change(['action'=>'borrow_days','days'=>3])['ok'],'3 days accepted');
$actor['id']=3;
check(!change(['action'=>'borrow_days','days'=>1])['ok'],'second operator cannot grant again');
$debt=['action'=>'debt','kind'=>'equipment','amount'=>'25.50','document_no'=>'TEST-123','request_id'=>'same_request_123456'];
check(change($debt)['ok'],'equipment debt accepted');
check(change($debt)['ok'],'retry accepted without duplicate');
$payload=json_decode($db->query('SELECT payload_json FROM portal_accounts')->fetchColumn(),true);
check($payload['billing']['debt_raw']==='25.50 AZN','debt counted once');
check($payload['billing']['borrowed_days_pending']===3,'pending days preserved');
$expiry=$payload['billing']['expires_at'];
check(change(['action'=>'cycle','mode'=>'stable','day'=>15])['ok'],'stable cycle accepted');
check(change(['action'=>'cycle','mode'=>'30_days'])['ok'],'30 day cycle accepted');
$payload=json_decode($db->query('SELECT payload_json FROM portal_accounts')->fetchColumn(),true);
check(!isset($payload['billing']['stable_due_day']) && $payload['billing']['expires_at']===$expiry,'cycle preserves expiry and clears anchor');
check(change(['action'=>'phone','phone'=>'+994509876543'])['ok'],'phone update accepted');
check(change(['action'=>'package','tariff_id'=>5])['ok'],'package change accepted');
$payload=json_decode($db->query('SELECT payload_json FROM portal_accounts')->fetchColumn(),true);
check($payload['billing']['tariff']==='Test 100' && $db->query('SELECT plan FROM portal_accounts')->fetchColumn()==='Test 100','package persisted and synced');
check(!app_customer_identity_change_allowed(['full_name'=>'Original'],['full_name'=>'Changed'],['employee_role'=>'admin']),'admin identity change denied');
check(app_customer_identity_change_allowed(['full_name'=>'Original'],['full_name'=>'Changed'],['employee_role'=>'super_admin']),'super admin identity change allowed');
$actor=['id'=>4,'role'=>'employee','employee_role'=>'technician','is_active'=>1];
check(app_customer_pppoe_allowed($actor),'technician pppoe access allowed');
$payload=json_decode($db->query('SELECT payload_json FROM portal_accounts')->fetchColumn(),true);
$payload['billing']['pppoe_username']='smoke-pppoe';
$db->prepare('UPDATE portal_accounts SET payload_json=? WHERE id=1')->execute([json_encode($payload)]);
check(change(['action'=>'reset_pppoe_password','pppoe_password'=>'TechPass123'])['ok'],'technician can reset pppoe password');
check(!change(['action'=>'phone','phone'=>'+994501111111'])['ok'],'technician cannot edit customer phone');
echo "Customer operator policy: $count checks passed (isolated SQLite).\n";
