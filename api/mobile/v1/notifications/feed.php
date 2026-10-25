<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';

$user = app_mobile_authenticate();
$db = app_db();
$isManager = in_array((string) ($user['employee_role'] ?? ''), ['super_admin','admin','director'], true)
    || app_mobile_has_capability($user, 'manager');
$sql = "SELECT id,account_id,alert_type,severity,title,message,event_at,resolved_at,metadata_json
          FROM mobile_manager_alerts WHERE event_at>=NOW()-INTERVAL 14 DAY";
$params = [];
if (!$isManager) {
    $sql .= ' AND account_id=?';
    $params[] = (int) $user['id'];
}
$sql .= ' ORDER BY (resolved_at IS NULL) DESC,event_at DESC LIMIT 100';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = array_map(static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['account_id'] = $row['account_id'] === null ? null : (int) $row['account_id'];
    $row['is_open'] = $row['resolved_at'] === null;
    $row['metadata'] = json_decode((string) ($row['metadata_json'] ?? ''), true) ?: [];
    unset($row['metadata_json']);
    return $row;
}, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

$pref = $db->prepare('SELECT * FROM mobile_notification_preferences WHERE account_id=?');
$pref->execute([(int) $user['id']]);
$preferences = $pref->fetch(PDO::FETCH_ASSOC) ?: [
    'account_id' => (int) $user['id'], 'sound_enabled' => 1, 'vibration_enabled' => 1,
    'sound_key' => 'superoptic_alert', 'quiet_start' => null, 'quiet_end' => null,
];
$preferences['sound_enabled'] = (bool) $preferences['sound_enabled'];
$preferences['vibration_enabled'] = (bool) $preferences['vibration_enabled'];
app_mobile_json_response(['items' => $items, 'preferences' => $preferences]);
