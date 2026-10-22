<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

/**
 * One-time historical import from the separate Node "SuperOptic MGS" app's
 * database (olt_control_center) into superopticnet's native tables
 * (docs/39_MGS_NODE_CONSOLIDATION.md Faza 2). Idempotent (safe to re-run;
 * every insert is an upsert keyed on a stable source id) and dry-run by
 * default like every other worker in this codebase.
 *
 * Explicitly NOT migrated:
 * - Credentials (devices.ssh_password_encrypted, snmp_*_encrypted,
 *   olts.ssh_password_ciphertext, snmp_password_ciphertext): encrypted with
 *   the Node app's own SECRETS_MASTER_KEY, which superopticnet does not
 *   have and must not attempt to guess or share. An admin must re-enter
 *   SNMP/SSH credentials for any imported device that needs live probing,
 *   via the existing device_access_overrides / credential vault UI.
 * - interfaces -> device_interfaces: that table's if_index is meant to be
 *   the real SNMP ifIndex used by tools/poll-device-interface-traffic.php's
 *   GET requests. The Node interfaces table has no such numeric index;
 *   inventing one risks colliding with a real ifIndex discovered later by
 *   app_mgs_discover_device_interfaces(). Interface counts are folded into
 *   the parent device's features_json instead, as historical reference.
 */

function mgs_import_flag(string $flag): bool
{
    global $argv;
    return in_array('--' . $flag, array_map('strval', $argv), true);
}

function mgs_import_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    return $default;
}

if (mgs_import_flag('help')) {
    echo implode(PHP_EOL, [
        'Usage:',
        '  php tools/import-mgs-node-data.php [--apply] [--only=devices,alarms,tickets,onts]',
        '',
        'Default mode is dry-run: reports what would be imported/updated without writing.',
        '--apply  Actually write to superopticnet tables.',
        '--only   Comma-separated subset of: devices, alarms, tickets, onts. Default: all.',
        '--source-user / --source-pass  Credentials for olt_control_center (default: root/"").',
        '',
        'Source: olt_control_center (the separate Node MGS app database). Safe to run',
        'repeatedly -- every write is an upsert keyed on a stable "mgs-<source id>" key.',
        '',
    ]) . PHP_EOL;
    exit(0);
}

$apply = mgs_import_flag('apply');
$only = array_filter(array_map('trim', explode(',', (string) mgs_import_arg('only', 'devices,alarms,tickets,onts'))));

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "superopticnet database is not available.\n");
    exit(2);
}

$settings = app_db_settings();
// superopticnet's own app DB user is intentionally scoped to only its own
// database (least privilege), so it has no grant on olt_control_center.
// Rather than widen that standing grant just for a one-time local import,
// this accepts separate source credentials (defaulting to the local dev
// root account this machine's other olt_control_center access already uses).
$sourceUser = (string) mgs_import_arg('source-user', 'root');
$sourcePass = (string) mgs_import_arg('source-pass', '');
try {
    $sourceDb = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=olt_control_center;charset=%s', $settings['host'], $settings['port'], $settings['charset']),
        $sourceUser,
        $sourcePass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'olt_control_center source database is not available: ' . $exception->getMessage() . "\n");
    fwrite(STDERR, "If this is not a local/root-accessible MySQL, pass --source-user=... --source-pass=...\n");
    exit(2);
}

function mgs_import_device_key(string $sourceId): string
{
    return 'mgs-' . $sourceId;
}

/**
 * portal_tickets.ticket_no and mgs_alarm_events.ticket_no are both
 * VARCHAR(32). Node ticket ids are "ticket-<20 hex chars>" (27 chars); a
 * naive "LEGACY-MGS-" . $id prefix (11 + 27 = 38 chars) silently truncates
 * on insert, which both breaks the exists-check this import relies on for
 * idempotency (comparing the untruncated value against what MySQL actually
 * stored) and risks collisions between different source tickets whose
 * truncated prefixes match. Stripping the source's own redundant "ticket-"
 * prefix keeps the result at 31 chars, comfortably under the limit.
 */
function mgs_import_ticket_no(string $sourceId): string
{
    $shortId = str_starts_with($sourceId, 'ticket-') ? substr($sourceId, strlen('ticket-')) : $sourceId;

    return mb_substr('LEGACY-MGS-' . $shortId, 0, 32);
}

/**
 * Resolves the device_key a source row should import as. Prefers (in
 * order): (1) a device_learning_profiles row this same import already
 * created for this source id (device_key = 'mgs-'.$sourceId -- stable,
 * idempotent), (2) a pre-existing NATIVE row (device_key NOT starting with
 * 'mgs-') at the same IP -- superopticnet already seeds a handful of known
 * real Cisco/HSGQ devices with proper vendor adapters
 * (app_mgs_default_device_profiles()), and the Node source happens to
 * track several of the same physical devices; reusing the native key
 * avoids creating an inferior GenericSnmpAdapter duplicate of a device
 * that already has a real CiscoRouterAdapter/CiscoSwitchAdapter/
 * HSGQOltAdapter row, (3) otherwise a fresh 'mgs-'.$sourceId key.
 */
function mgs_import_resolve_device_key(PDO $db, string $sourceId, string $ip): array
{
    $ownKey = mgs_import_device_key($sourceId);
    $ownCheck = $db->prepare('SELECT id FROM device_learning_profiles WHERE device_key = :device_key');
    $ownCheck->execute(['device_key' => $ownKey]);
    if ($ownCheck->fetchColumn() !== false) {
        return [$ownKey, false];
    }

    if ($ip !== '') {
        $nativeCheck = $db->prepare(
            'SELECT device_key FROM device_learning_profiles WHERE ip_address = :ip AND device_key NOT LIKE "mgs-%" LIMIT 1'
        );
        $nativeCheck->execute(['ip' => $ip]);
        $nativeKey = $nativeCheck->fetchColumn();
        if (is_string($nativeKey) && $nativeKey !== '') {
            return [$nativeKey, true];
        }
    }

    return [$ownKey, false];
}

function mgs_import_devices(PDO $sourceDb, PDO $db, bool $apply): array
{
    $summary = ['ok' => true, 'source_rows' => 0, 'created' => 0, 'updated' => 0, 'merged_into_native' => 0, 'samples' => [], 'key_map' => []];

    $upsert = $db->prepare(
        'INSERT INTO device_learning_profiles
            (device_key, device_name, ip_address, device_type, vendor, model, management_network,
             expected_reachability, monitoring_enabled, reachability_state, status, last_discovered_at, features_json)
         VALUES
            (:device_key, :device_name, :ip_address, :device_type, :vendor, :model, "public",
             :expected_reachability, 0, "unknown", "pending_discovery", :last_discovered_at, :features_json)
         ON DUPLICATE KEY UPDATE
            device_name = VALUES(device_name),
            ip_address = VALUES(ip_address),
            device_type = VALUES(device_type),
            vendor = VALUES(vendor),
            model = VALUES(model),
            expected_reachability = VALUES(expected_reachability),
            last_discovered_at = VALUES(last_discovered_at),
            features_json = VALUES(features_json)'
    );
    $nativeRowFetch = $db->prepare('SELECT id, features_json, last_discovered_at FROM device_learning_profiles WHERE device_key = :device_key');
    $nativeMerge = $db->prepare(
        'UPDATE device_learning_profiles
         SET features_json = :features_json,
             last_discovered_at = COALESCE(last_discovered_at, :last_discovered_at)
         WHERE device_key = :device_key'
    );
    $existsCheck = $db->prepare('SELECT id FROM device_learning_profiles WHERE device_key = :device_key');
    $interfaceCounts = $sourceDb->query('SELECT device_id, COUNT(*) AS c FROM interfaces GROUP BY device_id')->fetchAll(PDO::FETCH_KEY_PAIR);

    // Pass 1: devices (broadest set -- routers/servers/switches/OLTs).
    // Pass 2: olts (richer OLT-specific fields; upserts over any of the same
    // 3 physical OLTs devices already inserted, since ids match 1:1 -- see
    // docs/39_MGS_NODE_CONSOLIDATION.md Faza 2 for why this ordering is safe).
    foreach (['devices' => null, 'olts' => 'management_host'] as $table => $ipColumn) {
        $rows = $sourceDb->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $summary['source_rows']++;
            $sourceId = (string) $row['id'];
            $ip = $ipColumn !== null ? (string) ($row[$ipColumn] ?? '') : (string) ($row['ip_address'] ?? '');
            $deviceType = $table === 'olts' ? 'olt' : (string) ($row['device_type'] ?? 'unknown');
            [$deviceKey, $isNativeMatch] = mgs_import_resolve_device_key($db, $sourceId, $ip);
            $summary['key_map'][$sourceId] = $deviceKey;

            $legacyRef = [
                'legacy_source' => 'mgs_node_' . $table,
                'source_id' => $sourceId,
                'source_location' => (string) ($row['location'] ?? ''),
                'source_interface_count' => (int) ($interfaceCounts[$sourceId] ?? 0),
                'source_status_or_driver_mode' => (string) ($row['status'] ?? $row['driver_mode'] ?? ''),
                'imported_at' => date('c'),
            ];

            if (count($summary['samples']) < 6) {
                $summary['samples'][] = ['device_key' => $deviceKey, 'device_name' => (string) $row['name'], 'ip_address' => $ip, 'device_type' => $deviceType, 'matched_native' => $isNativeMatch];
            }
            if (!$apply) {
                continue;
            }

            if ($isNativeMatch) {
                // Native row is authoritative (real adapter_key, real
                // capability list) -- only attach the cross-reference,
                // never overwrite its vendor/model/adapter data.
                $nativeRowFetch->execute(['device_key' => $deviceKey]);
                $nativeRow = $nativeRowFetch->fetch(PDO::FETCH_ASSOC);
                if (is_array($nativeRow)) {
                    $existingFeatures = app_mgs_json_decode_array($nativeRow['features_json'] ?? null);
                    $existingFeatures['legacy_mgs_import'] = $legacyRef;
                    $nativeMerge->execute([
                        'features_json' => app_db_json_encode($existingFeatures),
                        'last_discovered_at' => $row['last_discovered_at'] ?? $row['created_at'] ?? null,
                        'device_key' => $deviceKey,
                    ]);
                    $summary['merged_into_native']++;
                }
                continue;
            }

            $existsCheck->execute(['device_key' => $deviceKey]);
            $isNew = $existsCheck->fetchColumn() === false;
            $upsert->execute([
                'device_key' => $deviceKey,
                'device_name' => (string) $row['name'],
                'ip_address' => $ip,
                'device_type' => $deviceType,
                'vendor' => (string) ($row['vendor'] ?? ''),
                'model' => (string) ($row['model'] ?? ''),
                'expected_reachability' => !empty($row['expected_reachability'] ?? 1) ? 1 : 0,
                'last_discovered_at' => $row['last_discovered_at'] ?? $row['created_at'] ?? null,
                'features_json' => app_db_json_encode(['legacy_mgs_import' => $legacyRef]),
            ]);
            if ($isNew) {
                $summary['created']++;
            } else {
                $summary['updated']++;
            }
        }
    }

    return $summary;
}

function mgs_import_alarm_status(string $sourceStatus): string
{
    return match ($sourceStatus) {
        'acknowledged' => 'ack',
        'resolved_pending_confirmation' => 'ack',
        'closed' => 'resolved',
        'false_positive' => 'suppressed',
        default => 'open', // open, in_progress
    };
}

function mgs_import_alarms(PDO $sourceDb, PDO $db, bool $apply, array $deviceKeyMap = []): array
{
    $summary = ['ok' => true, 'alarms_source_rows' => 0, 'alarms_created' => 0, 'alarms_updated' => 0, 'state_events_source_rows' => 0, 'state_events_created' => 0, 'samples' => []];

    $upsertAlarm = $db->prepare(
        'INSERT INTO mgs_alarm_events
            (dedup_key, alarm_type, severity, source, object_type, object_id, title, message, status, ticket_no,
             telegram_bot_key, first_seen_at, last_seen_at, seen_count, payload_json)
         VALUES
            (:dedup_key, :alarm_type, :severity, "mgs_legacy_import", "device", :object_id, :title, :message, :status, :ticket_no,
             "network", :first_seen_at, :last_seen_at, :seen_count, :payload_json)
         ON DUPLICATE KEY UPDATE
            object_id = VALUES(object_id),
            severity = VALUES(severity),
            title = VALUES(title),
            message = VALUES(message),
            status = VALUES(status),
            ticket_no = VALUES(ticket_no),
            last_seen_at = VALUES(last_seen_at),
            seen_count = VALUES(seen_count),
            payload_json = VALUES(payload_json)'
    );
    $existsAlarmCheck = $db->prepare('SELECT id FROM mgs_alarm_events WHERE dedup_key = :dedup_key');
    $alarmIdLookup = $db->prepare('SELECT id FROM mgs_alarm_events WHERE dedup_key = :dedup_key');

    $rows = $sourceDb->query('SELECT * FROM alarms')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $summary['alarms_source_rows']++;
        $dedupKey = 'mgs-legacy-' . (string) $row['dedupe_key'];
        $ticketNo = !empty($row['ticket_id']) ? mgs_import_ticket_no((string) $row['ticket_id']) : null;

        if (count($summary['samples']) < 6) {
            $summary['samples'][] = ['dedup_key' => $dedupKey, 'title' => (string) $row['title'], 'severity' => (string) $row['severity'], 'status' => (string) $row['status']];
        }
        if (!$apply) {
            continue;
        }
        $existsAlarmCheck->execute(['dedup_key' => $dedupKey]);
        $isNew = $existsAlarmCheck->fetchColumn() === false;
        $upsertAlarm->execute([
            'dedup_key' => $dedupKey,
            'alarm_type' => (string) ($row['alarm_type'] ?? 'unknown'),
            'severity' => in_array($row['severity'], ['info', 'warning', 'critical'], true) ? $row['severity'] : 'info',
            'object_id' => $deviceKeyMap[(string) $row['device_id']] ?? mgs_import_device_key((string) $row['device_id']),
            'title' => (string) $row['title'],
            'message' => (string) ($row['description'] ?? ''),
            'status' => mgs_import_alarm_status((string) $row['status']),
            'ticket_no' => $ticketNo,
            'first_seen_at' => $row['first_seen_at'],
            'last_seen_at' => $row['last_seen_at'],
            'seen_count' => max(1, (int) ($row['occurrences'] ?? 1)),
            'payload_json' => app_db_json_encode(['legacy_source' => 'mgs_node_alarms', 'source_id' => $row['id'], 'raw' => $row]),
        ]);
        if ($isNew) {
            $summary['alarms_created']++;
        } else {
            $summary['alarms_updated']++;
        }
    }

    if (!$apply) {
        $summary['state_events_source_rows'] = (int) $sourceDb->query('SELECT COUNT(*) FROM alarm_state_events')->fetchColumn();

        return $summary;
    }

    // Second pass for state history: needs the now-upserted alarm ids.
    // mgs_alarm_state_events (Faza 1) has no unique constraint of its own --
    // it is an append-only log fed by live alarm traffic, where every insert
    // is a genuinely new event. A bulk historical import is the one case
    // that needs re-run safety, so this checks for an existing row with the
    // same (alarm_event_id, created_at, to_status) before inserting rather
    // than relying on a DB-level constraint.
    $insertEvent = $db->prepare(
        'INSERT INTO mgs_alarm_state_events
            (alarm_event_id, dedup_key, from_status, to_status, from_severity, to_severity, seen_count, test_mode, created_at)
         VALUES (:alarm_event_id, :dedup_key, :from_status, :to_status, :from_severity, :to_severity, 1, 0, :created_at)'
    );
    $eventExistsCheck = $db->prepare(
        'SELECT id FROM mgs_alarm_state_events WHERE alarm_event_id = :alarm_event_id AND created_at = :created_at AND to_status = :to_status LIMIT 1'
    );
    $stateRows = $sourceDb->query(
        'SELECT e.*, a.dedupe_key AS alarm_dedupe_key
         FROM alarm_state_events e
         INNER JOIN alarms a ON a.id = e.alarm_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stateRows as $row) {
        $summary['state_events_source_rows']++;
        $dedupKey = 'mgs-legacy-' . (string) $row['alarm_dedupe_key'];
        $alarmIdLookup->execute(['dedup_key' => $dedupKey]);
        $alarmId = $alarmIdLookup->fetchColumn();
        if ($alarmId === false) {
            continue;
        }
        $toStatus = mgs_import_alarm_status((string) ($row['to_status'] ?? 'open'));
        $eventExistsCheck->execute(['alarm_event_id' => (int) $alarmId, 'created_at' => $row['created_at'], 'to_status' => $toStatus]);
        if ($eventExistsCheck->fetchColumn() !== false) {
            continue;
        }
        $insertEvent->execute([
            'alarm_event_id' => (int) $alarmId,
            'dedup_key' => $dedupKey,
            'from_status' => (string) ($row['from_status'] ?? '') !== '' ? mgs_import_alarm_status((string) $row['from_status']) : null,
            'to_status' => $toStatus,
            'from_severity' => null,
            'to_severity' => in_array($row['severity'], ['info', 'warning', 'critical'], true) ? $row['severity'] : 'info',
            'created_at' => $row['created_at'],
        ]);
        $summary['state_events_created']++;
    }

    return $summary;
}

function mgs_import_ticket_status(string $sourceStatus): string
{
    return match ($sourceStatus) {
        'closed' => 'Baglandi',
        'false_positive' => 'Baglandi',
        'resolved_pending_confirmation' => 'Tesdiqlendi',
        default => 'Aciqdir', // open, acknowledged, in_progress
    };
}

function mgs_import_tickets(PDO $sourceDb, PDO $db, bool $apply, array $deviceKeyMap = []): array
{
    $summary = ['ok' => true, 'source_rows' => 0, 'created' => 0, 'updated' => 0, 'samples' => []];

    $upsert = $db->prepare(
        'INSERT INTO portal_tickets
            (ticket_no, customer_name, area, subject, message, status, opened_at, updated_at, resolved_at,
             resolution_reason, created_by_role, created_by_name, metadata_json)
         VALUES
            (:ticket_no, :customer_name, :area, :subject, :message, :status, :opened_at, :updated_at, :resolved_at,
             :resolution_reason, "mgs_legacy_import", :created_by_name, :metadata_json)
         ON DUPLICATE KEY UPDATE
            customer_name = VALUES(customer_name),
            area = VALUES(area),
            subject = VALUES(subject),
            message = VALUES(message),
            status = VALUES(status),
            updated_at = VALUES(updated_at),
            resolved_at = VALUES(resolved_at),
            resolution_reason = VALUES(resolution_reason),
            metadata_json = VALUES(metadata_json)'
    );
    $existsCheck = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no');

    $rows = $sourceDb->query('SELECT * FROM tickets')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $summary['source_rows']++;
        $ticketNo = mgs_import_ticket_no((string) $row['id']);
        $status = mgs_import_ticket_status((string) $row['status']);
        $customerName = trim((string) ($row['scope_label'] ?? '')) !== '' ? (string) $row['scope_label'] : 'Şəbəkə insidenti';

        if (count($summary['samples']) < 6) {
            $summary['samples'][] = ['ticket_no' => $ticketNo, 'subject' => mb_substr((string) $row['title'], 0, 60), 'status' => $status];
        }
        if (!$apply) {
            continue;
        }
        $existsCheck->execute(['ticket_no' => $ticketNo]);
        $isNew = $existsCheck->fetchColumn() === false;
        $upsert->execute([
            'ticket_no' => $ticketNo,
            'customer_name' => mb_substr($customerName, 0, 191),
            'area' => mb_substr((string) ($row['scope_type'] ?? ''), 0, 128),
            'subject' => mb_substr((string) $row['title'], 0, 128) ?: 'Legacy MGS insident',
            'message' => mb_substr((string) ($row['description'] ?? $row['title']), 0, 255),
            'status' => $status,
            'opened_at' => $row['created_at'],
            'updated_at' => $row['updated_at'] ?? $row['created_at'],
            'resolved_at' => $row['resolved_at'],
            'resolution_reason' => mb_substr((string) ($row['resolution_reason'] ?? ''), 0, 191),
            'created_by_name' => mb_substr((string) ($row['assigned_to'] ?? ''), 0, 191),
            'metadata_json' => app_db_json_encode(['legacy_source' => 'mgs_node_tickets', 'source_id' => $row['id'], 'device_key' => $row['device_id'] !== null ? ($deviceKeyMap[(string) $row['device_id']] ?? mgs_import_device_key((string) $row['device_id'])) : null, 'raw' => $row]),
        ]);
        if ($isNew) {
            $summary['created']++;
        } else {
            $summary['updated']++;
        }
    }

    return $summary;
}

function mgs_import_onts(PDO $sourceDb, PDO $db, bool $apply, array $deviceKeyMap = []): array
{
    $summary = ['ok' => true, 'source_rows' => 0, 'created' => 0, 'updated' => 0, 'samples' => []];

    $hasTable = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "legacy_mgs_ont_inventory"'
    )->fetchColumn() > 0;
    if (!$hasTable) {
        return ['ok' => false, 'reason' => 'legacy_mgs_ont_inventory_not_migrated'];
    }

    $upsert = $db->prepare(
        'INSERT INTO legacy_mgs_ont_inventory
            (source_ont_id, olt_device_key, port_key, serial_number, status, rx_dbm, tx_dbm, olt_rx_dbm, distance_meters, ont_vendor, source_updated_at)
         VALUES
            (:source_ont_id, :olt_device_key, :port_key, :serial_number, :status, :rx_dbm, :tx_dbm, :olt_rx_dbm, :distance_meters, :ont_vendor, :source_updated_at)
         ON DUPLICATE KEY UPDATE
            olt_device_key = VALUES(olt_device_key),
            port_key = VALUES(port_key),
            serial_number = VALUES(serial_number),
            status = VALUES(status),
            rx_dbm = VALUES(rx_dbm),
            tx_dbm = VALUES(tx_dbm),
            olt_rx_dbm = VALUES(olt_rx_dbm),
            distance_meters = VALUES(distance_meters),
            ont_vendor = VALUES(ont_vendor),
            source_updated_at = VALUES(source_updated_at)'
    );
    $existsCheck = $db->prepare('SELECT id FROM legacy_mgs_ont_inventory WHERE source_ont_id = :source_ont_id');

    $rows = $sourceDb->query('SELECT * FROM onts')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $summary['source_rows']++;
        if (count($summary['samples']) < 6) {
            $summary['samples'][] = ['source_ont_id' => (string) $row['id'], 'serial' => (string) $row['serial'], 'status' => (string) $row['status']];
        }
        if (!$apply) {
            continue;
        }
        $existsCheck->execute(['source_ont_id' => (string) $row['id']]);
        $isNew = $existsCheck->fetchColumn() === false;
        $upsert->execute([
            'source_ont_id' => (string) $row['id'],
            'olt_device_key' => $deviceKeyMap[(string) $row['olt_id']] ?? mgs_import_device_key((string) $row['olt_id']),
            'port_key' => (string) ($row['port_key'] ?? ''),
            'serial_number' => (string) ($row['serial'] ?? ''),
            'status' => (string) ($row['status'] ?? 'unknown'),
            'rx_dbm' => $row['rx'] ?? null,
            'tx_dbm' => $row['tx'] ?? null,
            'olt_rx_dbm' => $row['olt_rx'] ?? null,
            'distance_meters' => (int) ($row['distance_meters'] ?? 0),
            'ont_vendor' => (string) ($row['ont_vendor'] ?? ''),
            'source_updated_at' => $row['updated_at'] ?? null,
        ]);
        if ($isNew) {
            $summary['created']++;
        } else {
            $summary['updated']++;
        }
    }

    return $summary;
}

$result = ['ok' => true, 'apply' => $apply, 'only' => array_values($only)];

// Always resolved (read-only unless --only includes "devices"), so
// alarms/tickets/onts consistently reference the same canonical device_key
// a devices-only run would have picked -- otherwise running e.g.
// --only=alarms in isolation would silently mint the old naive
// 'mgs-'.$sourceId keys instead of reusing a matching native device row.
$devicesResult = mgs_import_devices($sourceDb, $db, $apply && in_array('devices', $only, true));
$deviceKeyMap = is_array($devicesResult['key_map'] ?? null) ? $devicesResult['key_map'] : [];
if (in_array('devices', $only, true)) {
    $result['devices'] = $devicesResult;
}
if (in_array('alarms', $only, true)) {
    $result['alarms'] = mgs_import_alarms($sourceDb, $db, $apply, $deviceKeyMap);
}
if (in_array('tickets', $only, true)) {
    $result['tickets'] = mgs_import_tickets($sourceDb, $db, $apply, $deviceKeyMap);
}
if (in_array('onts', $only, true)) {
    $result['onts'] = mgs_import_onts($sourceDb, $db, $apply, $deviceKeyMap);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
