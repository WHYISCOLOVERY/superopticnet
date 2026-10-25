<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function legacy_network_arg(string $name, string $default = ''): string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return substr((string) $argument, strlen($prefix));
        }
    }
    return $default;
}

function legacy_network_rows(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !is_array($decoded['rows'] ?? null)) {
        throw new RuntimeException('Invalid legacy export: ' . basename($path));
    }
    return $decoded['rows'];
}

$sourceDir = rtrim(legacy_network_arg('source-dir'), '/\\');
$apply = in_array('--apply', $argv, true);
if ($sourceDir === '' || !is_dir($sourceDir)) {
    fwrite(STDERR, "A valid --source-dir is required.\n");
    exit(2);
}

$nasRows = legacy_network_rows($sourceDir . '/adminer-radius-nas.json');
$definitionRows = legacy_network_rows($sourceDir . '/adminer-radius-definitions.json');
$mappingRows = legacy_network_rows($sourceDir . '/adminer-radius-port_mappings.json');
if (count($nasRows) < 1 || count($definitionRows) < 1 || count($mappingRows) < 1) {
    throw new RuntimeException('Legacy RADIUS topology export is incomplete.');
}
foreach ($nasRows as $row) {
    if (filter_var((string) ($row['nasname'] ?? ''), FILTER_VALIDATE_IP) === false
        || trim((string) ($row['secret'] ?? '')) === '') {
        throw new RuntimeException('Legacy NAS row is incomplete.');
    }
}

$result = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'nas' => count($nasRows),
    'definitions' => count($definitionRows),
    'port_mappings' => count($mappingRows),
];
if (!$apply) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$db = app_db();
if (!$db instanceof PDO) {
    throw new RuntimeException('Database is unavailable.');
}
foreach (['definitions', 'port_mappings'] as $requiredTable) {
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table_name'
    );
    $statement->execute(['table_name' => $requiredTable]);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Required migration table is missing: ' . $requiredTable);
    }
}

$db->beginTransaction();
try {
    $nas = $db->prepare(
        'INSERT INTO nas (nasname,shortname,type,ports,secret,server,community,description)
         VALUES (:nasname,:shortname,:type,:ports,:secret,:server,:community,:description)
         ON DUPLICATE KEY UPDATE shortname=VALUES(shortname),type=VALUES(type),ports=VALUES(ports),
           secret=VALUES(secret),server=VALUES(server),community=VALUES(community),description=VALUES(description)'
    );
    foreach ($nasRows as $row) {
        $nas->execute([
            'nasname' => $row['nasname'],
            'shortname' => $row['shortname'] ?: null,
            'type' => $row['type'] ?: 'other',
            'ports' => ($row['ports'] ?? '') !== '' ? (int) $row['ports'] : null,
            'secret' => $row['secret'],
            'server' => $row['server'] ?: null,
            'community' => $row['community'] ?: null,
            'description' => $row['description'] ?: null,
        ]);
    }

    $db->exec('DELETE FROM definitions');
    $definition = $db->prepare(
        'INSERT INTO definitions (id,attrtype,name,value,description,created_at,updated_at)
         VALUES (:id,:attrtype,:name,:value,:description,:created_at,:updated_at)'
    );
    foreach ($definitionRows as $row) {
        $definition->execute([
            'id' => (int) $row['id'],
            'attrtype' => $row['attrtype'],
            'name' => $row['name'],
            'value' => (string) ($row['value'] ?? ''),
            'description' => $row['description'] ?: null,
            'created_at' => $row['created_at'] ?: null,
            'updated_at' => $row['updated_at'] ?: null,
        ]);
    }

    $db->exec('DELETE FROM port_mappings');
    $mapping = $db->prepare(
        'INSERT INTO port_mappings (username,nasportid,interface_id,vlan_id,interface_name,vlan_name)
         VALUES (:username,:nasportid,:interface_id,:vlan_id,:interface_name,:vlan_name)'
    );
    foreach ($mappingRows as $row) {
        $mapping->execute([
            'username' => $row['username'],
            'nasportid' => $row['nasportid'],
            'interface_id' => $row['interface_id'] ?: null,
            'vlan_id' => ($row['vlan_id'] ?? '') !== '' ? (int) $row['vlan_id'] : null,
            'interface_name' => $row['interface_name'] ?: null,
            'vlan_name' => $row['vlan_name'] ?: null,
        ]);
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}

$result['stored'] = [
    'nas' => (int) $db->query('SELECT COUNT(*) FROM nas')->fetchColumn(),
    'definitions' => (int) $db->query('SELECT COUNT(*) FROM definitions')->fetchColumn(),
    'port_mappings' => (int) $db->query('SELECT COUNT(*) FROM port_mappings')->fetchColumn(),
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
