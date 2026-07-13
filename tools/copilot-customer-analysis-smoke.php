<?php
declare(strict_types=1);

require dirname(__DIR__) . '/copilot-engine.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function copilot_smoke_finish(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($code);
}

$db = app_db();
if (!$db instanceof PDO) {
    copilot_smoke_finish([
        'status' => 'skipped',
        'reason' => 'database_unavailable',
    ]);
}

$subscriberCode = 'SMOKE-COPILOT-' . date('His') . random_int(100, 999);
$accountId = 0;
$ticketNo = 'SMK-COP-' . date('His') . random_int(100, 999);

try {
    $payload = [
        'profile' => [
            'full_name' => 'Copilot Smoke Customer',
            'subscriber_code' => $subscriberCode,
            'note' => 'Ev monolit betondur, 200 m2, uzaq otaqlarda wifi catmir.',
        ],
        'billing' => [
            'status' => 'Aktiv',
            'balance' => '0 AZN',
            'tariff' => 'Smoke 150',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+20 days')),
        ],
        'payments' => [[
            'date' => date('d.m.Y H:i'),
            'amount' => '25.00 AZN',
            'method' => 'emanat',
            'transaction_id' => 'COPILOT-SMOKE-TX',
        ]],
        'ont' => [
            'serial' => 'SMOKEONT123',
            'rx_power' => '-29.1',
            'tx_power' => '2.1',
            'temperature' => '71',
        ],
    ];

    $insert = $db->prepare(
        'INSERT INTO portal_accounts (
            login, password_hash, role, account_type, full_name, subscriber_code, phone, area, address, plan, status, is_active, payload_json
         ) VALUES (
            :login, :password_hash, "customer", "customer", :full_name, :subscriber_code, :phone, :area, :address, :plan, "Aktiv", 1, :payload_json
         )'
    );
    $insert->execute([
        'login' => $subscriberCode,
        'password_hash' => password_hash('smoke-pass', PASSWORD_DEFAULT),
        'full_name' => 'Copilot Smoke Customer',
        'subscriber_code' => $subscriberCode,
        'phone' => '0500000001',
        'area' => 'Smoke zona',
        'address' => 'Smoke unvan',
        'plan' => 'Smoke 150',
        'payload_json' => app_db_json_encode($payload),
    ]);
    $accountId = (int) $db->lastInsertId();

    app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'customer_account_id' => $accountId,
        'subscriber_code' => $subscriberCode,
        'contract_no' => $subscriberCode,
        'customer_name' => 'Copilot Smoke Customer',
        'phone' => '0500000001',
        'address' => 'Smoke unvan',
        'area' => 'Smoke zona',
        'subject' => 'Internet get gel problemi',
        'message' => 'Musteride internet tez-tez qirilir, modem sonub yanir ve ev monolit betondur.',
        'status' => 'open',
        'priority' => 'high',
        'source' => 'smoke',
        'created_by_name' => 'Copilot smoke',
    ]);

    $analysis = app_copilot_customer_analysis($subscriberCode . ' musterinin internet get geli var bunun sebebi nedir?', $db);
    $findingTypes = array_map(static fn (array $row): string => (string) ($row['type'] ?? ''), $analysis['findings'] ?? []);
    $ok = !empty($analysis['ok'])
        && in_array('flapping', $findingTypes, true)
        && (in_array('wifi_coverage', $findingTypes, true) || in_array('optical', $findingTypes, true))
        && !empty($analysis['safety']['read_only']);

    copilot_smoke_finish([
        'status' => $ok ? 'ok' : 'failed',
        'subscriber_code' => $subscriberCode,
        'confidence' => $analysis['confidence'] ?? null,
        'finding_types' => $findingTypes,
        'answer' => $analysis['answer'] ?? '',
        'read_only' => $analysis['safety']['read_only'] ?? false,
    ], $ok ? 0 : 3);
} catch (Throwable $exception) {
    copilot_smoke_finish([
        'status' => 'failed',
        'reason' => $exception->getMessage(),
    ], 2);
} finally {
    if ($accountId > 0) {
        $ticketIdStatement = $db->prepare('SELECT id FROM portal_tickets WHERE ticket_no = :ticket_no LIMIT 1');
        $ticketIdStatement->execute(['ticket_no' => $ticketNo]);
        $ticketId = (int) ($ticketIdStatement->fetchColumn() ?: 0);
        if ($ticketId > 0) {
            $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id')->execute(['ticket_id' => $ticketId]);
            $db->prepare('DELETE FROM portal_tickets WHERE id = :id')->execute(['id' => $ticketId]);
        }
        $db->prepare('DELETE FROM portal_accounts WHERE id = :id')->execute(['id' => $accountId]);
    }
}
