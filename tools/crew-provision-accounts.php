<?php
declare(strict_types=1);

/**
 * Provision AI-crew department SERVICE accounts (token-only, no password login).
 * CLI only. Idempotent. Creates: hr_position (module scope) + portal_accounts
 * employee (activated) + crew_service_tokens token. Prints each token ONCE.
 *
 * Usage:  php tools/crew-provision-accounts.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/hr-position-service.php';
require_once dirname(__DIR__) . '/hr-employee-service.php';
require_once dirname(__DIR__) . '/crew-service-auth-lib.php';

$db = app_db();
if (!$db instanceof PDO) {
    exit("DB unavailable.\n");
}

// Super-admin actor for the create functions.
$actor = ['id' => 0, 'role' => 'employee', 'account_type' => 'admin',
          'employee_role' => 'admin', 'full_name' => 'AI Provisioner', 'login' => 'ai-provisioner'];

// department => [label, login, module, employee_role]
$DEPTS = [
    'anbar'   => ['AI Anbar',           'ai-anbar',   'inventory', 'warehouse'],
    'sebeke'  => ['AI Şəbəkə',          'ai-sebeke',  'network',   'engineer'],
    'noc'     => ['AI NOC',             'ai-noc',     'statistics','noc'],
    'crm'     => ['AI CRM',             'ai-crm',     'crm',       'operator'],
    'maliyye' => ['AI Maliyyə',         'ai-maliyye', 'finance',   'finance'],
    'hr'      => ['AI HR',              'ai-hr',      'hr',        'hr'],
    'smm'     => ['AI SMM',             'ai-smm',     'messaging', 'sales'],
    'destek'  => ['AI Müştəri Dəstək',  'ai-destek',  'whatsapp_monitor', 'support'],
];

function find_position_id(PDO $db, string $title): int
{
    $s = $db->prepare('SELECT id FROM hr_positions WHERE title = :t LIMIT 1');
    $s->execute(['t' => $title]);
    return (int) $s->fetchColumn();
}

echo "=== AI-crew servis-hesab provisioning ===\n\n";
foreach ($DEPTS as $key => [$label, $login, $module, $role]) {
    $title = $label . ' (AI)';

    // 1) position
    $res = app_hr_save_position($db, [
        'title' => $title, 'change_reason' => 'AI crew service account provisioning',
        'recommended_account_type' => 'operator', 'recommended_employee_role' => $role,
        'module_keys' => [$module], 'is_active' => 1,
    ], $actor);
    $positionId = (int) ($res['position_id'] ?? 0);
    if ($positionId <= 0) {
        $positionId = find_position_id($db, $title);
    }
    if ($positionId <= 0) {
        echo "[$key] POSITION XETA: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
        continue;
    }

    // 2) employee account (idempotent by login)
    $account = app_db_account_by_login($login);
    if (is_array($account) && (int) ($account['id'] ?? 0) > 0) {
        $accountId = (int) $account['id'];
    } else {
        $emp = app_hr_create_employee($db, [
            'full_name' => $label, 'login' => $login, 'position_id' => $positionId,
        ], $actor);
        $accountId = (int) ($emp['account_id'] ?? 0);
        if ($accountId <= 0) {
            echo "[$key] EMPLOYEE XETA: " . json_encode($emp, JSON_UNESCAPED_UNICODE) . "\n";
            continue;
        }
    }

    // 3) activate + set employee_role (module access derives from role).
    //    Token-only account: random password already set by create → no human login.
    $db->prepare("UPDATE portal_accounts SET is_active = 1, status = 'Aktiv', employee_role = :role WHERE id = :id")
        ->execute(['role' => $role, 'id' => $accountId]);

    // 4) issue token
    $token = app_crew_service_issue_token($db, $accountId, $key, $label);

    echo str_pad($key, 9) . " | login=" . str_pad($login, 12)
        . " | modul=" . str_pad($module, 16) . " | account_id=$accountId\n";
    echo "  TOKEN: $token\n\n";
}
echo "Bitdi. Tokenləri agent .env-də dept=token kimi saxlayın.\n";
