<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function legacy_knowledge_text(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/(?:\+?994|0)(?:10|50|51|55|60|70|77)[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}/u', '[telefon]', $value) ?? $value;
    $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[email]', $value) ?? $value;
    $value = preg_replace('/\d{5,}/u', '[müştəri kodu]', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim(mb_substr($value, 0, 500, 'UTF-8'));
}

function legacy_knowledge_add_example(array &$examples, string $value): void
{
    $value = legacy_knowledge_text($value);
    if ($value === '') {
        return;
    }
    $key = hash('sha256', mb_strtolower($value, 'UTF-8'));
    if (!isset($examples[$key])) {
        $examples[$key] = ['text' => $value, 'count' => 0];
    }
    $examples[$key]['count']++;
}

function legacy_knowledge_top_examples(array $examples, int $limit = 10): array
{
    $rows = array_values($examples);
    usort($rows, static function (array $left, array $right): int {
        $countOrder = (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0);
        return $countOrder !== 0
            ? $countOrder
            : strnatcasecmp((string) ($left['text'] ?? ''), (string) ($right['text'] ?? ''));
    });
    return array_slice($rows, 0, $limit);
}

$db = app_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database is unavailable.\n");
    exit(2);
}

$rows = $db->query(
    "SELECT id, customer_account_id, subject, message, status, opened_at, resolved_at,
            resolution_note, metadata_json
     FROM portal_tickets
     WHERE ticket_no LIKE 'LEGACY-TICKET-%'
     ORDER BY opened_at, id"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$groups = [];
foreach ($rows as $row) {
    $metadata = app_db_json_decode((string) ($row['metadata_json'] ?? ''), []);
    $rawText = is_array($metadata['raw_text'] ?? null) ? $metadata['raw_text'] : [];
    $problem = trim((string) ($rawText['problem'] ?? $row['subject'] ?? ''));
    $problem = $problem !== '' ? $problem : 'Kateqoriyasız problem';
    $problemKey = hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', $problem) ?? $problem, 'UTF-8'));

    if (!isset($groups[$problemKey])) {
        $groups[$problemKey] = [
            'problem_title' => mb_substr($problem, 0, 191, 'UTF-8'),
            'ticket_count' => 0,
            'customer_ids' => [],
            'unlinked_ticket_count' => 0,
            'resolved_ticket_count' => 0,
            'cause_examples' => [],
            'solution_examples' => [],
            'first_seen_at' => null,
            'last_seen_at' => null,
        ];
    }

    $group =& $groups[$problemKey];
    $group['ticket_count']++;
    $accountId = (int) ($row['customer_account_id'] ?? 0);
    if ($accountId > 0) {
        $group['customer_ids'][$accountId] = true;
    } else {
        $group['unlinked_ticket_count']++;
    }
    if (!empty($row['resolved_at']) || in_array((string) ($row['status'] ?? ''), ['Baglandi', 'Bağlandı'], true)) {
        $group['resolved_ticket_count']++;
    }

    legacy_knowledge_add_example(
        $group['cause_examples'],
        (string) ($rawText['message'] ?? $row['message'] ?? '')
    );
    legacy_knowledge_add_example(
        $group['solution_examples'],
        (string) ($rawText['close'] ?? $row['resolution_note'] ?? '')
    );

    $openedAt = trim((string) ($row['opened_at'] ?? ''));
    if ($openedAt !== '') {
        $group['first_seen_at'] = $group['first_seen_at'] === null
            ? $openedAt
            : min((string) $group['first_seen_at'], $openedAt);
        $group['last_seen_at'] = $group['last_seen_at'] === null
            ? $openedAt
            : max((string) $group['last_seen_at'], $openedAt);
    }
    unset($group);
}

$db->beginTransaction();
try {
    $db->exec('DELETE FROM legacy_ticket_knowledge_base');
    $insert = $db->prepare(
        'INSERT INTO legacy_ticket_knowledge_base (
            problem_key, problem_title, ticket_count, linked_customer_count,
            unlinked_ticket_count, resolved_ticket_count, cause_examples_json,
            solution_examples_json, first_seen_at, last_seen_at, refreshed_at
         ) VALUES (
            :problem_key, :problem_title, :ticket_count, :linked_customer_count,
            :unlinked_ticket_count, :resolved_ticket_count, :cause_examples_json,
            :solution_examples_json, :first_seen_at, :last_seen_at, NOW()
         )'
    );
    foreach ($groups as $problemKey => $group) {
        $insert->execute([
            'problem_key' => $problemKey,
            'problem_title' => $group['problem_title'],
            'ticket_count' => (int) $group['ticket_count'],
            'linked_customer_count' => count($group['customer_ids']),
            'unlinked_ticket_count' => (int) $group['unlinked_ticket_count'],
            'resolved_ticket_count' => (int) $group['resolved_ticket_count'],
            'cause_examples_json' => app_db_json_encode(legacy_knowledge_top_examples($group['cause_examples'])),
            'solution_examples_json' => app_db_json_encode(legacy_knowledge_top_examples($group['solution_examples'])),
            'first_seen_at' => $group['first_seen_at'],
            'last_seen_at' => $group['last_seen_at'],
        ]);
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

$top = $db->query(
    'SELECT problem_title, ticket_count, linked_customer_count, unlinked_ticket_count,
            resolved_ticket_count
     FROM legacy_ticket_knowledge_base
     ORDER BY ticket_count DESC, problem_title
     LIMIT 20'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
    'ok' => true,
    'tickets_analyzed' => count($rows),
    'problem_groups' => count($groups),
    'privacy' => 'Examples are de-identified; complete source text remains in portal_tickets.metadata_json.',
    'top_problems' => $top,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
