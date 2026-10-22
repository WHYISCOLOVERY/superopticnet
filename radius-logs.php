<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();

function radius_logs_valid_date(string $value, string $fallback): string
{
    $value = clean_text($value);
    $timestamp = strtotime($value);
    if ($value === '' || $timestamp === false) {
        return $fallback;
    }

    return date('Y-m-d', $timestamp);
}

function radius_logs_badge_class(string $reply): string
{
    $reply = strtolower($reply);
    if (str_contains($reply, 'accept')) {
        return 'portal-pill-success';
    }
    if (str_contains($reply, 'reject') || str_contains($reply, 'fail')) {
        return 'portal-pill-danger';
    }

    return '';
}

$defaultStart = date('Y-m-d', strtotime('-7 days'));
$defaultEnd = date('Y-m-d');
$startDate = radius_logs_valid_date((string) ($_GET['start_date'] ?? ''), $defaultStart);
$endDate = radius_logs_valid_date((string) ($_GET['end_date'] ?? ''), $defaultEnd);
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$query = clean_text((string) ($_GET['q'] ?? ''));
$logType = strtolower(clean_text((string) ($_GET['type'] ?? $_GET['log_type'] ?? 'auth')));
$logType = in_array($logType, ['all', 'auth', 'accounting'], true) ? $logType : 'all';
$replyFilter = clean_text((string) ($_GET['reply'] ?? ''));
$nasFilter = clean_text((string) ($_GET['nas'] ?? ''));

$stats = [
    'period_accept' => 0,
    'period_reject' => 0,
    'period_accounting' => 0,
    'period_disconnect' => 0,
    'online' => 0,
    'period_traffic' => 0.0,
];
$replyOptions = [];
$nasOptions = [];
$replySummary = [];
$terminateSummary = [];
$nasSummary = [];
$postAuthRows = [];
$accountingRows = [];

if ($db instanceof PDO) {
    $periodParams = ['start_date' => $startDate, 'end_date' => $endDate];

    $statement = $db->prepare("SELECT COUNT(*) FROM radpostauth WHERE DATE(authdate) BETWEEN :start_date AND :end_date AND reply LIKE '%Accept%'");
    $statement->execute($periodParams);
    $stats['period_accept'] = (int) $statement->fetchColumn();

    $statement = $db->prepare("SELECT COUNT(*) FROM radpostauth WHERE DATE(authdate) BETWEEN :start_date AND :end_date AND reply NOT LIKE '%Accept%'");
    $statement->execute($periodParams);
    $stats['period_reject'] = (int) $statement->fetchColumn();

    $statement = $db->prepare('SELECT COUNT(*) FROM radacct WHERE acctstarttime >= :start_date AND acctstarttime < DATE_ADD(:end_date, INTERVAL 1 DAY)');
    $statement->execute($periodParams);
    $stats['period_accounting'] = (int) $statement->fetchColumn();

    $statement = $db->prepare('SELECT COUNT(*) FROM radacct WHERE acctstoptime >= :start_date AND acctstoptime < DATE_ADD(:end_date, INTERVAL 1 DAY)');
    $statement->execute($periodParams);
    $stats['period_disconnect'] = (int) $statement->fetchColumn();

    $stats['online'] = (int) $db->query('SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL')->fetchColumn();

    $statement = $db->prepare('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= :start_date AND acctstarttime < DATE_ADD(:end_date, INTERVAL 1 DAY)');
    $statement->execute($periodParams);
    $stats['period_traffic'] = (float) $statement->fetchColumn();

    $replyOptions = $db->query("SELECT DISTINCT reply FROM radpostauth WHERE reply <> '' ORDER BY reply")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $nasOptions = $db->query(
        "SELECT nasipaddress
         FROM (
             SELECT nasname AS nasipaddress FROM nas WHERE nasname <> ''
             UNION
             SELECT nasipaddress FROM radacct WHERE nasipaddress <> ''
         ) x
         ORDER BY nasipaddress"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $statement = $db->prepare(
        "SELECT COALESCE(NULLIF(reply,''),'unknown') AS label, COUNT(*) AS total
         FROM radpostauth
         WHERE DATE(authdate) BETWEEN :start_date AND :end_date
         GROUP BY COALESCE(NULLIF(reply,''),'unknown')
         ORDER BY total DESC
         LIMIT 8"
    );
    $statement->execute($periodParams);
    $replySummary = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statement = $db->prepare(
        "SELECT COALESCE(NULLIF(acctterminatecause,''),'online/unknown') AS label, COUNT(*) AS total
         FROM radacct
         WHERE DATE(COALESCE(acctstoptime, acctupdatetime, acctstarttime)) BETWEEN :start_date AND :end_date
         GROUP BY COALESCE(NULLIF(acctterminatecause,''),'online/unknown')
         ORDER BY total DESC
         LIMIT 8"
    );
    $statement->execute($periodParams);
    $terminateSummary = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statement = $db->prepare(
        "SELECT COALESCE(NULLIF(nasipaddress,''),'unknown') AS label,
                COUNT(*) AS total,
                SUM(COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0)) AS traffic_bytes
         FROM radacct
         WHERE DATE(COALESCE(acctupdatetime, acctstarttime)) BETWEEN :start_date AND :end_date
         GROUP BY COALESCE(NULLIF(nasipaddress,''),'unknown')
         ORDER BY total DESC
         LIMIT 8"
    );
    $statement->execute($periodParams);
    $nasSummary = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($logType !== 'accounting') {
        $where = ['DATE(p.authdate) BETWEEN :auth_start_date AND :auth_end_date'];
        $params = ['auth_start_date' => $startDate, 'auth_end_date' => $endDate];
        if ($query !== '') {
            $where[] = '(p.username LIKE :auth_q OR p.reply LIKE :auth_q OR p.class LIKE :auth_q OR a.subscriber_code LIKE :auth_q OR a.full_name LIKE :auth_q)';
            $params['auth_q'] = '%' . $query . '%';
        }
        if ($replyFilter !== '') {
            $where[] = 'p.reply = :auth_reply';
            $params['auth_reply'] = $replyFilter;
        }

        $statement = $db->prepare(
            'SELECT p.username, p.reply, p.authdate, p.class,
                    a.id AS account_id, a.subscriber_code, a.full_name
             FROM radpostauth p
             LEFT JOIN billing_radius_services s ON s.username = p.username
             LEFT JOIN portal_accounts a ON a.id = s.account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.authdate DESC, p.id DESC
             LIMIT 50'
        );
        $statement->execute($params);
        $postAuthRows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($logType !== 'auth') {
        $where = ['DATE(COALESCE(r.acctupdatetime, r.acctstarttime)) BETWEEN :acct_start_date AND :acct_end_date'];
        $params = ['acct_start_date' => $startDate, 'acct_end_date' => $endDate];
        if ($query !== '') {
            $where[] = '(r.username LIKE :acct_q OR r.framedipaddress LIKE :acct_q OR r.callingstationid LIKE :acct_q OR r.nasipaddress LIKE :acct_q OR r.acctterminatecause LIKE :acct_q OR a.subscriber_code LIKE :acct_q OR a.full_name LIKE :acct_q)';
            $params['acct_q'] = '%' . $query . '%';
        }
        if ($nasFilter !== '') {
            $where[] = 'r.nasipaddress = :acct_nas';
            $params['acct_nas'] = $nasFilter;
        }

        $statement = $db->prepare(
            'SELECT r.username, r.nasipaddress, r.nasportid, r.framedipaddress, r.callingstationid,
                    r.acctstarttime, r.acctupdatetime, r.acctstoptime, r.acctsessiontime,
                    r.acctterminatecause, r.acctinputoctets, r.acctoutputoctets,
                    a.id AS account_id, a.subscriber_code, a.full_name
             FROM radacct r
             LEFT JOIN billing_radius_services s ON s.username = r.username
             LEFT JOIN portal_accounts a ON a.id = s.account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(r.acctupdatetime, r.acctstarttime) DESC, r.radacctid DESC
             LIMIT 50'
        );
        $statement->execute($params);
        $accountingRows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$maxReply = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $replySummary ?: [['total' => 1]]));
$maxTerminate = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $terminateSummary ?: [['total' => 1]]));
$maxNas = max(1, ...array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $nasSummary ?: [['total' => 1]]));
$suffix = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RADIUS logları</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .radius-log-meta{display:flex;gap:8px;flex-wrap:wrap;color:#64748b;font-size:.86rem}
        .radius-log-code{font-family:ui-monospace,Consolas,monospace;font-size:.88rem;color:#334155}
        .radius-log-table td,.radius-log-table th{overflow-wrap:anywhere}
        @media (max-width:700px){
            .radius-log-meta{display:grid;grid-template-columns:1fr;gap:5px}
        }
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('RADIUS logları', 'radius-logs', $lang); ?>

    <main class="portal-layout">
        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">FreeRADIUS audit</span>
                <h1>Auth və accounting logları</h1>
                <p>Operator üçün sürətli audit görünüşü: kim qoşuldu, kim reject aldı, hansı NAS-dan gəldi, hansı IP/MAC göründü və sessiya necə bağlandı. Parol sahəsi göstərilmir.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="radius-active.php<?= e($suffix); ?>">Online sessiyalar</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-nas.php<?= e($suffix); ?>">NAS cihazları</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-natlog.php<?= e($suffix); ?>">NAT log axtarışı</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Dövr Accept / Reject</span>
                <strong><?= e((string) $stats['period_accept']); ?> / <?= e((string) $stats['period_reject']); ?></strong>
                <small>Online: <?= e((string) $stats['online']); ?> sessiya</small>
                <span>Dövr trafik</span>
                <strong><?= e(billing_bytes($stats['period_traffic'])); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal" data-auto-filter-results="radius-logs-kpis" data-auto-filter-form="radius-logs-filter">
            <?php billing_render_stat_card('Accept', (string) $stats['period_accept'], $startDate . ' - ' . $endDate, 'fa-circle-check', 'green'); ?>
            <?php billing_render_stat_card('Reject', (string) $stats['period_reject'], 'uğursuz auth', 'fa-circle-xmark', 'orange'); ?>
            <?php billing_render_stat_card('Accounting', (string) $stats['period_accounting'], 'sessiya qeydi', 'fa-database', 'blue'); ?>
            <?php billing_render_stat_card('Disconnect', (string) $stats['period_disconnect'], 'bağlanan sessiya', 'fa-link-slash', 'orange'); ?>
            <?php billing_render_stat_card('Online', (string) $stats['online'], 'açıq sessiya', 'fa-signal', 'green'); ?>
            <?php billing_render_stat_card('Trafik', billing_bytes($stats['period_traffic']), 'input + output', 'fa-chart-line', 'navy'); ?>
        </section>

        <section class="mgs-filter-panel portal-reveal">
            <div class="mgs-section-head">
                <div>
                    <h2>Log filtri</h2>
                    <p>Username, müştəri kodu, ad, IP, MAC, NAS, reply və tarix üzrə axtar.</p>
                </div>
            </div>
            <form method="get" class="portal-stacked-form" id="radius-logs-filter" data-auto-filter data-auto-filter-targets="radius-logs-kpis,radius-logs-charts,radius-logs-results">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row">
                        <label>Axtarış</label>
                        <input type="text" name="q" value="<?= e($query); ?>" placeholder="username, kod, ad, IP, MAC">
                    </div>
                    <div class="portal-form-row">
                        <label>Tip</label>
                        <select name="type">
                            <option value="all" <?= $logType === 'all' ? 'selected' : ''; ?>>Hamısı</option>
                            <option value="auth" <?= $logType === 'auth' ? 'selected' : ''; ?>>Auth / reject</option>
                            <option value="accounting" <?= $logType === 'accounting' ? 'selected' : ''; ?>>Accounting</option>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Reply</label>
                        <select name="reply">
                            <option value="">Bütün cavablar</option>
                            <?php foreach ($replyOptions as $replyOption): ?>
                                <option value="<?= e((string) $replyOption); ?>" <?= $replyFilter === (string) $replyOption ? 'selected' : ''; ?>><?= e((string) $replyOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>NAS IP</label>
                        <select name="nas">
                            <option value="">Bütün NAS-lar</option>
                            <?php foreach ($nasOptions as $nasOption): ?>
                                <option value="<?= e((string) $nasOption); ?>" <?= $nasFilter === (string) $nasOption ? 'selected' : ''; ?>><?= e((string) $nasOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row"><label>İlkin tarix</label><input type="date" name="start_date" value="<?= e($startDate); ?>"></div>
                    <div class="portal-form-row"><label>Son tarix</label><input type="date" name="end_date" value="<?= e($endDate); ?>"></div>
                    <div class="billing-filter-actions">
                        <button type="submit" class="portal-primary-btn">Axtar</button>
                        <a class="portal-ghost-btn portal-inline-link" href="radius-logs.php<?= e($suffix); ?>">Sıfırla</a>
                    </div>
                </div>
                <small data-auto-filter-status role="status" aria-live="polite"></small>
            </form>
        </section>

        <section class="portal-grid portal-grid-3 portal-reveal" data-auto-filter-results="radius-logs-charts" data-auto-filter-form="radius-logs-filter">
            <article class="mgs-chart-card">
                <div class="mgs-chart-head"><div><h2>Auth cavabları</h2><p>Seçilmiş period üzrə reply paylanması.</p></div></div>
                <div class="mgs-bars">
                    <?php foreach ($replySummary as $row): ?>
                        <?php $label = (string) ($row['label'] ?? '-'); ?>
                        <div class="mgs-bar-row">
                            <span><?= e($label); ?></span>
                            <span class="mgs-bar-track"><i class="mgs-bar-fill <?= str_contains(strtolower($label), 'accept') ? 'is-green' : 'is-orange'; ?>" style="width:<?= e((string) billing_percent((float) ($row['total'] ?? 0), $maxReply)); ?>%"></i></span>
                            <strong><?= e((string) ($row['total'] ?? 0)); ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($replySummary === []): ?><?php billing_render_empty_state('Auth log yoxdur', 'Seçilmiş period üçün radpostauth qeydi tapılmadı.', 'fa-lock'); ?><?php endif; ?>
                </div>
            </article>

            <article class="mgs-chart-card">
                <div class="mgs-chart-head"><div><h2>Bağlanma səbəbləri</h2><p>Terminate cause üzrə qruplaşdırma.</p></div></div>
                <div class="mgs-bars">
                    <?php foreach ($terminateSummary as $row): ?>
                        <div class="mgs-bar-row">
                            <span><?= e((string) ($row['label'] ?? '-')); ?></span>
                            <span class="mgs-bar-track"><i class="mgs-bar-fill is-orange" style="width:<?= e((string) billing_percent((float) ($row['total'] ?? 0), $maxTerminate)); ?>%"></i></span>
                            <strong><?= e((string) ($row['total'] ?? 0)); ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($terminateSummary === []): ?><?php billing_render_empty_state('Accounting yoxdur', 'Seçilmiş period üçün radacct qeydi tapılmadı.', 'fa-database'); ?><?php endif; ?>
                </div>
            </article>

            <article class="mgs-chart-card">
                <div class="mgs-chart-head"><div><h2>NAS paylanması</h2><p>Accounting yazan NAS-lar.</p></div></div>
                <div class="mgs-bars">
                    <?php foreach ($nasSummary as $row): ?>
                        <div class="mgs-bar-row">
                            <span><?= e((string) ($row['label'] ?? '-')); ?></span>
                            <span class="mgs-bar-track"><i class="mgs-bar-fill" style="width:<?= e((string) billing_percent((float) ($row['total'] ?? 0), $maxNas)); ?>%"></i></span>
                            <strong><?= e((string) ($row['total'] ?? 0)); ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($nasSummary === []): ?><?php billing_render_empty_state('NAS log yoxdur', 'Seçilmiş period üçün NAS accounting qeydi yoxdur.', 'fa-server'); ?><?php endif; ?>
                </div>
            </article>
        </section>

        <div data-auto-filter-results="radius-logs-results" data-auto-filter-form="radius-logs-filter">
        <?php if ($logType !== 'accounting'): ?>
            <section class="mgs-data-card portal-reveal" id="auth-logs">
                <div class="mgs-section-head">
                    <div>
                        <h2>Auth / reject logları</h2>
                        <p><?= e((string) count($postAuthRows)); ?> nəticə göstərilir. Parol sahəsi göstərilmir.</p>
                    </div>
                </div>
                <?php if ($postAuthRows === []): ?>
                    <?php billing_render_empty_state('Auth nəticəsi yoxdur', 'Seçilmiş filtrə uyğun radpostauth qeydi tapılmadı.', 'fa-user-lock'); ?>
                <?php else: ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table radius-log-table">
                            <thead><tr><th>Username</th><th>Müştəri</th><th>Reply</th><th>Class</th><th>Tarix</th><th>Əməliyyat</th></tr></thead>
                            <tbody>
                                <?php foreach ($postAuthRows as $row): ?>
                                    <tr>
                                        <td><strong class="radius-log-code"><?= e((string) ($row['username'] ?? '-')); ?></strong></td>
                                        <td>
                                            <?= e((string) ($row['subscriber_code'] ?? '-')); ?>
                                            <div class="radius-log-meta"><span><?= e((string) ($row['full_name'] ?? '')); ?></span></div>
                                        </td>
                                        <td><span class="portal-pill <?= e(radius_logs_badge_class((string) ($row['reply'] ?? ''))); ?>"><?= e((string) ($row['reply'] ?? '-')); ?></span></td>
                                        <td><?= e((string) ($row['class'] ?? '-')); ?></td>
                                        <td><?= e(billing_datetime($row['authdate'] ?? null)); ?></td>
                                        <td>
                                            <?php if ((int) ($row['account_id'] ?? 0) > 0): ?>
                                                <a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?id=<?= e((string) (int) $row['account_id']); ?>">Profil</a>
                                            <?php else: ?>
                                                <span class="portal-pill">Bağlı deyil</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($logType !== 'auth'): ?>
            <section class="mgs-data-card portal-reveal" id="accounting-logs">
                <div class="mgs-section-head">
                    <div>
                        <h2>Accounting logları</h2>
                        <p><?= e((string) count($accountingRows)); ?> nəticə göstərilir.</p>
                    </div>
                </div>
                <?php if ($accountingRows === []): ?>
                    <?php billing_render_empty_state('Accounting nəticəsi yoxdur', 'Seçilmiş filtrə uyğun radacct qeydi tapılmadı.', 'fa-database'); ?>
                <?php else: ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table radius-log-table">
                            <thead><tr><th>Username</th><th>Müştəri</th><th>NAS / port</th><th>IP / MAC</th><th>Status</th><th>Trafik</th><th>Əməliyyat</th></tr></thead>
                            <tbody>
                                <?php foreach ($accountingRows as $row): ?>
                                    <?php $online = trim((string) ($row['acctstoptime'] ?? '')) === ''; ?>
                                    <tr>
                                        <td><strong class="radius-log-code"><?= e((string) ($row['username'] ?? '-')); ?></strong><br><small><?= e(billing_datetime($row['acctstarttime'] ?? null)); ?></small></td>
                                        <td><?= e((string) ($row['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($row['full_name'] ?? '')); ?></small></td>
                                        <td><?= e((string) ($row['nasipaddress'] ?? '-')); ?><br><small><?= e((string) ($row['nasportid'] ?? '-')); ?></small></td>
                                        <td><?= e((string) ($row['framedipaddress'] ?? '-')); ?><br><small><?= e((string) ($row['callingstationid'] ?? '-')); ?></small></td>
                                        <td>
                                            <span class="portal-pill <?= e($online ? 'portal-pill-success' : ''); ?>"><?= e($online ? 'Online' : ((string) ($row['acctterminatecause'] ?? 'Closed'))); ?></span>
                                            <br><small><?= e($online ? billing_datetime($row['acctupdatetime'] ?? null) : billing_datetime($row['acctstoptime'] ?? null)); ?></small>
                                        </td>
                                        <td>↓ <?= e(billing_bytes((float) ($row['acctoutputoctets'] ?? 0))); ?><br><small>↑ <?= e(billing_bytes((float) ($row['acctinputoctets'] ?? 0))); ?></small></td>
                                        <td>
                                            <?php if ((int) ($row['account_id'] ?? 0) > 0): ?>
                                                <a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?id=<?= e((string) (int) $row['account_id']); ?>">Profil</a>
                                            <?php else: ?>
                                                <span class="portal-pill">Bağlı deyil</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        </div>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
