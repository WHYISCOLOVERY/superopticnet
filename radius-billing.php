<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee(true);

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($action === 'sync_test_radius') {
        $sync = app_billing_sync_radius_accounts('1100000', '1100040');
        $flash = [
            'type' => $sync['failed'] === 0 ? 'success' : 'error',
            'message' => 'Radius test sync: ' . $sync['synced'] . ' ugurlu, ' . $sync['failed'] . ' ugursuz.',
        ];
    }
}

$db = app_db();
$overview = app_billing_overview();
$tariffs = $db instanceof PDO ? $db->query('SELECT * FROM billing_tariffs ORDER BY service_type, download_mbps')->fetchAll() : [];
$pools = $db instanceof PDO ? $db->query('SELECT * FROM billing_ip_pools ORDER BY service_type, pool_type, name')->fetchAll() : [];
$services = [];
if ($db instanceof PDO) {
    $services = $db->query(
        "SELECT s.*, a.full_name, a.subscriber_code, t.name AS tariff_name, p.name AS pool_name
         FROM billing_radius_services s
         LEFT JOIN portal_accounts a ON a.id = s.account_id
         LEFT JOIN billing_tariffs t ON t.id = s.tariff_id
         LEFT JOIN billing_ip_pools p ON p.id = s.ip_pool_id
         ORDER BY s.updated_at DESC
         LIMIT 25"
    )->fetchAll();
} else {
    $tariffs = [
        ['name' => 'Ev 150 Mbps', 'service_type' => 'pppoe', 'download_mbps' => 150, 'upload_mbps' => 150, 'monthly_fee' => 25, 'radius_group' => 'EV_150'],
        ['name' => 'Ev 200 Mbps', 'service_type' => 'pppoe', 'download_mbps' => 200, 'upload_mbps' => 200, 'monthly_fee' => 30, 'radius_group' => 'EV_200'],
        ['name' => 'IPoE test 100 Mbps', 'service_type' => 'ipoe', 'download_mbps' => 100, 'upload_mbps' => 100, 'monthly_fee' => 20, 'radius_group' => 'IPOE_100'],
    ];
    $pools = [
        ['name' => 'PPPoE local pool', 'service_type' => 'pppoe', 'pool_type' => 'dynamic', 'range_start' => '10.11.20.10', 'range_end' => '10.11.25.254', 'gateway' => '10.11.20.1'],
        ['name' => 'IPoE VLAN1100 test', 'service_type' => 'ipoe', 'pool_type' => 'dynamic', 'range_start' => '10.110.0.10', 'range_end' => '10.110.0.254', 'gateway' => '10.110.0.1'],
        ['name' => 'Static public pool', 'service_type' => 'pppoe', 'pool_type' => 'static', 'range_start' => '94.20.46.59', 'range_end' => '94.20.46.63', 'gateway' => '94.20.46.58'],
    ];
    $services = array_slice(array_map(static fn (array $row): array => [
        'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'username' => (string) ($row['username'] ?? ''),
        'service_type' => (string) ($row['service_type'] ?? 'pppoe'),
        'tariff_name' => (string) ($row['tariff_name'] ?? ''),
        'static_ip' => (string) ($row['static_ip'] ?? ''),
        'assigned_ip' => (string) ($row['assigned_ip'] ?? ''),
        'pool_name' => 'Mock SQL pool',
        'status' => (string) ($row['status'] ?? 'active'),
        'expires_at' => (string) ($row['expires_at'] ?? ''),
        'last_radius_sync_at' => date('Y-m-d H:i:s'),
    ], billing_mock_radius_rows()), 0, 25);
    $overview = [
        'tariffs' => count($tariffs),
        'pools' => count($pools),
        'radius_services' => count($services),
        'radcheck' => count($services),
        'radreply' => count($services) * 2,
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeRADIUS / Billing</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('FreeRADIUS / Billing', 'radius-config', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">Billing radius</span>
                <h1>Tarif, pool, Radius auth ve accounting hazirliq paneli</h1>
                <p>Bu modul FreeRADIUS SQL inteqrasiyasi ucun standart `radcheck`, `radreply`, `radusergroup`, `radacct` cedvellerini doldurur.</p>
            </div>
            <div class="portal-status-card">
                <span>DB driver</span>
                <strong><?= e(app_db_available() ? 'MySQL aktiv' : 'Demo rejim'); ?></strong>
                <small>FreeRADIUS VM SQL ile bu DB-ni oxuyacaq</small>
            </div>
        </section>

        <section class="portal-grid portal-grid-4 portal-reveal">
            <article class="portal-metric-card"><span>Tarif</span><strong><?= e((string) ($overview['tariffs'] ?? 0)); ?></strong><small>PPPoE/IPoE planlari</small></article>
            <article class="portal-metric-card"><span>IP pool</span><strong><?= e((string) ($overview['pools'] ?? 0)); ?></strong><small>Dynamic/static araliqlar</small></article>
            <article class="portal-metric-card"><span>Radius user</span><strong><?= e((string) ($overview['radius_services'] ?? 0)); ?></strong><small>billing_radius_services</small></article>
            <article class="portal-metric-card"><span>radcheck/radreply</span><strong><?= e((string) ($overview['radcheck'] ?? 0)); ?>/<?= e((string) ($overview['radreply'] ?? 0)); ?></strong><small>FreeRADIUS auth cavabi</small></article>
        </section>

        <section class="mgs-action-strip portal-reveal">
            <article class="mgs-action-tile"><i class="fa-solid fa-database"></i><strong>SQL auth modeli</strong><small>radcheck/radreply proqram DB-sindən oxunur.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-route"></i><strong>PPPoE + IPoE</strong><small>VLAN1100 IPoE test modeli PPPoE-yə toxunmadan hazırlanır.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-network-wired"></i><strong>Core uyğunluğu</strong><small>85.132.57.218 ASR üzərində dəyişiklik etmədən atributlar saxlanır.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-vial"></i><strong>Test sync</strong><small>1100000-1100040 userləri ilə radtest üçün hazır data.</small></article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Test Radius sync</h2>
                <small>1100000-1100040 araligindaki test PPPoE musterilerini FreeRADIUS SQL cedvellerine yazir.</small>
            </div>
            <form action="radius-billing.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post" class="portal-inline-form">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="sync_test_radius">
                <button type="submit" class="portal-primary-btn">Test userleri Radius-a sync et</button>
            </form>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal" id="tariffs">
            <article class="portal-card" id="pools">
                <div class="portal-card-head">
                    <h2>Tarifler</h2>
                    <small>Buradaki radius_group sonradan router QoS policy mappingi ile baglanacaq.</small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Ad</th><th>Nov</th><th>Suret</th><th>Qiymet</th><th>Gün</th><th>Radius group</th></tr></thead>
                        <tbody>
                            <?php foreach ($tariffs as $tariff): ?>
                                <tr>
                                    <td><?= e((string) $tariff['name']); ?></td>
                                    <td><?= e((string) $tariff['service_type']); ?></td>
                                    <td><?= e((string) $tariff['download_mbps']); ?>/<?= e((string) $tariff['upload_mbps']); ?> Mb/s</td>
                                    <td><?= e((string) $tariff['monthly_fee']); ?> AZN</td>
                                    <td><?= e((string) app_billing_tariff_default_days($tariff)); ?> gün</td>
                                    <td><code><?= e((string) $tariff['radius_group']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>IP poollar</h2>
                    <small>Dynamic ve static IP araliqlari proqramda saxlanir, Radius-a user uzre oturulur.</small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Ad</th><th>Nov</th><th>Tip</th><th>Araliq</th><th>Gateway</th></tr></thead>
                        <tbody>
                            <?php foreach ($pools as $pool): ?>
                                <tr>
                                    <td><?= e((string) $pool['name']); ?></td>
                                    <td><?= e((string) $pool['service_type']); ?></td>
                                    <td><?= e((string) $pool['pool_type']); ?></td>
                                    <td><?= e((string) $pool['range_start']); ?> - <?= e((string) $pool['range_end']); ?></td>
                                    <td><?= e((string) ($pool['gateway'] ?? '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Son Radius servisleri</h2>
                <small>Odenis bitdikde status `expired/suspended` olur ve radcheck `Auth-Type := Reject` yazir.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Abonent</th><th>Istifadeci</th><th>Nov</th><th>Tarif</th><th>IP</th><th>Status</th><th>Bitme</th><th>Sync</th></tr></thead>
                    <tbody>
                        <?php foreach ($services as $service): ?>
                            <tr>
                                <td><?= e((string) ($service['subscriber_code'] ?? '')); ?><br><small><?= e((string) ($service['full_name'] ?? '')); ?></small></td>
                                <td><?= e((string) $service['username']); ?></td>
                                <td><?= e((string) $service['service_type']); ?></td>
                                <td><?= e((string) ($service['tariff_name'] ?? '-')); ?></td>
                                <td><?= e((string) ($service['static_ip'] ?: ($service['assigned_ip'] ?? '-'))); ?><br><small><?= e((string) ($service['pool_name'] ?? '-')); ?></small></td>
                                <td><span class="portal-pill"><?= e((string) $service['status']); ?></span></td>
                                <td><?= e((string) ($service['expires_at'] ?? '-')); ?></td>
                                <td><?= e((string) ($service['last_radius_sync_at'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>VM FreeRADIUS yoxlama komandasi</h2>
                <small>`radtest` no reply verirse servis listen etmir, firewall yox, adeten FreeRADIUS config/debug xetasidir. SQL qoşulması üçün generator skripti hazırdır.</small>
            </div>
            <pre><code>sudo systemctl status freeradius --no-pager
sudo ss -lunp | grep -E '1812|1813'
sudo systemctl stop freeradius
sudo freeradius -X

# Portal terefinde:
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\render-freeradius-config.php
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\check-radius-db.php 1100000
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\seed-ipoe-test-user.php 1200000 34:4A:C3:12:00:00 10.110.0.10
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\check-radius-db.php 1200000

# SQL modul aktiv olandan sonra Ubuntu-da:
radtest 1100000 UCuvsbLA2b 127.0.0.1 0 menim_gizli_acarim123
radtest 1200000 any-test-password 127.0.0.1 0 menim_gizli_acarim123</code></pre>
        </section>
    </main>
</body>
</html>
