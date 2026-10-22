<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee(true);
$db = app_db();
$flash = null;

function campaign_scope_label(string $scope): string
{
    return match ($scope) {
        'overdue_60d' => '60+ gÃ¼n Ã¶dÉ™niÅŸ etmÉ™yÉ™nlÉ™r',
        'inactive_30d' => '30+ gÃ¼n passiv/deaktiv qalanlar',
        'expiring_1d' => '1 gÃ¼n sonra xidmÉ™ti bitÉ™nlÉ™r',
        'risk_high' => 'Risk balÄ± yÃ¼ksÉ™k olanlar',
        'area' => 'SeÃ§ilmiÅŸ É™razi',
        default => 'Aktiv mÃ¼ÅŸtÉ™rilÉ™r',
    };
}

function campaign_account_enrich(array $row): array
{
    $payload = app_db_account_payload($row);
    $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
    $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
    $expiresAt = trim((string) ($row['service_expires_at'] ?? $billing['expires_at'] ?? ''));
    $lastPaymentAt = trim((string) ($row['last_payment_at'] ?? ''));
    $riskScore = (int) ($billing['risk_score'] ?? $profile['risk_score'] ?? $row['risk_score'] ?? 0);
    $daysLeft = $expiresAt !== '' ? app_billing_days_left($expiresAt) : null;
    $daysSincePayment = $lastPaymentAt !== '' && strtotime($lastPaymentAt) !== false
        ? (int) floor((time() - strtotime($lastPaymentAt)) / 86400)
        : null;

    $row['campaign_expires_at'] = $expiresAt;
    $row['campaign_last_payment_at'] = $lastPaymentAt;
    $row['campaign_risk_score'] = max(0, min(10, $riskScore));
    $row['campaign_days_left'] = $daysLeft;
    $row['campaign_days_since_payment'] = $daysSincePayment;
    $row['campaign_monthly_fee'] = (string) ($billing['monthly_fee'] ?? $billing['internet_monthly_fee'] ?? '');
    $row['campaign_tariff'] = (string) ($billing['tariff'] ?? $row['plan'] ?? '');

    return $row;
}

function campaign_accounts(string $scope, string $area = '', int $limit = 200): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return [];
    }

    $limit = max(1, min($limit, 500));
    $where = ['a.role = "customer"'];
    $params = [];

    if ($scope === 'active') {
        $where[] = 'a.is_active = 1';
        $where[] = '(a.status IS NULL OR (a.status NOT LIKE "%Deaktiv%" AND a.status NOT LIKE "%Passiv%"))';
    } elseif ($scope === 'inactive_30d') {
        $where[] = '(a.is_active = 0 OR a.status LIKE "%Deaktiv%" OR a.status LIKE "%Passiv%" OR svc.service_expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY))';
    } elseif ($scope === 'overdue_60d') {
        $where[] = '(
            pay.last_payment_at IS NULL
            OR pay.last_payment_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
            OR svc.service_expires_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
        )';
    } elseif ($scope === 'expiring_1d') {
        $where[] = 'svc.service_expires_at IS NOT NULL';
        $where[] = 'svc.service_expires_at >= NOW()';
        $where[] = 'svc.service_expires_at < DATE_ADD(NOW(), INTERVAL 2 DAY)';
    } elseif ($scope === 'area' && trim($area) !== '') {
        $where[] = 'a.area = :area';
        $params['area'] = trim($area);
    }

    $sql = 'SELECT a.*, pay.last_payment_at, pay.payment_count, svc.service_expires_at, svc.radius_service_count
            FROM portal_accounts a
            LEFT JOIN (
                SELECT account_id, MAX(created_at) AS last_payment_at, COUNT(*) AS payment_count
                FROM billing_payments
                GROUP BY account_id
            ) pay ON pay.account_id = a.id
            LEFT JOIN (
                SELECT account_id, MAX(expires_at) AS service_expires_at, COUNT(*) AS radius_service_count
                FROM billing_radius_services
                GROUP BY account_id
            ) svc ON svc.account_id = a.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY COALESCE(pay.last_payment_at, svc.service_expires_at, a.updated_at) ASC
            LIMIT ' . $limit;

    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = array_map('campaign_account_enrich', array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array')));

    if ($scope === 'risk_high') {
        $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['campaign_risk_score'] ?? 0) >= 7));
    }

    if ($scope === 'overdue_60d') {
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            $daysSincePayment = $row['campaign_days_since_payment'];
            $daysLeft = $row['campaign_days_left'];
            return ($daysSincePayment === null || $daysSincePayment >= 60) || ($daysLeft !== null && $daysLeft <= -60);
        }));
    }

    return $rows;
}

function campaign_scope_stats(): array
{
    $db = app_db();
    if (!$db instanceof PDO) {
        return ['active' => 0, 'expiring_1d' => 0, 'overdue_60d' => 0, 'inactive_30d' => 0, 'risk_high' => 0];
    }

    $stats = [
        'active' => (int) $db->query(
            'SELECT COUNT(*)
             FROM portal_accounts
             WHERE role = "customer"
               AND is_active = 1
               AND (status IS NULL OR (status NOT LIKE "%Deaktiv%" AND status NOT LIKE "%Passiv%"))'
        )->fetchColumn(),
        'expiring_1d' => (int) $db->query(
            'SELECT COUNT(*)
             FROM portal_accounts a
             INNER JOIN (
                SELECT account_id, MAX(expires_at) AS service_expires_at
                FROM billing_radius_services
                GROUP BY account_id
             ) svc ON svc.account_id = a.id
             WHERE a.role = "customer"
               AND svc.service_expires_at >= NOW()
               AND svc.service_expires_at < DATE_ADD(NOW(), INTERVAL 2 DAY)'
        )->fetchColumn(),
        'overdue_60d' => (int) $db->query(
            'SELECT COUNT(*)
             FROM portal_accounts a
             LEFT JOIN (
                SELECT account_id, MAX(created_at) AS last_payment_at
                FROM billing_payments
                GROUP BY account_id
             ) pay ON pay.account_id = a.id
             LEFT JOIN (
                SELECT account_id, MAX(expires_at) AS service_expires_at
                FROM billing_radius_services
                GROUP BY account_id
             ) svc ON svc.account_id = a.id
             WHERE a.role = "customer"
               AND (
                    pay.last_payment_at IS NULL
                    OR pay.last_payment_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
                    OR svc.service_expires_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
               )'
        )->fetchColumn(),
        'inactive_30d' => (int) $db->query(
            'SELECT COUNT(*)
             FROM portal_accounts a
             LEFT JOIN (
                SELECT account_id, MAX(expires_at) AS service_expires_at
                FROM billing_radius_services
                GROUP BY account_id
             ) svc ON svc.account_id = a.id
             WHERE a.role = "customer"
               AND (
                    a.is_active = 0
                    OR a.status LIKE "%Deaktiv%"
                    OR a.status LIKE "%Passiv%"
                    OR svc.service_expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
               )'
        )->fetchColumn(),
        'risk_high' => 0,
    ];

    $riskRows = $db->query('SELECT payload_json FROM portal_accounts WHERE role = "customer"')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($riskRows as $row) {
        $payload = app_db_json_decode((string) ($row['payload_json'] ?? ''), []);
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
        if ((int) ($billing['risk_score'] ?? $profile['risk_score'] ?? 0) >= 7) {
            $stats['risk_high']++;
        }
    }

    return $stats;
}

if ($db instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasÄ± uÄŸursuz oldu.'];
    } elseif ($action === 'queue_campaign') {
        $scope = clean_text((string) ($_POST['scope'] ?? 'overdue_60d'));
        $area = clean_text((string) ($_POST['area'] ?? ''));
        $templateCode = clean_text((string) ($_POST['template_code'] ?? 'retention_60d'));
        $channel = clean_text((string) ($_POST['channel'] ?? 'auto'));
        $customMessage = trim((string) ($_POST['custom_message'] ?? ''));
        $limit = max(1, min(500, (int) ($_POST['limit'] ?? 100)));
        $testMode = isset($_POST['test_mode']);
        $accounts = campaign_accounts($scope, $area, $testMode ? 1 : $limit);
        $queued = 0;
        $failed = 0;
        foreach ($accounts as $account) {
            if (app_message_queue_for_account($account, $templateCode, $channel, $customMessage, $user, $testMode)) {
                $queued++;
            } else {
                $failed++;
            }
        }
        $flash = [
            'type' => $failed === 0 ? 'success' : 'error',
            'message' => campaign_scope_label($scope) . ': uyÄŸun ' . count($accounts) . ', nÃ¶vbÉ™yÉ™ yazÄ±lan ' . $queued . ', xÉ™ta ' . $failed . '.',
        ];
    }
}

$scope = clean_text((string) ($_GET['scope'] ?? 'overdue_60d'));
$area = clean_text((string) ($_GET['area'] ?? ''));
$scope = in_array($scope, ['active', 'expiring_1d', 'overdue_60d', 'inactive_30d', 'risk_high', 'area'], true) ? $scope : 'overdue_60d';
$templates = app_message_templates();
$areas = app_service_areas();
$stats = $db instanceof PDO ? campaign_scope_stats() : ['active' => 0, 'expiring_1d' => 0, 'overdue_60d' => 0, 'inactive_30d' => 0, 'risk_high' => 0];
$candidates = $db instanceof PDO ? campaign_accounts($scope, $area, 100) : [];
$recentCampaignMessages = [];
if ($db instanceof PDO) {
    $recentCampaignMessages = $db->query(
        'SELECT m.*, a.area
         FROM communication_messages m
         LEFT JOIN portal_accounts a ON a.id = m.account_id
         WHERE m.template_code IN ("retention_60d", "service_expiring_1d", "payment_received", "service_activated_30d")
         ORDER BY m.created_at DESC
         LIMIT 20'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$channelLabels = [
    'auto' => 'Avto fallback',
    'whatsapp' => 'WhatsApp',
    'app' => 'Ã–z app / portal',
    'telegram' => 'Telegram',
    'sms' => 'SMS provider',
];
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kampaniyalar</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .campaign-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px}
        .campaign-card-list{display:grid;gap:12px}
        .campaign-candidate{display:grid;grid-template-columns:1fr auto;gap:14px;align-items:center;border:1px solid #e2e8f0;border-radius:18px;background:#fff;padding:14px}
        .campaign-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
        .campaign-meta span{border-radius:999px;background:#f1f5f9;color:#334155;padding:5px 9px;font-size:12px;font-weight:800}
        .campaign-risk-high{background:#fff1f2!important;color:#be123c!important}
        .campaign-score{width:58px;height:58px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,#f97316,#ef4444);color:#fff;font-weight:900}
        @media (max-width:1100px){.campaign-grid{grid-template-columns:1fr}.campaign-candidate{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('Kampaniyalar', 'campaigns', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">CRM / RETENTION</span>
                <h1>Kampaniya vÉ™ mÃ¼ÅŸtÉ™ri geri qaytarma paneli</h1>
                <p>Ã–dÉ™niÅŸi gecikÉ™n, passivlÉ™ÅŸÉ™n vÉ™ xidmÉ™ti bitmÉ™k Ã¼zrÉ™ olan mÃ¼ÅŸtÉ™rilÉ™r ayrÄ±ca qruplaÅŸdÄ±rÄ±lÄ±r. Mesajlar test rejimindÉ™ yoxlanÄ±b sonra nÃ¶vbÉ™yÉ™ yazÄ±la bilÉ™r.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="#queue-campaign">Kampaniya hazÄ±rla</a>
                    <a class="portal-ghost-btn portal-inline-link" href="message-center.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Mesaj mÉ™rkÉ™zi</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>60+ gÃ¼n</span>
                <strong><?= e((string) ($stats['overdue_60d'] ?? 0)); ?></strong>
                <small>retention namizÉ™di</small>
                <span>Riskli</span>
                <strong><?= e((string) ($stats['risk_high'] ?? 0)); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Aktiv baza', (string) ($stats['active'] ?? 0), 'mesaj Ã¼Ã§Ã¼n aktiv mÃ¼ÅŸtÉ™ri', 'fa-users', 'blue', 'campaigns.php?scope=active'); ?>
            <?php billing_render_stat_card('1 gÃ¼n sonra bitir', (string) ($stats['expiring_1d'] ?? 0), 'Ã¶dÉ™niÅŸ xatÄ±rlatmasÄ±', 'fa-hourglass-half', 'orange', 'campaigns.php?scope=expiring_1d'); ?>
            <?php billing_render_stat_card('60+ gÃ¼n Ã¶dÉ™niÅŸsiz', (string) ($stats['overdue_60d'] ?? 0), 'geri qaytarma kampaniyasÄ±', 'fa-rotate-left', 'navy', 'campaigns.php?scope=overdue_60d'); ?>
            <?php billing_render_stat_card('Riskli', (string) ($stats['risk_high'] ?? 0), 'risk balÄ± 7+', 'fa-triangle-exclamation', 'red', 'campaigns.php?scope=risk_high'); ?>
        </section>

        <section class="mgs-filter-panel portal-reveal">
            <div class="mgs-section-head"><div><h2>NamizÉ™d filteri</h2><p>Qrup seÃ§ vÉ™ kampaniyaya dÃ¼ÅŸÉ™cÉ™k ilk 100 mÃ¼ÅŸtÉ™rini yoxla.</p></div></div>
            <form method="get" class="portal-stacked-form">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row">
                        <label>Qrup</label>
                        <select name="scope">
                            <?php foreach (['overdue_60d', 'inactive_30d', 'expiring_1d', 'risk_high', 'active', 'area'] as $option): ?>
                                <option value="<?= e($option); ?>" <?= $scope === $option ? 'selected' : ''; ?>><?= e(campaign_scope_label($option)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Ærazi</label>
                        <select name="area">
                            <option value="">BÃ¼tÃ¼n É™razilÉ™r</option>
                            <?php foreach ($areas as $option): ?>
                                <option value="<?= e($option); ?>" <?= $area === $option ? 'selected' : ''; ?>><?= e($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="portal-form-actions">
                    <button class="portal-primary-btn" type="submit">NamizÉ™dlÉ™ri gÃ¶stÉ™r</button>
                </div>
            </form>
        </section>

        <section class="campaign-grid portal-reveal">
            <div class="portal-card">
                <div class="portal-card-head">
                    <h2><?= e(campaign_scope_label($scope)); ?></h2>
                    <small>Ä°lk 100 nÉ™ticÉ™. KÃ¼tlÉ™vi gÃ¶ndÉ™rimdÉ™ limit ayrÄ±ca seÃ§ilir.</small>
                </div>
                <?php if ($candidates === []): ?>
                    <?php billing_render_empty_state('NamizÉ™d yoxdur', 'Bu filter Ã¼zrÉ™ kampaniya namizÉ™di tapÄ±lmadÄ±.', 'fa-user-check'); ?>
                <?php else: ?>
                    <div class="campaign-card-list">
                        <?php foreach ($candidates as $candidate): ?>
                            <article class="campaign-candidate">
                                <div>
                                    <strong><?= e((string) ($candidate['subscriber_code'] ?? '-')); ?> - <?= e((string) ($candidate['full_name'] ?? '-')); ?></strong>
                                    <div class="campaign-meta">
                                        <span><?= e((string) ($candidate['area'] ?? '-')); ?></span>
                                        <span>Son Ã¶dÉ™niÅŸ: <?= e(billing_datetime($candidate['campaign_last_payment_at'] ?? '')); ?></span>
                                        <span>GÃ¼n fÉ™rqi: <?= e((string) (($candidate['campaign_days_since_payment'] ?? null) ?? '-')); ?></span>
                                        <span>BitmÉ™: <?= e(billing_datetime($candidate['campaign_expires_at'] ?? '')); ?></span>
                                        <span>Qalan: <?= e((string) (($candidate['campaign_days_left'] ?? null) ?? '-')); ?></span>
                                        <span>Tarif: <?= e((string) (($candidate['campaign_tariff'] ?? '') ?: '-')); ?></span>
                                        <?php if ((int) ($candidate['campaign_risk_score'] ?? 0) >= 7): ?>
                                            <span class="campaign-risk-high">Risk yÃ¼ksÉ™k</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="campaign-score"><?= e((string) ($candidate['campaign_risk_score'] ?? 0)); ?>/10</div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="portal-card" id="queue-campaign">
                <div class="portal-card-head">
                    <h2>KampaniyanÄ± nÃ¶vbÉ™yÉ™ yaz</h2>
                    <small>Default test rejimidir. Test rejimi yalnÄ±z 1 mesaj yaradÄ±r.</small>
                </div>
                <form method="post" class="portal-stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="queue_campaign">
                    <div class="portal-form-row">
                        <label>Qrup</label>
                        <select name="scope">
                            <?php foreach (['overdue_60d', 'inactive_30d', 'expiring_1d', 'risk_high', 'active', 'area'] as $option): ?>
                                <option value="<?= e($option); ?>" <?= $scope === $option ? 'selected' : ''; ?>><?= e(campaign_scope_label($option)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Ærazi</label>
                        <select name="area">
                            <option value="">BÃ¼tÃ¼n É™razilÉ™r</option>
                            <?php foreach ($areas as $option): ?>
                                <option value="<?= e($option); ?>" <?= $area === $option ? 'selected' : ''; ?>><?= e($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Åablon</label>
                        <select name="template_code">
                            <?php foreach ($templates as $template): ?>
                                <option value="<?= e((string) $template['code']); ?>" <?= (string) $template['code'] === 'retention_60d' ? 'selected' : ''; ?>><?= e((string) $template['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Kanal</label>
                        <select name="channel">
                            <?php foreach ($channelLabels as $value => $label): ?>
                                <option value="<?= e($value); ?>"><?= e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Limit</label>
                        <input type="number" name="limit" min="1" max="500" value="100">
                    </div>
                    <div class="portal-form-row">
                        <label>FÉ™rdi kampaniya mÉ™tni</label>
                        <textarea name="custom_message" maxlength="640" rows="5" data-ticket-message placeholder="BoÅŸ qalarsa seÃ§ilmiÅŸ ÅŸablon iÅŸlÉ™yÉ™cÉ™k"></textarea>
                        <small class="portal-char-counter" data-char-counter>0 / 640</small>
                    </div>
                    <label class="portal-checkbox-line">
                        <input type="checkbox" name="test_mode" value="1" checked>
                        <span>TEST MESAJI kimi yoxla</span>
                    </label>
                    <button class="portal-primary-btn" type="submit">NÃ¶vbÉ™yÉ™ yaz</button>
                </form>
            </aside>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Son kampaniya mesajlarÄ±</h2>
                <small>Provider adapteri qoÅŸulana qÉ™dÉ™r status `queued/test` qalÄ±r.</small>
            </div>
            <?php if ($recentCampaignMessages === []): ?>
                <?php billing_render_empty_state('Kampaniya mesajÄ± yoxdur', 'Ä°lk test kampaniyasÄ±nÄ± yuxarÄ±dakÄ± formadan yaradÄ±n.', 'fa-message'); ?>
            <?php else: ?>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Tarix</th><th>MÃ¼ÅŸtÉ™ri</th><th>Kanal</th><th>Åablon</th><th>Status</th><th>Mesaj</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentCampaignMessages as $message): ?>
                                <tr>
                                    <td><?= e(billing_datetime($message['created_at'] ?? '')); ?></td>
                                    <td><?= e((string) ($message['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($message['recipient_name'] ?? '-')); ?></small></td>
                                    <td><?= e((string) ($message['channel'] ?? '-')); ?><br><small><?= e((string) ($message['fallback_chain'] ?? '')); ?></small></td>
                                    <td><code><?= e((string) ($message['template_code'] ?? '-')); ?></code></td>
                                    <td><span class="portal-pill"><?= e((string) ($message['status'] ?? '-')); ?></span></td>
                                    <td><?= e((string) ($message['message_body'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
