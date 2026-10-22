<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/router-registry-lib.php';

$lang = app_lang();
$user = billing_require_employee(true);
$db = app_db();
$flash = null;
$registryReady = $db instanceof PDO && router_registry_ensure_schema($db);

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

function radius_router_registry_error(string $error, int $activeBindingCount = 0): string
{
    return match ($error) {
        'router_name_required' => 'Router adi mutleqdir.',
        'management_ip_required', 'management_ip_invalid' => 'Duzgun management IP yazin.',
        'subnet_mask_required', 'subnet_mask_invalid' => 'Duzgun subnet mask ve ya CIDR prefix yazin.',
        'platform_required' => 'Platform mutleqdir.',
        'nas_identity_required' => 'NAS identity mutleqdir.',
        'router_conflict' => 'Management IP ve ya NAS identity artiq qeydiyyatdadir.',
        'router_not_found' => 'Router tapilmadi.',
        'router_has_active_customer_bindings' => 'Router deaktiv edilmedi. Evvelce bu routere bagli ' . $activeBindingCount . ' aktiv musterini basqa aktiv routere kecirin ve yeniden cehd edin.',
        default => str_ends_with($error, '_env_invalid')
            ? 'Credential reference ROUTER_<ROUTER_ADI>_RADIUS_SECRET, ROUTER_<ROUTER_ADI>_SSH_USERNAME, ROUTER_<ROUTER_ADI>_SSH_PASSWORD ve ya ROUTER_<ROUTER_ADI>_SNMP_COMMUNITY formatinda olmalidir.'
            : 'Router qeydini saxlamaq alinmadi.',
    };
}

if ($db instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($action === 'save_router' && $registryReady) {
        $result = router_registry_save($db, $_POST, (int) ($_POST['id'] ?? 0), $user);
        $flash = !empty($result['ok'])
            ? ['type' => 'success', 'message' => 'Router registry qeydi saxlandi.']
            : ['type' => 'error', 'message' => radius_router_registry_error(
                (string) ($result['error'] ?? ''),
                (int) ($result['active_binding_count'] ?? 0)
            )];
    } elseif ($action === 'set_router_active' && $registryReady) {
        $result = router_registry_set_active(
            $db,
            (int) ($_POST['id'] ?? 0),
            (int) ($_POST['is_active'] ?? 0) === 1,
            $user
        );
        $flash = !empty($result['ok'])
            ? ['type' => 'success', 'message' => 'Router statusu yenilendi.']
            : ['type' => 'error', 'message' => radius_router_registry_error(
                (string) ($result['error'] ?? ''),
                (int) ($result['active_binding_count'] ?? 0)
            )];
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
$routerRows = [];
$editingRouter = null;

if ($db instanceof PDO) {
    if ($registryReady) {
        $routerRows = router_registry_list($db, false, $user);
        $editingRouter = router_registry_find($db, (int) ($_GET['edit_router'] ?? 0), $user);
    } elseif ($flash === null) {
        $flash = ['type' => 'error', 'message' => 'Router registry cedvelleri hazir deyil.'];
    }
    $nasStats['nas_total'] = (int) $db->query('SELECT COUNT(*) FROM nas')->fetchColumn();
    $nasStats['active_sessions'] = (int) $db->query('SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)')->fetchColumn();
    $nasStats['sessions_24h'] = (int) $db->query('SELECT COUNT(*) FROM radacct WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn();
    $nasStats['traffic_24h'] = (float) $db->query('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0)+COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn();

    $nasRows = $db->query(
        'SELECT n.id, n.nasname, n.shortname, n.type, n.ports, n.server, n.description,
                COALESCE(active_sessions.total, 0) AS active_sessions,
                COALESCE(day_sessions.total, 0) AS sessions_24h,
                COALESCE(day_sessions.traffic_bytes, 0) AS traffic_24h,
                nr.reloadtime
         FROM nas n
         LEFT JOIN (
             SELECT nasipaddress, COUNT(DISTINCT username) AS total
             FROM radacct
             WHERE acctstoptime IS NULL
               AND COALESCE(acctupdatetime, acctstarttime) >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)
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
$activeRouterCount = count(array_filter(
    $routerRows,
    static fn (array $router): bool => (int) ($router['is_active'] ?? 0) === 1
));
$routerForm = is_array($editingRouter) ? $editingRouter : [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && clean_text((string) ($_POST['action'] ?? '')) === 'save_router'
    && is_array($flash)
    && (string) ($flash['type'] ?? '') === 'error') {
    $routerForm = $_POST;
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
                <span class="portal-kicker">Multi-router registry</span>
                <h1>Router / BNG / NAS cihazlarının idarəetməsi</h1>
                <p>Router metadata ve musteri binding registry-de saxlanir. FreeRADIUS NAS ve accounting axini deyismeden qalir.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="radius-active.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Online sessiyalar</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-billing.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Tarif və poollar</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-natlog.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">NAT log</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Aktiv router</span>
                <strong><?= e((string) $activeRouterCount); ?></strong>
                <small>Registry cemi: <?= e((string) count($routerRows)); ?> / NAS: <?= e((string) $nasStats['nas_total']); ?></small>
                <span>24 saat trafik</span>
                <strong><?= e(billing_bytes($nasStats['traffic_24h'])); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Router', (string) count($routerRows), $activeRouterCount . ' aktiv registry qeydi', 'fa-server', 'blue'); ?>
            <?php billing_render_stat_card('Son 20 dəq online', (string) $nasStats['active_sessions'], 'RADIUS accounting yeniləməsi', 'fa-signal', 'green'); ?>
            <?php billing_render_stat_card('24h sessiya', (string) $nasStats['sessions_24h'], 'son accounting', 'fa-clock-rotate-left', 'orange'); ?>
            <?php billing_render_stat_card('24h trafik', billing_bytes($nasStats['traffic_24h']), 'download + upload', 'fa-chart-line', 'navy'); ?>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2><?= $editingRouter !== null ? 'Router yenile' : 'Router elave et'; ?></h2><small>Metadata ve environment credential reference-lari.</small></div>
                <form class="portal-stacked-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save_router">
                    <input type="hidden" name="id" value="<?= e((string) ((int) ($routerForm['id'] ?? 0))); ?>">
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="router_name" value="<?= e((string) ($routerForm['router_name'] ?? '')); ?>" placeholder="Router adi" aria-label="Router adi" required>
                        <input type="text" name="management_ip" value="<?= e((string) ($routerForm['management_ip'] ?? '')); ?>" placeholder="Management IP" aria-label="Management IP" required>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="subnet_mask" value="<?= e((string) ($routerForm['subnet_mask'] ?? '')); ?>" placeholder="255.255.255.0 ve ya /24" aria-label="Subnet mask" required>
                        <?php $selectedPlatform = strtolower((string) ($routerForm['platform'] ?? 'mikrotik')); ?>
                        <select name="platform" aria-label="Platform" required>
                            <?php foreach (['mikrotik' => 'MikroTik', 'cisco' => 'Cisco', 'juniper' => 'Juniper', 'linux' => 'Linux BNG', 'other' => 'Other'] as $value => $label): ?>
                                <option value="<?= e($value); ?>" <?= $selectedPlatform === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="os_version" value="<?= e((string) ($routerForm['os_version'] ?? '')); ?>" placeholder="OS versiyasi" aria-label="OS versiyasi">
                        <input type="text" name="nas_identity" value="<?= e((string) ($routerForm['nas_identity'] ?? '')); ?>" placeholder="NAS identity / nasname" aria-label="NAS identity" required>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="branch_name" value="<?= e((string) ($routerForm['branch_name'] ?? '')); ?>" placeholder="Filial" aria-label="Filial">
                        <input type="text" name="radius_secret_env" value="<?= e((string) ($routerForm['radius_secret_env'] ?? 'ROUTER_PRIMARY_RADIUS_SECRET')); ?>" placeholder="RADIUS secret ENV adi" aria-label="RADIUS secret environment reference">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="ssh_username_env" value="<?= e((string) ($routerForm['ssh_username_env'] ?? '')); ?>" placeholder="SSH username ENV adi" aria-label="SSH username environment reference">
                        <input type="text" name="ssh_password_env" value="<?= e((string) ($routerForm['ssh_password_env'] ?? 'ROUTER_PRIMARY_SSH_PASSWORD')); ?>" placeholder="SSH password ENV adi" aria-label="SSH password environment reference">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="snmp_community_env" value="<?= e((string) ($routerForm['snmp_community_env'] ?? 'ROUTER_PRIMARY_SNMP_COMMUNITY')); ?>" placeholder="SNMP credential ENV adi" aria-label="SNMP credential environment reference">
                        <label><input type="checkbox" name="is_active" value="1" <?= $routerForm === [] || !empty($routerForm['is_active']) ? 'checked' : ''; ?>> Aktiv</label>
                    </div>
                    <div class="radius-nas-actions">
                        <button class="portal-primary-btn" type="submit">Router saxla</button>
                        <?php if ($editingRouter !== null): ?><a class="portal-ghost-btn portal-inline-link" href="radius-nas.php">Legv et</a><?php endif; ?>
                    </div>
                </form>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Credential siyaseti</h2><small>Registry secret value qebul etmir.</small></div>
                <div class="message-worker-list">
                    <div class="message-worker-row"><strong>RADIUS</strong><span class="portal-pill">ENV reference</span></div>
                    <div class="message-worker-row"><strong>SSH</strong><span class="portal-pill">ENV reference</span></div>
                    <div class="message-worker-row"><strong>SNMP</strong><span class="portal-pill">ENV reference</span></div>
                    <div class="message-worker-row"><strong>FreeRADIUS SQL</strong><span class="portal-pill">Deyisdirilmir</span></div>
                </div>
                <div class="portal-section-gap">
                    <code class="message-worker-code">ROUTER_PRIMARY_RADIUS_SECRET</code>
                    <code class="message-worker-code">ROUTER_PRIMARY_SSH_PASSWORD</code>
                    <code class="message-worker-code">ROUTER_PRIMARY_SNMP_COMMUNITY</code>
                </div>
            </article>
        </section>

        <section class="mgs-data-card portal-reveal">
            <div class="mgs-section-head"><div><h2>Router registry</h2><p><?= e((string) count($routerRows)); ?> metadata qeydi.</p></div></div>
            <?php if ($routerRows === []): ?>
                <?php billing_render_empty_state('Router yoxdur', 'Ilk router qeydini elave edin.', 'fa-server'); ?>
            <?php else: ?>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Router / management</th><th>Platform</th><th>NAS / filial</th><th>Credential refs</th><th>Musteri</th><th>Status</th><th>Emeliyyat</th></tr></thead>
                        <tbody>
                            <?php foreach ($routerRows as $router): ?>
                                <tr>
                                    <td><strong><?= e((string) ($router['router_name'] ?? '-')); ?></strong><br><small><?= e((string) ($router['management_ip'] ?? '-')); ?> <?= e((string) ($router['subnet_mask'] ?? '')); ?></small></td>
                                    <td><?= e(radius_nas_type_label((string) ($router['platform'] ?? 'other'))); ?><br><small><?= e((string) (($router['os_version'] ?? '') ?: '-')); ?></small></td>
                                    <td><?= e((string) ($router['nas_identity'] ?? '-')); ?><br><small><?= e((string) (($router['branch_name'] ?? '') ?: '-')); ?></small></td>
                                    <td><div class="router-registry-refs">
                                        <?php foreach (router_registry_credential_reference_fields() as $referenceField): ?>
                                            <?php if (trim((string) ($router[$referenceField] ?? '')) !== ''): ?><div><code><?= e((string) $router[$referenceField]); ?></code></div><?php endif; ?>
                                        <?php endforeach; ?>
                                    </div></td>
                                    <td><?= e((string) ($router['customer_count'] ?? 0)); ?></td>
                                    <td><span class="portal-pill <?= (int) ($router['is_active'] ?? 0) === 1 ? 'portal-pill-success' : ''; ?>"><?= (int) ($router['is_active'] ?? 0) === 1 ? 'Aktiv' : 'Passiv'; ?></span></td>
                                    <td><div class="radius-nas-actions">
                                        <a class="portal-ghost-btn portal-inline-link" href="radius-nas.php?edit_router=<?= e((string) ((int) ($router['id'] ?? 0))); ?>">Redakte</a>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="set_router_active">
                                            <input type="hidden" name="id" value="<?= e((string) ((int) ($router['id'] ?? 0))); ?>">
                                            <input type="hidden" name="is_active" value="<?= (int) ($router['is_active'] ?? 0) === 1 ? '0' : '1'; ?>">
                                            <button class="portal-ghost-btn" type="submit"><?= (int) ($router['is_active'] ?? 0) === 1 ? 'Passiv et' : 'Aktiv et'; ?></button>
                                        </form>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="mgs-data-card portal-reveal">
            <div class="mgs-section-head"><div><h2>FreeRADIUS NAS siyahısı</h2><p><?= e((string) count($nasRows)); ?> movcud NAS, read-only.</p></div></div>
            <?php if ($nasRows === []): ?>
                <?php billing_render_empty_state('NAS yoxdur', 'Core router və ya BNG əlavə etdikdə burada görünəcək.', 'fa-server'); ?>
            <?php else: ?>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>NAS</th><th>Tip</th><th>24h</th><th>Reload</th><th>Qeyd</th><th>Emeliyyat</th></tr></thead>
                        <tbody>
                            <?php foreach ($nasRows as $nas): ?>
                                <tr>
                                    <td><strong><?= e((string) ($nas['nasname'] ?? '-')); ?></strong><br><small><?= e((string) ($nas['shortname'] ?? '-')); ?></small></td>
                                    <td><?= e(radius_nas_type_label((string) ($nas['type'] ?? 'other'))); ?><br><small><?= e((string) ($nas['ports'] ?? '-')); ?> port</small></td>
                                    <td><?= e((string) ($nas['sessions_24h'] ?? 0)); ?> sessiya<br><small><?= e(billing_bytes((float) ($nas['traffic_24h'] ?? 0))); ?></small></td>
                                    <td><?= e(billing_datetime($nas['reloadtime'] ?? null) ?: '-'); ?></td>
                                    <td><?= e((string) ($nas['description'] ?? '')); ?><br><small><?= e((string) ($nas['server'] ?? '')); ?></small></td>
                                    <td>
                                        <div class="radius-nas-actions">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="mark_reload">
                                                <input type="hidden" name="nasname" value="<?= e((string) ($nas['nasname'] ?? '')); ?>">
                                                <button class="portal-ghost-btn" type="submit">Reload qeyd et</button>
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
