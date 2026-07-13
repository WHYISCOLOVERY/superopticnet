<?php
declare(strict_types=1);

require_once __DIR__ . '/mgs-integration-lib.php';

function app_security_anomaly_defaults(): array
{
    return [
        'traffic_window_hours' => 24,
        'nat_window_minutes' => 15,
        'high_total_gb' => 120.0,
        'high_upload_gb' => 25.0,
        'upload_ratio_threshold' => 0.70,
        'active_session_threshold' => 5,
        'nat_destination_threshold' => 300,
        'nat_port_threshold' => 80,
        'max_findings' => 50,
        'record' => false,
        'test_mode' => true,
    ];
}

function app_security_anomaly_ensure_schema(?PDO $db = null): bool
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_mgs_ensure_schema($db)) {
        return false;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS security_anomaly_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dedup_key VARCHAR(191) NOT NULL,
            subject_type VARCHAR(64) NOT NULL DEFAULT 'customer',
            subject_id VARCHAR(191) NOT NULL DEFAULT '',
            subscriber_code VARCHAR(64) DEFAULT NULL,
            username VARCHAR(64) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            risk_score INT NOT NULL DEFAULT 0,
            severity ENUM('info','warning','critical','emergency') NOT NULL DEFAULT 'info',
            signal_type VARCHAR(64) NOT NULL DEFAULT '',
            title VARCHAR(191) NOT NULL DEFAULT '',
            evidence_json LONGTEXT DEFAULT NULL,
            recommended_actions_json LONGTEXT DEFAULT NULL,
            status ENUM('open','ack','resolved','false_positive','test') NOT NULL DEFAULT 'open',
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            seen_count INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_security_anomaly_dedup (dedup_key),
            KEY idx_security_anomaly_status (status, severity, last_seen_at),
            KEY idx_security_anomaly_subject (subject_type, subject_id),
            KEY idx_security_anomaly_user (username, last_seen_at),
            KEY idx_security_anomaly_subscriber (subscriber_code, last_seen_at),
            KEY idx_security_anomaly_ip (ip_address, last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    return true;
}

function app_security_anomaly_severity(int $riskScore): string
{
    if ($riskScore >= 90) {
        return 'emergency';
    }
    if ($riskScore >= 75) {
        return 'critical';
    }
    if ($riskScore >= 55) {
        return 'warning';
    }
    return 'info';
}

function app_security_anomaly_bytes_gb(float|int|string|null $bytes): float
{
    return round(((float) ($bytes ?? 0)) / 1073741824, 3);
}

function app_security_anomaly_subject_key(array $row): string
{
    foreach (['subscriber_code', 'username', 'ip_address', 'source_ip'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return 'unknown';
}

function app_security_anomaly_actions(int $riskScore, string $signalType): array
{
    $actions = [
        [
            'action' => 'open_security_ticket',
            'mode' => 'manual_or_superpilot_confirmed',
            'reason' => 'NOC/security araşdırması üçün bilet aç.',
        ],
        [
            'action' => 'notify_customer',
            'mode' => 'manual_or_superpilot_confirmed',
            'reason' => 'Müştəriyə modem/router virusu və anormal trafik barədə xəbərdarlıq göndər.',
        ],
    ];

    if ($riskScore >= 75) {
        $actions[] = [
            'action' => 'radius_coa_disconnect_or_refresh',
            'mode' => 'requires_confirmation',
            'reason' => 'Sessiyanı yeniləmək və RADIUS logunu təkrar ölçmək üçün CoA/disconnect təklif olunur.',
        ];
        $actions[] = [
            'action' => 'temporary_rate_limit',
            'mode' => 'requires_confirmation',
            'reason' => 'Uplink dolmasını azaltmaq üçün müvəqqəti limit profili tətbiq edilə bilər.',
        ];
    }

    if ($riskScore >= 90 || in_array($signalType, ['nat_fanout', 'session_storm'], true)) {
        $actions[] = [
            'action' => 'quarantine_pool_or_vlan',
            'mode' => 'requires_admin_policy',
            'reason' => 'Təkrar təsdiqlənmiş malware/abuse halında müştərini karantin pool/VLAN-a sal.',
        ];
    }

    return $actions;
}

function app_security_anomaly_make_finding(array $row, string $signalType, int $riskScore, string $title, array $evidence): array
{
    $subjectId = app_security_anomaly_subject_key($row);
    $severity = app_security_anomaly_severity($riskScore);
    $dedupKey = hash('sha256', implode('|', ['security_anomaly', $signalType, $subjectId, $severity]));

    return [
        'dedup_key' => $dedupKey,
        'subject_type' => 'customer_or_ip',
        'subject_id' => $subjectId,
        'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
        'username' => (string) ($row['username'] ?? ''),
        'ip_address' => (string) ($row['ip_address'] ?? $row['framedipaddress'] ?? $row['source_ip'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? $row['full_name'] ?? ''),
        'risk_score' => max(0, min(100, $riskScore)),
        'severity' => $severity,
        'signal_type' => $signalType,
        'title' => $title,
        'evidence' => $evidence,
        'recommended_actions' => app_security_anomaly_actions($riskScore, $signalType),
    ];
}

function app_security_anomaly_record(PDO $db, array $finding, bool $testMode = true): void
{
    app_security_anomaly_ensure_schema($db);
    $status = $testMode ? 'test' : 'open';
    $statement = $db->prepare(
        'INSERT INTO security_anomaly_events (
            dedup_key, subject_type, subject_id, subscriber_code, username, ip_address,
            risk_score, severity, signal_type, title, evidence_json, recommended_actions_json, status
         ) VALUES (
            :dedup_key, :subject_type, :subject_id, :subscriber_code, :username, :ip_address,
            :risk_score, :severity, :signal_type, :title, :evidence_json, :recommended_actions_json, :status
         )
         ON DUPLICATE KEY UPDATE
            risk_score = GREATEST(risk_score, VALUES(risk_score)),
            severity = VALUES(severity),
            title = VALUES(title),
            evidence_json = VALUES(evidence_json),
            recommended_actions_json = VALUES(recommended_actions_json),
            status = VALUES(status),
            last_seen_at = NOW(),
            seen_count = seen_count + 1'
    );
    $statement->execute([
        'dedup_key' => (string) $finding['dedup_key'],
        'subject_type' => (string) $finding['subject_type'],
        'subject_id' => (string) $finding['subject_id'],
        'subscriber_code' => (string) ($finding['subscriber_code'] ?? ''),
        'username' => (string) ($finding['username'] ?? ''),
        'ip_address' => (string) ($finding['ip_address'] ?? ''),
        'risk_score' => (int) $finding['risk_score'],
        'severity' => (string) $finding['severity'],
        'signal_type' => (string) $finding['signal_type'],
        'title' => mb_substr((string) $finding['title'], 0, 191),
        'evidence_json' => app_db_json_encode((array) ($finding['evidence'] ?? [])),
        'recommended_actions_json' => app_db_json_encode((array) ($finding['recommended_actions'] ?? [])),
        'status' => $status,
    ]);

    app_mgs_record_alarm_event([
        'alarm_type' => 'SECURITY_ANOMALY',
        'severity' => (string) $finding['severity'],
        'source' => 'security_anomaly_engine',
        'object_type' => 'customer_or_ip',
        'object_id' => (string) $finding['subject_id'],
        'title' => (string) $finding['title'],
        'message' => 'Security anomaly risk score: ' . (string) $finding['risk_score'],
        'telegram_bot_key' => 'network',
        'payload' => $finding,
    ], $testMode, false);
}

function app_security_anomaly_fetch_traffic_findings(PDO $db, array $options): array
{
    $hours = max(1, min(168, (int) $options['traffic_window_hours']));
    $statement = $db->query(
        "SELECT
            r.username,
            MAX(r.framedipaddress) AS ip_address,
            MAX(r.nasipaddress) AS nas_ip,
            COUNT(*) AS session_count,
            COALESCE(SUM(COALESCE(r.acctinputoctets,0)),0) AS upload_bytes,
            COALESCE(SUM(COALESCE(r.acctoutputoctets,0)),0) AS download_bytes,
            MAX(COALESCE(r.acctupdatetime, r.acctstarttime)) AS last_seen_at,
            MAX(s.account_id) AS account_id,
            MAX(s.assigned_ip) AS assigned_ip,
            MAX(s.static_ip) AS static_ip,
            MAX(a.subscriber_code) AS subscriber_code,
            MAX(a.full_name) AS customer_name
         FROM radacct r
         LEFT JOIN billing_radius_services s ON s.username = r.username
         LEFT JOIN portal_accounts a ON a.id = s.account_id
         WHERE COALESCE(r.acctupdatetime, r.acctstarttime) >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
         GROUP BY r.username
         ORDER BY (COALESCE(SUM(COALESCE(r.acctinputoctets,0)),0) + COALESCE(SUM(COALESCE(r.acctoutputoctets,0)),0)) DESC
         LIMIT 50"
    );
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $findings = [];

    foreach ($rows as $row) {
        $uploadGb = app_security_anomaly_bytes_gb($row['upload_bytes'] ?? 0);
        $downloadGb = app_security_anomaly_bytes_gb($row['download_bytes'] ?? 0);
        $totalGb = $uploadGb + $downloadGb;
        $uploadRatio = $totalGb > 0 ? $uploadGb / $totalGb : 0.0;

        if ($totalGb >= (float) $options['high_total_gb']) {
            $risk = 50 + (int) min(25, ($totalGb - (float) $options['high_total_gb']) / 10);
            if ($uploadRatio >= (float) $options['upload_ratio_threshold']) {
                $risk += 15;
            }
            $findings[] = app_security_anomaly_make_finding($row, 'top_talker', $risk, 'Şəbəkəni yüksək həcmdə istifadə edən müştəri/IP', [
                'window_hours' => $hours,
                'upload_gb' => $uploadGb,
                'download_gb' => $downloadGb,
                'total_gb' => round($totalGb, 3),
                'upload_ratio' => round($uploadRatio, 3),
                'nas_ip' => (string) ($row['nas_ip'] ?? ''),
                'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
            ]);
        }

        if ($uploadGb >= (float) $options['high_upload_gb'] && $uploadRatio >= (float) $options['upload_ratio_threshold']) {
            $risk = 65 + (int) min(25, ($uploadGb - (float) $options['high_upload_gb']) / 5);
            $findings[] = app_security_anomaly_make_finding($row, 'upload_abuse', $risk, 'Upload ağırlıqlı anormal trafik aşkarlandı', [
                'window_hours' => $hours,
                'upload_gb' => $uploadGb,
                'download_gb' => $downloadGb,
                'upload_ratio' => round($uploadRatio, 3),
                'possible_causes' => ['infected_router_or_ont', 'camera_cloud_upload', 'p2p_seed', 'compromised_lan_device'],
            ]);
        }
    }

    return $findings;
}

function app_security_anomaly_fetch_session_findings(PDO $db, array $options): array
{
    $threshold = max(2, (int) $options['active_session_threshold']);
    $statement = $db->query(
        "SELECT
            r.username,
            MAX(r.framedipaddress) AS ip_address,
            MAX(r.nasipaddress) AS nas_ip,
            COUNT(*) AS active_sessions,
            MAX(s.account_id) AS account_id,
            MAX(a.subscriber_code) AS subscriber_code,
            MAX(a.full_name) AS customer_name
         FROM radacct r
         LEFT JOIN billing_radius_services s ON s.username = r.username
         LEFT JOIN portal_accounts a ON a.id = s.account_id
         WHERE r.acctstoptime IS NULL
         GROUP BY r.username
         HAVING COUNT(*) >= {$threshold}
         ORDER BY active_sessions DESC
         LIMIT 30"
    );
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $findings = [];

    foreach ($rows as $row) {
        $sessions = (int) ($row['active_sessions'] ?? 0);
        $risk = 60 + (int) min(30, ($sessions - $threshold) * 5);
        $findings[] = app_security_anomaly_make_finding($row, 'session_storm', $risk, 'Eyni istifadəçidə çox sayda aktiv RADIUS sessiya var', [
            'active_sessions' => $sessions,
            'threshold' => $threshold,
            'nas_ip' => (string) ($row['nas_ip'] ?? ''),
            'possible_causes' => ['router_loop', 'pppoe_redial_storm', 'compromised_cpe', 'stuck_radius_accounting'],
        ]);
    }

    return $findings;
}

function app_security_anomaly_fetch_nat_findings(PDO $db, array $options): array
{
    $minutes = max(1, min(1440, (int) $options['nat_window_minutes']));
    $destThreshold = max(10, (int) $options['nat_destination_threshold']);
    $portThreshold = max(10, (int) $options['nat_port_threshold']);
    $statement = $db->query(
        "SELECT
            COALESCE(NULLIF(username,''), NULLIF(subscriber_code,''), source_ip) AS subject_id,
            MAX(username) AS username,
            MAX(subscriber_code) AS subscriber_code,
            source_ip,
            COUNT(*) AS flow_count,
            COUNT(DISTINCT destination_ip) AS destination_count,
            COUNT(DISTINCT destination_port) AS destination_port_count,
            MAX(logged_at) AS last_seen_at
         FROM radius_nat_logs
         WHERE logged_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
         GROUP BY COALESCE(NULLIF(username,''), NULLIF(subscriber_code,''), source_ip), source_ip
         HAVING COUNT(DISTINCT destination_ip) >= {$destThreshold}
             OR COUNT(DISTINCT destination_port) >= {$portThreshold}
         ORDER BY destination_count DESC, destination_port_count DESC
         LIMIT 30"
    );
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $findings = [];

    foreach ($rows as $row) {
        $destinations = (int) ($row['destination_count'] ?? 0);
        $ports = (int) ($row['destination_port_count'] ?? 0);
        $risk = 70 + (int) min(25, max($destinations - $destThreshold, $ports - $portThreshold) / 10);
        $row['ip_address'] = (string) ($row['source_ip'] ?? '');
        $findings[] = app_security_anomaly_make_finding($row, 'nat_fanout', $risk, 'Qısa müddətdə çox sayda destination/port bağlantısı aşkarlandı', [
            'window_minutes' => $minutes,
            'flow_count' => (int) ($row['flow_count'] ?? 0),
            'destination_count' => $destinations,
            'destination_port_count' => $ports,
            'thresholds' => [
                'destination_count' => $destThreshold,
                'destination_port_count' => $portThreshold,
            ],
            'possible_causes' => ['worm_or_botnet_scan', 'ddos_participation', 'infected_lan_host', 'open_proxy_or_compromised_router'],
        ]);
    }

    return $findings;
}

function app_security_anomaly_run(?PDO $db = null, array $options = []): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO || !app_security_anomaly_ensure_schema($db)) {
        return ['ok' => false, 'reason' => 'db_unavailable', 'findings' => []];
    }

    $options = array_replace(app_security_anomaly_defaults(), $options);
    $findings = array_merge(
        app_security_anomaly_fetch_traffic_findings($db, $options),
        app_security_anomaly_fetch_session_findings($db, $options),
        app_security_anomaly_fetch_nat_findings($db, $options)
    );

    usort($findings, static fn (array $a, array $b): int => ((int) ($b['risk_score'] ?? 0)) <=> ((int) ($a['risk_score'] ?? 0)));
    $maxFindings = max(1, min(1000, (int) ($options['max_findings'] ?? 50)));
    $findings = array_slice($findings, 0, $maxFindings);

    if (!empty($options['record'])) {
        foreach ($findings as $finding) {
            app_security_anomaly_record($db, $finding, (bool) $options['test_mode']);
        }
        app_mgs_record_operation_action([
            'action_type' => 'security_anomaly_scan',
            'entity_type' => 'security',
            'entity_id' => 'global',
            'status' => !empty($options['test_mode']) ? 'dry_run' : 'executed',
            'summary' => 'Security anomaly scan completed: ' . count($findings) . ' finding(s)',
            'payload' => [
                'options' => $options,
                'finding_count' => count($findings),
                'top_findings' => array_slice($findings, 0, 10),
            ],
        ]);
    }

    return [
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'mode' => !empty($options['record']) ? (!empty($options['test_mode']) ? 'record_test' : 'record_live') : 'detect_only',
        'summary' => [
            'findings' => count($findings),
            'critical_or_higher' => count(array_filter($findings, static fn (array $f): bool => in_array((string) ($f['severity'] ?? ''), ['critical', 'emergency'], true))),
            'max_risk_score' => (int) max(array_map(static fn (array $f): int => (int) ($f['risk_score'] ?? 0), $findings) ?: [0]),
        ],
        'findings' => $findings,
    ];
}

function app_security_anomaly_answer(string $query, ?PDO $db = null): array
{
    $result = app_security_anomaly_run($db, []);
    if (empty($result['ok'])) {
        return ['ok' => false, 'text' => 'Security anomaly engine DB hazir deyil.'];
    }

    $findings = array_values(array_filter((array) ($result['findings'] ?? []), 'is_array'));
    if ($findings === []) {
        return [
            'ok' => true,
            'text' => "SECURITY/NOC ANALIZI\nHazirda radacct/NAT log esasinda kritik virus/abuse/top-talker siqnali tapilmadi.\nQeyd: NetFlow/IPFIX ve live router counters qosulanda deqiqlik artacaq.",
            'result' => $result,
        ];
    }

    $lines = [
        'SECURITY/NOC ANALIZI',
        'Tapilan riskler: ' . (string) count($findings) . ' / Max risk: ' . (string) (($result['summary']['max_risk_score'] ?? 0)),
        '',
        'Top riskler:',
    ];
    foreach (array_slice($findings, 0, 5) as $finding) {
        $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
        $lines[] = '- [' . strtoupper((string) ($finding['severity'] ?? 'info')) . ' ' . (string) ($finding['risk_score'] ?? 0) . '] '
            . (string) ($finding['subject_id'] ?? '-')
            . ' / ' . (string) ($finding['signal_type'] ?? '-')
            . ' - ' . (string) ($finding['title'] ?? '-');
        if (isset($evidence['total_gb']) || isset($evidence['upload_gb']) || isset($evidence['destination_count'])) {
            $lines[] = '  Sübut: total=' . (string) ($evidence['total_gb'] ?? '-')
                . 'GB upload=' . (string) ($evidence['upload_gb'] ?? '-')
                . 'GB destinations=' . (string) ($evidence['destination_count'] ?? '-')
                . ' ports=' . (string) ($evidence['destination_port_count'] ?? '-');
        }
    }
    $lines[] = '';
    $lines[] = 'Təklif: əvvəlcə bilet + müştəri xəbərdarlığı, risk 75+ olarsa NOC təsdiqi ilə RADIUS refresh/rate-limit, risk 90+ olarsa policy ilə quarantine.';
    $lines[] = 'Canli bloklama avtomatik edilmədi; Superpilot təsdiqsiz CoA/ACL tətbiq etməyəcək.';

    return ['ok' => true, 'text' => implode("\n", $lines), 'result' => $result];
}
