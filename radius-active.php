<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();
$query = clean_text((string) ($_GET['q'] ?? ''));
$statusFilter = clean_text((string) ($_GET['status'] ?? ''));
$flash = null;
$canRequestDisconnect = app_portal_is_admin($user);
$radiusSettings = app_radius_settings();
$disconnectWorkerReadiness = app_radius_disconnect_worker_readiness($radiusSettings, $db);

$stats = ['total' => 0, 'active' => 0, 'online' => 0, 'traffic24' => 0.0];
$rows = [];
$recentActions = [];

if ($db instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($action === 'request_disconnect') {
        if (!$canRequestDisconnect) {
            $flash = ['type' => 'error', 'message' => 'Disconnect request ucun admin/NOC icazesi lazimdir.'];
        } else {
            $requestId = app_radius_request_disconnect($db, [
                'account_id' => (int) ($_POST['account_id'] ?? 0),
                'username' => clean_text((string) ($_POST['username'] ?? '')),
                'framed_ip' => clean_text((string) ($_POST['framed_ip'] ?? '')),
                'nas_ip' => clean_text((string) ($_POST['nas_ip'] ?? '')),
                'nas_port_id' => clean_text((string) ($_POST['nas_port_id'] ?? '')),
                'calling_station_id' => clean_text((string) ($_POST['calling_station_id'] ?? '')),
            ], $user, clean_text((string) ($_POST['reason'] ?? '')));
            $flash = $requestId > 0
                ? ['type' => 'success', 'message' => 'Disconnect sorgusu queue-ya dusdu #' . $requestId . '. CoA worker bunu ' . ($radiusSettings['dynamic_author_client_ip'] ?: $radiusSettings['server_ip']) . ' uzerinden emal edecek.']
                : ['type' => 'error', 'message' => 'Disconnect sorgusu saxlanmadi. Username tapilmadi.'];
        }
    }
}

if ($db instanceof PDO) {
    $stats['total'] = (int) $db->query("SELECT COUNT(*) FROM portal_accounts WHERE role = 'customer'")->fetchColumn();
    $stats['active'] = (int) $db->query("SELECT COUNT(*) FROM billing_radius_services WHERE status = 'active'")->fetchColumn();
    $stats['online'] = (int) $db->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)")->fetchColumn();
    $stats['traffic24'] = (float) $db->query("SELECT COALESCE(SUM(COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

    $sql = "SELECT s.username, s.service_type, s.assigned_ip, s.static_ip, s.status, s.expires_at,
                   a.id AS account_id, a.full_name, a.subscriber_code, a.phone,
                   t.name AS tariff_name,
                   traffic.download_bytes, traffic.upload_bytes, traffic.sessions_24h,
                   current_session.framedipaddress, current_session.callingstationid, current_session.nasipaddress,
                   current_session.nasportid, current_session.acctstarttime, current_session.acctupdatetime
            FROM billing_radius_services s
            LEFT JOIN portal_accounts a ON a.id = s.account_id
            LEFT JOIN billing_tariffs t ON t.id = s.tariff_id
            LEFT JOIN (
                SELECT username,
                       SUM(COALESCE(acctoutputoctets,0)) AS download_bytes,
                       SUM(COALESCE(acctinputoctets,0)) AS upload_bytes,
                       COUNT(*) AS sessions_24h
                FROM radacct
                WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY username
            ) traffic ON traffic.username = s.username
            LEFT JOIN (
                SELECT r1.*
                FROM radacct r1
                INNER JOIN (
                    SELECT username, MAX(radacctid) AS max_id
                    FROM radacct
                    WHERE acctstoptime IS NULL
                    GROUP BY username
                ) last_open ON last_open.max_id = r1.radacctid
            ) current_session ON current_session.username = s.username
            WHERE 1=1";
    $params = [];
    if ($query !== '') {
        $sql .= ' AND (s.username LIKE :q OR a.subscriber_code LIKE :q OR a.full_name LIKE :q OR a.phone LIKE :q OR s.assigned_ip LIKE :q OR s.static_ip LIKE :q OR current_session.callingstationid LIKE :q)';
        $params['q'] = '%' . $query . '%';
    }
    if ($statusFilter === 'online') {
        $sql .= ' AND current_session.radacctid IS NOT NULL';
    } elseif ($statusFilter === 'offline') {
        $sql .= ' AND current_session.radacctid IS NULL';
    } elseif ($statusFilter !== '') {
        $sql .= ' AND s.status = :status';
        $params['status'] = $statusFilter;
    }
    $sql .= ' ORDER BY current_session.acctupdatetime DESC, traffic.download_bytes DESC, s.updated_at DESC LIMIT 200';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll() ?: [];
    $recentActions = app_radius_recent_session_actions($db, 12);
} else {
    $mockRows = billing_mock_radius_rows();
    $stats['total'] = count($mockRows);
    $stats['active'] = count(array_filter($mockRows, static fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'active'));
    $stats['online'] = count(array_filter($mockRows, static fn (array $row): bool => trim((string) ($row['acctstarttime'] ?? '')) !== ''));
    $stats['traffic24'] = array_sum(array_map(static fn (array $row): float => (float) ($row['download_bytes'] ?? 0) + (float) ($row['upload_bytes'] ?? 0), $mockRows));

    $rows = array_values(array_filter($mockRows, static function (array $row) use ($query, $statusFilter): bool {
        if ($query !== '') {
            $haystack = strtolower(implode(' ', [
                (string) ($row['username'] ?? ''),
                (string) ($row['subscriber_code'] ?? ''),
                (string) ($row['full_name'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['assigned_ip'] ?? ''),
                (string) ($row['static_ip'] ?? ''),
                (string) ($row['callingstationid'] ?? ''),
            ]));
            if (!str_contains($haystack, strtolower($query))) {
                return false;
            }
        }

        $isOnline = trim((string) ($row['acctstarttime'] ?? '')) !== '';
        if ($statusFilter === 'online') {
            return $isOnline;
        }
        if ($statusFilter === 'offline') {
            return !$isOnline;
        }
        if ($statusFilter !== '' && !in_array($statusFilter, ['online', 'offline'], true)) {
            return strtolower((string) ($row['status'] ?? '')) === strtolower($statusFilter);
        }

        return true;
    }));
}

$nasDistribution = [];
$statusDistribution = ['online' => 0, 'offline' => 0, 'active' => 0, 'expired' => 0, 'suspended' => 0];
$topTrafficRows = [];
foreach ($rows as $row) {
    $nas = trim((string) ($row['nasipaddress'] ?? ''));
    if ($nas !== '') {
        $nasDistribution[$nas] = ($nasDistribution[$nas] ?? 0) + 1;
    }
    $isOnline = trim((string) ($row['acctstarttime'] ?? '')) !== '';
    $statusKey = $isOnline ? 'online' : strtolower((string) ($row['status'] ?? 'offline'));
    $statusDistribution[$statusKey] = ($statusDistribution[$statusKey] ?? 0) + 1;
    $topTrafficRows[] = [
        'username' => (string) ($row['username'] ?? '-'),
        'traffic' => (float) ($row['download_bytes'] ?? 0) + (float) ($row['upload_bytes'] ?? 0),
    ];
}
arsort($nasDistribution);
usort($topTrafficRows, static fn (array $a, array $b): int => $b['traffic'] <=> $a['traffic']);
$topTrafficRows = array_slice($topTrafficRows, 0, 6);
$maxTopTraffic = max(1.0, (float) ($topTrafficRows[0]['traffic'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalRows = count($rows);
$totalPages = max(1, (int) ceil(($totalRows > 0 ? $totalRows : 1) / $perPage));
$page = min($page, $totalPages);
$visibleRows = array_slice($rows, ($page - 1) * $perPage, $perPage);
$queryBase = $_GET;
unset($queryBase['page']);
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radius aktiv list</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .radius-active-table td,.radius-active-table th{overflow-wrap:anywhere}
        .radius-active-table small{display:block;line-height:1.3}
        @media (max-width:700px){
            .radius-active-table .portal-primary-btn,
            .radius-active-table .portal-ghost-btn,
            .radius-active-table button{min-height:30px;padding:6px 8px;font-size:.72rem}
        }
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('Radius aktiv list', 'radius-active', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">FreeRADIUS</span>
                <h1>Aktiv istifadəçilər və sessiyalar</h1>
                <p>Referans sistemdəki aktiv list modeli: username, IP, MAC, VLAN/status, 24 saat trafik, sessiya və disconnect əməliyyatına hazır görünüş.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="billing-customers.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Müştəri siyahısı</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-natlog.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">NAT log axtarışı</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Online</span>
                <strong><?= e((string) $stats['online']); ?></strong>
                <small>radacct açıq sessiyalar</small>
                <span>24 saat trafik</span>
                <strong><?= e(billing_bytes($stats['traffic24'])); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Cəmi istifadəçi', (string) $stats['total'], 'portal customer', 'fa-users', 'blue'); ?>
            <?php billing_render_stat_card('Aktiv servis', (string) $stats['active'], 'billing_radius_services', 'fa-circle-check', 'green'); ?>
            <?php billing_render_stat_card('Son 20 dəq online', (string) $stats['online'], 'RADIUS accounting yeniləməsi', 'fa-signal', 'orange'); ?>
            <?php billing_render_stat_card('24 saat trafik', billing_bytes($stats['traffic24']), 'download + upload', 'fa-chart-line', 'navy'); ?>
        </section>

        <section class="mgs-dashboard-grid portal-reveal">
            <article class="mgs-chart-card">
                <div class="mgs-chart-head">
                    <div><h2>Top trafik istifadəçiləri</h2><p>Son 24 saat üçün download + upload xülasəsi.</p></div>
                </div>
                <div class="mgs-bars">
                    <?php if ($topTrafficRows === []): ?>
                        <?php billing_render_empty_state('Trafik yoxdur', 'Son 24 saat üçün RADIUS accounting trafiki tapılmadı.', 'fa-chart-simple'); ?>
                    <?php endif; ?>
                    <?php foreach ($topTrafficRows as $trafficRow): ?>
                        <div class="mgs-bar-row">
                            <span><?= e($trafficRow['username']); ?></span>
                            <div class="mgs-bar-track"><span class="mgs-bar-fill" style="width: <?= e((string) billing_percent($trafficRow['traffic'], $maxTopTraffic)); ?>%"></span></div>
                            <strong><?= e(billing_bytes($trafficRow['traffic'])); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="mgs-chart-card">
                <div class="mgs-chart-head">
                    <div><h2>NAS və status paylanması</h2><p>Aktiv list nəticəsinə görə operativ bölünmə.</p></div>
                </div>
                <div class="mgs-mini-chart">
                    <?php foreach (array_slice($nasDistribution, 0, 5, true) as $nasIp => $count): ?>
                        <div class="mgs-bar-row">
                            <span><?= e((string) $nasIp); ?></span>
                            <div class="mgs-bar-track"><span class="mgs-bar-fill is-orange" style="width: <?= e((string) billing_percent($count, max($nasDistribution ?: [1]))); ?>%"></span></div>
                            <strong><?= e((string) $count); ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <div class="portal-badge-list">
                        <span>Online: <?= e((string) ($statusDistribution['online'] ?? 0)); ?></span>
                        <span>Offline: <?= e((string) ($statusDistribution['offline'] ?? 0)); ?></span>
                        <span>Aktiv: <?= e((string) ($statusDistribution['active'] ?? 0)); ?></span>
                    </div>
                </div>
            </article>
        </section>

        <section class="mgs-filter-panel portal-reveal">
            <div class="mgs-section-head"><div><h2>Axtarış və filtr</h2><p>İstifadəçi, IP, MAC, telefon və müştəri adı üzrə axtar.</p></div></div>
            <form method="get" class="portal-stacked-form" id="radius-active-filter" data-auto-filter data-auto-filter-targets="radius-active-results">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row"><label>Axtarış</label><input type="text" name="q" value="<?= e($query); ?>" placeholder="username, IP, MAC, ad, telefon"></div>
                    <div class="portal-form-row">
                        <label>Status</label>
                        <select name="status">
                            <option value="">Hamısı</option>
                            <option value="online" <?= $statusFilter === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="offline" <?= $statusFilter === 'offline' ? 'selected' : ''; ?>>Offline</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>Aktiv</option>
                            <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : ''; ?>>Vaxt bitib</option>
                            <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : ''; ?>>Dayandırılıb</option>
                        </select>
                    </div>
                    <div class="billing-filter-actions">
                        <button type="submit" class="portal-primary-btn">Axtar</button>
                        <a class="portal-ghost-btn portal-inline-link" href="radius-active.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Sıfırla</a>
                    </div>
                </div>
                <small data-auto-filter-status role="status" aria-live="polite"></small>
            </form>
        </section>

        <section class="mgs-data-card portal-reveal">
            <div class="mgs-section-head">
                <div>
                    <h2>Disconnect sorğuları</h2>
                    <p>Portal queue yaradir; canli Disconnect/CoA worker bu sorğulari <?= e((string) ($radiusSettings['dynamic_author_client_ip'] ?: $radiusSettings['server_ip'])); ?> uzerinden routere oturur.</p>
                </div>
                <span class="portal-pill <?= e($canRequestDisconnect && !empty($disconnectWorkerReadiness['ok']) ? 'portal-pill-success' : ''); ?>"><?= e($canRequestDisconnect ? 'Admin/NOC icazəsi var' : 'Admin/NOC icazəsi lazımdır'); ?> / <?= e(!empty($disconnectWorkerReadiness['ok']) ? 'CoA config hazirdir' : 'CoA config natamam'); ?></span>
            </div>
            <?php if ($recentActions === []): ?>
                <?php billing_render_empty_state('Disconnect sorğusu yoxdur', 'Online sessiyadan sorğu yaradıldıqda burada görünəcək.', 'fa-shield-halved'); ?>
            <?php else: ?>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>#</th><th>Username</th><th>Müştəri</th><th>IP / NAS</th><th>Status</th><th>Səbəb</th><th>Yaradan</th><th>Tarix</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentActions as $actionRow): ?>
                                <tr>
                                    <td><?= e((string) ($actionRow['id'] ?? 0)); ?></td>
                                    <td><strong><?= e((string) ($actionRow['username'] ?? '-')); ?></strong></td>
                                    <td><?= e((string) ($actionRow['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($actionRow['customer_name'] ?? '')); ?></small></td>
                                    <td><?= e((string) ($actionRow['framed_ip'] ?? '-')); ?><br><small><?= e((string) ($actionRow['nas_ip'] ?? '-')); ?> <?= e((string) ($actionRow['nas_port_id'] ?? '')); ?></small></td>
                                    <td><span class="portal-pill"><?= e((string) ($actionRow['status'] ?? '-')); ?></span></td>
                                    <td><?= e((string) ($actionRow['reason'] ?? '-')); ?></td>
                                    <td><?= e((string) ($actionRow['requested_by_name'] ?? '-')); ?></td>
                                    <td><?= e(billing_datetime($actionRow['requested_at'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="mgs-data-card portal-reveal" data-auto-filter-results="radius-active-results" data-auto-filter-form="radius-active-filter">
            <div class="mgs-section-head"><div><h2>İstifadəçi siyahısı</h2><p><?= e((string) count($rows)); ?> nəticə göstərilir.</p></div></div>
            <?php if ($rows === []): ?>
                <?php billing_render_empty_state('Radius istifadəçisi tapılmadı', 'Axtarış və filtr şərtlərinə uyğun sessiya və ya servis yoxdur.', 'fa-user-magnifying-glass'); ?>
            <?php else: ?>
            <div class="mgs-session-grid">
                <?php foreach (array_slice($rows, 0, 6) as $row): ?>
                    <?php
                    $online = trim((string) ($row['acctstarttime'] ?? '')) !== '';
                    $ip = (string) (($row['framedipaddress'] ?? '') ?: (($row['static_ip'] ?? '') ?: ($row['assigned_ip'] ?? '-')));
                    ?>
                    <article class="mgs-session-card">
                        <h3><?= e((string) ($row['username'] ?? '-')); ?></h3>
                        <span class="portal-pill <?= e($online ? 'portal-pill-success' : billing_status_class((string) ($row['status'] ?? ''))); ?>"><?= e($online ? 'Online' : (string) ($row['status'] ?? '-')); ?></span>
                        <small><?= e((string) ($row['subscriber_code'] ?? '-')); ?> / <?= e((string) ($row['full_name'] ?? '-')); ?></small>
                        <div class="mgs-profile-kv">
                            <div><span>IP</span><strong><?= e($ip); ?></strong></div>
                            <div><span>MAC/NAS</span><strong><?= e((string) ($row['callingstationid'] ?? '-')); ?> / <?= e((string) ($row['nasipaddress'] ?? '-')); ?></strong></div>
                            <div><span>Trafik</span><strong>↓ <?= e(billing_bytes($row['download_bytes'] ?? 0)); ?> / ↑ <?= e(billing_bytes($row['upload_bytes'] ?? 0)); ?></strong></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table radius-active-table">
                    <thead><tr><th>İstifadəçi</th><th>Tarif</th><th>IP</th><th>MAC / NAS</th><th>Status</th><th>24 saat trafik</th><th>Sessiya</th><th>Əməliyyat</th></tr></thead>
                    <tbody>
                        <?php foreach ($visibleRows as $row): ?>
                            <?php
                            $online = trim((string) ($row['acctstarttime'] ?? '')) !== '';
                            $ip = (string) (($row['framedipaddress'] ?? '') ?: (($row['static_ip'] ?? '') ?: ($row['assigned_ip'] ?? '-')));
                            ?>
                            <tr>
                                <td><strong><?= e((string) ($row['username'] ?? '-')); ?></strong><br><small><?= e((string) ($row['subscriber_code'] ?? '-')); ?> / <?= e((string) ($row['full_name'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($row['service_type'] ?? '-')); ?><br><small><?= e((string) ($row['tariff_name'] ?? '-')); ?></small></td>
                                <td><?= e($ip); ?></td>
                                <td><?= e((string) ($row['callingstationid'] ?? '-')); ?><br><small><?= e((string) ($row['nasipaddress'] ?? '-')); ?> <?= e((string) ($row['nasportid'] ?? '')); ?></small></td>
                                <td><span class="portal-pill <?= e($online ? 'portal-pill-success' : billing_status_class((string) ($row['status'] ?? ''))); ?>"><?= e($online ? 'Online' : (string) ($row['status'] ?? '-')); ?></span></td>
                                <td>↓ <?= e(billing_bytes($row['download_bytes'] ?? 0)); ?><br><small>↑ <?= e(billing_bytes($row['upload_bytes'] ?? 0)); ?></small></td>
                                <td><?= e((string) ($row['sessions_24h'] ?? 0)); ?><br><small><?= e(billing_datetime($row['acctupdatetime'] ?? $row['acctstarttime'] ?? null)); ?></small></td>
                                <td>
                                    <a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?id=<?= e((string) ($row['account_id'] ?? 0)); ?>#profile-radius">Ətraflı</a>
                                    <?php if ($online && $canRequestDisconnect): ?>
                                        <form method="post" class="portal-inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="request_disconnect">
                                            <input type="hidden" name="account_id" value="<?= e((string) ($row['account_id'] ?? 0)); ?>">
                                            <input type="hidden" name="username" value="<?= e((string) ($row['username'] ?? '')); ?>">
                                            <input type="hidden" name="framed_ip" value="<?= e($ip); ?>">
                                            <input type="hidden" name="nas_ip" value="<?= e((string) ($row['nasipaddress'] ?? '')); ?>">
                                            <input type="hidden" name="nas_port_id" value="<?= e((string) ($row['nasportid'] ?? '')); ?>">
                                            <input type="hidden" name="calling_station_id" value="<?= e((string) ($row['callingstationid'] ?? '')); ?>">
                                            <input type="hidden" name="reason" value="Operator yoxlamasi: sessiyani yenile">
                                            <button type="submit" class="portal-ghost-btn">Disconnect sorğusu</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="billing-mini-muted"><?= e($online ? 'Disconnect ucun admin/NOC icazesi lazimdir.' : 'Offline sessiyada disconnect gerek deyil.'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <nav class="portal-pagination" aria-label="Radius aktiv list sehifeleri">
                <?php
                $prevParams = $queryBase + ['page' => max(1, $page - 1)];
                $nextParams = $queryBase + ['page' => min($totalPages, $page + 1)];
                ?>
                <a class="portal-ghost-btn portal-inline-link<?= $page <= 1 ? ' portal-disabled-link' : ''; ?>" href="radius-active.php?<?= e(http_build_query($prevParams)); ?>">Evvelki</a>
                <span><?= e((string) $page); ?> / <?= e((string) $totalPages); ?>, <?= e((string) $perPage); ?> qeyd</span>
                <a class="portal-ghost-btn portal-inline-link<?= $page >= $totalPages ? ' portal-disabled-link' : ''; ?>" href="radius-active.php?<?= e(http_build_query($nextParams)); ?>">Novbeti</a>
            </nav>
            <?php endif; ?>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
