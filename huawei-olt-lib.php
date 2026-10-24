<?php
declare(strict_types=1);

/**
 * Stage 6 - Huawei SmartAX OLT/ONT command + SNMP matrix (staging library).
 *
 * Covers SmartAX MA5800-X7 (V100R024C10) and MA5608T (V800R019C10) plus the
 * Huawei ONTs EG8141A5 and EchoLife EG8245W5-6T. The library is pure data +
 * render/plan helpers: it produces the exact SNMP OID set and CLI command
 * sequences the platform will run once a real OLT is connected. It does NOT
 * open any transport by itself.
 *
 * Grounded in the official references catalogued in
 * docs/27_HUAWEI_MA5608T_MA5800X7.md:
 *   - MA5600T&MA5603T&MA5608T V800R019C10 Command Reference,
 *   - MA5800 V100R024C10 Command Reference,
 *   - Huawei CLI optical-power / ONT run-state / OLT fault articles.
 *
 * SNMP uses only standard MIB trees (MIB-II / IF-MIB / ENTITY-MIB). The Huawei
 * enterprise tree (1.3.6.1.4.1.2011) is intentionally excluded here, matching
 * the read-only adapter's security model.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feature-flags-lib.php';

/**
 * Supported OLT profiles.
 *
 * @return array<string,array<string,mixed>>
 */
function app_huawei_olt_profiles(): array
{
    return [
        'MA5800-X7' => [
            'model' => 'SmartAX MA5800-X7',
            'release' => 'V100R024C10',
            'family' => 'MA5800',
            'gpon_interface' => 'interface gpon 0/{slot}',
            'ont_addressing' => '{port} {ont}',
            'notes' => 'Chassis OLT; slot 1-2 control, service slots vary. GPON boards H901GPSFE/H902GPHFE.',
        ],
        'MA5608T' => [
            'model' => 'SmartAX MA5608T',
            'release' => 'V800R019C10',
            'family' => 'MA5600T',
            'gpon_interface' => 'interface gpon 0/{slot}',
            'ont_addressing' => '{port} {ont}',
            'notes' => 'Compact 2-slot OLT; control board MCUD/MCUD1, GPON board GPBD/GPFD.',
        ],
    ];
}

/**
 * Supported Huawei ONT models with their capabilities and provisioning hints.
 *
 * @return array<string,array<string,mixed>>
 */
function app_huawei_ont_models(): array
{
    return [
        'EG8141A5' => [
            'model' => 'Huawei EG8141A5',
            'type' => 'GPON HGU',
            'eth_ports' => 4,      // 1x GE + 3x FE
            'ge_ports' => 1,
            'fe_ports' => 3,
            'pots_ports' => 1,
            'wifi' => '2.4GHz',
            'usb' => false,
            'default_lineprofile' => 'HGU-LINE',
            'default_srvprofile' => 'HGU-SRV',
            'notes' => 'Ev üçün GPON HGU; router/bridge, tək bant Wi-Fi.',
        ],
        'EG8245W5-6T' => [
            'model' => 'Huawei EchoLife EG8245W5-6T',
            'type' => 'GPON HGU',
            'eth_ports' => 4,      // 4x GE
            'ge_ports' => 4,
            'fe_ports' => 0,
            'pots_ports' => 2,
            'wifi' => '2.4GHz+5GHz',
            'usb' => true,
            'default_lineprofile' => 'HGU-LINE',
            'default_srvprofile' => 'HGU-SRV-DB',
            'notes' => 'Dual-band Wi-Fi (802.11ac), 4xGE, 2xPOTS, USB.',
        ],
    ];
}

/**
 * Standard-MIB SNMP OID map for OLT reads. Scalars end in .0; table roots are
 * meant to be walked. No Huawei enterprise OIDs.
 *
 * @return array<string,array{oid:string,mode:string,label:string}>
 */
function app_huawei_snmp_oids(): array
{
    return [
        // MIB-II system (RFC 1213)
        'sys_descr' => ['oid' => '1.3.6.1.2.1.1.1.0', 'mode' => 'get', 'label' => 'Sistem təsviri / versiya'],
        'sys_object_id' => ['oid' => '1.3.6.1.2.1.1.2.0', 'mode' => 'get', 'label' => 'Sistem obyekt ID'],
        'sys_uptime' => ['oid' => '1.3.6.1.2.1.1.3.0', 'mode' => 'get', 'label' => 'Uptime'],
        'sys_name' => ['oid' => '1.3.6.1.2.1.1.5.0', 'mode' => 'get', 'label' => 'Sistem adı'],
        // IF-MIB (RFC 2863) — interface/uplink counters
        'if_descr' => ['oid' => '1.3.6.1.2.1.2.2.1.2', 'mode' => 'walk', 'label' => 'İnterfeys təsviri'],
        'if_oper_status' => ['oid' => '1.3.6.1.2.1.2.2.1.8', 'mode' => 'walk', 'label' => 'İnterfeys oper status'],
        'if_hc_in_octets' => ['oid' => '1.3.6.1.2.1.31.1.1.1.6', 'mode' => 'walk', 'label' => 'HC gələn oktet'],
        'if_hc_out_octets' => ['oid' => '1.3.6.1.2.1.31.1.1.1.10', 'mode' => 'walk', 'label' => 'HC gedən oktet'],
        // ENTITY-MIB (RFC 4133) — board/slot inventory
        'ent_phys_descr' => ['oid' => '1.3.6.1.2.1.47.1.1.1.1.2', 'mode' => 'walk', 'label' => 'Fiziki komponent (board) təsviri'],
        'ent_phys_class' => ['oid' => '1.3.6.1.2.1.47.1.1.1.1.5', 'mode' => 'walk', 'label' => 'Komponent sinfi'],
        'ent_phys_serial' => ['oid' => '1.3.6.1.2.1.47.1.1.1.1.11', 'mode' => 'walk', 'label' => 'Komponent seriya nömrəsi'],
    ];
}

/**
 * Categorized CLI command matrix. Templates use the placeholders
 * {frame} {slot} {port} {ont} {vlan} {gem} {uservlan} {sn} {lineprofile}
 * {srvprofile} {sportidx} {desc} {ethport}.
 *
 * Each command declares its intent category, whether it mutates the device
 * (dangerous -> requires explicit apply) and an expected output marker used to
 * verify the live result.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function app_huawei_command_matrix(): array
{
    return [
        // "OLT ilə bağlı məlumatları görürük?"
        'read_olt' => [
            ['key' => 'version', 'cmd' => 'display version', 'dangerous' => false, 'expect' => 'VERSION'],
            ['key' => 'board_all', 'cmd' => 'display board 0', 'dangerous' => false, 'expect' => 'Board'],
            ['key' => 'board_slot', 'cmd' => 'display board 0/{slot}', 'dangerous' => false, 'expect' => 'Port'],
            ['key' => 'alarm_active', 'cmd' => 'display alarm active', 'dangerous' => false, 'expect' => 'ALARM'],
            ['key' => 'port_state', 'cmd' => 'display port state 0/{slot}/{port}', 'dangerous' => false, 'expect' => 'State'],
            ['key' => 'sysman', 'cmd' => 'display sysman service state', 'dangerous' => false, 'expect' => 'State'],
        ],
        // "İstədiyimizi (ONT məlumatını) görürük?"
        'read_ont' => [
            ['key' => 'ont_info', 'cmd' => 'display ont info {port} {ont}', 'dangerous' => false, 'expect' => 'Run state', 'context' => 'gpon'],
            ['key' => 'ont_optical', 'cmd' => 'display ont optical-info {port} {ont}', 'dangerous' => false, 'expect' => 'Rx optical power', 'context' => 'gpon'],
            ['key' => 'ont_register', 'cmd' => 'display ont register-info {port} {ont}', 'dangerous' => false, 'expect' => 'SN', 'context' => 'gpon'],
            ['key' => 'ont_wan', 'cmd' => 'display ont wan-info {port} {ont}', 'dangerous' => false, 'expect' => 'IP', 'context' => 'gpon'],
            ['key' => 'ont_version', 'cmd' => 'display ont version {port} {ont}', 'dangerous' => false, 'expect' => 'Version', 'context' => 'gpon'],
            ['key' => 'ont_by_sn', 'cmd' => 'display ont info by-sn {sn}', 'dangerous' => false, 'expect' => 'F/S/P'],
        ],
        // "Dəyişiklik edirik? (ONT provisioning)"
        'config_change' => [
            ['key' => 'ont_add', 'cmd' => 'ont add {port} {ont} sn-auth "{sn}" omci ont-lineprofile-id {lineprofile} ont-srvprofile-id {srvprofile} desc "{desc}"', 'dangerous' => true, 'expect' => 'success', 'context' => 'gpon'],
            ['key' => 'ont_modify_desc', 'cmd' => 'ont modify {port} {ont} desc "{desc}"', 'dangerous' => true, 'expect' => 'success', 'context' => 'gpon'],
            ['key' => 'ont_native_vlan', 'cmd' => 'ont port native-vlan {port} {ont} eth {ethport} vlan {vlan}', 'dangerous' => true, 'expect' => 'success', 'context' => 'gpon'],
            ['key' => 'save', 'cmd' => 'save', 'dangerous' => true, 'expect' => 'saved'],
        ],
        // "VLAN ötürə bilirik? (service-port / vlan)"
        'vlan_push' => [
            ['key' => 'vlan_create', 'cmd' => 'vlan {vlan} smart', 'dangerous' => true, 'expect' => 'success', 'context' => 'config'],
            ['key' => 'port_vlan_bind', 'cmd' => 'port vlan {vlan} 0/{slot} {port}', 'dangerous' => true, 'expect' => 'success', 'context' => 'config'],
            ['key' => 'service_port', 'cmd' => 'service-port {sportidx} vlan {vlan} gpon 0/{slot}/{port} ont {ont} gemport {gem} multi-service user-vlan {uservlan}', 'dangerous' => true, 'expect' => 'success', 'context' => 'config'],
            ['key' => 'service_port_show', 'cmd' => 'display service-port port 0/{slot}/{port}', 'dangerous' => false, 'expect' => 'VLAN'],
        ],
        // ONT control (dangerous)
        'ont_control' => [
            ['key' => 'ont_reset', 'cmd' => 'ont reset {port} {ont}', 'dangerous' => true, 'expect' => 'success', 'context' => 'gpon'],
            ['key' => 'ont_activate', 'cmd' => 'ont activate {port} {ont}', 'dangerous' => true, 'expect' => 'success', 'context' => 'gpon'],
            ['key' => 'ont_deactivate', 'cmd' => 'ont deactivate {port} {ont}', 'dangerous' => true, 'expect' => 'success', 'context' => 'gpon'],
            ['key' => 'ont_delete', 'cmd' => 'ont delete {port} {ont}', 'dangerous' => true, 'expect' => 'success', 'context' => 'gpon'],
        ],
    ];
}

/**
 * Context-entry command sequence for a given command context.
 *
 * @return list<string>
 */
function app_huawei_context_prefix(string $context): array
{
    return match ($context) {
        'config' => ['enable', 'config'],
        'gpon' => ['enable', 'config', 'interface gpon 0/{slot}'],
        default => ['enable'],
    };
}

/**
 * Render a command template with the given variables. Trims and substitutes
 * every {placeholder}. Returns the rendered command.
 *
 * @param array<string,int|string> $vars
 */
function app_huawei_render(string $template, array $vars = []): string
{
    $map = [];
    foreach ($vars as $key => $value) {
        $map['{' . trim((string) $key, '{}') . '}'] = trim((string) $value);
    }
    return trim(strtr($template, $map));
}

/**
 * Any {placeholder} left unrendered in a string?
 */
function app_huawei_has_unrendered(string $command): bool
{
    return preg_match('/\{[a-z_]+\}/i', $command) === 1;
}

/**
 * Whether the platform may run the Huawei plan against a live device.
 *
 * Mirrors the read-only adapter's hard gate: the feature flag must be enabled
 * AND the device profile must be marked hardware_validated. Until a real OLT is
 * connected and validated this returns false with real_hardware_not_validated.
 *
 * @return array{ok:bool,reason?:string,mode:string}
 */
function app_huawei_live_ready(string $model, array $device = [], ?PDO $db = null): array
{
    $profiles = app_huawei_olt_profiles();
    if (!isset($profiles[$model])) {
        return ['ok' => false, 'reason' => 'unsupported_model', 'mode' => 'none'];
    }
    $db = $db ?? (function_exists('app_db') ? app_db() : null);
    $flagOn = $db instanceof PDO && function_exists('app_feature_flag_enabled')
        && app_feature_flag_enabled($db, 'stage6_huawei_adapter', [], false);
    if (!$flagOn) {
        return ['ok' => false, 'reason' => 'feature_flag_disabled', 'mode' => 'mock_plan'];
    }
    if (empty($device['hardware_validated'])) {
        return ['ok' => false, 'reason' => 'real_hardware_not_validated', 'mode' => 'mock_plan'];
    }
    return ['ok' => true, 'mode' => 'live'];
}

/**
 * Build the ordered live-test plan a technician runs when the OLT arrives.
 *
 * Groups the exact command sequences by the four operator intents:
 *   olt_info   -> "OLT ilə bağlı məlumatları görürük?"
 *   ont_read   -> "İstədiyimizi (ONT) görürük?"
 *   config     -> "Dəyişiklik edirik?"
 *   vlan       -> "VLAN ötürə bilirik?"
 *
 * Each step carries its rendered commands, expected marker, and whether it
 * mutates the device (needs explicit apply). Read steps are safe; config/vlan
 * steps are dangerous and stay dry-run until explicitly applied on real gear.
 *
 * @param array<string,int|string> $vars
 * @return array<string,mixed>
 */
function app_huawei_live_test_plan(string $model, array $vars = [], array $device = [], ?PDO $db = null): array
{
    $profiles = app_huawei_olt_profiles();
    $matrix = app_huawei_command_matrix();
    $ready = app_huawei_live_ready($model, $device, $db);

    $defaults = [
        'frame' => 0, 'slot' => 1, 'port' => 0, 'ont' => 1,
        'vlan' => 100, 'gem' => 1, 'uservlan' => 100, 'sportidx' => 1,
        'sn' => '48575443XXXXXXXX', 'lineprofile' => 'HGU-LINE', 'srvprofile' => 'HGU-SRV',
        'desc' => 'superoptic-test', 'ethport' => 1,
    ];
    $vars = array_merge($defaults, $vars);

    $intentMap = [
        'olt_info' => ['title' => 'OLT ilə bağlı məlumatları görürük?', 'category' => 'read_olt'],
        'ont_read' => ['title' => 'İstədiyimizi (ONT) görürük?', 'category' => 'read_ont'],
        'config' => ['title' => 'Dəyişiklik edirik?', 'category' => 'config_change'],
        'vlan' => ['title' => 'VLAN ötürə bilirik?', 'category' => 'vlan_push'],
    ];

    $intents = [];
    foreach ($intentMap as $intentKey => $meta) {
        $steps = [];
        foreach ($matrix[$meta['category']] as $command) {
            $prefix = array_map(
                static fn (string $c): string => app_huawei_render($c, $vars),
                app_huawei_context_prefix((string) ($command['context'] ?? 'enable'))
            );
            $rendered = app_huawei_render((string) $command['cmd'], $vars);
            $steps[] = [
                'key' => (string) $command['key'],
                'context_commands' => $prefix,
                'command' => $rendered,
                'dangerous' => (bool) $command['dangerous'],
                'expect_marker' => (string) ($command['expect'] ?? ''),
                'requires_apply' => (bool) $command['dangerous'],
            ];
        }
        $intents[$intentKey] = ['title' => $meta['title'], 'steps' => $steps];
    }

    return [
        'model' => $model,
        'profile' => $profiles[$model] ?? null,
        'mode' => $ready['mode'],
        'live_ready' => (bool) $ready['ok'],
        'gate_reason' => (string) ($ready['reason'] ?? ''),
        'snmp_oids' => app_huawei_snmp_oids(),
        'intents' => $intents,
        'variables' => $vars,
    ];
}
