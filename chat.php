<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'reason' => 'legacy_endpoint_disabled',
    'reply' => 'Bu kohne chat endpoint-i baglanib. Emekdas portalindaki Superpilot-dan istifade edin.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
