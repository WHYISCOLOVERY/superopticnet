<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function legacy_ticket_verify_expected(): int
{
    global $argv;
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, '--expected=')) {
            return max(0, (int) substr((string) $argument, strlen('--expected=')));
        }
    }
    return 0;
}

function legacy_ticket_verify_count(PDO $db, string $sql): int
{
    return (int) ($db->query($sql)->fetchColumn() ?: 0);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$expected = legacy_ticket_verify_expected();
$counts = [
    'imported_tickets' => legacy_ticket_verify_count($db, "SELECT COUNT(*) FROM portal_tickets WHERE ticket_no LIKE 'LEGACY-TICKET-%'"),
    'linked_tickets' => legacy_ticket_verify_count($db, "SELECT COUNT(*) FROM portal_tickets WHERE ticket_no LIKE 'LEGACY-TICKET-%' AND customer_account_id IS NOT NULL"),
    'unlinked_tickets' => legacy_ticket_verify_count($db, "SELECT COUNT(*) FROM portal_tickets WHERE ticket_no LIKE 'LEGACY-TICKET-%' AND customer_account_id IS NULL"),
    'linked_unique_customers' => legacy_ticket_verify_count($db, "SELECT COUNT(DISTINCT customer_account_id) FROM portal_tickets WHERE ticket_no LIKE 'LEGACY-TICKET-%' AND customer_account_id IS NOT NULL"),
    'problem_groups' => legacy_ticket_verify_count($db, 'SELECT COUNT(*) FROM legacy_ticket_knowledge_base'),
    'knowledge_ticket_total' => legacy_ticket_verify_count($db, 'SELECT COALESCE(SUM(ticket_count),0) FROM legacy_ticket_knowledge_base'),
];
$problems = [
    'duplicate_ticket_numbers' => legacy_ticket_verify_count(
        $db,
        "SELECT COUNT(*) FROM (
            SELECT ticket_no FROM portal_tickets
            WHERE ticket_no LIKE 'LEGACY-TICKET-%'
            GROUP BY ticket_no HAVING COUNT(*) > 1
         ) duplicates"
    ),
    'tickets_without_source_metadata' => legacy_ticket_verify_count(
        $db,
        "SELECT COUNT(*) FROM portal_tickets
         WHERE ticket_no LIKE 'LEGACY-TICKET-%'
           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.legacy_source')), '') <> 'old_billing_ticket'"
    ),
    'linked_tickets_without_customer' => legacy_ticket_verify_count(
        $db,
        "SELECT COUNT(*) FROM portal_tickets t
         LEFT JOIN portal_accounts a ON a.id=t.customer_account_id
         WHERE t.ticket_no LIKE 'LEGACY-TICKET-%'
           AND t.customer_account_id IS NOT NULL
           AND a.id IS NULL"
    ),
    'knowledge_count_mismatch' => $counts['knowledge_ticket_total'] === $counts['imported_tickets'] ? 0 : 1,
];

$ok = ($expected === 0 || $counts['imported_tickets'] === $expected)
    && !in_array(true, array_map(static fn(int $value): bool => $value > 0, $problems), true);

echo json_encode([
    'ok' => $ok,
    'expected' => $expected,
    'counts' => $counts,
    'problems' => $problems,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 4);
