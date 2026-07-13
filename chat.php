<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

$apiKey = app_config('openai_api_key');
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode([
        'reply' => 'Chat xidməti hazırda aktiv deyil.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$userMessage = trim((string) ($input['message'] ?? ''));

if ($userMessage === '') {
    http_response_code(400);
    echo json_encode([
        'reply' => 'Mesaj boş göndərildi.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = [
    'model' => app_config('openai_model', 'gpt-4o-mini'),
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Sən Super Optik Tech adından Azərbaycan dilində nəzakətli və qısa cavab verən asistentsən.',
        ],
        [
            'role' => 'user',
            'content' => $userMessage,
        ],
    ],
    'temperature' => 0.7,
];

$response = http_post_json(
    'https://api.openai.com/v1/chat/completions',
    $payload,
    [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]
);

$reply = is_array($response)
    ? (string) ($response['choices'][0]['message']['content'] ?? 'Bağışlayın, cavab alınmadı.')
    : 'Bağışlayın, cavab alınmadı.';

echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
