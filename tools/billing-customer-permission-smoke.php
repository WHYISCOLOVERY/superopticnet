<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/billing-ui.php';

$cases = [
    'super admin can modify customer' => [
        ['role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'super_admin', 'login' => 's.abdullayev@superoptic.net', 'is_active' => 1],
        true,
    ],
    'admin can modify customer' => [
        ['role' => 'employee', 'account_type' => 'admin', 'employee_role' => 'admin', 'login' => 'ruslan.gulaliyev@superoptic.local', 'is_active' => 1],
        true,
    ],
    'operator is read only' => [
        ['role' => 'employee', 'account_type' => 'employee', 'employee_role' => 'operator'],
        false,
    ],
    'technician is read only' => [
        ['role' => 'employee', 'account_type' => 'employee', 'employee_role' => 'technician'],
        false,
    ],
    'team lead is read only' => [
        ['role' => 'employee', 'account_type' => 'employee', 'employee_role' => 'team_lead'],
        false,
    ],
];

$failures = [];
foreach ($cases as $label => [$actor, $expected]) {
    $actual = billing_actor_can_modify_customer($actor);
    if ($actual !== $expected) {
        $failures[] = $label . ': expected ' . ($expected ? 'true' : 'false') . ', got ' . ($actual ? 'true' : 'false');
    }
}

$networkCases = [
    'operator can manage IP and refresh' => [['employee_role' => 'operator'], true],
    'support can manage IP and refresh' => [['employee_role' => 'support'], true],
    'NOC can manage IP and refresh' => [['employee_role' => 'noc'], true],
    'technician cannot manage IP' => [['employee_role' => 'technician'], false],
];
foreach ($networkCases as $label => [$actor, $expected]) {
    $actual = billing_actor_can_manage_customer_network($actor);
    if ($actual !== $expected) {
        $failures[] = $label . ': expected ' . ($expected ? 'true' : 'false') . ', got ' . ($actual ? 'true' : 'false');
    }
}

$operator = ['role' => 'employee', 'account_type' => 'employee', 'employee_role' => 'operator', 'is_active' => 1];
if (!billing_actor_can_create_customer($operator)) {
    $failures[] = 'operator can create customer: expected true, got false';
}
if (!billing_actor_can_change_customer_package($operator)) {
    $failures[] = 'operator can change package: expected true, got false';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'billing-customer-permission-smoke: 11 passed' . PHP_EOL;
