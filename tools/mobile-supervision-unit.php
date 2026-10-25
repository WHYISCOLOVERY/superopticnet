<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/api/mobile/v1/lib/mobile-supervision-lib.php';

$timezone = new DateTimeZone('Asia/Baku');
$seconds = app_mobile_seconds_excluding_lunch(
    new DateTimeImmutable('2026-10-08 12:45:00', $timezone),
    new DateTimeImmutable('2026-10-08 14:15:00', $timezone),
    '13:00:00',
    '14:00:00'
);
if ($seconds !== 1800) {
    fwrite(STDERR, "Lunch exclusion failed: {$seconds}\n");
    exit(1);
}

$normal = app_mobile_ticket_priority([
    'opened_at' => '2026-10-08 15:00:00',
    'status' => 'assigned',
    'subject' => 'Tək müştəri problemi',
    'message' => 'Modem yenidən başladılmalıdır',
], 1, new DateTimeImmutable('2026-10-08 16:00:00', $timezone));
$critical = app_mobile_ticket_priority([
    'opened_at' => '2026-10-05 10:00:00',
    'status' => 'assigned',
    'subject' => 'OLT PON kütləvi problem',
    'message' => '50 müştəri təsirlənib',
    'metadata_json' => json_encode(['affected_customers' => 50, 'sla_due_at' => '2026-10-08 15:00:00']),
], 3, new DateTimeImmutable('2026-10-08 16:00:00', $timezone));
if ((int) $critical['score'] <= (int) $normal['score'] || (string) $critical['label'] !== 'kritik') {
    fwrite(STDERR, "Priority ordering failed.\n");
    exit(1);
}

echo "Mobile supervision unit checks passed: 2\n";
