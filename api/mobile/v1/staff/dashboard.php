<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__) . '/lib/mobile-alert-feed-lib.php';

$user = app_mobile_authenticate();
app_mobile_require_role($user, [
    'operator', 'night_operator', 'support', 'finance',
    'engineer', 'noc', 'network_engineer',
]);
$db = app_db();
$role = (string) ($user['employee_role'] ?: $user['role']);
$isEngineer = in_array($role, ['engineer', 'noc', 'network_engineer'], true);

$ticketStats = $db->query(
    "SELECT
        COUNT(CASE WHEN status IN ('open','assigned','in_progress','Aciqdir','Icrada','Gozlenilir') THEN 1 END) AS active_count,
        COUNT(CASE WHEN status IN ('open','Aciqdir','Gozlenilir') THEN 1 END) AS open_count,
        COUNT(CASE WHEN status IN ('in_progress','Icrada') THEN 1 END) AS in_progress_count,
        COUNT(CASE WHEN status IN ('resolved','closed','Baglandi','Tesdiqlendi') AND DATE(COALESCE(resolved_at, updated_at)) = CURRENT_DATE() THEN 1 END) AS completed_today
     FROM portal_tickets
     WHERE ticket_no NOT LIKE 'LEGACY-%' OR opened_at >= (NOW() - INTERVAL 24 HOUR)"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$items = [];
$showNoc = $isEngineer || in_array($role, ['operator', 'night_operator', 'support'], true)
    || app_mobile_has_capability($user, 'noc_monitor');
if ($showNoc) {
    $items = app_mobile_actionable_alarm_feed($db, 50);
    $openCount = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'open'));
    $criticalCount = count(array_filter(
        $items,
        static fn (array $item): bool => $item['status'] === 'open'
            && in_array($item['severity'], ['critical', 'emergency'], true)
    ));
    $acknowledgedCount = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'ack'));
    $metrics = [
        'open_alarms' => $openCount,
        'critical_alarms' => $criticalCount,
        'acknowledged_alarms' => $acknowledgedCount,
        'active_tickets' => (int) ($ticketStats['active_count'] ?? 0),
    ];
}
if (!$isEngineer) {
    $messageStats = $db->query(
        "SELECT
            COUNT(CASE WHEN status = 'queued' THEN 1 END) AS queued_count,
            COUNT(CASE WHEN status = 'failed' OR delivery_state = 'final_failed' THEN 1 END) AS failed_count,
            COUNT(CASE WHEN status = 'sent' AND DATE(COALESCE(sent_at, created_at)) = CURRENT_DATE() THEN 1 END) AS sent_today
         FROM communication_messages"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($db->query(
        "SELECT id, recipient_name, subscriber_code, channel, message_body, status, delivery_state,
                provider_status_description, created_at
         FROM communication_messages
         WHERE (status IN ('queued','failed') OR delivery_state = 'final_failed')
           AND created_at >= (NOW() - INTERVAL 7 DAY)
         ORDER BY FIELD(status, 'failed','queued'), created_at DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'kind' => 'message',
            'severity' => (string) $row['status'] === 'failed' ? 'warning' : 'info',
            'title' => strtoupper((string) $row['channel']) . ' · ' . (string) ($row['recipient_name'] ?: $row['subscriber_code']),
            'subtitle' => (string) ($row['provider_status_description'] ?? ''),
            'message' => (string) $row['message_body'],
            'status' => (string) $row['status'],
            'occurred_at' => (string) $row['created_at'],
        ];
    }
    $metrics = array_merge($metrics ?? [], [
        'open_tickets' => (int) ($ticketStats['open_count'] ?? 0),
        'in_progress_tickets' => (int) ($ticketStats['in_progress_count'] ?? 0),
        'queued_messages' => (int) ($messageStats['queued_count'] ?? 0),
        'failed_messages' => (int) ($messageStats['failed_count'] ?? 0),
        'sent_today' => (int) ($messageStats['sent_today'] ?? 0),
    ]);
}

$ownAlerts = $db->prepare(
    "SELECT id,alert_type,severity,title,message,event_at,resolved_at
       FROM mobile_manager_alerts
      WHERE account_id=? AND resolved_at IS NULL
        AND alert_type IN ('operator_presence_reminder','operator_presence_missed','gps_disabled','gps_stale')
      ORDER BY event_at DESC LIMIT 20"
);
$ownAlerts->execute([(int) $user['id']]);
foreach ($ownAlerts->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $items[] = [
        'id' => 'staff-alert-' . $row['id'],
        'kind' => (string) $row['alert_type'],
        'group' => 'Şəxsi növbə xəbərdarlığı',
        'severity' => (string) $row['severity'],
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'status' => 'open',
        'occurred_at' => (string) $row['event_at'],
    ];
}

app_mobile_json_response([
    'role' => $role,
    'capabilities' => app_mobile_account_capabilities($user),
    'metrics' => $metrics,
    'items' => $items,
], 200);
