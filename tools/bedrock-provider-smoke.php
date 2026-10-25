<?php
declare(strict_types=1);
require dirname(__DIR__) . '/ai-provider-lib.php';
$keys = ['AI_MODEL_PROVIDER','BEDROCK_API_KEY','AWS_BEARER_TOKEN_BEDROCK','BEDROCK_REGION','BEDROCK_MODEL'];
$old = [];
foreach ($keys as $key) { $value = getenv($key); $old[$key] = is_string($value) ? $value : null; }
try {
    putenv('AI_MODEL_PROVIDER=bedrock');
    putenv('BEDROCK_API_KEY=bedrock-smoke-secret-not-real');
    putenv('BEDROCK_REGION=us-east-1');
    putenv('BEDROCK_MODEL=us.anthropic.claude-sonnet-4-6');
    $called = false;
    $mock = static function (string $url, string $body, array $headers) use (&$called): array {
        $called = true; $payload = json_decode($body, true);
        $valid = $url === 'https://bedrock-runtime.us-east-1.amazonaws.com/model/us.anthropic.claude-sonnet-4-6/converse'
            && is_array($payload) && ($payload['messages'][0]['role'] ?? '') === 'user'
            && ($payload['inferenceConfig']['maxTokens'] ?? 0) === 600
            && count($headers) === 2;
        return ['ok' => $valid, 'status' => $valid ? 200 : 400, 'body' => json_encode(['output' => ['message' => ['content' => [['text' => 'Bedrock test cavabi']]]]]), 'latency_ms' => 1, 'response_bytes' => 80];
    };
    $dry = app_ai_bedrock_chat('salam', $mock, true);
    $dryAvoidedTransport = !$called;
    $reply = app_ai_bedrock_chat('umumi komek et', $mock);
    $public = app_ai_bedrock_public_status();
    $checks = [
      'region_validation' => app_ai_bedrock_valid_region('us-east-1') && !app_ai_bedrock_valid_region('evil.example'),
      'model_validation' => app_ai_bedrock_valid_model('us.anthropic.claude-sonnet-4-6') && !app_ai_bedrock_valid_model('../unsafe'),
      'dry_run' => !empty($dry['ok']) && $dryAvoidedTransport,
      'mock_completion' => !empty($reply['ok']) && $called && ($reply['text'] ?? '') === 'Bedrock test cavabi',
      'secret_not_public' => !array_key_exists('secret', $public) && !str_contains(json_encode($reply) ?: '', 'bedrock-smoke-secret-not-real'),
    ];
} finally { foreach ($old as $key => $value) putenv($value === null ? $key : "$key=$value"); }
$failed = array_keys(array_filter($checks, static fn($value) => !$value));
echo json_encode(['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
