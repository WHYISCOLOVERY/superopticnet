<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/communication-provider.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/ui-language-lib.php';

$lang = app_lang();
$user = billing_require_employee();

$flash = null;
$providerTestResult = null;
$db = app_db();
$telegramBotDefinitions = app_mgs_telegram_bot_definitions();

function message_center_mask_destination(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }

    $length = strlen($value);
    if ($length <= 6) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 3) . str_repeat('*', max(3, $length - 6)) . substr($value, -3);
}

function message_center_text_preview(string $value, int $limit = 180): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if (mb_strlen($value) <= $limit) {
        return $value;
    }

    return mb_substr($value, 0, $limit - 3) . '...';
}

function message_center_channel_label(string $channel): string
{
    static $labels = [
        'auto' => 'Avtomatik secim',
        'whatsapp' => 'WhatsApp',
        'app' => 'Tetbiq / portal',
        'telegram' => 'Telegram',
        'sms' => 'SMS provider',
        'other' => 'Diger kanal',
    ];

    $key = strtolower(trim($channel));
    return $labels[$key] ?? ($channel !== '' ? $channel : '-');
}

function message_center_status_label(string $status): string
{
    if ($status === 'all') {
        return app_ui_status('');
    }

    return app_ui_status($status);
}

function message_center_mode_label(string $mode): string
{
    static $labels = [
        'apply' => 'Real gonderim',
        'dry-run' => 'Yoxlama rejimi',
        'test' => 'Test',
    ];

    $key = strtolower(trim($mode));
    return $labels[$key] ?? ($mode !== '' ? $mode : '-');
}

function message_center_reason_label(string $reason): string
{
    static $labels = [
        '' => 'Gonderilmeyib',
        'provider_not_configured_or_destination_missing' => 'Provider konfiqurasiyasi ve ya unvan catismir',
        'dry_run_no_provider_request_sent' => 'Dry-run: real provider sorgusu gonderilmedi',
        'provider_rejected' => 'Provider imtina etdi',
        'not sent' => 'Gonderilmeyib',
        'unknown' => 'Bilinmir',
    ];

    $key = strtolower(trim($reason));
    return $labels[$key] ?? ($reason !== '' ? $reason : $labels['']);
}

function message_center_filtered_message_where(string $status, string $channel, string $query, array &$params): array
{
    $allowedStatuses = ['queued', 'sent', 'failed', 'test'];
    $allowedChannels = ['auto', 'whatsapp', 'app', 'telegram', 'sms'];
    $where = ['(m.account_id IS NOT NULL OR NULLIF(m.subscriber_code, "") IS NOT NULL)'];

    if (in_array($status, $allowedStatuses, true)) {
        $where[] = 'm.status = :status';
        $params['status'] = $status;
    }

    if (in_array($channel, $allowedChannels, true)) {
        $where[] = 'm.channel = :channel';
        $params['channel'] = $channel;
    }

    if ($query !== '') {
        $where[] = '(m.subscriber_code LIKE :query OR m.recipient_name LIKE :query OR m.destination LIKE :query OR a.full_name LIKE :query OR a.phone LIKE :query)';
        $params['query'] = '%' . $query . '%';
    }

    return $where;
}

function message_center_filtered_messages(PDO $db, string $status, string $channel, string $query, int $limit = 60, int $offset = 0): array
{
    $params = [];
    $where = message_center_filtered_message_where($status, $channel, $query, $params);

    $sql = 'SELECT m.*, a.full_name, a.phone, a.area
            FROM communication_messages m
            LEFT JOIN portal_accounts a ON a.id = m.account_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . max(1, min($limit, 100)) . ' OFFSET ' . max(0, $offset);

    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll() ?: [];
}

function message_center_filtered_messages_count(PDO $db, string $status, string $channel, string $query): int
{
    $params = [];
    $where = message_center_filtered_message_where($status, $channel, $query, $params);

    $sql = 'SELECT COUNT(*) AS total
            FROM communication_messages m
            LEFT JOIN portal_accounts a ON a.id = m.account_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $statement = $db->prepare($sql);
    $statement->execute($params);
    return (int) ($statement->fetchColumn() ?: 0);
}

function message_center_page_url(string $pageParam, int $page): string
{
    $query = $_GET;
    $query[$pageParam] = max(1, $page);
    return 'message-center.php?' . http_build_query($query);
}

function message_center_pagination(string $pageParam, int $currentPage, int $totalPages, int $totalRows, int $pageSize): void
{
    if ($totalRows <= $pageSize) {
        return;
    }

    $fromRow = (($currentPage - 1) * $pageSize) + 1;
    $toRow = min($totalRows, $currentPage * $pageSize);
    ?>
    <div class="message-pagination" aria-label="Cedvel sehifeleme">
        <div class="message-pagination-info">
            <?= e((string) $fromRow); ?>-<?= e((string) $toRow); ?> / <?= e((string) $totalRows); ?> gosterilir
        </div>
        <div class="message-pagination-actions">
            <a class="portal-ghost-btn portal-inline-link <?= $currentPage <= 1 ? 'is-disabled' : ''; ?>" data-message-nav href="<?= e(message_center_page_url($pageParam, max(1, $currentPage - 1))); ?>">Evvelki</a>
            <span class="message-page-count"><?= e((string) $currentPage); ?> / <?= e((string) $totalPages); ?></span>
            <a class="portal-ghost-btn portal-inline-link <?= $currentPage >= $totalPages ? 'is-disabled' : ''; ?>" data-message-nav href="<?= e(message_center_page_url($pageParam, min($totalPages, $currentPage + 1))); ?>">Novbeti</a>
        </div>
    </div>
    <?php
}

function message_center_percent_value(float $value, float $max): int
{
    if ($max <= 0.0) {
        return 0;
    }

    return (int) max(0, min(100, round(($value / $max) * 100)));
}

function message_center_failed_reason_summary(PDO $db): array
{
    $statement = $db->query(
        'SELECT COALESCE(NULLIF(reason, ""), "unknown") AS reason, channel, COUNT(*) AS total, MAX(attempted_at) AS last_seen
         FROM communication_delivery_attempts
         WHERE status = "failed"
         GROUP BY COALESCE(NULLIF(reason, ""), "unknown"), channel
         ORDER BY total DESC, last_seen DESC
         LIMIT 8'
    );

    return $statement !== false ? ($statement->fetchAll() ?: []) : [];
}

function message_center_retry_failed_messages(PDO $db, string $channel, string $query, array $actor, int $limit = 50): int
{
    $allowedChannels = ['auto', 'whatsapp', 'app', 'telegram', 'sms'];
    $where = ['m.status = "failed"'];
    $params = [];

    if (in_array($channel, $allowedChannels, true)) {
        $where[] = 'm.channel = :channel';
        $params['channel'] = $channel;
    }

    if ($query !== '') {
        $where[] = '(m.subscriber_code LIKE :query OR m.recipient_name LIKE :query OR m.destination LIKE :query)';
        $params['query'] = '%' . $query . '%';
    }

    $select = $db->prepare(
        'SELECT m.*
         FROM communication_messages m
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT ' . max(1, min($limit, 100))
    );
    $select->execute($params);
    $rows = $select->fetchAll() ?: [];

    $updated = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $metadata = app_db_json_decode((string) ($row['metadata_json'] ?? ''), []);
        $metadata['retry'] = [
            'queued_by_account_id' => (int) ($actor['id'] ?? 0),
            'queued_by' => (string) ($actor['full_name'] ?? $actor['login'] ?? ''),
            'queued_at' => app_db_now(),
            'previous_status' => 'failed',
            'bulk' => true,
        ];

        $update = $db->prepare(
            'UPDATE communication_messages
             SET status = "queued", sent_at = NULL, metadata_json = :metadata_json
             WHERE id = :id AND status = "failed"'
        );
        $update->execute([
            'id' => (int) ($row['id'] ?? 0),
            'metadata_json' => app_db_json_encode($metadata),
        ]);
        $updated += $update->rowCount() > 0 ? 1 : 0;
    }

    return $updated;
}

function message_center_whatsapp_guard_stats(?PDO $db): array
{
    $empty = [
        'contacts' => 0,
        'known_contacts' => 0,
        'sent_24h' => 0,
        'blocked' => 0,
        'global_sent_24h' => 0,
        'global_sent_hour' => 0,
        'global_blocked_until' => '',
    ];
    if (!$db instanceof PDO) {
        return $empty;
    }

    try {
        app_communication_whatsapp_guard_schema($db);
        $row = $db->query(
            'SELECT
                COUNT(*) AS contacts,
                SUM(CASE WHEN known_contact = 1 THEN 1 ELSE 0 END) AS known_contacts,
                SUM(sent_24h) AS sent_24h,
                SUM(CASE WHEN blocked_until IS NOT NULL AND blocked_until > NOW() THEN 1 ELSE 0 END) AS blocked
             FROM whatsapp_delivery_guard'
        )->fetch(PDO::FETCH_ASSOC);
        $global = $db->query(
            'SELECT sent_24h, sent_hour, blocked_until
             FROM whatsapp_delivery_guard_global
             WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'contacts' => (int) ($row['contacts'] ?? 0),
            'known_contacts' => (int) ($row['known_contacts'] ?? 0),
            'sent_24h' => (int) ($row['sent_24h'] ?? 0),
            'blocked' => (int) ($row['blocked'] ?? 0),
            'global_sent_24h' => is_array($global) ? (int) ($global['sent_24h'] ?? 0) : 0,
            'global_sent_hour' => is_array($global) ? (int) ($global['sent_hour'] ?? 0) : 0,
            'global_blocked_until' => is_array($global) ? (string) ($global['blocked_until'] ?? '') : '',
        ] : $empty;
    } catch (Throwable) {
        return $empty;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($action === 'send_single') {
        $subscriberCode = clean_text((string) ($_POST['subscriber_code'] ?? ''));
        $account = app_db_account_by_subscriber_code($subscriberCode);
        if ($account === null) {
            $flash = ['type' => 'error', 'message' => 'Musteri tapilmadi. Muqavile/abonent kodunu yoxlayin.'];
        } else {
            $ok = app_message_queue_for_account(
                $account,
                clean_text((string) ($_POST['template_code'] ?? '')),
                clean_text((string) ($_POST['channel'] ?? 'auto')),
                trim((string) ($_POST['custom_message'] ?? '')),
                $user,
                isset($_POST['test_mode'])
            );
            $flash = [
                'type' => $ok ? 'success' : 'error',
                'message' => $ok ? 'Mesaj gonderme novbesine yazildi.' : 'Mesaj hazirlanmadi. Sablon ve metni yoxlayin.',
            ];
        }
    } elseif ($action === 'send_group' && app_portal_is_admin($user)) {
        $result = app_message_queue_group(
            clean_text((string) ($_POST['scope'] ?? 'active')),
            clean_text((string) ($_POST['area'] ?? '')),
            clean_text((string) ($_POST['template_code'] ?? '')),
            clean_text((string) ($_POST['channel'] ?? 'auto')),
            trim((string) ($_POST['custom_message'] ?? '')),
            $user,
            isset($_POST['test_mode'])
        );
        $flash = [
            'type' => ((int) $result['failed']) === 0 ? 'success' : 'error',
            'message' => 'Qrup mesajlari: uygun ' . (int) $result['matched'] . ', novbeye yazilan ' . (int) $result['queued'] . ', xeta ' . (int) $result['failed'] . '.',
        ];
    } elseif ($action === 'provider_test' && app_portal_is_admin($user)) {
        $channel = clean_text((string) ($_POST['provider_channel'] ?? 'telegram'));
        $providerBotKey = clean_text((string) ($_POST['provider_bot_key'] ?? 'superpilot')) ?: 'superpilot';
        $destination = trim((string) ($_POST['provider_destination'] ?? ''));
        $message = trim((string) ($_POST['provider_message'] ?? ''));
        $applyProviderTest = isset($_POST['provider_apply']);

        if (!in_array($channel, ['telegram', 'sms', 'whatsapp', 'app'], true)) {
            $flash = ['type' => 'error', 'message' => 'Provider test kanali yanlisdir.'];
        } elseif ($channel === 'telegram' && !isset($telegramBotDefinitions[$providerBotKey])) {
            $flash = ['type' => 'error', 'message' => 'Telegram bot profili yanlisdir.'];
        } else {
            if ($message === '') {
                $message = 'TEST MESAJI' . "\n" . 'SuperOptic MGS provider UI smoke test: ' . date('Y-m-d H:i:s');
            } elseif (!str_starts_with($message, 'TEST MESAJI')) {
                $message = 'TEST MESAJI' . "\n" . $message;
            }
            if (mb_strlen($message) > 640) {
                $message = mb_substr($message, 0, 640);
            }

            $provider = $channel === 'telegram'
                ? app_mgs_telegram_bot_config($providerBotKey, $destination)
                : app_communication_provider_config($channel, $destination);
            $providerTestResult = [
                'mode' => $applyProviderTest ? 'apply' : 'dry-run',
                'channel' => $channel,
                'bot_key' => $channel === 'telegram' ? $providerBotKey : '',
                'configured' => (bool) ($provider['configured'] ?? false),
                'delivery' => $channel === 'telegram' ? 'telegram_bot' : (string) ($provider['delivery'] ?? 'unsupported'),
                'destination' => message_center_mask_destination((string) ($provider['destination'] ?? '')),
                'sent' => false,
                'reason' => '',
                'provider_message_id' => null,
            ];

            if (empty($provider['configured'])) {
                $providerTestResult['reason'] = 'provider_not_configured_or_destination_missing';
                $flash = ['type' => 'error', 'message' => 'Provider test hazir deyil: konfigurasiya ve ya unvan yoxdur.'];
            } elseif (!$applyProviderTest) {
                $providerTestResult['reason'] = 'dry_run_no_provider_request_sent';
                $flash = ['type' => 'success', 'message' => 'Provider yoxlama rejimi ugurludur. Real mesaj gonderilmedi.'];
            } else {
                if ($channel === 'telegram') {
                    $sendResult = http_post_json((string) ($provider['endpoint'] ?? ''), [
                        'chat_id' => (string) ($provider['destination'] ?? ''),
                        'text' => $message,
                        'disable_web_page_preview' => true,
                    ]);
                    $sendResult = [
                        'ok' => is_array($sendResult) && !empty($sendResult['ok']),
                        'provider_message_id' => is_array($sendResult) ? ($sendResult['result']['message_id'] ?? null) : null,
                        'reason' => is_array($sendResult) && empty($sendResult['ok'])
                            ? (string) ($sendResult['description'] ?? 'provider_rejected')
                            : '',
                    ];
                } else {
                    $sendResult = app_communication_send_provider($channel, $destination, $message, 'ui-provider-smoke-' . date('YmdHis'));
                }
                $providerTestResult['sent'] = !empty($sendResult['ok']);
                $providerTestResult['reason'] = (string) ($sendResult['reason'] ?? '');
                $providerTestResult['provider_message_id'] = $sendResult['provider_message_id'] ?? null;
                $flash = [
                    'type' => $providerTestResult['sent'] ? 'success' : 'error',
                    'message' => $providerTestResult['sent'] ? 'Provider TEST MESAJI gonderildi.' : 'Provider TEST MESAJI gonderilmedi.',
                ];
            }
        }
    } elseif ($action === 'save_sms_provider_credentials' && app_portal_is_admin($user)) {
        $smsUsername = clean_text((string) ($_POST['sms_provider_username'] ?? ''));
        $smsPassword = trim((string) ($_POST['sms_provider_password'] ?? ''));
        $existingSmsProvider = app_communication_provider_config('sms', '994000000000');
        $existingPassword = (string) ($existingSmsProvider['password'] ?? '');
        if ($smsUsername === '') {
            $flash = ['type' => 'error', 'message' => 'SMS provider username bos ola bilmez.'];
        } elseif ($smsPassword === '' && $existingPassword === '') {
            $flash = ['type' => 'error', 'message' => 'SMS provider password bos ola bilmez.'];
        } else {
            $settings = [
                'sms_provider_driver' => 'poctgoyercini_json',
                'sms_provider_endpoint' => APP_COMMUNICATION_SMS_POCTGOYERCINI_SEND_ENDPOINT,
                'sms_provider_user' => $smsUsername,
                'sms_provider_password' => $smsPassword !== '' ? $smsPassword : $existingPassword,
                'sms_provider_sender' => 'SuperOptic',
                'sms_provider_purpose' => 'INF',
                'sms_provider_encoding' => 'LATIN',
            ];
            $saved = 0;
            foreach ($settings as $settingKey => $settingValue) {
                if (app_mgs_set_runtime_setting($settingKey, $settingValue, $user)) {
                    $saved++;
                }
            }
            $flash = [
                'type' => $saved === count($settings) ? 'success' : 'error',
                'message' => $saved === count($settings)
                    ? 'SMS provider melumatlari saxlanildi. API arxada poctgoyercini JSON modeli ile isleyir.'
                    : 'SMS provider melumatlarinin bir hissesi saxlanmadi.',
            ];
        }
    } elseif ($action === 'retry_message' && app_portal_is_admin($user)) {
        $messageId = (int) ($_POST['message_id'] ?? 0);
        if (!$db instanceof PDO || $messageId <= 0) {
            $flash = ['type' => 'error', 'message' => 'Mesaj secimi yanlisdir.'];
        } else {
            $messageStatement = $db->prepare('SELECT * FROM communication_messages WHERE id = :id LIMIT 1');
            $messageStatement->execute(['id' => $messageId]);
            $messageRow = $messageStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($messageRow)) {
                $flash = ['type' => 'error', 'message' => 'Mesaj tapilmadi.'];
            } elseif ((string) ($messageRow['status'] ?? '') !== 'failed') {
                $flash = ['type' => 'error', 'message' => 'Yalniz xetali mesaj yeniden novbeye qaytarila biler.'];
            } else {
                $metadata = app_db_json_decode((string) ($messageRow['metadata_json'] ?? ''), []);
                $metadata['retry'] = [
                    'queued_by_account_id' => (int) ($user['id'] ?? 0),
                    'queued_by' => (string) ($user['full_name'] ?? $user['login'] ?? ''),
                    'queued_at' => app_db_now(),
                    'previous_status' => 'failed',
                ];
                $update = $db->prepare(
                    'UPDATE communication_messages
                     SET status = "queued", sent_at = NULL, metadata_json = :metadata_json
                     WHERE id = :id AND status = "failed"'
                );
                $update->execute([
                    'id' => $messageId,
                    'metadata_json' => app_db_json_encode($metadata),
                ]);
                $flash = [
                    'type' => $update->rowCount() > 0 ? 'success' : 'error',
                    'message' => $update->rowCount() > 0 ? 'Mesaj yeniden provider novbesine qaytarildi.' : 'Mesaj statusu deyismedi.',
                ];
            }
        }
    } elseif ($action === 'bulk_retry_failed' && app_portal_is_admin($user)) {
        $bulkChannel = clean_text((string) ($_POST['bulk_channel'] ?? 'all'));
        $bulkQuery = clean_text((string) ($_POST['bulk_q'] ?? ''));
        if (!$db instanceof PDO) {
            $flash = ['type' => 'error', 'message' => 'DB hazir deyil.'];
        } else {
            $updated = message_center_retry_failed_messages($db, $bulkChannel, $bulkQuery, $user, 50);
            $flash = [
                'type' => $updated > 0 ? 'success' : 'error',
                'message' => $updated > 0
                    ? $updated . ' xetali mesaj yeniden provider novbesine qaytarildi.'
                    : 'Retry ucun uygun xetali mesaj tapilmadi.',
            ];
        }
    } elseif ($action === 'update_whatsapp_guard' && app_portal_is_admin($user)) {
        $settings = [
            'whatsapp_unknown_daily_limit' => max(1, min(50, (int) ($_POST['whatsapp_unknown_daily_limit'] ?? 5))),
            'whatsapp_known_daily_limit' => max(1, min(500, (int) ($_POST['whatsapp_known_daily_limit'] ?? 60))),
            'whatsapp_unknown_min_gap_sec' => max(60, min(86400, (int) ($_POST['whatsapp_unknown_min_gap_sec'] ?? 900))),
            'whatsapp_known_min_gap_sec' => max(30, min(86400, (int) ($_POST['whatsapp_known_min_gap_sec'] ?? 120))),
            'whatsapp_global_daily_limit' => max(1, min(1000, (int) ($_POST['whatsapp_global_daily_limit'] ?? 80))),
            'whatsapp_global_hourly_limit' => max(1, min(200, (int) ($_POST['whatsapp_global_hourly_limit'] ?? 10))),
            'whatsapp_global_min_gap_sec' => max(30, min(3600, (int) ($_POST['whatsapp_global_min_gap_sec'] ?? 90))),
        ];
        $saved = 0;
        foreach ($settings as $settingKey => $settingValue) {
            if (app_mgs_set_runtime_setting($settingKey, (string) $settingValue, $user)) {
                $saved++;
            }
        }
        $flash = [
            'type' => $saved === count($settings) ? 'success' : 'error',
            'message' => $saved === count($settings)
                ? 'WhatsApp guard limitleri yenilendi.'
                : 'WhatsApp guard limitlerinin bir hissesi saxlanmadi.',
        ];
    }
}

$templates = app_message_templates();
$stats = app_message_stats();
$messageStatusFilter = clean_text((string) ($_GET['message_status'] ?? 'all'));
$messageChannelFilter = clean_text((string) ($_GET['message_channel'] ?? 'all'));
$messageSearch = clean_text((string) ($_GET['message_q'] ?? ''));
$messageView = in_array((string) ($_GET['view'] ?? 'overview'), ['overview', 'single', 'group', 'templates', 'history', 'audit', 'test', 'settings'], true)
    ? (string) ($_GET['view'] ?? 'overview')
    : 'overview';
if (in_array($messageView, ['group', 'test', 'settings'], true) && !app_portal_is_admin($user)) {
    $messageView = 'overview';
}
$messagePageSize = 20;
$messagePage = max(1, (int) ($_GET['message_page'] ?? 1));
$messageTotal = 0;
$auditPageSize = 20;
$auditPage = max(1, (int) ($_GET['audit_page'] ?? 1));
$auditTotal = 0;
$recent = app_message_recent($messagePageSize);
$queueByChannel = [
    'app' => 0,
    'telegram' => 0,
    'sms' => 0,
    'whatsapp' => 0,
    'other' => 0,
];
$queueByStatus = [
    'queued' => 0,
    'sent' => 0,
    'failed' => 0,
    'test' => 0,
];
$messageChannelUsage = [
    'whatsapp' => 0,
    'telegram' => 0,
    'sms' => 0,
    'app' => 0,
    'auto' => 0,
    'other' => 0,
];
$messageChannelStatusUsage = [];
$deliveryAttempts = [];
$failedReasonSummary = [];
$workerRuns = [];
$providerReadiness = [
    'app' => app_communication_provider_public_status('app'),
    'telegram' => app_communication_provider_public_status('telegram'),
    'sms' => app_communication_provider_public_status('sms'),
    'whatsapp' => app_communication_provider_public_status('whatsapp'),
];
$smsProviderSettings = app_communication_provider_config('sms', '994000000000');
$smsProviderUsername = (string) ($smsProviderSettings['user'] ?? '');
$smsProviderHasPassword = (string) ($smsProviderSettings['password'] ?? '') !== '';
$whatsappBridge = is_array($providerReadiness['whatsapp']['bridge'] ?? null) ? $providerReadiness['whatsapp']['bridge'] : [];
$whatsappQr = app_portal_is_admin($user) && $messageView === 'settings'
    ? app_communication_whatsapp_bridge_qr()
    : ['ok' => false, 'status' => 'not_requested', 'qr' => ''];
$whatsappGuardLimits = app_communication_whatsapp_guard_limits();
$whatsappGuardStats = message_center_whatsapp_guard_stats($db);
if ($db instanceof PDO) {
    $channelRows = $db->query(
        'SELECT channel, COUNT(*) AS total
         FROM communication_messages
         WHERE status = "queued"
         GROUP BY channel'
    )->fetchAll() ?: [];
    foreach ($channelRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = strtolower((string) ($row['channel'] ?? 'other'));
        if (!array_key_exists($key, $queueByChannel)) {
            $key = 'other';
        }
        $queueByChannel[$key] += (int) ($row['total'] ?? 0);
    }

    $usageRows = $db->query(
        'SELECT channel, status, COUNT(*) AS total
         FROM communication_messages
         GROUP BY channel, status'
    )->fetchAll() ?: [];
    foreach ($usageRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = strtolower((string) ($row['channel'] ?? 'other'));
        if (!array_key_exists($key, $messageChannelUsage)) {
            $key = 'other';
        }
        $statusKey = strtolower((string) ($row['status'] ?? 'queued'));
        $total = (int) ($row['total'] ?? 0);
        $messageChannelUsage[$key] += $total;
        $messageChannelStatusUsage[$key][$statusKey] = ($messageChannelStatusUsage[$key][$statusKey] ?? 0) + $total;
    }

    $statusRows = $db->query(
        'SELECT status, COUNT(*) AS total
         FROM communication_messages
         GROUP BY status'
    )->fetchAll() ?: [];
    foreach ($statusRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = strtolower((string) ($row['status'] ?? 'queued'));
        if (!array_key_exists($key, $queueByStatus)) {
            continue;
        }
        $queueByStatus[$key] = (int) ($row['total'] ?? 0);
    }

    try {
        $messageTotal = message_center_filtered_messages_count($db, $messageStatusFilter, $messageChannelFilter, $messageSearch);
        $messageTotalPages = max(1, (int) ceil($messageTotal / $messagePageSize));
        $messagePage = min($messagePage, $messageTotalPages);
        $recent = message_center_filtered_messages(
            $db,
            $messageStatusFilter,
            $messageChannelFilter,
            $messageSearch,
            $messagePageSize,
            ($messagePage - 1) * $messagePageSize
        );

        $auditTotal = (int) ($db->query('SELECT COUNT(*) FROM communication_delivery_attempts')->fetchColumn() ?: 0);
        $auditTotalPages = max(1, (int) ceil($auditTotal / $auditPageSize));
        $auditPage = min($auditPage, $auditTotalPages);
        $auditOffset = ($auditPage - 1) * $auditPageSize;
        $deliveryAttempts = $db->query(
            'SELECT d.*, m.recipient_name
             FROM communication_delivery_attempts d
             LEFT JOIN communication_messages m ON m.id = d.message_id
             ORDER BY d.attempted_at DESC, d.id DESC
             LIMIT ' . $auditPageSize . ' OFFSET ' . $auditOffset
        )->fetchAll() ?: [];
    } catch (Throwable) {
        $deliveryAttempts = [];
    }

    try {
        $failedReasonSummary = message_center_failed_reason_summary($db);
    } catch (Throwable) {
        $failedReasonSummary = [];
    }

    try {
        $workerRuns = $db->query(
            'SELECT *
             FROM communication_worker_runs
             ORDER BY finished_at DESC, id DESC
             LIMIT 12'
        )->fetchAll() ?: [];
    } catch (Throwable) {
        $workerRuns = [];
    }
}
if ($templates === []) {
    $templates = [
        ['code' => 'payment_received', 'title' => 'Odenis qebul edildi', 'category' => 'billing', 'body' => 'Hormetli {full_name}, {customer_code} kodlu xidmetiniz uzre odenis qebul edildi. Xidmetiniz aktivdir.'],
        ['code' => 'expires_tomorrow', 'title' => '1 gun sonra bitme', 'category' => 'billing', 'body' => 'Hormetli {full_name}, {customer_code} kodlu internet xidmetiniz 1 gun sonra bitecek. Davam etmek ucun odenis edin.'],
        ['code' => 'modem_heat_warning', 'title' => 'Modem qizma xeberdarligi', 'category' => 'technical', 'body' => 'Hormetli {full_name}, modeminizde qizma ve qeyri-stabil isleme elameti gorunur. Suret ve baglanti problemi yaranarsa texniki yoxlama tovsiye olunur.'],
        ['code' => 'retention_60d', 'title' => '60+ gun odenissiz musteri', 'category' => 'retention', 'body' => 'Hormetli {full_name}, SuperOptic xidmetini yeniden aktiv etmek ucun sizin ucun uygun kampaniya teklifi hazirlaya bilerik.'],
    ];
}
if (($stats['templates'] ?? 0) === 0) {
    $stats['templates'] = count($templates);
}
if ($recent === []) {
    $sampleCustomer = billing_mock_customer_rows()[0] ?? [];
    $recent = $sampleCustomer === [] ? [] : [[
        'created_at' => date('Y-m-d H:i:s'),
        'subscriber_code' => (string) ($sampleCustomer['subscriber_code'] ?? ''),
        'recipient_name' => (string) ($sampleCustomer['full_name'] ?? ''),
        'channel' => 'test',
        'fallback_chain' => 'auto>whatsapp',
        'status' => 'test',
        'message_body' => 'TEST MESAJI: mesaj merkezi dizayn ve tarixce gorunusu ucun numune qeyd.',
    ]];
}
if ($messageTotal === 0 && $recent !== []) {
    $messageTotal = count($recent);
}
$areas = app_service_areas();
$customerQuery = clean_text((string) ($_GET['q'] ?? ''));
$customerRows = [];
if ($db instanceof PDO) {
    if ($customerQuery !== '') {
        $statement = $db->prepare(
            "SELECT * FROM portal_accounts
             WHERE role = 'customer'
               AND (subscriber_code LIKE :q OR full_name LIKE :q OR phone LIKE :q OR login LIKE :q)
             ORDER BY updated_at DESC
             LIMIT 25"
        );
        $statement->execute(['q' => '%' . $customerQuery . '%']);
        $customerRows = $statement->fetchAll() ?: [];
    } else {
        $customerRows = $db->query("SELECT * FROM portal_accounts WHERE role = 'customer' ORDER BY updated_at DESC LIMIT 15")->fetchAll() ?: [];
    }
} else {
    $mockRows = billing_mock_customer_rows();
    if ($customerQuery !== '') {
        $needle = strtolower($customerQuery);
        $mockRows = array_values(array_filter($mockRows, static function (array $row) use ($needle): bool {
            return str_contains(strtolower(implode(' ', [
                (string) ($row['subscriber_code'] ?? ''),
                (string) ($row['full_name'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['login'] ?? ''),
            ])), $needle);
        }));
    }
    $customerRows = array_slice($mockRows, 0, 15);
}

$channelLabels = [
    'auto' => message_center_channel_label('auto'),
    'whatsapp' => message_center_channel_label('whatsapp'),
    'app' => message_center_channel_label('app'),
    'telegram' => message_center_channel_label('telegram'),
    'sms' => message_center_channel_label('sms'),
];
$channelTone = [
    'whatsapp' => '#16a34a',
    'telegram' => '#2563eb',
    'sms' => '#f59e0b',
    'app' => '#7c3aed',
    'auto' => '#0f766e',
    'other' => '#64748b',
];
if (array_sum($messageChannelUsage) === 0 && $recent !== []) {
    foreach ($recent as $message) {
        if (!is_array($message)) {
            continue;
        }
        $key = strtolower((string) ($message['channel'] ?? 'other'));
        if (!array_key_exists($key, $messageChannelUsage)) {
            $key = 'other';
        }
        $statusKey = strtolower((string) ($message['status'] ?? 'queued'));
        $messageChannelUsage[$key]++;
        $messageChannelStatusUsage[$key][$statusKey] = ($messageChannelStatusUsage[$key][$statusKey] ?? 0) + 1;
    }
}
$messageChannelTotal = max(1, array_sum($messageChannelUsage));
$messageChannelMax = max(1, max($messageChannelUsage));
$messageChannelRows = [];
foreach (['whatsapp', 'telegram', 'sms', 'app', 'auto', 'other'] as $channelKey) {
    $total = (int) ($messageChannelUsage[$channelKey] ?? 0);
    $messageChannelRows[] = [
        'key' => $channelKey,
        'label' => (string) ($channelLabels[$channelKey] ?? ucfirst($channelKey)),
        'total' => $total,
        'percent' => message_center_percent_value((float) $total, (float) $messageChannelTotal),
        'bar' => message_center_percent_value((float) $total, (float) $messageChannelMax),
        'tone' => (string) ($channelTone[$channelKey] ?? '#64748b'),
        'statuses' => $messageChannelStatusUsage[$channelKey] ?? [],
    ];
}
$messageChannelRingStops = [];
$ringCursor = 0;
foreach ($messageChannelRows as $row) {
    $percent = (int) ($row['percent'] ?? 0);
    if ($percent <= 0) {
        continue;
    }
    $nextCursor = min(100, $ringCursor + $percent);
    $messageChannelRingStops[] = (string) $row['tone'] . ' ' . $ringCursor . '% ' . $nextCursor . '%';
    $ringCursor = $nextCursor;
}
if ($ringCursor < 100 && $messageChannelRingStops !== []) {
    $messageChannelRingStops[] = '#e2e8f0 ' . $ringCursor . '% 100%';
}
$messageChannelRingGradient = $messageChannelRingStops !== []
    ? 'conic-gradient(' . implode(',', $messageChannelRingStops) . ')'
    : 'conic-gradient(#e2e8f0 0 100%)';
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mesaj merkezi</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <link rel="stylesheet" href="<?= e(app_asset('css/message-center.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('Mesaj merkezi', 'messages', $lang); ?>

    <main class="portal-layout message-center-layout" data-message-shell data-message-view="<?= e($messageView); ?>">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>" data-autohide-alert>
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">Mesaj merkezi</span>
                <h1>Gundelik mesaj emeliyyatlari</h1>
                <p>Musteriye mesaj gonderin. "Avtomatik secim" uygun kanali ozu tapir.</p>
            </div>
            <div class="portal-status-card">
                <span>Novbe statusu</span>
                <strong>Gonderime hazir</strong>
                <small>Mesajlar novbeye yazilir ve sirayla gonderilir</small>
            </div>
        </section>

        <?php if ($messageView === 'overview'): ?>
        <section class="portal-grid portal-grid-4 portal-reveal">
            <article class="portal-metric-card"><span>Sablonlar</span><strong><?= e((string) $stats['templates']); ?></strong><small>Hazir mesaj metnleri</small></article>
            <article class="portal-metric-card"><span>Bu gun</span><strong><?= e((string) $stats['today']); ?></strong><small>Yaradilan mesajlar</small></article>
            <article class="portal-metric-card"><span>Novbede</span><strong><?= e((string) $stats['queued']); ?></strong><small>Gonderilmesini gozleyen</small></article>
            <article class="portal-metric-card"><span>Sinaq</span><strong><?= e((string) $stats['test']); ?></strong><small>Real gonderilmeyen yoxlamalar</small></article>
        </section>
        <?php endif; ?>

        <?php
        $messageHref = static fn (string $view): string => 'message-center.php?view=' . rawurlencode($view) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '');
        $smsProviderSettingsHref = $messageHref('settings') . '#sms-provider-settings';
        $messageLink = static fn (string $key, string $label): array => ['key'=>$key,'label'=>$label,'href'=>$messageHref($key)];
        $messageSendLinks = [$messageLink('single','Ferdi mesaj')];
        if (app_portal_is_admin($user)) $messageSendLinks[] = $messageLink('group','Qrup mesaj');
        $moduleNavGroups = [
            ['label'=>'Umumi baxis','icon'=>'fa-gauge-high','links'=>[$messageLink('overview','Umumi baxis')]],
            ['label'=>'Mesaj gonder','icon'=>'fa-paper-plane','links'=>$messageSendLinks],
            ['label'=>'Mezmun','icon'=>'fa-file-lines','links'=>[$messageLink('templates','Sablonlar')]],
            ['label'=>'Tarixce ve audit','icon'=>'fa-clock-rotate-left','links'=>[$messageLink('history','Tarixce'),$messageLink('audit','Gonderim auditi')]],
        ];
        if (app_portal_is_admin($user)) $moduleNavGroups[] = ['label'=>'Ayarlar','icon'=>'fa-sliders','links'=>[['key'=>'settings','label'=>'Kanal ve provider ayarlari','href'=>$smsProviderSettingsHref]]];
        if (app_portal_is_admin($user)) $moduleNavGroups[] = ['label'=>'Provider testi','icon'=>'fa-vial','links'=>[$messageLink('test','Provider testi')]];
        $moduleNavActive = $messageView;
        $moduleNavAria = 'Mesaj merkezi bolmeleri';
        $moduleNavName = 'message-module-nav';
        $moduleNavLinkAttribute = 'data-message-nav';
        require __DIR__ . '/views/_module-group-nav.php';
        ?>

        <div class="message-command-strip portal-reveal" data-message-command-strip>
            <div>
                <strong>Mesaj emeliyyatlari</strong>
                <small>Gonderim, tarixce ve kanal ayarlarina sehife yenilenmeden kecin.</small>
            </div>
            <div class="message-command-actions">
                <a class="portal-primary-btn portal-inline-link" data-message-nav data-module-section="single" href="<?= e($messageHref('single')); ?>"><i class="fa-solid fa-paper-plane"></i> Ferdi mesaj</a>
                <?php if (app_portal_is_admin($user)): ?>
                    <a class="portal-ghost-btn portal-inline-link" data-message-nav data-module-section="group" href="<?= e($messageHref('group')); ?>"><i class="fa-solid fa-users"></i> Qrup mesaj</a>
                <?php endif; ?>
                <a class="portal-ghost-btn portal-inline-link" data-message-nav data-module-section="history" href="<?= e($messageHref('history')); ?>"><i class="fa-solid fa-clock-rotate-left"></i> Tarixce</a>
                <?php if (app_portal_is_admin($user)): ?>
                    <a class="portal-ghost-btn portal-inline-link" data-message-nav data-module-section="settings" href="<?= e($smsProviderSettingsHref); ?>"><i class="fa-solid fa-sliders"></i> Kanal ayarlari</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="message-ajax-status" data-message-status aria-live="polite"></div>
        <div data-message-content>
        <?php if ($messageView === 'overview'): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Kanal ve novbe veziyyeti</h2>
                <small>Mesajlarin hansi kanaldan gonderildiyini ve novbede qalanlari burada izleyin.</small>
            </div>
            <div class="message-channel-chart" data-message-channel-chart>
                <div class="message-channel-ring" style="background:<?= e($messageChannelRingGradient); ?>">
                    <div>
                        <strong><?= e((string) array_sum($messageChannelUsage)); ?></strong>
                        <span>kanal mesaji</span>
                    </div>
                </div>
                <div class="message-channel-bars">
                    <?php foreach ($messageChannelRows as $row): ?>
                        <div class="message-channel-row" data-message-channel="<?= e((string) $row['key']); ?>">
                            <strong><?= e((string) $row['label']); ?></strong>
                            <span class="message-channel-track">
                                <b class="message-channel-fill" style="--channel-tone:<?= e((string) $row['tone']); ?>;width:<?= e((string) max(4, (int) $row['bar'])); ?>%"></b>
                            </span>
                            <span class="message-channel-total"><?= e((string) $row['total']); ?> / <?= e((string) $row['percent']); ?>%</span>
                            <span class="message-channel-statuses">
                                <?php foreach (['queued', 'sent', 'failed', 'test'] as $statusKey): ?>
                                    <?php $statusTotal = (int) (($row['statuses'][$statusKey] ?? 0)); ?>
                                    <?php if ($statusTotal <= 0) { continue; } ?>
                                    <span><?= e(message_center_status_label($statusKey)); ?>: <?= e((string) $statusTotal); ?></span>
                                <?php endforeach; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="message-worker-grid">
                <div>
                    <strong class="portal-kicker">Novbe / kanal</strong>
                    <div class="message-worker-list">
                        <?php foreach ($queueByChannel as $channelName => $total): ?>
                            <div class="message-worker-row">
                                <strong><?= e(message_center_channel_label($channelName)); ?></strong>
                                <span class="portal-pill"><?= e((string) $total); ?> novbede</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <strong class="portal-kicker">Provider statusu</strong>
                    <div class="message-worker-list">
                        <?php foreach ($providerReadiness as $providerName => $status): ?>
                            <?php $ready = !empty($status['configured']) || ($providerName === 'sms' && !empty($status['has_endpoint']) && !empty($status['has_credentials'])); ?>
                            <div class="message-worker-row">
                                <strong><?= e($providerName); ?></strong>
                                <span class="portal-pill <?= $ready ? 'message-ready' : 'message-waiting'; ?>"><?= $ready ? 'hazir' : 'ayar gozleyir'; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php if (app_portal_is_admin($user)): ?>
                <div class="message-inline-action">
                    <a class="message-soft-btn portal-inline-link" data-message-nav data-module-section="settings" href="<?= e($smsProviderSettingsHref); ?>"><i class="fa-solid fa-sliders"></i> Kanal ve provider ayarlarina kec</a>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (app_portal_is_admin($user) && $messageView === 'settings'): ?>
        <section class="message-settings-section portal-reveal">
            <div class="portal-card">
                <div class="portal-card-head">
                    <h2>Kanal ve provider ayarlari</h2>
                    <small>SMS giris melumatlari, WhatsApp baglantisi ve gonderim limitleri yalniz bu bolmede deyisdirilir.</small>
                </div>
                <form method="post" class="portal-stacked-form portal-section-gap" id="sms-provider-settings" data-sms-provider-config>
                    <input type="hidden" name="action" value="save_sms_provider_credentials">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <strong class="portal-kicker">SMS provider</strong>
                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="smsProviderUsername">Istifadeci adi</label>
                            <input id="smsProviderUsername" name="sms_provider_username" type="text" value="<?= e($smsProviderUsername); ?>" autocomplete="off" placeholder="SMS provider istifadeci adi">
                        </div>
                        <div>
                            <label for="smsProviderPassword">Sifre</label>
                            <input id="smsProviderPassword" name="sms_provider_password" type="password" autocomplete="new-password" placeholder="<?= $smsProviderHasPassword ? 'Saxlanib, deyismek ucun yazin' : 'Provider sifresi'; ?>">
                        </div>
                    </div>
                    <button type="submit" class="message-soft-btn">SMS provider saxla</button>
                    <small>API unvani sistemde sabitdir: api.poctgoyercini.com. Istifadeci adi ve sifre yalniz gonderim zamani tehlukesiz provider sorgusunda istifade olunur.</small>
                </form>
                <div class="message-whatsapp-bridge">
                    <div>
                        <strong class="portal-kicker">WhatsApp Web bridge</strong>
                        <h3>QR sessiyasi ve yerli xidmetin veziyyeti</h3>
                        <p>
                            Status: <strong><?= e(message_center_status_label((string) ($whatsappBridge['status'] ?? 'unknown'))); ?></strong>.
                            Xidmet aciqdir: <strong><?= !empty($whatsappBridge['reachable']) ? 'beli' : 'xeyr'; ?></strong>.
                            WhatsApp qosulub: <strong><?= !empty($whatsappBridge['connected']) ? 'beli' : 'xeyr'; ?></strong>.
                        </p>
                        <?php if (!empty($whatsappBridge['last_error'])): ?>
                            <small>Xeta: <?= e((string) $whatsappBridge['last_error']); ?></small>
                        <?php elseif ((string) ($whatsappQr['status'] ?? '') === 'qr'): ?>
                            <small>Telefonunuzda WhatsApp Web QR oxudun. Bundan sonra mesaj worker WhatsApp kanalindan gondere bilecek.</small>
                        <?php else: ?>
                            <small>WhatsApp elcatan deyilse mesaj diger aktiv kanalla gonderilmeye davam edir.</small>
                        <?php endif; ?>
                    </div>
                    <div class="message-whatsapp-qr">
                        <?php if (!empty($whatsappQr['qr'])): ?>
                            <img src="<?= e((string) $whatsappQr['qr']); ?>" alt="WhatsApp QR">
                        <?php elseif (!empty($whatsappBridge['connected'])): ?>
                            <span class="portal-pill portal-pill-success">WhatsApp qosulub</span>
                        <?php else: ?>
                            <span class="portal-pill message-waiting">QR gozlenilir</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="message-guard-grid">
                    <div class="message-guard-card"><span>Yeni nomre ucun gunluk limit</span><strong><?= e((string) $whatsappGuardLimits['unknown_daily_limit']); ?></strong></div>
                    <div class="message-guard-card"><span>Taninmis nomre ucun gunluk limit</span><strong><?= e((string) $whatsappGuardLimits['known_daily_limit']); ?></strong></div>
                    <div class="message-guard-card"><span>Umumi saatliq limit</span><strong><?= e((string) $whatsappGuardLimits['global_hourly_limit']); ?></strong></div>
                    <div class="message-guard-card"><span>Umumi gunluk limit</span><strong><?= e((string) $whatsappGuardLimits['global_daily_limit']); ?></strong></div>
                    <div class="message-guard-card"><span>Nomreye 24 saatda gonderilen</span><strong><?= e((string) $whatsappGuardStats['sent_24h']); ?></strong></div>
                    <div class="message-guard-card"><span>Umumi saatliq gonderim</span><strong><?= e((string) $whatsappGuardStats['global_sent_hour']); ?></strong></div>
                    <div class="message-guard-card"><span>Umumi 24 saatliq gonderim</span><strong><?= e((string) $whatsappGuardStats['global_sent_24h']); ?></strong></div>
                    <div class="message-guard-card"><span>Hazirda bloklanan</span><strong><?= e((string) $whatsappGuardStats['blocked']); ?></strong></div>
                </div>
                <?php if (app_portal_is_admin($user)): ?>
                    <details class="message-details">
                        <summary>WhatsApp gonderim limitlerini deyis</summary>
                        <div class="message-details-body">
                            <form method="post" class="portal-stacked-form">
                                <input type="hidden" name="action" value="update_whatsapp_guard">
                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                <div class="portal-form-row portal-grid-slim">
                                    <div>
                                        <label for="waUnknownDaily">Yeni nomre ucun gunluk limit</label>
                                        <input id="waUnknownDaily" type="number" min="1" max="50" name="whatsapp_unknown_daily_limit" value="<?= e((string) $whatsappGuardLimits['unknown_daily_limit']); ?>">
                                    </div>
                                    <div>
                                        <label for="waKnownDaily">Taninmis nomre ucun gunluk limit</label>
                                        <input id="waKnownDaily" type="number" min="1" max="500" name="whatsapp_known_daily_limit" value="<?= e((string) $whatsappGuardLimits['known_daily_limit']); ?>">
                                    </div>
                                    <div>
                                        <label for="waUnknownGap">Yeni nomreye gonderim fasilesi (saniye)</label>
                                        <input id="waUnknownGap" type="number" min="60" max="86400" name="whatsapp_unknown_min_gap_sec" value="<?= e((string) $whatsappGuardLimits['unknown_min_gap_sec']); ?>">
                                    </div>
                                    <div>
                                        <label for="waKnownGap">Taninmis nomreye gonderim fasilesi (saniye)</label>
                                        <input id="waKnownGap" type="number" min="30" max="86400" name="whatsapp_known_min_gap_sec" value="<?= e((string) $whatsappGuardLimits['known_min_gap_sec']); ?>">
                                    </div>
                                    <div>
                                        <label for="waGlobalDaily">Umumi gunluk limit</label>
                                        <input id="waGlobalDaily" type="number" min="1" max="1000" name="whatsapp_global_daily_limit" value="<?= e((string) $whatsappGuardLimits['global_daily_limit']); ?>">
                                    </div>
                                    <div>
                                        <label for="waGlobalHourly">Umumi saatliq limit</label>
                                        <input id="waGlobalHourly" type="number" min="1" max="200" name="whatsapp_global_hourly_limit" value="<?= e((string) $whatsappGuardLimits['global_hourly_limit']); ?>">
                                    </div>
                                    <div>
                                        <label for="waGlobalGap">Umumi gonderim fasilesi (saniye)</label>
                                        <input id="waGlobalGap" type="number" min="30" max="3600" name="whatsapp_global_min_gap_sec" value="<?= e((string) $whatsappGuardLimits['global_min_gap_sec']); ?>">
                                    </div>
                                </div>
                                <button type="submit" class="message-soft-btn">Gonderim limitlerini saxla</button>
                                <small>Limit asildiqda mesaj novbede saxlanilmir; sistem onu diger aktiv kanalla gonderir.</small>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
                <div class="portal-section-gap">
                    <details class="message-details">
                        <summary>Texniki novbe komandalari</summary>
                        <div class="message-details-body">
                            <code class="message-worker-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\process-communication-queue.php --limit=50</code>
                            <code class="message-worker-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\process-communication-queue.php --apply --limit=50</code>
                            <code class="message-worker-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\billing-maintenance.php --apply --queue-notices --channel=auto</code>
                        </div>
                    </details>
                    <details class="message-details">
                        <summary>Texniki provider test komandalari</summary>
                        <div class="message-details-body">
                            <code class="message-worker-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\test-communication-provider.php --channel=telegram</code>
                            <code class="message-worker-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\test-communication-provider.php --channel=sms --destination=994XXXXXXXXX</code>
                            <code class="message-worker-code">D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\test-communication-provider.php --channel=whatsapp --destination=994XXXXXXXXX</code>
                            <small>Bu test skripti default yoxlama rejiminde isleyir. Real test ucun sonda `--apply` verilir ve DB/ticket/alarm yaratmir.</small>
                        </div>
                    </details>
                    <details class="message-details">
                        <summary>Son worker icralari</summary>
                        <div class="message-details-body">
                            <?php if ($workerRuns === []): ?>
                                <?php billing_render_empty_state('Worker icrasi yoxdur', 'Real gonderim worker-i hele DB-ye icra xulasesi yazmayib.', 'fa-clock-rotate-left'); ?>
                            <?php else: ?>
                                <div class="portal-table-wrap">
                                    <table class="portal-table">
                                        <thead><tr><th>Bitme vaxti</th><th>Rejim</th><th>Kanal</th><th>Status</th><th>Skan</th><th>Gonderildi</th><th>Xeta</th><th>Qalib</th><th>Ms</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($workerRuns as $run): ?>
                                                <tr>
                                                    <td data-label="Bitme vaxti"><?= e((string) ($run['finished_at'] ?? '')); ?></td>
                                                    <td data-label="Rejim"><?= e((string) ($run['mode'] ?? '-')); ?></td>
                                                    <td data-label="Kanal"><?= e(message_center_channel_label((string) ($run['channel'] ?? '-'))); ?></td>
                                                    <td data-label="Status"><span class="portal-pill message-status-<?= e((string) ($run['status'] ?? 'completed')); ?>"><?= e(message_center_status_label((string) ($run['status'] ?? 'completed'))); ?></span></td>
                                                    <td data-label="Skan"><?= e((string) ($run['scanned_count'] ?? 0)); ?></td>
                                                    <td data-label="Gonderildi"><?= e((string) ($run['sent_count'] ?? 0)); ?></td>
                                                    <td data-label="Xeta"><?= e((string) ($run['failed_count'] ?? 0)); ?></td>
                                                    <td data-label="Qalib"><?= e((string) ($run['left_queued_count'] ?? 0)); ?></td>
                                                    <td data-label="Ms"><?= e((string) ($run['duration_ms'] ?? 0)); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (app_portal_is_admin($user) && $messageView === 'test'): ?>
            <section class="portal-card portal-reveal">
                <div class="portal-card-head">
                    <h2>Provider test paneli</h2>
                    <small>DB mesaj, ticket ve alarm yaratmadan kanal konfiqurasiyasini yoxlayir. Real gonderim yalniz checkbox ile aktivdir.</small>
                </div>
                <?php if ($providerTestResult !== null): ?>
                    <div class="message-provider-note">
                        <strong>Son test:</strong>
                        <?= e(message_center_channel_label((string) $providerTestResult['channel'])); ?> /
                        <?= !empty($providerTestResult['bot_key']) ? e((string) $providerTestResult['bot_key']) . ' /' : ''; ?>
                        <?= e(message_center_mode_label((string) $providerTestResult['mode'])); ?> /
                        unvan <?= e($providerTestResult['destination']); ?> /
                        <?= !empty($providerTestResult['sent']) ? e(message_center_status_label('sent')) : e(message_center_reason_label((string) ($providerTestResult['reason'] ?? ''))); ?>
                    </div>
                <?php endif; ?>
                <form class="portal-stacked-form portal-section-gap" method="post">
                    <input type="hidden" name="action" value="provider_test">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="providerChannel">Kanal</label>
                            <select id="providerChannel" name="provider_channel">
                                <option value="telegram">Telegram</option>
                                <option value="sms">SMS</option>
                                <option value="whatsapp">WhatsApp</option>
                                <option value="app">Oz app / portal</option>
                            </select>
                        </div>
                        <div>
                            <label for="providerBotKey">Telegram bot profili</label>
                            <select id="providerBotKey" name="provider_bot_key">
                                <?php foreach ($telegramBotDefinitions as $botKey => $definition): ?>
                                    <option value="<?= e((string) $botKey); ?>" <?= $botKey === 'superpilot' ? 'selected' : ''; ?>>
                                        <?= e((string) ($definition['display_name'] ?? $botKey)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Telegram testleri generic qrupa yox, secilen botun qrupuna gedir.</small>
                        </div>
                        <div>
                            <label for="providerDestination">Unvan / chat ID</label>
                            <input id="providerDestination" name="provider_destination" type="text" placeholder="Telegram bos qalarsa secilen bot qrupu / SMS ucun 994...">
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label for="providerMessage">Test mesaji</label>
                        <textarea id="providerMessage" name="provider_message" maxlength="640" rows="4" data-ticket-message placeholder="Bos qalarsa avtomatik TEST MESAJI yaradilir"></textarea>
                        <small class="portal-char-counter" data-char-counter>0 / 640</small>
                    </div>
                    <label class="portal-checkbox-line">
                        <input type="checkbox" name="provider_apply" value="1">
                        <span>Real TEST MESAJI gonder. DB/ticket/alarm yaradilmiyacaq.</span>
                    </label>
                    <button class="portal-primary-btn" type="submit">Provider test et</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($messageView === 'overview'): ?>
                <section class="mgs-channel-grid portal-reveal">
            <article class="mgs-channel-card"><i class="fa-brands fa-whatsapp"></i><strong>WhatsApp</strong><small>Esas kanal: nomre varsa birinci yoxlanir.</small></article>
            <article class="mgs-channel-card"><i class="fa-solid fa-mobile-screen"></i><strong>Tetbiq / portal</strong><small>Portal aktivdirsa mesaj kabinet tarixcesinde gorunur.</small></article>
            <article class="mgs-channel-card"><i class="fa-brands fa-telegram"></i><strong>Telegram</strong><small>Telegram ID baglidirsa alternativ kanal kimi istifade olunur.</small></article>
            <article class="mgs-channel-card"><i class="fa-solid fa-comment-sms"></i><strong>SMS provider</strong><small>Son fallback kimi SMS xarakter sayaci ile gonderilir.</small></article>
            <article class="mgs-channel-card"><i class="fa-solid fa-robot"></i><strong>Avtomatik secim</strong><small>WhatsApp -> tetbiq -> Telegram -> SMS ardicilligi.</small></article>
        </section>
        <?php endif; ?>

        <?php if ($messageView === 'single'): ?>
        <section class="message-layout portal-reveal">
            <div class="portal-card">
                <div class="portal-card-head">
                    <h2>Ferdi mesaj gonder</h2>
                    <small>Muqavile/abonent kodu ile musteri tapilir, sablon icindeki ad ve kod avtomatik doldurulur.</small>
                </div>
                <form class="portal-stacked-form" method="post">
                    <input type="hidden" name="action" value="send_single">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="singleSubscriber">Musteri kodu</label>
                            <input id="singleSubscriber" type="text" name="subscriber_code" placeholder="1100000" required>
                        </div>
                        <div>
                            <label for="singleChannel">Kanal</label>
                            <select id="singleChannel" name="channel">
                                <?php foreach ($channelLabels as $value => $label): ?>
                                    <option value="<?= e($value); ?>"><?= e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label for="singleTemplate">Sablon</label>
                        <select id="singleTemplate" data-template-select name="template_code" required>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?= e((string) $template['code']); ?>" data-template-body="<?= e((string) $template['body']); ?>"><?= e((string) $template['title']); ?> / <?= e((string) $template['category']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="message-template-preview" data-template-preview aria-live="polite" hidden><strong>Sablon metni</strong><p></p></div>
                    </div>
                    <div class="portal-form-row">
                        <label for="singleMessage">Ferdi metn (bos qalarsa sablon gonderilir)</label>
                        <textarea id="singleMessage" name="custom_message" maxlength="640" rows="6" placeholder="Mesaj metni..." data-ticket-message></textarea>
                        <small class="portal-char-counter" data-char-counter>0 / 640</small>
                    </div>
                    <label class="portal-checkbox-line">
                        <input type="checkbox" name="test_mode" value="1">
                        <span>TEST MESAJI kimi yaz, real tarixceye salma</span>
                    </label>
                    <button type="submit" class="portal-primary-btn">Mesaji novbeye yaz</button>
                </form>
            </div>

            <aside class="portal-card">
                <div class="portal-card-head">
                    <h2>Musteri axtarisi</h2>
                    <small>Kodu tapmaq ucun qisa axtaris.</small>
                </div>
                <form method="get" class="portal-inline-form message-customer-search-form" data-message-auto-filter data-message-filter-delay="400">
                    <input type="hidden" name="view" value="single">
                    <?php if ($lang !== 'az'): ?>
                        <input type="hidden" name="lang" value="<?= e($lang); ?>">
                    <?php endif; ?>
                    <label for="customerSearch">Ad, telefon, kod</label>
                    <div class="portal-inline-inputs">
                        <input id="customerSearch" type="text" name="q" value="<?= e($customerQuery); ?>" placeholder="1100000 / ad / telefon">
                        <button type="submit">Axtar</button>
                    </div>
                </form>
                <div class="portal-action-list portal-section-gap">
                    <?php if ($customerRows === []): ?>
                        <?php billing_render_empty_state('Musteri tapilmadi', 'Kod, telefon ve ya ad uzre basqa axtaris yazin.', 'fa-user-magnifying-glass'); ?>
                    <?php endif; ?>
                    <?php foreach ($customerRows as $customer): ?>
                        <button type="button" class="portal-link-card message-customer-result" data-message-customer-code="<?= e((string) ($customer['subscriber_code'] ?? '')); ?>">
                            <div>
                                <strong><?= e((string) ($customer['subscriber_code'] ?? '-')); ?> - <?= e((string) ($customer['full_name'] ?? '')); ?></strong>
                                <span><?= e((string) ($customer['phone'] ?? '-')); ?> / <?= e((string) ($customer['area'] ?? '-')); ?></span>
                            </div>
                            <span class="message-customer-result-action">Sec</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </aside>
        </section>
        <?php endif; ?>

        <?php if (app_portal_is_admin($user) && $messageView === 'group'): ?>
            <section class="portal-card portal-reveal">
                <div class="portal-card-head">
                    <h2>Qrup mesajlari</h2>
                    <small>Aktiv/deaktiv/erazi/bitme gunu/retention qruplari uzre mesaj novbesi.</small>
                </div>
                <form class="portal-stacked-form" method="post">
                    <input type="hidden" name="action" value="send_group">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="groupScope">Qrup</label>
                            <select id="groupScope" name="scope">
                                <option value="active">Aktiv musteriler</option>
                                <option value="inactive">Deaktiv/passiv musteriler</option>
                                <option value="area">Secilmis erazi</option>
                                <option value="expiring_1d">1 gun sonra bitecek</option>
                                <option value="overdue_60d">60+ gun odenissiz</option>
                            </select>
                        </div>
                        <div>
                            <label for="groupArea">Erazi (lazimdirsa)</label>
                            <select id="groupArea" name="area">
                                <option value="">Butun eraziler</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?= e($area); ?>"><?= e($area); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="groupTemplate">Sablon</label>
                            <select id="groupTemplate" data-template-select name="template_code" required>
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?= e((string) $template['code']); ?>" data-template-body="<?= e((string) $template['body']); ?>"><?= e((string) $template['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <div class="message-template-preview" data-template-preview aria-live="polite" hidden><strong>Sablon metni</strong><p></p></div>
                        </div>
                        <div>
                            <label for="groupChannel">Kanal</label>
                            <select id="groupChannel" name="channel">
                                <?php foreach ($channelLabels as $value => $label): ?>
                                    <option value="<?= e($value); ?>"><?= e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label for="groupMessage">Qrup metni (bos qalarsa sablon)</label>
                        <textarea id="groupMessage" name="custom_message" maxlength="640" rows="5" data-ticket-message></textarea>
                        <small class="portal-char-counter" data-char-counter>0 / 640</small>
                    </div>
                    <label class="portal-checkbox-line">
                        <input type="checkbox" name="test_mode" value="1" checked>
                        <span>Test rejimi: yalniz 1 numune mesaj yaz</span>
                    </label>
                    <button type="submit" class="portal-primary-btn">Qrup mesajini hazirla</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if (in_array($messageView, ['templates', 'history'], true)): ?>
        <section class="message-tab-shell portal-reveal">
            <?php if ($messageView === 'templates'): ?>
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Hazir sablonlar</h2>
                    <small>Billing, modem xeberdarligi, retention ve bayram mesajlari.</small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Kod</th><th>Ad</th><th>Kateqoriya</th><th>Metn</th></tr></thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td data-label="Kod"><code><?= e((string) $template['code']); ?></code></td>
                                    <td data-label="Ad"><?= e((string) $template['title']); ?></td>
                                    <td data-label="Kateqoriya"><span class="portal-pill"><?= e((string) $template['category']); ?></span></td>
                                    <td class="message-template-body" data-label="Metn"><?= e((string) $template['body']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <?php endif; ?>

            <?php if ($messageView === 'history'): ?>
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Son mesaj tarixcesi</h2>
                    <small>Provider qosulmayibsa mesaj novbede qalir. Filterler toplu retry ucun de istifade olunur.</small>
                </div>
                <div class="message-provider-note">
                    Real SMS/WhatsApp/Telegram adapteri qosulanda bu cedvel gonderim workerinin giris novbesi olacaq.
                </div>
                <form method="get" class="message-filter-bar" data-message-auto-filter data-message-filter-delay="350">
                    <input type="hidden" name="view" value="history">
                    <?php if ($lang !== 'az'): ?>
                        <input type="hidden" name="lang" value="<?= e($lang); ?>">
                    <?php endif; ?>
                    <div>
                        <label for="messageStatus">Status</label>
                        <select id="messageStatus" name="message_status">
                            <?php foreach (['all', 'queued', 'sent', 'failed', 'test'] as $value): ?>
                                <?php $label = message_center_status_label($value); ?>
                                <option value="<?= e($value); ?>" <?= $messageStatusFilter === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="messageChannel">Kanal</label>
                        <select id="messageChannel" name="message_channel">
                            <option value="all" <?= $messageChannelFilter === 'all' ? 'selected' : ''; ?>>Butun kanallar</option>
                            <?php foreach ($channelLabels as $value => $label): ?>
                                <option value="<?= e($value); ?>" <?= $messageChannelFilter === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="messageQuery">Axtaris</label>
                        <input id="messageQuery" type="text" name="message_q" value="<?= e($messageSearch); ?>" placeholder="kod / ad / telefon / unvan">
                    </div>
                    <div class="message-filter-actions">
                        <button class="message-soft-btn" type="submit">Tetbiq et</button>
                        <a class="message-soft-btn" data-message-nav data-module-section="history" href="message-center.php?view=history">Sifirla</a>
                    </div>
                </form>
                <?php if (app_portal_is_admin($user) && (($queueByStatus['failed'] ?? 0) > 0 || $messageStatusFilter === 'failed')): ?>
                    <form method="post" class="message-filter-actions portal-section-gap">
                        <input type="hidden" name="action" value="bulk_retry_failed">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="bulk_channel" value="<?= e($messageChannelFilter); ?>">
                        <input type="hidden" name="bulk_q" value="<?= e($messageSearch); ?>">
                        <button class="message-danger-btn" type="submit">Bu filterde xetali mesajlari yeniden novbeye al (max 50)</button>
                    </form>
                <?php endif; ?>
                <div class="portal-table-wrap portal-section-gap">
                    <table class="portal-table message-history-table mgs-compact-table">
                        <thead><tr><th>Vaxt</th><th>Musteri</th><th>Kanal</th><th>Status</th><th>Mesaj</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent as $message): ?>
                                <tr>
                                    <td data-label="Vaxt"><?= e((string) ($message['created_at'] ?? '')); ?></td>
                                    <td data-label="Musteri"><?= e((string) ($message['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($message['recipient_name'] ?? $message['full_name'] ?? '')); ?></small></td>
                                    <td data-label="Kanal"><?= e(message_center_channel_label((string) ($message['channel'] ?? '-'))); ?><br><small><?= e((string) ($message['fallback_chain'] ?? '')); ?></small></td>
                                    <td data-label="Status"><span class="portal-pill message-status-<?= e((string) ($message['status'] ?? 'queued')); ?>"><?= e(message_center_status_label((string) ($message['status'] ?? 'queued'))); ?></span></td>
                                    <?php $messageBody = (string) ($message['message_body'] ?? ''); ?>
                                    <td data-label="Mesaj" title="<?= e($messageBody); ?>">
                                        <?= e(message_center_text_preview($messageBody)); ?>
                                        <?php if (app_portal_is_admin($user) && (string) ($message['status'] ?? '') === 'failed'): ?>
                                            <form method="post" class="message-inline-action">
                                                <input type="hidden" name="action" value="retry_message">
                                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                <input type="hidden" name="message_id" value="<?= e((string) ($message['id'] ?? 0)); ?>">
                                                <button type="submit">Yeniden novbeye al</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php message_center_pagination('message_page', $messagePage, max(1, (int) ceil($messageTotal / $messagePageSize)), $messageTotal, $messagePageSize); ?>
            </article>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($messageView === 'audit'): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Provider gonderim auditi</h2>
                <small>Yalniz real gonderim worker cehdleri burada saxlanir; unvan maskalanir ve yoxlama rejimi DB-ye yazmir.</small>
            </div>
            <?php if ($failedReasonSummary !== []): ?>
                <div class="message-reason-grid">
                    <?php foreach ($failedReasonSummary as $reasonRow): ?>
                        <div class="message-reason-card">
                            <strong><?= e(message_center_reason_label((string) ($reasonRow['reason'] ?? 'unknown'))); ?></strong>
                            <span><?= e(message_center_channel_label((string) ($reasonRow['channel'] ?? '-'))); ?> / son: <?= e((string) ($reasonRow['last_seen'] ?? '-')); ?></span>
                            <b class="portal-pill message-status-failed"><?= e((string) ($reasonRow['total'] ?? 0)); ?> xeta</b>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($deliveryAttempts === []): ?>
                <?php billing_render_empty_state('Gonderim auditi yoxdur', 'Real provider worker hele gonderis cehdi saxlamayib.', 'fa-shield-halved'); ?>
            <?php else: ?>
                <div class="portal-table-wrap">
                    <table class="portal-table message-audit-table mgs-compact-table">
                        <thead><tr><th>Vaxt</th><th>Kanal</th><th>Status</th><th>Musteri</th><th>Unvan</th><th>Provider</th><th>Qeyd</th></tr></thead>
                        <tbody>
                            <?php foreach ($deliveryAttempts as $attempt): ?>
                                <tr>
                                    <td data-label="Vaxt"><?= e((string) ($attempt['attempted_at'] ?? '')); ?></td>
                                    <td data-label="Kanal"><?= e(message_center_channel_label((string) ($attempt['channel'] ?? '-'))); ?></td>
                                    <td data-label="Status"><span class="portal-pill message-status-<?= e((string) ($attempt['status'] ?? 'failed')); ?>"><?= e(message_center_status_label((string) ($attempt['status'] ?? 'failed'))); ?></span></td>
                                    <td data-label="Musteri"><?= e((string) ($attempt['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($attempt['recipient_name'] ?? '')); ?></small></td>
                                    <td data-label="Unvan"><code><?= e((string) ($attempt['destination_masked'] ?? '')); ?></code></td>
                                    <td data-label="Provider"><?= e((string) ($attempt['delivery'] ?? '-')); ?></td>
                                    <td data-label="Qeyd"><?= e((string) ($attempt['reason'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php message_center_pagination('audit_page', $auditPage, max(1, (int) ceil($auditTotal / $auditPageSize)), $auditTotal, $auditPageSize); ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        </div>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <script src="<?= e(app_asset('script/message-center.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
