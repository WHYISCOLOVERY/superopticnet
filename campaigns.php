<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/business-rules-lib.php';

$lang = app_lang();
$user = billing_require_employee(true);
$db = app_db();
$flash = null;

function campaign_scope_label(string $scope): string
{
    return match ($scope) {
        'overdue_60d' => '60+ gün ödəniş etməyənlər',
        'inactive_30d' => '30+ gün passiv/deaktiv qalanlar',
        'expiring_1d' => '1 gün sonra xidməti bitənlər',
        'risk_high' => 'Risk balı yüksək olanlar',
        'area' => 'Seçilmiş ərazi',
        default => 'Aktiv müştərilər',
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
        $expiringSoonDays = max(1, (int) app_business_rule_get($db, 'campaign_expiring_soon_days', 2));
        $where[] = 'svc.service_expires_at IS NOT NULL';
        $where[] = 'svc.service_expires_at >= NOW()';
        $where[] = 'svc.service_expires_at < DATE_ADD(NOW(), INTERVAL ' . $expiringSoonDays . ' DAY)';
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

    $expiringSoonDays = max(1, (int) app_business_rule_get($db, 'campaign_expiring_soon_days', 2));
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
               AND svc.service_expires_at < DATE_ADD(NOW(), INTERVAL ' . $expiringSoonDays . ' DAY)'
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

    // Count high-risk customers in SQL instead of shipping every customer's
    // payload_json to PHP and JSON-decoding 5k+ rows on each render (~2s).
    // billing.risk_score takes precedence over profile.risk_score, default 0.
    $stats['risk_high'] = (int) $db->query(
        "SELECT COUNT(*) FROM portal_accounts
          WHERE role = 'customer'
            AND CAST(COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.billing.risk_score')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.profile.risk_score')),
                    '0'
                ) AS UNSIGNED) >= 7"
    )->fetchColumn();

    return $stats;
}

if ($db instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlaması uğursuz oldu.'];
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
            'message' => campaign_scope_label($scope) . ': uyğun ' . count($accounts) . ', növbəyə yazılan ' . $queued . ', xəta ' . $failed . '.',
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
    'app' => 'Öz app / portal',
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
                <h1>Kampaniya və müştəri geri qaytarma paneli</h1>
                <p>Ödənişi gecikən, passivləşən və xidməti bitmək üzrə olan müştərilər ayrıca qruplaşdırılır. Mesajlar test rejimində yoxlanıb sonra növbəyə yazıla bilər.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="#queue-campaign">Kampaniya hazırla</a>
                    <a class="portal-ghost-btn portal-inline-link" href="message-center.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Mesaj mərkəzi</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>60+ gün</span>
                <strong><?= e((string) ($stats['overdue_60d'] ?? 0)); ?></strong>
                <small>retention namizədi</small>
                <span>Riskli</span>
                <strong><?= e((string) ($stats['risk_high'] ?? 0)); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Aktiv baza', (string) ($stats['active'] ?? 0), 'mesaj üçün aktiv müştəri', 'fa-users', 'blue', 'campaigns.php?scope=active'); ?>
            <?php billing_render_stat_card('1 gün sonra bitir', (string) ($stats['expiring_1d'] ?? 0), 'ödəniş xatırlatması', 'fa-hourglass-half', 'orange', 'campaigns.php?scope=expiring_1d'); ?>
            <?php billing_render_stat_card('60+ gün ödənişsiz', (string) ($stats['overdue_60d'] ?? 0), 'geri qaytarma kampaniyası', 'fa-rotate-left', 'navy', 'campaigns.php?scope=overdue_60d'); ?>
            <?php billing_render_stat_card('Riskli', (string) ($stats['risk_high'] ?? 0), 'risk balı 7+', 'fa-triangle-exclamation', 'red', 'campaigns.php?scope=risk_high'); ?>
        </section>

        <section class="mgs-filter-panel portal-reveal">
            <div class="mgs-section-head"><div><h2>Namizəd filteri</h2><p>Qrup seç və kampaniyaya düşəcək ilk 100 müştərini yoxla.</p></div></div>
            <form method="get" class="portal-stacked-form" id="campaign-filter" data-auto-filter data-auto-filter-targets="campaign-candidates">
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
                        <label>Ərazi</label>
                        <select name="area">
                            <option value="">Bütün ərazilər</option>
                            <?php foreach ($areas as $option): ?>
                                <option value="<?= e($option); ?>" <?= $area === $option ? 'selected' : ''; ?>><?= e($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="portal-form-actions">
                    <button class="portal-primary-btn" type="submit">Namizədləri göstər</button>
                </div>
                <small data-auto-filter-status role="status" aria-live="polite"></small>
            </form>
        </section>

        <section class="campaign-grid portal-reveal">
            <div class="portal-card" data-auto-filter-results="campaign-candidates" data-auto-filter-form="campaign-filter">
                <div class="portal-card-head">
                    <h2><?= e(campaign_scope_label($scope)); ?></h2>
                    <small>İlk 100 nəticə. Kütləvi göndərimdə limit ayrıca seçilir.</small>
                </div>
                <?php if ($candidates === []): ?>
                    <?php billing_render_empty_state('Namizəd yoxdur', 'Bu filter üzrə kampaniya namizədi tapılmadı.', 'fa-user-check'); ?>
                <?php else: ?>
                    <div class="campaign-card-list">
                        <?php foreach ($candidates as $candidate): ?>
                            <article class="campaign-candidate">
                                <div>
                                    <strong><?= e((string) ($candidate['subscriber_code'] ?? '-')); ?> - <?= e((string) ($candidate['full_name'] ?? '-')); ?></strong>
                                    <div class="campaign-meta">
                                        <span><?= e((string) ($candidate['area'] ?? '-')); ?></span>
                                        <span>Son ödəniş: <?= e(billing_datetime($candidate['campaign_last_payment_at'] ?? '')); ?></span>
                                        <span>Gün fərqi: <?= e((string) (($candidate['campaign_days_since_payment'] ?? null) ?? '-')); ?></span>
                                        <span>Bitmə: <?= e(billing_datetime($candidate['campaign_expires_at'] ?? '')); ?></span>
                                        <span>Qalan: <?= e((string) (($candidate['campaign_days_left'] ?? null) ?? '-')); ?></span>
                                        <span>Tarif: <?= e((string) (($candidate['campaign_tariff'] ?? '') ?: '-')); ?></span>
                                        <?php if ((int) ($candidate['campaign_risk_score'] ?? 0) >= 7): ?>
                                            <span class="campaign-risk-high">Risk yüksək</span>
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
                    <h2>Kampaniyanı növbəyə yaz</h2>
                    <small>Default test rejimidir. Test rejimi yalnız 1 mesaj yaradır.</small>
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
                        <label>Ərazi</label>
                        <select name="area">
                            <option value="">Bütün ərazilər</option>
                            <?php foreach ($areas as $option): ?>
                                <option value="<?= e($option); ?>" <?= $area === $option ? 'selected' : ''; ?>><?= e($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Şablon</label>
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
                        <label>Fərdi kampaniya mətni</label>
                        <textarea name="custom_message" maxlength="640" rows="5" data-ticket-message placeholder="Boş qalarsa seçilmiş şablon işləyəcək"></textarea>
                        <small class="portal-char-counter" data-char-counter>0 / 640</small>
                    </div>
                    <label class="portal-checkbox-line">
                        <input type="checkbox" name="test_mode" value="1" checked>
                        <span>TEST MESAJI kimi yoxla</span>
                    </label>
                    <button class="portal-primary-btn" type="submit">Növbəyə yaz</button>
                </form>
            </aside>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Son kampaniya mesajları</h2>
                <small>Provider adapteri qoşulana qədər status `queued/test` qalır.</small>
            </div>
            <?php if ($recentCampaignMessages === []): ?>
                <?php billing_render_empty_state('Kampaniya mesajı yoxdur', 'İlk test kampaniyasını yuxarıdakı formadan yaradın.', 'fa-message'); ?>
            <?php else: ?>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Tarix</th><th>Müştəri</th><th>Kanal</th><th>Şablon</th><th>Status</th><th>Mesaj</th></tr></thead>
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
</body>
</html>
