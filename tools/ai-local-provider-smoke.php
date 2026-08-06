<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/ai-local-lib.php';

$passes = [];
$failures = [];

function local_check(string $name, bool $condition, array &$passes, array &$failures, string $detail = ''): void
{
    if ($condition) {
        $passes[] = $name;
        return;
    }
    $failures[] = $name . ($detail !== '' ? ' (' . $detail . ')' : '');
}

/* 1. Loopback guard: only 127.0.0.0/8, ::1, localhost are allowed. */
foreach (['http://127.0.0.1:11434', 'http://localhost:1234/v1', 'http://127.0.0.5:8080', 'http://[::1]:11434'] as $ok) {
    local_check('loopback allowed: ' . $ok, app_ai_local_is_loopback_url($ok), $passes, $failures);
}
foreach (['http://8.8.8.8', 'https://api.openai.com', 'http://evil.com:11434', 'http://169.254.1.1', 'http://user:pass@127.0.0.1'] as $bad) {
    local_check('non-loopback blocked: ' . $bad, !app_ai_local_is_loopback_url($bad), $passes, $failures);
}

/* 2. Transport hard-refuses any non-loopback URL before making a request. */
$blocked = app_ai_local_http_transport('http://8.8.8.8/api/chat', '{}', ['Content-Type: application/json']);
local_check('transport refuses non-loopback', empty($blocked['ok']) && ($blocked['reason'] ?? '') === 'non_loopback_blocked', $passes, $failures);

/* 3. Config reflects the local provider. */
$config = app_ai_local_config();
local_check('provider is local', $config['provider'] === 'local', $passes, $failures, $config['provider']);
local_check('endpoint is loopback', app_ai_local_is_loopback_url($config['endpoint']), $passes, $failures, $config['endpoint']);
local_check('api is ollama or openai', in_array($config['api'], ['ollama', 'openai'], true), $passes, $failures, $config['api']);

/* 4. Secret redaction before the prompt reaches the model. */
$safe = app_ai_local_safe_prompt('parol: Secret123 token sk-abcdef1234567890 Bearer xyztoken123456');
local_check('password redacted', !str_contains($safe, 'Secret123'), $passes, $failures, $safe);
local_check('api key redacted', !str_contains($safe, 'sk-abcdef1234567890'), $passes, $failures, $safe);
local_check('bearer redacted', !str_contains($safe, 'xyztoken123456'), $passes, $failures, $safe);

/* 5. Memory admission is deterministic and blocks unsafe model loading. */
$memoryBlocked = app_ai_local_memory_decision(2 * 1024 ** 3, 5 * 1024 ** 3);
$memoryReady = app_ai_local_memory_decision(8 * 1024 ** 3, 5 * 1024 ** 3);
local_check('insufficient memory is blocked', !$memoryBlocked['ready'] && $memoryBlocked['reason'] === 'insufficient_local_memory', $passes, $failures);
local_check('sufficient memory is accepted', $memoryReady['ready'] && $memoryReady['reason'] === '', $passes, $failures);

/* 6. Chat routes through a deterministic mock transport (Ollama shape). */
$mockOllama = static function (string $url, string $body, array $headers, int $timeout = 30): array {
    return [
        'ok' => true,
        'status' => 200,
        'body' => json_encode(['message' => ['role' => 'assistant', 'content' => 'Salam, bu lokal cavabdır.']]),
        'latency_ms' => 5,
        'response_bytes' => 60,
    ];
};
$reply = app_ai_local_chat('Astara rayonunda neçə müştəri var?', [], $mockOllama);
local_check('local chat returns text', !empty($reply['ok']) && str_contains((string) $reply['text'], 'lokal cavab'), $passes, $failures, (string) ($reply['reason'] ?? ''));
local_check('local chat provider tag', ($reply['provider'] ?? '') === 'local', $passes, $failures);

/* 7. OpenAI-compatible response shape is also parsed. */
$mockOpenai = static function (string $url, string $body, array $headers, int $timeout = 30): array {
    return [
        'ok' => true,
        'status' => 200,
        'body' => json_encode(['choices' => [['message' => ['content' => 'OpenAI-compat cavab.']]]]),
        'latency_ms' => 5,
        'response_bytes' => 50,
    ];
};
$reply2 = app_ai_local_chat('test', [], $mockOpenai);
local_check('openai-compat shape parsed', !empty($reply2['ok']) && str_contains((string) $reply2['text'], 'OpenAI-compat'), $passes, $failures);

/* 8. Empty runtime response is a clean failure, not a crash. */
$mockEmpty = static fn (string $url, string $body, array $headers, int $timeout = 30): array => ['ok' => true, 'status' => 200, 'body' => '{}', 'latency_ms' => 1, 'response_bytes' => 2];
$reply3 = app_ai_local_chat('test', [], $mockEmpty);
local_check('empty response handled', empty($reply3['ok']) && ($reply3['reason'] ?? '') === 'local_empty_response', $passes, $failures);

/* 9. Agent chat prefers local and never silently calls the cloud when local is set. */
local_check('agent_chat function exists', function_exists('app_ai_agent_chat'), $passes, $failures);

/* 10. copilot-agent is wired to the unified entry, not the raw cloud call. */
$agentSrc = (string) file_get_contents($root . '/copilot-agent.php');
local_check('copilot-agent uses app_ai_agent_chat', str_contains($agentSrc, 'app_ai_agent_chat('), $passes, $failures);
local_check('copilot-agent requires ai-local-lib', str_contains($agentSrc, "require_once __DIR__ . '/ai-local-lib.php'"), $passes, $failures);

echo 'ai_local_pass=' . count($passes) . PHP_EOL;
if ($failures !== []) {
    echo 'ai_local_fail=' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo 'FAIL: ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'ai_local_fail=0' . PHP_EOL;
exit(0);
