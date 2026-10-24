<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
require_once dirname(__DIR__) . '/config.php';
$db = app_db();
$sessions = $db->query(
    "SELECT ds.id session_id,ds.account_id,ds.started_at,p.full_name,
            (SELECT MAX(l.recorded_at) FROM employee_live_locations l WHERE l.account_id=ds.account_id AND l.is_on_duty=1) last_location_at,
            c.service_enabled,c.permission_status,c.last_checked_at
       FROM mobile_duty_sessions ds
       JOIN portal_accounts p ON p.id=ds.account_id AND p.is_active=1
       LEFT JOIN mobile_location_compliance c ON c.account_id=ds.account_id
      WHERE ds.ended_at IS NULL"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$created = 0;
foreach ($sessions as $row) {
    $last = (string) ($row['last_location_at'] ?? '');
    $age = $last !== '' ? time() - strtotime($last) : PHP_INT_MAX;
    $nonCompliant = $age > 180 || (isset($row['service_enabled']) && !(bool) $row['service_enabled']);
    $key = 'gps_stale:' . (int) $row['account_id'] . ':' . (int) $row['session_id'];
    if ($nonCompliant) {
        $minutes = $age === PHP_INT_MAX ? 'heç vaxt' : (string) max(3, (int) floor($age / 60)) . ' dəqiqə əvvəl';
        $stmt = $db->prepare(
            "INSERT IGNORE INTO mobile_manager_alerts
                (alert_key,account_id,alert_type,severity,title,message,event_at,metadata_json)
             VALUES (?,?,'gps_stale','high',?,?,NOW(),?)"
        );
        $stmt->execute([
            $key, (int) $row['account_id'], (string) $row['full_name'] . ' üçün GPS axını dayanıb',
            'Əməkdaş növbədədir, son etibarlı mövqe ' . $minutes . ' qeydə alınıb. İş əməliyyatları bloklanıb.',
            json_encode(['session_id' => (int) $row['session_id'], 'last_location_at' => $last ?: null], JSON_UNESCAPED_UNICODE),
        ]);
        $created += $stmt->rowCount();
    } else {
        $db->prepare('UPDATE mobile_manager_alerts SET resolved_at=NOW() WHERE alert_key=? AND resolved_at IS NULL')->execute([$key]);
    }
}
echo 'active_duty_sessions=' . count($sessions) . ' gps_alerts_created=' . $created . PHP_EOL;
