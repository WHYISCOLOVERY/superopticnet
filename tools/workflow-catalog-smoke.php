<?php
declare(strict_types=1);

/** Static safety/shape check for the agent workflow catalog. */
$path = dirname(__DIR__) . '/config/agent_workflows.json';
$raw = is_file($path) ? file_get_contents($path) : false;
$catalog = is_string($raw) ? json_decode($raw, true) : null;
$failed = [];
$workflows = is_array($catalog['workflows'] ?? null) ? $catalog['workflows'] : [];
if (!is_array($catalog) || (int) ($catalog['schema'] ?? 0) !== 1) {
    $failed[] = 'catalog_schema';
}
if (count($workflows) < 5) {
    $failed[] = 'workflow_count';
}
$ids = [];
$secretWords = '/password|secret|token|community|private_key|credential_value/i';
foreach ($workflows as $i => $workflow) {
    if (!is_array($workflow)) {
        $failed[] = "workflow_{$i}_shape";
        continue;
    }
    $id = (string) ($workflow['id'] ?? '');
    if ($id === '' || isset($ids[$id])) {
        $failed[] = "workflow_{$i}_id";
    }
    $ids[$id] = true;
    foreach (['label', 'module', 'stages', 'read_operations', 'propose_operations',
        'approval_required_operations', 'evidence_required', 'source_refs'] as $key) {
        if (!array_key_exists($key, $workflow) || (!is_string($workflow[$key]) && !is_array($workflow[$key]))) {
            $failed[] = "{$id}_{$key}";
        }
    }
    if (preg_match($secretWords, json_encode($workflow, JSON_UNESCAPED_UNICODE) ?: '') === 1) {
        $failed[] = "{$id}_secret_like_field";
    }
    if (count((array) ($workflow['stages'] ?? [])) < 3 || count((array) ($workflow['approval_required_operations'] ?? [])) < 1) {
        $failed[] = "{$id}_incomplete_gate";
    }
    foreach ((array) ($workflow['source_refs'] ?? []) as $ref) {
        if (!is_string($ref) || !is_file(dirname(__DIR__) . '/' . $ref)) {
            $failed[] = "{$id}_missing_source_ref_" . preg_replace('/[^a-z0-9]+/i', '_', (string) $ref);
        }
    }
}
if ($failed !== []) {
    echo json_encode(['status' => 'failed', 'failed' => $failed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
echo json_encode(['status' => 'passed', 'workflow_count' => count($workflows), 'failed' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
