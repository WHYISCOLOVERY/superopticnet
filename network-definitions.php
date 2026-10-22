<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';

$lang = app_lang();
$user = billing_require_employee(true);
$db = app_db();
$flash = null;
$dryRunPreview = null;

function network_def_cidr_to_netmask(string $cidr): string
{
    if (preg_match('/\/(\d{1,2})$/', trim($cidr), $match) !== 1) {
        return '';
    }

    $bits = max(0, min(32, (int) $match[1]));
    $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
    return long2ip($mask) ?: '';
}

function network_def_command_preview(array $definition, array $hop = []): string
{
    $vlanId = (int) ($definition['vlan_id'] ?? 0);
    $name = trim((string) ($definition['name'] ?? 'SERVICE'));
    $mode = trim((string) ($hop['port_mode'] ?? $definition['port_mode'] ?? 'access'));
    $ingress = trim((string) ($hop['ingress_port'] ?? $definition['upstream_port'] ?? ''));
    $egress = trim((string) ($hop['egress_port'] ?? $definition['customer_port'] ?? ''));
    $subnet = trim((string) ($definition['subnet_cidr'] ?? ''));
    $gateway = trim((string) ($definition['gateway_ip'] ?? ''));
    $netmask = network_def_cidr_to_netmask($subnet);

    $lines = [
        '! Preview only - cihazda avtomatik icra edilmir',
        'conf t',
        'vlan ' . $vlanId,
        ' name ' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name),
    ];

    if ($ingress !== '') {
        $lines[] = 'interface ' . $ingress;
        if ($mode === 'routed' && $gateway !== '') {
            $lines[] = ' encapsulation dot1Q ' . $vlanId;
            $lines[] = ' ip address ' . $gateway . ($netmask !== '' ? ' ' . $netmask : ' <netmask>');
        } else {
            $lines[] = ' switchport mode trunk';
            $lines[] = ' switchport trunk allowed vlan add ' . $vlanId;
        }
    }

    if ($egress !== '') {
        $lines[] = 'interface ' . $egress;
        if ($mode === 'access') {
            $lines[] = ' switchport mode access';
            $lines[] = ' switchport access vlan ' . $vlanId;
        } elseif ($mode === 'hybrid') {
            $lines[] = ' switchport mode hybrid';
            $lines[] = ' switchport hybrid allowed vlan add ' . $vlanId;
        } elseif ($mode === 'trunk') {
            $lines[] = ' switchport mode trunk';
            $lines[] = ' switchport trunk allowed vlan add ' . $vlanId;
        }
    }

    $lines[] = 'end';
    $lines[] = 'write memory';

    return implode("\n", $lines);
}

function network_def_parse_hops(string $text): array
{
    $rows = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $rows[] = [
            'device_name' => $parts[0] ?? '',
            'device_ip' => $parts[1] ?? '',
            'ingress_port' => $parts[2] ?? '',
            'egress_port' => $parts[3] ?? '',
            'port_mode' => in_array(($parts[4] ?? 'trunk'), ['access', 'trunk', 'hybrid', 'routed'], true) ? $parts[4] : 'trunk',
        ];
    }

    return $rows;
}

function network_def_dry_run_payload(array $definition, array $hops): array
{
    $vlanId = (int) ($definition['vlan_id'] ?? 0);
    $commands = [];
    $warnings = [];

    if ($hops === []) {
        $hops[] = [
            'hop_order' => 1,
            'device_name' => (string) ($definition['device_name'] ?? ''),
            'device_ip' => (string) ($definition['device_ip'] ?? ''),
            'ingress_port' => (string) ($definition['upstream_port'] ?? ''),
            'egress_port' => (string) ($definition['customer_port'] ?? ''),
            'port_mode' => (string) ($definition['port_mode'] ?? 'access'),
            'command_preview' => network_def_command_preview($definition),
        ];
    }

    foreach ($hops as $index => $hop) {
        $command = trim((string) ($hop['command_preview'] ?? ''));
        if ($command === '') {
            $command = network_def_command_preview($definition, $hop);
        }

        $commands[] = [
            'hop_order' => (int) ($hop['hop_order'] ?? ($index + 1)),
            'device_name' => (string) ($hop['device_name'] ?? ''),
            'device_ip' => (string) ($hop['device_ip'] ?? ''),
            'ingress_port' => (string) ($hop['ingress_port'] ?? ''),
            'egress_port' => (string) ($hop['egress_port'] ?? ''),
            'port_mode' => (string) ($hop['port_mode'] ?? $definition['port_mode'] ?? 'access'),
            'command_preview' => $command,
        ];
    }

    $hasDeviceIp = false;
    $hasPorts = false;
    foreach ($commands as $command) {
        $hasDeviceIp = $hasDeviceIp || trim((string) $command['device_ip']) !== '';
        $hasPorts = $hasPorts || trim((string) $command['ingress_port']) !== '' || trim((string) $command['egress_port']) !== '';
    }

    if ($vlanId < 1 || $vlanId > 4094) {
        $warnings[] = 'VLAN ID 1-4094 araliginda deyil.';
    }
    if (!$hasDeviceIp) {
        $warnings[] = 'Hech bir hop ucun cihaz IP-si yazilmayib.';
    }
    if (!$hasPorts) {
        $warnings[] = 'Hoplarda ingress/egress port melumati yoxdur.';
    }

    return [
        'mode' => 'dry-run',
        'safety' => [
            'device_write' => false,
            'requires_admin_apply' => true,
            'requires_device_reachability' => true,
            'note' => 'Preview and audit only; no SSH/SNMP write is executed.',
        ],
        'definition' => [
            'id' => (int) ($definition['id'] ?? 0),
            'name' => (string) ($definition['name'] ?? ''),
            'vlan_id' => $vlanId,
            'status' => (string) ($definition['status'] ?? ''),
            'customer_code' => (string) ($definition['customer_code'] ?? ''),
            'customer_name' => (string) ($definition['customer_name'] ?? ''),
            'branch' => (string) ($definition['branch'] ?? ''),
            'definition_type' => (string) ($definition['definition_type'] ?? ''),
        ],
        'checks' => [
            ['name' => 'VLAN ID', 'status' => ($vlanId >= 1 && $vlanId <= 4094) ? 'ok' : 'warning'],
            ['name' => 'Hop count', 'status' => count($commands) > 0 ? 'ok' : 'warning'],
            ['name' => 'Device IP', 'status' => $hasDeviceIp ? 'ok' : 'warning'],
            ['name' => 'Port mapping', 'status' => $hasPorts ? 'ok' : 'warning'],
            ['name' => 'Device write', 'status' => 'blocked_by_design'],
        ],
        'diff' => [
            'Would validate VLAN ' . $vlanId . ' on ' . count($commands) . ' hop(s).',
            'Would stage command preview for engineering approval.',
            'Would require explicit apply adapter before any device write.',
        ],
        'warnings' => $warnings,
        'commands' => $commands,
    ];
}

function network_def_device_for_hop(array $hop, ?PDO $db = null): ?array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return null;
    }

    $ip = trim((string) ($hop['device_ip'] ?? ''));
    $name = trim((string) ($hop['device_name'] ?? ''));
    if ($ip !== '') {
        $statement = $db->prepare('SELECT * FROM device_learning_profiles WHERE ip_address = :ip LIMIT 1');
        $statement->execute(['ip' => $ip]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    if ($name !== '') {
        $statement = $db->prepare(
            'SELECT *
             FROM device_learning_profiles
             WHERE device_name = :name OR device_key = :name
             ORDER BY id
             LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    return null;
}

function network_def_apply_definition(array $definition, array $hops, array $actor, PDO $db, bool $allowDangerous): array
{
    $preview = network_def_dry_run_payload($definition, $hops);
    $results = [];
    $allOk = true;

    foreach (($preview['commands'] ?? []) as $command) {
        $device = network_def_device_for_hop((array) $command, $db);
        if (!is_array($device)) {
            $allOk = false;
            $results[] = [
                'ok' => false,
                'reason' => 'device_profile_not_found',
                'hop' => $command,
            ];
            continue;
        }

        $result = app_mgs_execute_device_command_plan(
            $device,
            app_mgs_command_list_from_text((string) ($command['command_preview'] ?? '')),
            $actor,
            $db,
            [
                'allow_dangerous' => $allowDangerous,
                'action_type' => 'mpls_vlan_apply',
                'timeout_ms' => 15000,
            ]
        );
        if (empty($result['ok'])) {
            $allOk = false;
        }
        $results[] = [
            'ok' => !empty($result['ok']),
            'reason' => (string) ($result['reason'] ?? ''),
            'device_key' => (string) ($device['device_key'] ?? ''),
            'device_ip' => (string) ($device['ip_address'] ?? ''),
            'hop_order' => (int) ($command['hop_order'] ?? 0),
            'result' => $result,
        ];
    }

    $payload = [
        'definition_id' => (int) ($definition['id'] ?? 0),
        'vlan_id' => (int) ($definition['vlan_id'] ?? 0),
        'allow_dangerous' => $allowDangerous,
        'preview' => $preview,
        'results' => $results,
    ];
    app_mgs_record_operation_action([
        'action_type' => 'mpls_vlan_apply_batch',
        'entity_type' => 'network_vlan_definition',
        'entity_id' => (string) ($definition['id'] ?? ''),
        'status' => $allOk ? 'executed' : 'failed',
        'actor' => $actor,
        'summary' => 'MPLS/VLAN real apply: VLAN ' . (string) ($definition['vlan_id'] ?? '') . ' ' . (string) ($definition['name'] ?? ''),
        'payload' => $payload,
    ]);

    $statement = $db->prepare(
        'UPDATE network_vlan_definitions
         SET status = :status, metadata_json = :metadata_json, updated_at = NOW()
         WHERE id = :id'
    );
    $statement->execute([
        'status' => $allOk ? 'active' : 'failed',
        'metadata_json' => app_db_json_encode(['last_apply' => $payload]),
        'id' => (int) ($definition['id'] ?? 0),
    ]);

    return [
        'ok' => $allOk,
        'reason' => $allOk ? '' : 'one_or_more_hops_failed',
        'results' => $results,
        'preview' => $preview,
    ];
}

if ($db instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasÄ± uÄŸursuz oldu.'];
    } elseif ($action === 'create_definition') {
        $name = clean_text((string) ($_POST['name'] ?? ''));
        $vlanId = (int) ($_POST['vlan_id'] ?? 0);
        $definitionType = clean_text((string) ($_POST['definition_type'] ?? 'internet'));
        $portMode = clean_text((string) ($_POST['port_mode'] ?? 'access'));
        $ipPoolId = (int) ($_POST['ip_pool_id'] ?? 0);
        $validTypes = ['internet', 'management', 'iptv', 'voice', 'transport', 'other'];
        $validModes = ['access', 'trunk', 'hybrid', 'routed'];

        if ($name === '' || $vlanId < 1 || $vlanId > 4094) {
            $flash = ['type' => 'error', 'message' => 'Ad vÉ™ VLAN ID (1-4094) mÃ¼tlÉ™q dÃ¼zgÃ¼n yazÄ±lmalÄ±dÄ±r.'];
        } else {
            $definition = [
                'name' => $name,
                'definition_type' => in_array($definitionType, $validTypes, true) ? $definitionType : 'internet',
                'vlan_id' => $vlanId,
                'branch' => clean_text((string) ($_POST['branch'] ?? '')),
                'customer_name' => clean_text((string) ($_POST['customer_name'] ?? '')),
                'customer_code' => clean_text((string) ($_POST['customer_code'] ?? '')),
                'customer_voen' => clean_text((string) ($_POST['customer_voen'] ?? '')),
                'device_name' => clean_text((string) ($_POST['device_name'] ?? '')),
                'device_ip' => clean_text((string) ($_POST['device_ip'] ?? '')),
                'upstream_port' => clean_text((string) ($_POST['upstream_port'] ?? '')),
                'customer_port' => clean_text((string) ($_POST['customer_port'] ?? '')),
                'port_mode' => in_array($portMode, $validModes, true) ? $portMode : 'access',
                'ip_pool_id' => $ipPoolId > 0 ? $ipPoolId : null,
                'subnet_cidr' => clean_text((string) ($_POST['subnet_cidr'] ?? '')),
                'gateway_ip' => clean_text((string) ($_POST['gateway_ip'] ?? '')),
                'customer_ip' => clean_text((string) ($_POST['customer_ip'] ?? '')),
                'status' => 'planned',
                'notes' => trim((string) ($_POST['notes'] ?? '')),
                'metadata_json' => app_db_json_encode([
                    'safety' => 'preview_only',
                    'created_from' => 'network-definitions.php',
                ]),
                'created_by_account_id' => (int) ($user['id'] ?? 0) > 0 ? (int) $user['id'] : null,
            ];

            $statement = $db->prepare(
                'INSERT INTO network_vlan_definitions (
                    name, definition_type, vlan_id, branch, customer_name, customer_code, customer_voen,
                    device_name, device_ip, upstream_port, customer_port, port_mode, ip_pool_id,
                    subnet_cidr, gateway_ip, customer_ip, status, notes, metadata_json, created_by_account_id
                 ) VALUES (
                    :name, :definition_type, :vlan_id, :branch, :customer_name, :customer_code, :customer_voen,
                    :device_name, :device_ip, :upstream_port, :customer_port, :port_mode, :ip_pool_id,
                    :subnet_cidr, :gateway_ip, :customer_ip, :status, :notes, :metadata_json, :created_by_account_id
                 )'
            );
            $statement->execute($definition);
            $definitionId = (int) $db->lastInsertId();
            $hops = network_def_parse_hops((string) ($_POST['hops'] ?? ''));
            if ($hops === [] && ($definition['device_name'] !== '' || $definition['device_ip'] !== '')) {
                $hops[] = [
                    'device_name' => $definition['device_name'],
                    'device_ip' => $definition['device_ip'],
                    'ingress_port' => $definition['upstream_port'],
                    'egress_port' => $definition['customer_port'],
                    'port_mode' => $definition['port_mode'],
                ];
            }

            if ($hops !== []) {
                $hopStatement = $db->prepare(
                    'INSERT INTO network_vlan_path_hops (
                        definition_id, hop_order, device_name, device_ip, ingress_port, egress_port, port_mode, command_preview
                     ) VALUES (
                        :definition_id, :hop_order, :device_name, :device_ip, :ingress_port, :egress_port, :port_mode, :command_preview
                     )'
                );
                foreach ($hops as $index => $hop) {
                    $hopStatement->execute([
                        'definition_id' => $definitionId,
                        'hop_order' => $index + 1,
                        'device_name' => clean_text((string) ($hop['device_name'] ?? '')),
                        'device_ip' => clean_text((string) ($hop['device_ip'] ?? '')),
                        'ingress_port' => clean_text((string) ($hop['ingress_port'] ?? '')),
                        'egress_port' => clean_text((string) ($hop['egress_port'] ?? '')),
                        'port_mode' => clean_text((string) ($hop['port_mode'] ?? 'trunk')),
                        'command_preview' => network_def_command_preview($definition, $hop),
                    ]);
                }
            }

            $flash = ['type' => 'success', 'message' => 'VLAN/MPLS tÉ™rifi plan kimi saxlanÄ±ldÄ±. Cihazlara komanda gÃ¶ndÉ™rilmÉ™di.'];
        }
    } elseif ($action === 'mark_removed') {
        $id = (int) ($_POST['definition_id'] ?? 0);
        if ($id > 0) {
            $statement = $db->prepare('UPDATE network_vlan_definitions SET status = "removed", updated_at = NOW() WHERE id = :id');
            $statement->execute(['id' => $id]);
            $flash = ['type' => 'success', 'message' => 'TÉ™rif removed statusuna keÃ§irildi. Real cihazlarda silmÉ™ komandasÄ± icra edilmÉ™di.'];
        }
    } elseif ($action === 'dry_run_definition') {
        $id = (int) ($_POST['definition_id'] ?? 0);
        if ($id <= 0) {
            $flash = ['type' => 'error', 'message' => 'Dry-run ucun VLAN plan ID tapilmadi.'];
        } else {
            $statement = $db->prepare(
                'SELECT d.*, p.name AS pool_name, p.range_start, p.range_end, p.gateway AS pool_gateway
                 FROM network_vlan_definitions d
                 LEFT JOIN billing_ip_pools p ON p.id = d.ip_pool_id
                 WHERE d.id = :id
                 LIMIT 1'
            );
            $statement->execute(['id' => $id]);
            $definition = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($definition)) {
                $flash = ['type' => 'error', 'message' => 'VLAN plan tapilmadi.'];
            } else {
                $hopStatement = $db->prepare(
                    'SELECT * FROM network_vlan_path_hops
                     WHERE definition_id = :id
                     ORDER BY hop_order'
                );
                $hopStatement->execute(['id' => $id]);
                $hops = $hopStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $dryRunPreview = network_def_dry_run_payload($definition, $hops);
                $auditOk = app_mgs_record_operation_action([
                    'action_type' => 'mpls_vlan_dry_run',
                    'entity_type' => 'network_vlan_definition',
                    'entity_id' => (string) $id,
                    'status' => 'dry_run',
                    'actor' => $user,
                    'summary' => 'MPLS/VLAN dry-run: VLAN ' . (string) ($definition['vlan_id'] ?? '') . ' ' . (string) ($definition['name'] ?? ''),
                    'payload' => $dryRunPreview,
                ]);
                $flash = [
                    'type' => $auditOk ? 'success' : 'error',
                    'message' => $auditOk
                        ? 'Dry-run hazirlandi. Cihazlara komanda gonderilmedi; netice audit jurnalina yazildi.'
                        : 'Dry-run hazirlandi, amma audit jurnali yazilmadi.',
                ];
            }
        }
    } elseif ($action === 'apply_definition') {
        $id = (int) ($_POST['definition_id'] ?? 0);
        $allowDangerous = isset($_POST['allow_dangerous']);
        if (!app_portal_is_admin($user)) {
            $flash = ['type' => 'error', 'message' => 'Real cihaz apply ucun admin icazesi lazimdir.'];
        } elseif ($id <= 0) {
            $flash = ['type' => 'error', 'message' => 'Apply ucun VLAN plan ID tapilmadi.'];
        } elseif (!$allowDangerous) {
            $flash = ['type' => 'error', 'message' => 'Real cihazlara yazmaq ucun risk tesdiqi secilmelidir.'];
        } else {
            $statement = $db->prepare(
                'SELECT d.*, p.name AS pool_name, p.range_start, p.range_end, p.gateway AS pool_gateway
                 FROM network_vlan_definitions d
                 LEFT JOIN billing_ip_pools p ON p.id = d.ip_pool_id
                 WHERE d.id = :id
                 LIMIT 1'
            );
            $statement->execute(['id' => $id]);
            $definition = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($definition)) {
                $flash = ['type' => 'error', 'message' => 'VLAN plan tapilmadi.'];
            } else {
                $hopStatement = $db->prepare(
                    'SELECT * FROM network_vlan_path_hops
                     WHERE definition_id = :id
                     ORDER BY hop_order'
                );
                $hopStatement->execute(['id' => $id]);
                $hops = $hopStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $applyResult = network_def_apply_definition($definition, $hops, $user, $db, true);
                $dryRunPreview = $applyResult['preview'] ?? null;
                $failed = array_values(array_filter((array) ($applyResult['results'] ?? []), static fn (array $row): bool => empty($row['ok'])));
                $flash = [
                    'type' => !empty($applyResult['ok']) ? 'success' : 'error',
                    'message' => !empty($applyResult['ok'])
                        ? 'Real MPLS/VLAN apply tamamlandi ve audit yazildi.'
                        : ('Real apply tamamlanmadi: ' . (string) ($failed[0]['reason'] ?? $applyResult['reason'] ?? 'unknown')),
                ];
            }
        }
    }
}

$query = clean_text((string) ($_GET['q'] ?? ''));
$status = clean_text((string) ($_GET['status'] ?? ''));
$stats = ['total' => 0, 'planned' => 0, 'active' => 0, 'unique_vlan' => 0];
$pools = [];
$definitions = [];
$hopsByDefinition = [];

if ($db instanceof PDO) {
    $stats['total'] = (int) $db->query('SELECT COUNT(*) FROM network_vlan_definitions')->fetchColumn();
    $stats['planned'] = (int) $db->query('SELECT COUNT(*) FROM network_vlan_definitions WHERE status IN ("draft","planned")')->fetchColumn();
    $stats['active'] = (int) $db->query('SELECT COUNT(*) FROM network_vlan_definitions WHERE status = "active"')->fetchColumn();
    $stats['unique_vlan'] = (int) $db->query('SELECT COUNT(DISTINCT vlan_id) FROM network_vlan_definitions')->fetchColumn();
    $pools = $db->query('SELECT * FROM billing_ip_pools WHERE status = "active" ORDER BY service_type, pool_type, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $where = ['1=1'];
    $params = [];
    if ($query !== '') {
        $where[] = '(d.name LIKE :q OR d.customer_name LIKE :q OR d.customer_code LIKE :q OR d.customer_voen LIKE :q OR d.device_name LIKE :q OR d.device_ip LIKE :q OR CAST(d.vlan_id AS CHAR) LIKE :q)';
        $params['q'] = '%' . $query . '%';
    }
    if ($status !== '') {
        $where[] = 'd.status = :status';
        $params['status'] = $status;
    }

    $statement = $db->prepare(
        'SELECT d.*, p.name AS pool_name, p.range_start, p.range_end, p.gateway AS pool_gateway
         FROM network_vlan_definitions d
         LEFT JOIN billing_ip_pools p ON p.id = d.ip_pool_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY d.updated_at DESC, d.id DESC
         LIMIT 100'
    );
    $statement->execute($params);
    $definitions = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $definitionIds = array_map(static fn (array $row): int => (int) $row['id'], $definitions);
    if ($definitionIds !== []) {
        $placeholders = implode(',', array_fill(0, count($definitionIds), '?'));
        $hopStatement = $db->prepare(
            'SELECT * FROM network_vlan_path_hops
             WHERE definition_id IN (' . $placeholders . ')
             ORDER BY definition_id, hop_order'
        );
        $hopStatement->execute($definitionIds);
        foreach ($hopStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $hopRow) {
            $hopsByDefinition[(int) $hopRow['definition_id']][] = $hopRow;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLAN/MPLS definitions</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .definition-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
            gap: 16px;
        }
        .definition-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
            padding: 18px;
        }
        .definition-title {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }
        .definition-title h3 {
            margin: 0 0 6px;
        }
        .definition-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0;
        }
        .definition-meta span {
            border-radius: 999px;
            background: #f1f5f9;
            color: #334155;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 800;
        }
        .hop-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }
        .hop-card {
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            padding: 12px;
        }
        .hop-card code {
            display: block;
            white-space: pre-wrap;
            margin-top: 8px;
            font-size: 12px;
            line-height: 1.45;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 12px;
            padding: 10px;
            overflow-x: auto;
        }
        .definition-warning {
            border-left: 4px solid #f97316;
            background: #fff7ed;
            color: #9a3412;
            padding: 12px 14px;
            border-radius: 14px;
            font-weight: 700;
        }
        .vlan-dry-run-panel {
            display: grid;
            gap: 14px;
        }
        .vlan-dry-run-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .vlan-dry-run-box {
            border: 1px solid #dbeafe;
            border-radius: 18px;
            background: #f8fbff;
            padding: 14px;
        }
        .vlan-dry-run-box strong {
            display: block;
            color: #10233f;
            margin-bottom: 6px;
        }
        .vlan-dry-run-command {
            display: block;
            white-space: pre-wrap;
            word-break: break-word;
            border-radius: 16px;
            background: #0f172a;
            color: #e2e8f0;
            padding: 14px;
            margin-top: 10px;
            font-size: 12px;
            line-height: 1.5;
            overflow-x: auto;
        }
        .vlan-action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('VLAN/MPLS definitions', 'network-definitions', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">NETWORK / MPLS</span>
                <h1>VLAN, IP pool vÉ™ hop planlama</h1>
                <p>Core router, switch vÉ™ OLT Ã¼zÉ™rindÉ™n keÃ§É™n VLAN xidmÉ™tlÉ™rini bir yerdÉ™ saxlayÄ±rÄ±q. Bu mÉ™rhÉ™lÉ™ real cihazlara toxunmadan plan, port mode vÉ™ command preview yaradÄ±r.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="#create-definition">Yeni VLAN planÄ±</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-billing.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>#pools">IP poollar</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Plan</span>
                <strong><?= e((string) $stats['planned']); ?></strong>
                <small>draft/planned tÉ™rif</small>
                <span>Aktiv</span>
                <strong><?= e((string) $stats['active']); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('CÉ™mi tÉ™rif', (string) $stats['total'], 'network_vlan_definitions', 'fa-diagram-project', 'blue'); ?>
            <?php billing_render_stat_card('Planlanan', (string) $stats['planned'], 'icra gÃ¶zlÉ™yir', 'fa-list-check', 'orange'); ?>
            <?php billing_render_stat_card('Aktiv', (string) $stats['active'], 'manual tÉ™sdiq edilmiÅŸ', 'fa-circle-check', 'green'); ?>
            <?php billing_render_stat_card('Unikal VLAN', (string) $stats['unique_vlan'], 'VLAN ID sayÄ±', 'fa-hashtag', 'navy'); ?>
        </section>

        <section class="portal-card portal-reveal" id="mpls-vlan-dry-run">
            <div class="portal-card-head">
                <h2>MPLS/VLAN dry-run diff</h2>
                <small>Bu blok yalniz auditli preview yaradir. Real cihaz yazisi yoxdur; apply adapter ayrica admin tesdiqi isteyecek.</small>
            </div>
            <div class="vlan-dry-run-panel">
                <?php if (is_array($dryRunPreview)): ?>
                    <div class="vlan-dry-run-grid">
                        <?php foreach (($dryRunPreview['checks'] ?? []) as $check): ?>
                            <div class="vlan-dry-run-box">
                                <strong><?= e((string) ($check['name'] ?? '-')); ?></strong>
                                <span class="portal-pill"><?= e((string) ($check['status'] ?? '-')); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="vlan-dry-run-grid">
                        <div class="vlan-dry-run-box">
                            <strong>Diff</strong>
                            <?php foreach (($dryRunPreview['diff'] ?? []) as $line): ?>
                                <p><?= e((string) $line); ?></p>
                            <?php endforeach; ?>
                        </div>
                        <div class="vlan-dry-run-box">
                            <strong>Safety</strong>
                            <p>Device write: <?= !empty($dryRunPreview['safety']['device_write']) ? 'enabled' : 'blocked'; ?></p>
                            <p><?= e((string) ($dryRunPreview['safety']['note'] ?? '')); ?></p>
                        </div>
                    </div>
                    <?php if (!empty($dryRunPreview['warnings'])): ?>
                        <div class="definition-warning">
                            <?= e(implode(' ', array_map('strval', $dryRunPreview['warnings']))); ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach (($dryRunPreview['commands'] ?? []) as $command): ?>
                        <div class="vlan-dry-run-box">
                            <strong>#<?= e((string) ($command['hop_order'] ?? '-')); ?> <?= e((string) ($command['device_name'] ?? '-')); ?> <?= e((string) ($command['device_ip'] ?? '')); ?></strong>
                            <code class="vlan-dry-run-command" data-vlan-command-copy><?= e((string) ($command['command_preview'] ?? '')); ?></code>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="vlan-dry-run-box">
                        <strong>Dry-run gozleyir</strong>
                        <p>Asagidaki VLAN kartinda "Dry-run diff yarat" duymesine basin. Netice hem burada gosterilecek, hem de SPoG audit jurnalina yazilacaq.</p>
                        <code class="vlan-dry-run-command" data-vlan-command-copy>Dry-run secilmeyib - cihazlara yazili komanda gonderilmir.</code>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="portal-card portal-reveal" id="create-definition">
            <div class="portal-card-head">
                <h2>Yeni VLAN/MPLS tÉ™rifi</h2>
                <small>Bu forma yalnÄ±z plan saxlayÄ±r. Real SSH/SNMP provisioning ayrÄ±ca auditli icra mÉ™rhÉ™lÉ™sindÉ™ aktiv edilÉ™cÉ™k.</small>
            </div>
            <?php if (!$db instanceof PDO): ?>
                <?php billing_render_empty_state('DB hazÄ±r deyil', 'VLAN tÉ™rifi yaratmaq Ã¼Ã§Ã¼n MySQL baÄŸlantÄ±sÄ± aktiv olmalÄ±dÄ±r.', 'fa-database'); ?>
            <?php else: ?>
                <form method="post" class="portal-stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_definition">
                    <div class="definition-warning">TÉ™hlÃ¼kÉ™sizlik qaydasÄ±: bu sÉ™hifÉ™ hÉ™lÉ™ cihazlara komanda gÃ¶ndÉ™rmir. Komanda preview yalnÄ±z mÃ¼hÉ™ndis yoxlamasÄ± Ã¼Ã§Ã¼ndÃ¼r.</div>
                    <div class="billing-filter-grid">
                        <div class="portal-form-row"><label>XidmÉ™t adÄ±</label><input type="text" name="name" required placeholder="KÃ¼rdÉ™xanÄ± ayrÄ±lmÄ±ÅŸ internet"></div>
                        <div class="portal-form-row"><label>VLAN ID</label><input type="number" name="vlan_id" min="1" max="4094" required placeholder="1100"></div>
                        <div class="portal-form-row">
                            <label>NÃ¶v</label>
                            <select name="definition_type">
                                <option value="internet">Internet</option>
                                <option value="management">Management</option>
                                <option value="transport">Transport/MPLS</option>
                                <option value="iptv">IPTV</option>
                                <option value="voice">Voice</option>
                                <option value="other">DigÉ™r</option>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <label>Port mode</label>
                            <select name="port_mode">
                                <option value="access">Access</option>
                                <option value="trunk">Trunk</option>
                                <option value="hybrid">Hybrid</option>
                                <option value="routed">Routed sub-interface</option>
                            </select>
                        </div>
                        <div class="portal-form-row"><label>Filial/É™razi</label><input type="text" name="branch" placeholder="KÃ¼rdÉ™xanÄ±"></div>
                        <div class="portal-form-row"><label>MÃ¼ÅŸtÉ™ri adÄ± / ÅŸirkÉ™t</label><input type="text" name="customer_name" placeholder="ÅirkÉ™t vÉ™ ya ad soyad"></div>
                        <div class="portal-form-row"><label>MÃ¼ÅŸtÉ™ri kodu</label><input type="text" name="customer_code" placeholder="mÉ™s: 2320025"></div>
                        <div class="portal-form-row"><label>VÃ–EN</label><input type="text" name="customer_voen" placeholder="varsa"></div>
                        <div class="portal-form-row"><label>Æsas cihaz</label><input type="text" name="device_name" placeholder="Kurdexani 21 OLT"></div>
                        <div class="portal-form-row"><label>Cihaz IP</label><input type="text" name="device_ip" placeholder="172.16.5.21"></div>
                        <div class="portal-form-row"><label>Router/uplink port</label><input type="text" name="upstream_port" placeholder="XGE01 / Te1/2/0.1100"></div>
                        <div class="portal-form-row"><label>MÃ¼ÅŸtÉ™ri portu</label><input type="text" name="customer_port" placeholder="GE02"></div>
                        <div class="portal-form-row">
                            <label>IP pool</label>
                            <select name="ip_pool_id">
                                <option value="0">Pool seÃ§ilmÉ™yib</option>
                                <?php foreach ($pools as $pool): ?>
                                    <option value="<?= e((string) $pool['id']); ?>"><?= e((string) $pool['service_type']); ?> / <?= e((string) $pool['name']); ?> / <?= e((string) $pool['range_start']); ?>-<?= e((string) $pool['range_end']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row"><label>Subnet CIDR</label><input type="text" name="subnet_cidr" placeholder="94.20.46.60/30"></div>
                        <div class="portal-form-row"><label>Gateway IP</label><input type="text" name="gateway_ip" placeholder="/30 birinci IP"></div>
                        <div class="portal-form-row"><label>MÃ¼ÅŸtÉ™ri IP</label><input type="text" name="customer_ip" placeholder="/30 ikinci IP"></div>
                    </div>
                    <div class="portal-form-row">
                        <label>Hop xÉ™tti</label>
                        <textarea name="hops" rows="4" placeholder="HÉ™r sÉ™tir: cihaz adÄ± | IP | giriÅŸ portu | Ã§Ä±xÄ±ÅŸ portu | trunk/access/hybrid/routed"></textarea>
                    </div>
                    <div class="portal-form-row">
                        <label>Qeyd</label>
                        <textarea name="notes" rows="3" placeholder="MÃ¼hÉ™ndis qeydi, mÃ¼ÅŸtÉ™ri ÅŸÉ™rti, silinmÉ™ zamanÄ± nÉ™zÉ™rÉ™ alÄ±nacaq detal"></textarea>
                    </div>
                    <div class="portal-form-actions">
                        <button class="portal-primary-btn" type="submit">PlanÄ± saxla</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="mgs-filter-panel portal-reveal">
            <div class="mgs-section-head"><div><h2>AxtarÄ±ÅŸ</h2><p>VLAN ID, mÃ¼ÅŸtÉ™ri, cihaz, IP vÉ™ status Ã¼zrÉ™ filter.</p></div></div>
            <form method="get" class="portal-stacked-form">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row"><label>AxtarÄ±ÅŸ</label><input type="text" name="q" value="<?= e($query); ?>" placeholder="VLAN, mÃ¼ÅŸtÉ™ri, cihaz, IP"></div>
                    <div class="portal-form-row">
                        <label>Status</label>
                        <select name="status">
                            <option value="">HamÄ±sÄ±</option>
                            <?php foreach (['draft', 'planned', 'active', 'removing', 'removed', 'failed'] as $option): ?>
                                <option value="<?= e($option); ?>" <?= $status === $option ? 'selected' : ''; ?>><?= e($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="portal-form-actions">
                    <button class="portal-primary-btn" type="submit">FiltrlÉ™</button>
                    <a class="portal-ghost-btn portal-inline-link" href="network-definitions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">SÄ±fÄ±rla</a>
                </div>
            </form>
        </section>

        <section class="portal-reveal">
            <div class="mgs-section-head">
                <div>
                    <h2>VLAN/MPLS planlarÄ±</h2>
                    <p>Son 100 plan. HÉ™r planÄ±n hop-larÄ± vÉ™ command preview-lÉ™ri ayrÄ±ca saxlanÄ±lÄ±r.</p>
                </div>
            </div>
            <?php if ($definitions === []): ?>
                <?php billing_render_empty_state('VLAN tÉ™rifi yoxdur', 'Ä°lk VLAN/MPLS planÄ±nÄ± yuxarÄ±dakÄ± formadan É™lavÉ™ edin.', 'fa-diagram-project'); ?>
            <?php else: ?>
                <div class="definition-card-grid">
                    <?php foreach ($definitions as $definition): ?>
                        <?php $definitionHops = $hopsByDefinition[(int) $definition['id']] ?? []; ?>
                        <article class="definition-card">
                            <div class="definition-title">
                                <div>
                                    <h3>VLAN <?= e((string) $definition['vlan_id']); ?> - <?= e((string) $definition['name']); ?></h3>
                                    <span><?= e((string) ($definition['branch'] ?: '-')); ?> / <?= e((string) ($definition['definition_type'] ?? 'internet')); ?></span>
                                </div>
                                <span class="portal-pill"><?= e((string) $definition['status']); ?></span>
                            </div>
                            <div class="definition-meta">
                                <span>Cihaz: <?= e((string) ($definition['device_name'] ?: '-')); ?></span>
                                <span>IP: <?= e((string) ($definition['device_ip'] ?: '-')); ?></span>
                                <span>Mode: <?= e((string) $definition['port_mode']); ?></span>
                                <span>Pool: <?= e((string) ($definition['pool_name'] ?: '-')); ?></span>
                                <span>MÃ¼ÅŸtÉ™ri: <?= e((string) (($definition['customer_code'] ?: '') . ($definition['customer_name'] ? ' / ' . $definition['customer_name'] : '')) ?: '-'); ?></span>
                            </div>
                            <p><strong>IP plan:</strong> <?= e((string) ($definition['subnet_cidr'] ?: '-')); ?> | Gateway: <?= e((string) ($definition['gateway_ip'] ?: '-')); ?> | MÃ¼ÅŸtÉ™ri: <?= e((string) ($definition['customer_ip'] ?: '-')); ?></p>
                            <?php if (trim((string) ($definition['notes'] ?? '')) !== ''): ?>
                                <p><?= e((string) $definition['notes']); ?></p>
                            <?php endif; ?>
                            <div class="hop-list">
                                <?php if ($definitionHops === []): ?>
                                    <div class="hop-card">Hop É™lavÉ™ edilmÉ™yib.</div>
                                <?php endif; ?>
                                <?php foreach ($definitionHops as $hop): ?>
                                    <div class="hop-card">
                                        <strong>#<?= e((string) $hop['hop_order']); ?> <?= e((string) ($hop['device_name'] ?: '-')); ?> <?= e((string) ($hop['device_ip'] ?: '')); ?></strong>
                                        <span><?= e((string) ($hop['ingress_port'] ?: '-')); ?> â†’ <?= e((string) ($hop['egress_port'] ?: '-')); ?> / <?= e((string) $hop['port_mode']); ?></span>
                                        <code><?= e((string) ($hop['command_preview'] ?? '')); ?></code>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="vlan-action-row">
                                <form method="post" class="portal-inline-form" data-vlan-dry-run-action>
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="dry_run_definition">
                                    <input type="hidden" name="definition_id" value="<?= e((string) $definition['id']); ?>">
                                    <button class="portal-primary-btn" type="submit">Dry-run diff yarat</button>
                                </form>
                                <?php if (app_portal_is_admin($user)): ?>
                                    <form method="post" class="portal-inline-form" onsubmit="return confirm('Real cihazlara VLAN/MPLS command yazilsin? VLAN 550 yoxdursa internal cihazlar failure qaytaracaq.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="apply_definition">
                                        <input type="hidden" name="definition_id" value="<?= e((string) $definition['id']); ?>">
                                        <label class="portal-inline-check"><input type="checkbox" name="allow_dangerous" value="1" required> real cihaz apply tesdiq</label>
                                        <button class="portal-ghost-btn" type="submit">Real apply et</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if (($definition['status'] ?? '') !== 'removed'): ?>
                                <form method="post" class="portal-inline-form" style="margin-top:12px">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="mark_removed">
                                    <input type="hidden" name="definition_id" value="<?= e((string) $definition['id']); ?>">
                                    <button class="portal-ghost-btn" type="submit">PlanÄ± removed et</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
