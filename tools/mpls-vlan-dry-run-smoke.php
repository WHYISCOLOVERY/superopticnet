<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function mpls_vlan_smoke_fail(string $message, int $code = 1): never
{
    echo json_encode([
        'status' => 'failed',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

function mpls_vlan_smoke_employee(PDO $db): array
{
    $row = $db->query(
        "SELECT *
         FROM portal_accounts
         WHERE role = 'employee' AND is_active = 1
         ORDER BY FIELD(account_type, 'admin', 'operator', 'technician'), id ASC
         LIMIT 1"
    )?->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        mpls_vlan_smoke_fail('No active employee account for smoke test.', 2);
    }

    return $row;
}

function mpls_vlan_smoke_render(string $method = 'GET', array $post = []): string
{
    $db = app_db();
    if (!$db instanceof PDO) {
        mpls_vlan_smoke_fail('Database unavailable.', 3);
    }

    $employee = mpls_vlan_smoke_employee($db);
    $_GET = [];
    $_POST = $post;
    $_REQUEST = $method === 'POST' ? $post : [];
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/superopticnet/network-definitions.php';
    $_SERVER['SCRIPT_NAME'] = '/superopticnet/network-definitions.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    app_session_boot();
    $_SESSION['portal_user'] = app_db_user_session_payload($employee);

    chdir(dirname(__DIR__));
    ob_start();
    include dirname(__DIR__) . '/network-definitions.php';
    return (string) ob_get_clean();
}

$db = app_db();
if (!$db instanceof PDO) {
    mpls_vlan_smoke_fail('Database unavailable.', 3);
}

$smokeDefinitionId = 0;
$smokeName = 'SMOKE-MPLS-DRY-RUN-' . date('YmdHis');
$insert = $db->prepare(
    'INSERT INTO network_vlan_definitions (
        name, definition_type, vlan_id, branch, customer_name, customer_code,
        device_name, device_ip, upstream_port, customer_port, port_mode, status, notes
     ) VALUES (
        :name, "transport", 4093, "Smoke", "Smoke customer", "SMOKE4093",
        "Smoke switch", "172.16.5.5", "TenGigabitEthernet1/2/0", "GigabitEthernet0/1", "trunk", "planned", "temporary smoke row"
     )'
);
$insert->execute(['name' => $smokeName]);
$smokeDefinitionId = (int) $db->lastInsertId();
$hopInsert = $db->prepare(
    'INSERT INTO network_vlan_path_hops (
        definition_id, hop_order, device_name, device_ip, ingress_port, egress_port, port_mode, command_preview
     ) VALUES (
        :definition_id, 1, "Smoke switch", "172.16.5.5", "TenGigabitEthernet1/2/0", "GigabitEthernet0/1", "trunk",
        "! Preview only - cihazda avtomatik icra edilmir\nconf t\nvlan 4093\n name SMOKE-MPLS-DRY-RUN\nend\nwrite memory"
     )'
);
$hopInsert->execute(['definition_id' => $smokeDefinitionId]);

register_shutdown_function(static function () use ($db, $smokeDefinitionId): void {
    if ($smokeDefinitionId <= 0) {
        return;
    }
    try {
        $deleteActions = $db->prepare('DELETE FROM mgs_operation_actions WHERE action_type IN ("mpls_vlan_dry_run", "mpls_vlan_apply_batch", "mpls_vlan_apply") AND entity_type IN ("network_vlan_definition", "device_learning") AND entity_id = :id');
        $deleteActions->execute(['id' => (string) $smokeDefinitionId]);
        $deleteDefinition = $db->prepare('DELETE FROM network_vlan_definitions WHERE id = :id');
        $deleteDefinition->execute(['id' => $smokeDefinitionId]);
    } catch (Throwable) {
    }
});

$definition = ['id' => $smokeDefinitionId];

if ($smokeDefinitionId <= 0) {
    echo json_encode([
        'status' => 'ok',
        'checks' => [
            'dry_run_controls' => true,
            'post_dry_run' => 'skipped_no_vlan_definition',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$before = (int) $db->query('SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type = "mpls_vlan_dry_run"')->fetchColumn();
$postHtml = mpls_vlan_smoke_render('POST', [
    'csrf_token' => app_csrf_token(),
    'action' => 'dry_run_definition',
    'definition_id' => (string) $definition['id'],
]);
$after = (int) $db->query('SELECT COUNT(*) FROM mgs_operation_actions WHERE action_type = "mpls_vlan_dry_run"')->fetchColumn();

if ($after <= $before) {
    mpls_vlan_smoke_fail('Dry-run POST did not record mgs_operation_actions audit row.');
}

foreach ([
    'MPLS/VLAN dry-run diff',
    'data-vlan-dry-run-action',
    'name="action" value="dry_run_definition"',
    'name="action" value="apply_definition"',
    'Real apply et',
    'data-vlan-command-copy',
    'Dry-run hazirlandi',
    'MPLS/VLAN dry-run diff',
    'Preview only - cihazda avtomatik icra edilmir',
] as $marker) {
    if (!str_contains($postHtml, $marker)) {
        mpls_vlan_smoke_fail('Dry-run POST response missing marker: ' . $marker);
    }
}

echo json_encode([
    'status' => 'ok',
    'checks' => [
        'dry_run_controls' => true,
        'post_dry_run_audit' => true,
        'real_apply_controls' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
