<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee(true);
$db = app_db();
$flash = null;

function radius_nas_type_label(string $type): string
{
    return match (strtolower(trim($type))) {
        'cisco' => 'Cisco',
        'mikrotik' => 'MikroTik',
        'juniper' => 'Juniper',
        'other' => 'Other',
        default => $type !== '' ? $type : 'other',
    };
}

if ($db instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($action === 'save_nas') {
        $id = (int) ($_POST['id'] ?? 0);
        $nasname = clean_text((string) ($_POST['nasname'] ?? ''));
        $shortname = clean_text((string) ($_POST['shortname'] ?? ''));
        $type = clean_text((string) ($_POST['type'] ?? 'cisco'));
        $ports = (int) ($_POST['ports'] ?? 0);
        $secret = (string) ($_POST['secret'] ?? '');
        $server = clean_text((string) ($_POST['server'] ?? ''));
        $community = clean_text((string) ($_POST['community'] ?? ''));
        $description = clean_text((string) ($_POST['description'] ?? ''));

        if ($nasname === '') {
            $flash = ['type' => 'error', 'message' => 'NAS IP/host adi bos ola bilmez.'];
        } elseif ($id > 0) {
            $params = [
                'nasname' => $nasname,
                'shortname' => $shortname !== '' ? $shortname : null,
                'type' => $type !== '' ? $type : 'other',
                'ports' => $ports > 0 ? $ports : null,
                'server' => $server !== '' ? $server : null,
                'community' => $community !== '' ? $community : null,
                'description' => $description !== '' ? $description : null,
                'id' => $id,
            ];
            $secretSql = '';
            if (trim($secret) !== '') {
                $secretSql = ', secret = :secret';
                $params['secret'] = trim($secret);
            }
            $statement = $db->prepare(
                'UPDATE nas
                 SET nasname = :nasname,
                     shortname = :shortname,
                     type = :type,
                     ports = :ports,
                     server = :server,
                     community = :community,
                     description = :description' . $secretSql . '
                 WHERE id = :id'
            );
            $statement->execute($params);
            $flash = ['type' => 'success', 'message' => 'NAS yenilendi.'];
        } else {
            $statement = $db->prepare(
                'INSERT INTO nas (nasname, shortname, type, ports, secret, server, community, description)
                 VALUES (:nasname, :shortname, :type, :ports, :secret, :server, :community, :description)
                 ON DUPLICATE KEY UPDATE
                    shortname = VALUES(shortname),
                    type = VALUES(type),
                    ports = VALUES(ports),
                    secret = VALUES(secret),
                    server = VALUES(server),
                    community = VALUES(community),
                    description = VALUES(description)'
            );
            $statement->execute([
                'nasname' => $nasname,
                'shortname' => $shortname !== '' ? $shortname : null,
                'type' => $type !== '' ? $type : 'other',
                'ports' => $ports > 0 ? $ports : null,
                'secret' => trim($secret) !== '' ? trim($secret) : 'secret',
                'server' => $server !== '' ? $server : null,
                'community' => $community !== '' ? $community : null,
                'description' => $description !== '' ? $description : null,
            ]);
            $flash = ['type' => 'success', 'message' => 'NAS saxlandi.'];
        }
    } elseif ($action === 'delete_nas') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $statement = $db->prepare('DELETE FROM nas WHERE id = :id');
            $statement->execute(['id' => $id]);
            $flash = ['type' => 'success', 'message' => 'NAS silindi.'];
        }
    } elseif ($action === 'mark_reload') {
        $nasname = clean_text((string) ($_POST['nasname'] ?? ''));
        if ($nasname !== '') {
            $statement = $db->prepare(
                'INSERT INTO nasreload (nasipaddress, reloadtime)
                 VALUES (:nasipaddress, NOW())
                 ON DUPLICATE KEY UPDATE reloadtime = NOW()'
            );
            $statement->execute(['nasipaddress' => $nasname]);
            $flash = ['type' => 'success', 'message' => 'NAS reload qeydi yenilendi.'];
        }
    }
}

$nasRows = [];
$nasStats = [
    'nas_total' => 0,
    'active_sessions' => 0,
    'sessions_24h' => 0,
    'traffic_24h' => 0.0,
];
$recentAccounting = [];

if ($db instanceof PDO) {
    $nasStats['nas_total'] = (int) $db->query('SELECT COUNT(*) FROM nas')->fetchColumn();
    $nasStats['active_sessions'] = (int) $db->query('SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL')->fetchColumn();
    $nasStats['sessions_24h'] = (int) $db->query('SELECT COUNT(*) FROM radacct WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn();
    $nasStats['traffic_24h'] = (float) $db->query('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn();

    $nasRows = $db->query(
        'SELECT n.*,
                COALESCE(active_sessions.total, 0) AS active_sessions,
                COALESCE(day_sessions.total, 0) AS sessions_24h,
                COALESCE(day_sessions.traffic_bytes, 0) AS traffic_24h,
                nr.reloadtime
         FROM nas n
         LEFT JOIN (
             SELECT nasipaddress, COUNT(*) AS total
             FROM radacct
             WHERE acctstoptime IS NULL
             GROUP BY nasipaddress
         ) active_sessions ON active_sessions.nasipaddress = n.nasname
         LEFT JOIN (
             SELECT nasipaddress,
                    COUNT(*) AS total,
                    SUM(COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0)) AS traffic_bytes
             FROM radacct
             WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY nasipaddress
         ) day_sessions ON day_sessions.nasipaddress = n.nasname
         LEFT JOIN nasreload nr ON nr.nasipaddress = n.nasname
         ORDER BY n.nasname'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recentAccounting = $db->query(
        'SELECT r.username, r.nasipaddress, r.framedipaddress, r.callingstationid, r.acctstarttime, r.acctstoptime,
                r.acctterminatecause, r.acctinputoctets, r.acctoutputoctets, a.subscriber_code, a.full_name
         FROM radacct r
         LEFT JOIN billing_radius_services s ON s.username = r.username
         LEFT JOIN portal_accounts a ON a.id = s.account_id
         ORDER BY COALESCE(r.acctupdatetime, r.acctstarttime) DESC, r.radacctid DESC
         LIMIT 30'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RADIUS NAS cihazları</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .radius-nas-secret{font-family:ui-monospace,Consolas,monospace;color:#64748b}
        .radius-nas-actions{display:flex;gap:8px;flex-wrap:wrap}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('RADIUS NAS cihazları', 'radius-nas', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">FreeRADIUS NAS</span>
                <h1>Router / BNG / NAS cihazlarının idarəetməsi</h1>
                <p>Bu səhifə FreeRADIUS `nas` cədvəlini idarə edir. Secret siyahıda göstərilmir; dəyişiklik yalnız admin/NOC tərəfindən yazılır.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="radius-active.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Online sessiyalar</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-billing.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Tarif və poollar</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-natlog.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">NAT log</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>NAS sayı</span>
                <strong><?= e((string) $nasStats['nas_total']); ?></strong>
                <small>Aktiv sessiya: <?= e((string) $nasStats['active_sessions']); ?></small>
                <span>24 saat trafik</span>
                <strong><?= e(billing_bytes($nasStats['traffic_24h'])); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('NAS', (string) $nasStats['nas_total'], 'FreeRADIUS nas cedveli', 'fa-server', 'blue'); ?>
            <?php billing_render_stat_card('Online sessiya', (string) $nasStats['active_sessions'], 'radacct open', 'fa-signal', 'green'); ?>
            <?php billing_render_stat_card('24h sessiya', (string) $nasStats['sessions_24h'], 'son accounting', 'fa-clock-rotate-left', 'orange'); ?>
            <?php billing_render_stat_card('24h trafik', billing_bytes($nasStats['traffic_24h']), 'download + upload', 'fa-chart-line', 'navy'); ?>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2>NAS əlavə et / yenilə</h2><small>Core router və BNG-lər burada saxlanır.</small></div>
                <form class="portal-stacked-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save_nas">
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="nasname" placeholder="85.132.57.218 və ya 172.16.5.5" required>
                        <input type="text" name="shortname" placeholder="SPTECH Core">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <select name="type">
                            <option value="cisco">Cisco</option>
                            <option value="mikrotik">MikroTik</option>
                            <option value="juniper">Juniper</option>
                            <option value="other">Other</option>
                        </select>
                        <input type="number" name="ports" min="0" placeholder="Port sayı">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="password" name="secret" placeholder="RADIUS secret">
                        <input type="text" name="server" placeholder="Virtual server / qrup">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="community" placeholder="SNMP community optional">
                        <input type="text" name="description" placeholder="Qeyd">
                    </div>
                    <button class="portal-primary-btn" type="submit">NAS saxla</button>
                </form>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Konfiqurasiya qeydi</h2><small>Routerə toxunmadan operator üçün checklist.</small></div>
                <div class="message-worker-list">
                    <div class="message-worker-row"><strong>Auth port</strong><span class="portal-pill">UDP 1812</span></div>
                    <div class="message-worker-row"><strong>Accounting port</strong><span class="portal-pill">UDP 1813</span></div>
                    <div class="message-worker-row"><strong>Core router</strong><span class="portal-pill">Client kimi əlavə olunmalıdır</span></div>
                    <div class="message-worker-row"><strong>Secret</strong><span class="portal-pill">Router və FreeRADIUS eyni olmalıdır</span></div>
                </div>
                <div class="portal-section-gap">
                    <code class="message-worker-code">client core-router { ipaddr = 85.132.57.218 secret = ***** }</code>
                    <code class="message-worker-code">sql nas table = bu səhifədəki `nas` cədvəli</code>
                </div>
            </article>
        </section>

        <section class="mgs-data-card portal-reveal">
            <div class="mgs-section-head"><div><h2>NAS siyahısı</h2><p><?= e((string) count($nasRows)); ?> cihaz göstərilir.</p></div></div>
            <?php if ($nasRows === []): ?>
                <?php billing_render_empty_state('NAS yoxdur', 'Core router və ya BNG əlavə etdikdə burada görünəcək.', 'fa-server'); ?>
            <?php else: ?>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>NAS</th><th>Tip</th><th>Secret</th><th>24h</th><th>Reload</th><th>Qeyd</th><th>Əməliyyat</th></tr></thead>
                        <tbody>
                            <?php foreach ($nasRows as $nas): ?>
                                <tr>
                                    <td><strong><?= e((string) ($nas['nasname'] ?? '-')); ?></strong><br><small><?= e((string) ($nas['shortname'] ?? '-')); ?></small></td>
                                    <td><?= e(radius_nas_type_label((string) ($nas['type'] ?? 'other'))); ?><br><small><?= e((string) ($nas['ports'] ?? '-')); ?> port</small></td>
                                    <td><span class="radius-nas-secret">••••••••</span><br><small><?= e((string) ($nas['server'] ?? '')); ?></small></td>
                                    <td><?= e((string) ($nas['sessions_24h'] ?? 0)); ?> sessiya<br><small><?= e(billing_bytes((float) ($nas['traffic_24h'] ?? 0))); ?></small></td>
                                    <td><?= e(billing_datetime($nas['reloadtime'] ?? null) ?: '-'); ?></td>
                                    <td><?= e((string) ($nas['description'] ?? '')); ?><br><small><?= e((string) ($nas['community'] ?? '')); ?></small></td>
                                    <td>
                                        <div class="radius-nas-actions">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="mark_reload">
                                                <input type="hidden" name="nasname" value="<?= e((string) ($nas['nasname'] ?? '')); ?>">
                                                <button class="portal-ghost-btn" type="submit">Reload qeyd et</button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('NAS silinsin?');">
                                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete_nas">
                                                <input type="hidden" name="id" value="<?= e((string) ($nas['id'] ?? 0)); ?>">
                                                <button class="portal-ghost-btn" type="submit">Sil</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="mgs-data-card portal-reveal">
            <div class="mgs-section-head"><div><h2>Son accounting qeydləri</h2><p>NAS-lar üzrə ən son radacct yazıları.</p></div></div>
            <?php if ($recentAccounting === []): ?>
                <?php billing_render_empty_state('Accounting qeydi yoxdur', 'FreeRADIUS accounting qoşulduqda son sessiyalar burada görünəcək.', 'fa-database'); ?>
            <?php else: ?>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Username</th><th>Müştəri</th><th>NAS / IP</th><th>MAC</th><th>Status</th><th>Trafik</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentAccounting as $row): ?>
                                <?php $online = trim((string) ($row['acctstoptime'] ?? '')) === ''; ?>
                                <tr>
                                    <td><strong><?= e((string) ($row['username'] ?? '-')); ?></strong></td>
                                    <td><?= e((string) ($row['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($row['full_name'] ?? '')); ?></small></td>
                                    <td><?= e((string) ($row['nasipaddress'] ?? '-')); ?><br><small><?= e((string) ($row['framedipaddress'] ?? '-')); ?></small></td>
                                    <td><?= e((string) ($row['callingstationid'] ?? '-')); ?></td>
                                    <td><span class="portal-pill <?= e($online ? 'portal-pill-success' : ''); ?>"><?= e($online ? 'Online' : ((string) ($row['acctterminatecause'] ?? 'Closed'))); ?></span><br><small><?= e(billing_datetime($row['acctstarttime'] ?? null)); ?></small></td>
                                    <td>↓ <?= e(billing_bytes((float) ($row['acctoutputoctets'] ?? 0))); ?><br><small>↑ <?= e(billing_bytes((float) ($row['acctinputoctets'] ?? 0))); ?></small></td>
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
