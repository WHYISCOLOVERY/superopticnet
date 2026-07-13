<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();

$query = clean_text((string) ($_GET['q'] ?? ''));
$sourceIp = clean_text((string) ($_GET['source_ip'] ?? ''));
$destinationIp = clean_text((string) ($_GET['destination_ip'] ?? ''));
$port = clean_text((string) ($_GET['port'] ?? ''));
$date = clean_text((string) ($_GET['date'] ?? ''));
$node = clean_text((string) ($_GET['node'] ?? ''));
$protocol = clean_text((string) ($_GET['protocol'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
$offset = ($page - 1) * $perPage;

$stats = [
    'total' => 0,
    'today' => 0,
    'nodes' => 0,
    'results' => 0,
];
$nodes = [];
$rows = [];
$hasFilters = $query !== '' || $sourceIp !== '' || $destinationIp !== '' || $port !== '' || $date !== '' || $node !== '' || $protocol !== '';
$filterError = '';

if ($db instanceof PDO) {
    $stats['total'] = (int) $db->query('SELECT COUNT(*) FROM radius_nat_logs')->fetchColumn();
    $stats['today'] = (int) $db->query('SELECT COUNT(*) FROM radius_nat_logs WHERE logged_at >= CURDATE()')->fetchColumn();
    $stats['nodes'] = (int) $db->query('SELECT COUNT(*) FROM radius_nat_log_nodes')->fetchColumn();
    $nodes = $db->query(
        'SELECT *
         FROM radius_nat_log_nodes
         ORDER BY COALESCE(last_seen_at, updated_at, created_at) DESC
         LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $where = ['1=1'];
    $params = [];
    if ($query !== '') {
        $where[] = '(l.username LIKE :q OR l.subscriber_code LIKE :q OR l.source_ip LIKE :q OR l.nat_ip LIKE :q OR l.destination_ip LIKE :q OR l.node_name LIKE :q OR l.raw_message LIKE :q)';
        $params['q'] = '%' . $query . '%';
    }
    if ($sourceIp !== '') {
        $where[] = 'l.source_ip LIKE :source_ip';
        $params['source_ip'] = '%' . $sourceIp . '%';
    }
    if ($destinationIp !== '') {
        $where[] = 'l.destination_ip LIKE :destination_ip';
        $params['destination_ip'] = '%' . $destinationIp . '%';
    }
    if ($node !== '') {
        $where[] = 'l.node_name = :node_name';
        $params['node_name'] = $node;
    }
    if ($protocol !== '') {
        $where[] = 'l.protocol = :protocol';
        $params['protocol'] = strtoupper($protocol);
    }
    if ($port !== '') {
        if (ctype_digit($port)) {
            $where[] = '(l.source_port = :port OR l.nat_port = :port OR l.destination_port = :port)';
            $params['port'] = (int) $port;
        } else {
            $filterError = 'Port yalnÄ±z rÉ™qÉ™m olmalÄ±dÄ±r.';
        }
    }
    if ($date !== '') {
        $parsedDate = app_db_parse_date($date);
        if ($parsedDate !== null) {
            $where[] = 'l.logged_at >= :date_from AND l.logged_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $params['date_from'] = $parsedDate;
            $params['date_to'] = $parsedDate;
        } else {
            $filterError = 'Tarix formatÄ± dÃ¼zgÃ¼n deyil.';
        }
    }

    $whereSql = implode(' AND ', $where);
    $countStatement = $db->prepare('SELECT COUNT(*) FROM radius_nat_logs l WHERE ' . $whereSql);
    $countStatement->execute($params);
    $stats['results'] = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil(max(1, $stats['results']) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $statement = $db->prepare(
        'SELECT l.*,
                COALESCE(direct_account.full_name, service_account.full_name) AS customer_name,
                COALESCE(direct_account.subscriber_code, service_account.subscriber_code, l.subscriber_code) AS resolved_subscriber_code,
                COALESCE(direct_account.id, service_account.id) AS account_id
         FROM radius_nat_logs l
         LEFT JOIN portal_accounts direct_account ON direct_account.subscriber_code = l.subscriber_code
         LEFT JOIN billing_radius_services service ON service.username = l.username
         LEFT JOIN portal_accounts service_account ON service_account.id = service.account_id
         WHERE ' . $whereSql . '
         ORDER BY l.logged_at DESC, l.id DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $filterError = 'MySQL baÄŸlantÄ±sÄ± hazÄ±r deyil. NAT log axtarÄ±ÅŸÄ± Ã¼Ã§Ã¼n radius_nat_logs cÉ™dvÉ™li aktiv olmalÄ±dÄ±r.';
}

$nodeOptions = array_values(array_unique(array_filter(array_map(static fn (array $row): string => (string) ($row['node_name'] ?? ''), $nodes))));
$protocolOptions = ['TCP', 'UDP', 'ICMP', 'GRE'];
$totalPages = $totalPages ?? 1;
$queryBase = $_GET;
unset($queryBase['page']);
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAT log axtarÄ±ÅŸÄ±</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .nat-node-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        .nat-node-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
            padding: 16px;
        }
        .nat-node-card strong {
            display: block;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .nat-node-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            color: #64748b;
            font-size: 12px;
        }
        .nat-progress {
            height: 8px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
            margin-top: 10px;
        }
        .nat-progress span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #22c55e, #f97316);
        }
        .nat-flow {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            align-items: stretch;
            gap: 6px;
            max-width: 100%;
        }
        .nat-flow-card {
            border-radius: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            min-width: 0;
            padding: 8px 10px;
        }
        .nat-flow-card small {
            display: block;
            color: #64748b;
            margin-bottom: 3px;
            font-size: 0.78rem;
        }
        .nat-flow-card strong {
            display: block;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.2;
        }
        .nat-flow-arrow {
            display: none;
        }
        .nat-results-table {
            table-layout: fixed;
        }
        .nat-results-table th:nth-child(1) {
            width: 120px;
        }
        .nat-results-table th:nth-child(2) {
            width: 38%;
        }
        .nat-results-table th:nth-child(3) {
            width: 22%;
        }
        .nat-results-table th:nth-child(4) {
            width: 16%;
        }
        .nat-results-table th:nth-child(5) {
            width: 110px;
        }
        .nat-results-table td {
            vertical-align: top;
        }
        .nat-flow-card {
            box-sizing: border-box;
            display: block;
            width: 100%;
        }
        .nat-flow-card strong {
            font-size: 0.9rem;
        }
        @media (max-width: 820px) {
            .nat-flow {
                grid-template-columns: 1fr;
            }
            .nat-flow-arrow {
                transform: rotate(90deg);
                justify-self: center;
            }
        }
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('NAT log axtarÄ±ÅŸÄ±', 'radius-natlog', $lang); ?>

    <main class="portal-layout">
        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">FreeRADIUS / NAT LOG</span>
                <h1>NAT log axtarÄ±ÅŸÄ± vÉ™ mÃ¼ÅŸtÉ™ri izi</h1>
                <p>MÉ™nbÉ™ IP, hÉ™dÉ™f IP, port, tarix, istifadÉ™Ã§i vÉ™ log node Ã¼zrÉ™ indexed axtarÄ±ÅŸ. MÉ™lumat syslog/parser tÉ™rÉ™findÉ™n radius_nat_logs cÉ™dvÉ™linÉ™ yazÄ±ldÄ±qdan sonra burada gÃ¶rÃ¼nÉ™cÉ™k.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="radius-active.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Online sessiyalar</a>
                    <a class="portal-ghost-btn portal-inline-link" href="billing-statistics.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>#radius-logs">RADIUS loglarÄ±</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Bu gÃ¼n</span>
                <strong><?= e((string) $stats['today']); ?></strong>
                <small>NAT log qeydi</small>
                <span>NÉ™ticÉ™</span>
                <strong><?= e((string) $stats['results']); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('CÉ™mi NAT log', (string) $stats['total'], 'radius_nat_logs', 'fa-database', 'blue'); ?>
            <?php billing_render_stat_card('BugÃ¼nkÃ¼ log', (string) $stats['today'], 'logged_at >= today', 'fa-clock-rotate-left', 'green'); ?>
            <?php billing_render_stat_card('Log node', (string) $stats['nodes'], 'radius_nat_log_nodes', 'fa-server', 'orange'); ?>
            <?php billing_render_stat_card('Filtr nÉ™ticÉ™si', (string) $stats['results'], 'maksimum 200 sÉ™tir gÃ¶stÉ™rilir', 'fa-magnifying-glass-chart', 'navy'); ?>
        </section>

        <section class="mgs-filter-panel portal-reveal">
            <div class="mgs-section-head">
                <div>
                    <h2>AxtarÄ±ÅŸ filtri</h2>
                    <p>MÉ™nbÉ™ IP, hÉ™dÉ™f IP vÉ™ port kombinasiyasÄ± hÃ¼quqi sorÄŸular vÉ™ problem araÅŸdÄ±rmasÄ± Ã¼Ã§Ã¼n É™sas axtarÄ±ÅŸ yoludur.</p>
                </div>
            </div>
            <?php if ($filterError !== ''): ?>
                <div class="portal-alert is-warning"><?= e($filterError); ?></div>
            <?php endif; ?>
            <form method="get" class="portal-stacked-form">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row">
                        <label>Ãœmumi axtarÄ±ÅŸ</label>
                        <input type="text" name="q" value="<?= e($query); ?>" placeholder="username, abonent kodu, IP, raw log">
                    </div>
                    <div class="portal-form-row">
                        <label>MÉ™nbÉ™ IP</label>
                        <input type="text" name="source_ip" value="<?= e($sourceIp); ?>" placeholder="mÉ™sÉ™lÉ™n: 10.11.16.35">
                    </div>
                    <div class="portal-form-row">
                        <label>HÉ™dÉ™f IP</label>
                        <input type="text" name="destination_ip" value="<?= e($destinationIp); ?>" placeholder="mÉ™sÉ™lÉ™n: 8.8.8.8">
                    </div>
                    <div class="portal-form-row">
                        <label>Port</label>
                        <input type="text" name="port" value="<?= e($port); ?>" placeholder="443">
                    </div>
                    <div class="portal-form-row">
                        <label>Tarix</label>
                        <input type="date" name="date" value="<?= e(app_db_parse_date($date) ?? ''); ?>">
                    </div>
                    <div class="portal-form-row">
                        <label>Protokol</label>
                        <select name="protocol">
                            <option value="">HamÄ±sÄ±</option>
                            <?php foreach ($protocolOptions as $option): ?>
                                <option value="<?= e($option); ?>" <?= strtoupper($protocol) === $option ? 'selected' : ''; ?>><?= e($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Log node</label>
                        <select name="node">
                            <option value="">BÃ¼tÃ¼n node-lar</option>
                            <?php foreach ($nodeOptions as $option): ?>
                                <option value="<?= e($option); ?>" <?= $node === $option ? 'selected' : ''; ?>><?= e($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="portal-form-actions">
                    <button class="portal-primary-btn" type="submit">Axtar</button>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-natlog.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">SÄ±fÄ±rla</a>
                </div>
            </form>
        </section>

        <section class="mgs-dashboard-grid portal-reveal">
            <article class="mgs-chart-card">
                <div class="mgs-chart-head">
                    <div><h2>Log serverlÉ™ri</h2><p>Parser vÉ™ ya syslog agentinin son vÉ™ziyyÉ™ti.</p></div>
                </div>
                <?php if ($nodes === []): ?>
                    <?php billing_render_empty_state('Log node É™lavÉ™ edilmÉ™yib', 'NAT log toplayÄ±cÄ± iÅŸÉ™ dÃ¼ÅŸÉ™ndÉ™ node-lar burada gÃ¶rÃ¼nÉ™cÉ™k.', 'fa-server'); ?>
                <?php else: ?>
                    <div class="nat-node-grid">
                        <?php foreach ($nodes as $nodeRow): ?>
                            <?php $usedPercent = max(0, min(100, (float) ($nodeRow['disk_used_percent'] ?? 0))); ?>
                            <article class="nat-node-card">
                                <strong><?= e((string) ($nodeRow['node_name'] ?? '-')); ?></strong>
                                <span><?= e((string) ($nodeRow['ip_address'] ?? '-')); ?></span>
                                <div class="nat-progress"><span style="width: <?= e((string) max(2, $usedPercent)); ?>%"></span></div>
                                <div class="nat-node-meta">
                                    <span>Disk: <?= e($usedPercent > 0 ? number_format($usedPercent, 1, '.', '') . '%' : '-'); ?></span>
                                    <span>Son É™laqÉ™: <?= e(billing_datetime($nodeRow['last_seen_at'] ?? '')); ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
            <article class="mgs-chart-card">
                <div class="mgs-chart-head">
                    <div><h2>Ä°ngest qaydasÄ±</h2><p>Bu sÉ™hifÉ™ log yaratmÄ±r, yalnÄ±z indexed axtarÄ±ÅŸ edir.</p></div>
                </div>
                <div class="portal-timeline">
                    <div><strong>1. Toplama</strong><span>Router/syslog vÉ™ ya NAT log server fayllarÄ± parserÉ™ dÃ¼ÅŸÃ¼r.</span></div>
                    <div><strong>2. Normalize</strong><span>IP, port, protokol, username vÉ™ abonent kodu ayrÄ±lÄ±r.</span></div>
                    <div><strong>3. Saxlama</strong><span>Hash ilÉ™ dublikatlar bloklanÄ±r, tarix/IP/port indekslÉ™ri axtarÄ±ÅŸÄ± sÃ¼rÉ™tlÉ™ndirir.</span></div>
                    <div><strong>4. Ä°z tapma</strong><span>NÉ™ticÉ™ mÃ¼mkÃ¼n olduqda mÃ¼ÅŸtÉ™ri profili vÉ™ RADIUS sessiyasÄ± ilÉ™ baÄŸlanÄ±r.</span></div>
                </div>
            </article>
        </section>

        <section class="mgs-table-card portal-reveal">
            <div class="mgs-section-head">
                <div>
                    <h2>AxtarÄ±ÅŸ nÉ™ticÉ™lÉ™ri</h2>
                    <p><?= $hasFilters ? 'FiltrÉ™ uyÄŸun son 200 NAT log qeydi.' : 'Filtr seÃ§ilmÉ™yib. BÃ¶yÃ¼k log bazasÄ±nÄ± yÃ¼klÉ™mÉ™mÉ™k Ã¼Ã§Ã¼n yalnÄ±z son 200 qeyd gÃ¶stÉ™rilir.'; ?></p>
                </div>
            </div>
            <?php if ($rows === []): ?>
                <?php billing_render_empty_state('NÉ™ticÉ™ tapÄ±lmadÄ±', 'Log ingest iÅŸÉ™ dÃ¼ÅŸmÉ™yibsÉ™ bu normaldÄ±r. Syslog/parser qoÅŸulduqdan sonra real NAT qeydlÉ™ri burada gÃ¶rÃ¼nÉ™cÉ™k.', 'fa-magnifying-glass'); ?>
            <?php else: ?>
                <div class="portal-table-wrap nat-results-wrap">
                    <table class="portal-table nat-results-table">
                        <thead>
                            <tr>
                                <th>Tarix</th>
                                <th>AxÄ±n</th>
                                <th>Ä°stifadÉ™Ã§i</th>
                                <th>Node</th>
                                <th>ÆmÉ™liyyat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $resolvedCode = trim((string) ($row['resolved_subscriber_code'] ?? ''));
                                $customerName = trim((string) ($row['customer_name'] ?? ''));
                                $profileHref = $resolvedCode !== '' ? 'billing-customer-profile.php?code=' . rawurlencode($resolvedCode) . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : '') : '';
                                ?>
                                <tr>
                                    <td><?= e(billing_datetime($row['logged_at'] ?? '')); ?></td>
                                    <td>
                                        <div class="nat-flow">
                                            <div class="nat-flow-card">
                                                <small>MÉ™nbÉ™</small>
                                                <strong><?= e((string) ($row['source_ip'] ?? '-')); ?><?= isset($row['source_port']) && $row['source_port'] !== null ? ':' . e((string) $row['source_port']) : ''; ?></strong>
                                            </div>
                                            <i class="fa-solid fa-arrow-right nat-flow-arrow"></i>
                                            <div class="nat-flow-card">
                                                <small>NAT</small>
                                                <strong><?= e((string) ($row['nat_ip'] ?? '-')); ?><?= isset($row['nat_port']) && $row['nat_port'] !== null ? ':' . e((string) $row['nat_port']) : ''; ?></strong>
                                            </div>
                                            <i class="fa-solid fa-arrow-right nat-flow-arrow"></i>
                                            <div class="nat-flow-card">
                                                <small>HÉ™dÉ™f</small>
                                                <strong><?= e((string) ($row['destination_ip'] ?? '-')); ?><?= isset($row['destination_port']) && $row['destination_port'] !== null ? ':' . e((string) $row['destination_port']) : ''; ?></strong>
                                            </div>
                                        </div>
                                        <span class="portal-mini-badge"><?= e((string) ($row['protocol'] ?? '-')); ?></span>
                                    </td>
                                    <td>
                                        <strong><?= e((string) ($row['username'] ?? '-')); ?></strong><br>
                                        <span><?= e($resolvedCode !== '' ? $resolvedCode : '-'); ?></span>
                                        <?php if ($customerName !== ''): ?><br><small><?= e($customerName); ?></small><?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($row['node_name'] ?? '-')); ?></td>
                                    <td>
                                        <?php if ($profileHref !== ''): ?>
                                            <a class="portal-ghost-btn portal-inline-link" href="<?= e($profileHref); ?>">Profil</a>
                                        <?php else: ?>
                                            <span class="portal-mini-badge">Profil tapÄ±lmadÄ±</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="portal-pagination" aria-label="NAT log sehifeleri">
                        <?php
                        $prevParams = $queryBase + ['page' => max(1, $page - 1), 'per_page' => $perPage];
                        $nextParams = $queryBase + ['page' => min($totalPages, $page + 1), 'per_page' => $perPage];
                        ?>
                        <a class="portal-ghost-btn portal-inline-link<?= $page <= 1 ? ' is-disabled' : ''; ?>" href="radius-natlog.php?<?= e(http_build_query($prevParams)); ?>">Evvelki</a>
                        <strong><?= e((string) $page); ?> / <?= e((string) $totalPages); ?></strong>
                        <a class="portal-ghost-btn portal-inline-link<?= $page >= $totalPages ? ' is-disabled' : ''; ?>" href="radius-natlog.php?<?= e(http_build_query($nextParams)); ?>">Novbeti</a>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
