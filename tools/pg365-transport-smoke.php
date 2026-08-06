<?php
declare(strict_types=1);

require dirname(__DIR__) . '/communication-provider.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$assertions = 0;
$check = static function (bool $condition, string $label) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $label);
    }
};

$savedEnvironment = [
    'SMS_PROVIDER_PUBLIC_KEY' => getenv('SMS_PROVIDER_PUBLIC_KEY'),
    'SMS_PROVIDER_PRIVATE_KEY' => getenv('SMS_PROVIDER_PRIVATE_KEY'),
    'TELEGRAM_TOKEN' => getenv('TELEGRAM_TOKEN'),
    'WHATSAPP_PROVIDER_TOKEN' => getenv('WHATSAPP_PROVIDER_TOKEN'),
];
$restoreEnvironment = static function (array $saved): void {
    foreach ($saved as $key => $value) {
        if ($value === false) {
            putenv((string) $key);
        } else {
            putenv((string) $key . '=' . (string) $value);
        }
    }
};

try {
    putenv('SMS_PROVIDER_PUBLIC_KEY');
    putenv('SMS_PROVIDER_PRIVATE_KEY');
    $withoutEnvironment = app_communication_provider_config('sms', '994501112233');
    $check(!array_key_exists('user', $withoutEnvironment), 'public key field is absent from provider config');
    $check(!array_key_exists('password', $withoutEnvironment), 'private key field is absent from provider config');
    $check(!array_key_exists('token', $withoutEnvironment), 'token field is absent from provider config');

    putenv('SMS_PROVIDER_PUBLIC_KEY=unit-public-key');
    putenv('SMS_PROVIDER_PRIVATE_KEY=unit-private-key');
    $fromEnvironment = app_communication_provider_config('sms', '994501112233');
    $check(!empty($fromEnvironment['credentials_ready']), 'SMS credential readiness comes from environment');
    $check(($fromEnvironment['credential_envs'] ?? []) === ['SMS_PROVIDER_PUBLIC_KEY', 'SMS_PROVIDER_PRIVATE_KEY'], 'SMS config exposes env names only');
    $serializedConfig = json_encode($fromEnvironment) ?: '';
    $check(!str_contains($serializedConfig, 'unit-public-key'), 'SMS public key absent from config serialization');
    $check(!str_contains($serializedConfig, 'unit-private-key'), 'SMS private key absent from config serialization');
    $check((string) ($fromEnvironment['driver'] ?? '') === 'pg365_json', 'SMS driver is forced to PG365 JSON');
    $check(app_communication_enabled_setting_value('off') === false, 'disabled channel setting parsed');
    $check(app_communication_enabled_setting_value('enabled') === true, 'enabled channel setting parsed');
    $check(app_communication_channel_enabled('not-a-channel') === false, 'unknown channel disabled');

    $provider = [
        'enabled' => true,
        'configured' => true,
        'driver' => 'pg365_json',
        'endpoint' => APP_COMMUNICATION_SMS_PG365_SEND_ENDPOINT,
        'user' => 'spoofed-public-key-must-be-ignored',
        'password' => 'spoofed-private-key-must-be-ignored',
        'sender' => 'UnitTest',
        'purpose' => 'INF',
        'encoding' => 'LATIN',
        'destination' => '+994 50 111 22 33',
    ];

    $blockedRequests = 0;
    foreach ([
        'http://api.poctgoyercini.com/gateway/api/sms/v1/message/send',
        'https://api.poctgoyercini.com:443/gateway/api/sms/v1/message/send',
        'https://api.poctgoyercini.com.evil.example/gateway/api/sms/v1/message/send',
        'https://example.com/gateway/api/sms/v1/message/send',
    ] as $blockedUrl) {
        $blocked = app_communication_pg365_json_request(
            $blockedUrl,
            'POST',
            ['Text' => 'must not leave process'],
            app_communication_pg365_headers('unit-private-key'),
            static function () use (&$blockedRequests): array {
                $blockedRequests++;
                return ['Status' => 200];
            }
        );
        $check($blocked === null, 'unapproved PG365 request URL rejected: ' . $blockedUrl);
    }
    $check($blockedRequests === 0, 'unapproved URL never reaches transport callback');

    $sendRequests = [];
    $accepted = app_communication_send_poctgoyercini_json_sms(
        $provider,
        'Deterministic test message',
        'communication-message-42',
        static function (string $url, string $method, ?array $payload, array $headers) use (&$sendRequests): array {
            $sendRequests[] = compact('url', 'method', 'payload', 'headers');
            return [
                'Status' => 200,
                'Description' => '',
                'Result' => [
                    'ReceiversAccepted' => [
                        ['id' => 'receiver-10176', 'Receiver' => '994501112233'],
                    ],
                    'ReceiversRejected' => [],
                ],
            ];
        }
    );
    $check(!empty($accepted['ok']), 'accepted send succeeds');
    $check(($accepted['provider_status'] ?? '') === 'accepted', 'accepted receiver remains acceptance state');
    $check(($accepted['provider_message_id'] ?? '') === 'receiver-10176', 'accepted id retained');
    $check(count($sendRequests) === 1 && $sendRequests[0]['method'] === 'POST', 'send uses one POST');
    $check(($sendRequests[0]['payload']['Options']['ReportLabel'] ?? '') === 'communication-message-42', 'stable report label');
    $sendQuery = [];
    parse_str((string) parse_url((string) $sendRequests[0]['url'], PHP_URL_QUERY), $sendQuery);
    $check(($sendQuery['publicKey'] ?? '') === 'unit-public-key', 'public key query parameter');
    $check(in_array('Authorization: Bearer unit-private-key', $sendRequests[0]['headers'], true), 'private key bearer header');

    $withoutOriginatorRequests = [];
    $providerWithoutOriginator = $provider;
    $providerWithoutOriginator['sender'] = '';
    $withoutOriginator = app_communication_send_poctgoyercini_json_sms(
        $providerWithoutOriginator,
        'Provider default header test',
        'communication-message-no-originator',
        static function (string $url, string $method, ?array $payload, array $headers) use (&$withoutOriginatorRequests): array {
            $withoutOriginatorRequests[] = compact('url', 'method', 'payload', 'headers');
            return [
                'Status' => 200,
                'Description' => '',
                'Result' => [
                    'ReceiversAccepted' => [
                        ['id' => 'receiver-no-originator', 'Receiver' => '994501112233'],
                    ],
                    'ReceiversRejected' => [],
                ],
            ];
        }
    );
    $check(!empty($withoutOriginator['ok']), 'send without explicit originator succeeds');
    $check(
        !array_key_exists('Originator', (array) ($withoutOriginatorRequests[0]['payload']['Options'] ?? [])),
        'empty sender omits unapproved originator'
    );

    putenv('TELEGRAM_TOKEN=telegram-secret-must-not-leak');
    $telegramConfig = app_communication_provider_config('telegram', '-1001234567890');
    $check(($telegramConfig['endpoint'] ?? '') === APP_COMMUNICATION_TELEGRAM_BASE_URL, 'Telegram config endpoint is non-secret base URL');
    $check(!array_key_exists('token', $telegramConfig), 'Telegram config has no token field');
    $check(!str_contains(json_encode($telegramConfig) ?: '', 'telegram-secret-must-not-leak'), 'Telegram token absent from config serialization');

    putenv('WHATSAPP_PROVIDER_TOKEN=whatsapp-secret-must-not-leak');
    $whatsappConfig = app_communication_provider_config('whatsapp', '994501112233');
    $check(!array_key_exists('token', $whatsappConfig), 'WhatsApp config has no token field');
    $check(!str_contains(json_encode($whatsappConfig) ?: '', 'whatsapp-secret-must-not-leak'), 'WhatsApp token absent from config serialization');

    $rejected = app_communication_send_poctgoyercini_json_sms(
        $provider,
        'Rejected test message',
        'communication-message-43',
        static fn (): array => [
            'Status' => 200,
            'Description' => '',
            'Result' => [
                'ReceiversAccepted' => [],
                'ReceiversRejected' => [[
                    'RowNumber' => 1,
                    'Receiver' => '994501112233',
                    'ErrorCode' => 'ERRSMS4102',
                    'ErrorMessage' => 'Receiver must be valid MSISDN',
                ]],
            ],
        ]
    );
    $check(empty($rejected['ok']), 'rejected receiver fails');
    $check(($rejected['reason'] ?? '') === 'ERRSMS4102:Receiver must be valid MSISDN', 'rejection reason retained');
    $check(($rejected['provider_status'] ?? '') === 'final_failed', 'rejected receiver is final failed');

    $transportFailure = app_communication_send_poctgoyercini_json_sms(
        $provider,
        'Transient failure',
        'transport-failure',
        static fn (): ?array => null
    );
    $check(empty($transportFailure['ok']), 'transport failure is not accepted');
    $check(($transportFailure['provider_status'] ?? '') === 'pending', 'transport failure remains pending');
    $check(empty($transportFailure['provider_status_final']), 'transport failure is retryable');

    $invalidResponse = app_communication_send_poctgoyercini_json_sms(
        $provider,
        'Invalid response',
        'invalid-response',
        static fn (): array => []
    );
    $check(($invalidResponse['reason'] ?? '') === 'sms_provider_invalid_response', 'invalid response is identified');
    $check(($invalidResponse['provider_status'] ?? '') === 'pending', 'invalid response remains pending');

    foreach ([429, 500, 503] as $retryableStatus) {
        $retryable = app_communication_send_poctgoyercini_json_sms(
            $provider,
            'Retryable provider response',
            'retryable-' . $retryableStatus,
            static fn (): array => [
                'Status' => $retryableStatus,
                'Description' => 'temporary_provider_failure',
            ]
        );
        $check(empty($retryable['ok']), 'retryable status is not accepted: ' . $retryableStatus);
        $check(($retryable['provider_status'] ?? '') === 'pending', 'retryable status remains pending: ' . $retryableStatus);
        $check(empty($retryable['provider_status_final']), 'retryable status is not final: ' . $retryableStatus);
    }

    $redirectResponse = app_communication_send_poctgoyercini_json_sms(
        $provider,
        'Redirect response must not be parsed',
        'redirect-response',
        static fn (): array => [
            'http_status' => 302,
            'body' => json_encode([
                'Status' => 200,
                'Result' => ['ReceiversAccepted' => [['id' => 'must-not-be-accepted']]],
            ]) ?: '',
        ]
    );
    $check(empty($redirectResponse['ok']), 'redirect body is never accepted');
    $check(($redirectResponse['reason'] ?? '') === 'sms_provider_http_302', 'redirect HTTP status is retained');

    $httpServerFailure = app_communication_send_poctgoyercini_json_sms(
        $provider,
        'HTTP server failure',
        'http-server-failure',
        static fn (): array => [
            'http_status' => 503,
            'body' => '{"Status":200}',
        ]
    );
    $check(($httpServerFailure['provider_status'] ?? '') === 'pending', 'HTTP 5xx remains retryable');
    $check(($httpServerFailure['reason'] ?? '') === 'sms_provider_http_503', 'HTTP 5xx body is ignored');

    $singleRequests = [];
    $single = app_communication_pg365_poll_status(
        $provider,
        'receiver-10176',
        static function (string $url, string $method, ?array $payload, array $headers) use (&$singleRequests): array {
            $singleRequests[] = compact('url', 'method', 'payload', 'headers');
            return [
                'Status' => 200,
                'Description' => '',
                'Result' => [
                    'id' => 'receiver-10176',
                    'Receiver' => '994******33',
                    'Status' => 400,
                    'StatusDescription' => 'Delivered',
                    'RecordTime' => '2025-03-25 10:00:00',
                    'SentTime' => '2025-03-25 10:00:01',
                    'DeliverTime' => '2025-03-25 10:00:02',
                    'IsFinal' => true,
                ],
            ];
        }
    );
    $check(!empty($single['ok']), 'single status succeeds');
    $check(($single['item']['delivery_state'] ?? '') === 'delivered', 'single status maps delivered');
    $check(!empty($single['item']['is_final']), 'single status finality retained');
    $singleQuery = [];
    parse_str((string) parse_url((string) $singleRequests[0]['url'], PHP_URL_QUERY), $singleQuery);
    $check($singleRequests[0]['method'] === 'GET' && $singleRequests[0]['payload'] === null, 'single status uses GET without body');
    $check(($singleQuery['id'] ?? '') === 'receiver-10176', 'single status id query parameter');

    $disabledProvider = $provider;
    $disabledProvider['enabled'] = false;
    $disabledProvider['configured'] = false;
    $disabledProvider['status_configured'] = true;
    $disabledProvider['reason'] = 'channel_disabled';
    $disabledPollRequests = 0;
    $disabledPoll = app_communication_pg365_poll_status(
        $disabledProvider,
        'receiver-disabled-switch',
        static function () use (&$disabledPollRequests): array {
            $disabledPollRequests++;
            return [
                'Status' => 200,
                'Result' => [
                    'id' => 'receiver-disabled-switch',
                    'Status' => 300,
                    'StatusDescription' => 'Sent',
                    'IsFinal' => false,
                ],
            ];
        }
    );
    $check(!empty($disabledPoll['ok']) && $disabledPollRequests === 1, 'disabled SMS still permits status polling');

    $manyRequests = [];
    $many = app_communication_pg365_poll_status_many(
        $provider,
        ['receiver-10176', 'receiver-10177', 'receiver-10176'],
        static function (string $url, string $method, ?array $payload, array $headers) use (&$manyRequests): array {
            $manyRequests[] = compact('url', 'method', 'payload', 'headers');
            return [
                'Status' => 200,
                'Description' => '',
                'Result' => [
                    [
                        'id' => 'receiver-10176',
                        'Receiver' => '994******33',
                        'Status' => 300,
                        'StatusDescription' => 'Sent',
                        'IsFinal' => false,
                    ],
                    [
                        'id' => 'receiver-10177',
                        'Receiver' => '994******44',
                        'Status' => 402,
                        'StatusDescription' => 'Not delivered',
                        'IsFinal' => true,
                    ],
                ],
            ];
        }
    );
    $check(!empty($many['ok']) && count($many['items']) === 2, 'many status succeeds');
    $check($manyRequests[0]['method'] === 'GET', 'many status uses GET');
    $check(($manyRequests[0]['payload']['ReceiverIds'] ?? []) === ['receiver-10176', 'receiver-10177'], 'many status body is deduplicated');
    $check(($many['items'][0]['delivery_state'] ?? '') === 'submitted', 'status 300 maps submitted, not delivered');
    $check(($many['items'][1]['delivery_state'] ?? '') === 'final_failed', 'final failure maps final_failed');

    $failedPoll = app_communication_pg365_parse_status_response([
        'Status' => 403,
        'Description' => 'Authorization error',
        'Result' => null,
    ]);
    $check(empty($failedPoll['ok']) && ($failedPoll['reason'] ?? '') === 'Authorization error', 'top-level failure parsed');

    $unsupported = app_communication_send_provider('unsupported', 'none', 'message');
    $check(empty($unsupported['ok']) && ($unsupported['reason'] ?? '') === 'unsupported_channel', 'unsupported channel rejected locally');

    echo json_encode([
        'status' => 'passed',
        'assertions' => $assertions,
        'real_provider_requests' => 0,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $restoreEnvironment($savedEnvironment);
}
