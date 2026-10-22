<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee(true);
$db = app_db();
$flash = null;

function ip_pool_label(string $type): string
{
    return match ($type) {
        'local' => 'Local/CGNAT',
        'nat_public' => 'NAT public',
        'static_public' => 'Static public',
        default => $type,
    };
}

function ip_pool_status_label(string $status): string
{
    return match ($status) {
        'free' => 'Bos',
        'used' => 'Istifadede',
        'reserved' => 'Reserved',
        'blacklist' => 'Blacklist',
        default => $status,
    };
}

function ip_pool_allowed_status(string $status, string $fallback = 'reserved'): string
{
    return in_array($status, ['free', 'used', 'reserved', 'blacklist'], true) ? $status : $fallback;
}

function ip_pool_allowed_type(string $type): string
{
    return in_array($type, ['local', 'nat_public', 'static_public'], true) ? $type : '';
}

function ip_pool_parse_ip_list(string $value): array
{
    $parts = preg_split('/[\s,;]+/', trim($value)) ?: [];
    $ips = [];
    foreach ($parts as $part) {
        $ip = trim($part);
        if ($ip !== '' && app_ip_to_num($ip) !== null) {
            $ips[$ip] = true;
        }
    }

    return array_keys($ips);
}

if ($db instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($action === 'create_pool') {
        $name = clean_text((string) ($_POST['name'] ?? ''));
        $type = clean_text((string) ($_POST['type'] ?? 'local'));
        $serviceType = clean_text((string) ($_POST['service_type'] ?? 'pppoe'));
        $startIp = clean_text((string) ($_POST['start_ip'] ?? ''));
        $endIp = clean_text((string) ($_POST['end_ip'] ?? ''));
        $startNum = app_ip_to_num($startIp);
        $endNum = app_ip_to_num($endIp);
        if ($name === '' || !in_array($type, ['local', 'nat_public', 'static_public'], true)
            || !in_array($serviceType, ['pppoe', 'ipoe'], true)
            || $startNum === null || $endNum === null || $endNum < $startNum) {
            $flash = ['type' => 'error', 'message' => 'Pool adi, tipi ve IP araligi duzgun olmalidir.'];
        } else {
            $db->beginTransaction();
            try {
                $billingPool = $db->prepare(
                    'INSERT INTO billing_ip_pools
                        (name, service_type, pool_type, range_start, range_end, gateway, netmask, status)
                     VALUES
                        (:name, :service_type, :pool_type, :range_start, :range_end, :gateway, :netmask, "active")
                     ON DUPLICATE KEY UPDATE
                        service_type=VALUES(service_type), pool_type=VALUES(pool_type),
                        range_start=VALUES(range_start), range_end=VALUES(range_end), gateway=VALUES(gateway),
                        netmask=VALUES(netmask), status="active"'
                );
                $billingPool->execute([
                    'name' => $name,
                    'service_type' => $serviceType,
                    'pool_type' => $type === 'static_public' ? 'static' : 'dynamic',
                    'range_start' => $startIp,
                    'range_end' => $endIp,
                    'gateway' => clean_text((string) ($_POST['gateway'] ?? '')),
                    'netmask' => clean_text((string) ($_POST['netmask'] ?? '')),
                ]);
                $billingPoolLookup = $db->prepare('SELECT id FROM billing_ip_pools WHERE name=:name LIMIT 1');
                $billingPoolLookup->execute(['name' => $name]);
                $billingPoolId = (int) ($billingPoolLookup->fetchColumn() ?: 0);

                $statement = $db->prepare(
                'INSERT INTO ip_pools (
                    legacy_billing_pool_id, name, type, service_type, start_ip, end_ip, start_ip_num, end_ip_num,
                    gateway, netmask, zone_name, branch_name, status, metadata_json
                 ) VALUES (
                    :legacy_billing_pool_id, :name, :type, :service_type, :start_ip, :end_ip, :start_ip_num, :end_ip_num,
                    :gateway, :netmask, :zone_name, :branch_name, "active", :metadata_json
                 )
                 ON DUPLICATE KEY UPDATE
                    legacy_billing_pool_id = VALUES(legacy_billing_pool_id),
                    type = VALUES(type),
                    service_type = VALUES(service_type),
                    start_ip = VALUES(start_ip),
                    end_ip = VALUES(end_ip),
                    start_ip_num = VALUES(start_ip_num),
                    end_ip_num = VALUES(end_ip_num),
                    gateway = VALUES(gateway),
                    netmask = VALUES(netmask),
                    zone_name = VALUES(zone_name),
                    branch_name = VALUES(branch_name),
                    status = "active",
                    metadata_json = VALUES(metadata_json)'
            );
            $statement->execute([
                'legacy_billing_pool_id' => $billingPoolId > 0 ? $billingPoolId : null,
                'name' => $name,
                'type' => $type,
                'service_type' => $serviceType,
                'start_ip' => $startIp,
                'end_ip' => $endIp,
                'start_ip_num' => $startNum,
                'end_ip_num' => $endNum,
                'gateway' => clean_text((string) ($_POST['gateway'] ?? '')),
                'netmask' => clean_text((string) ($_POST['netmask'] ?? '')),
                'zone_name' => clean_text((string) ($_POST['zone_name'] ?? '')),
                'branch_name' => clean_text((string) ($_POST['branch_name'] ?? '')),
                'metadata_json' => app_db_json_encode(['created_from' => 'ip-pools.php']),
            ]);
            $poolId = (int) ($db->lastInsertId() ?: 0);
            if ($poolId <= 0) {
                $poolLookup = $db->prepare('SELECT id FROM ip_pools WHERE name = :name LIMIT 1');
                $poolLookup->execute(['name' => $name]);
                $poolId = (int) ($poolLookup->fetchColumn() ?: 0);
            }
            $db->commit();
            $generated = $poolId > 0 ? app_ip_ensure_pool_addresses($db, $poolId) : 0;
            $flash = ['type' => 'success', 'message' => 'IP pool saxlandi. Kicik araliq ucun yaradilan IP: ' . $generated];
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $flash = ['type' => 'error', 'message' => 'IP pool saxlanmadi; RADIUS ve IP kataloqu birlikde geri qaytarildi.'];
            }
        }
    } elseif ($action === 'generate_addresses') {
        $poolId = (int) ($_POST['pool_id'] ?? 0);
        $generated = app_ip_ensure_pool_addresses($db, $poolId);
        $flash = ['type' => 'success', 'message' => 'IP address cedveli yenilendi. Yeni setir: ' . $generated];
    } elseif ($action === 'mark_ip') {
        $ip = clean_text((string) ($_POST['ip_address'] ?? ''));
        $markStatus = ip_pool_allowed_status(clean_text((string) ($_POST['mark_status'] ?? 'reserved')));
        $markType = ip_pool_allowed_type(clean_text((string) ($_POST['mark_type'] ?? '')));
        $note = clean_text((string) ($_POST['note'] ?? ''));
        $ok = app_ip_mark_address($db, $ip, $markStatus, null, '', $note, $markType);
        $flash = $ok
            ? ['type' => 'success', 'message' => $ip . ' statusu yenilendi: ' . ip_pool_status_label($markStatus)]
            : ['type' => 'error', 'message' => 'IP statusu yenilenmedi. IP pool daxilinde olmalidir.'];
    } elseif ($action === 'bulk_mark_ips') {
        $bulkIps = ip_pool_parse_ip_list((string) ($_POST['bulk_ips'] ?? ''));
        $markStatus = ip_pool_allowed_status(clean_text((string) ($_POST['mark_status'] ?? 'reserved')));
        $markType = ip_pool_allowed_type(clean_text((string) ($_POST['mark_type'] ?? '')));
        $note = clean_text((string) ($_POST['note'] ?? 'Bulk IP status update'));
        $updated = 0;
        $failed = 0;
        foreach ($bulkIps as $ip) {
            if (app_ip_mark_address($db, $ip, $markStatus, null, '', $note, $markType)) {
                $updated++;
            } else {
                $failed++;
            }
        }
        $flash = $updated > 0
            ? ['type' => 'success', 'message' => 'Toplu IP statusu yenilendi. Ugurlu: ' . $updated . ', xeta: ' . $failed]
            : ['type' => 'error', 'message' => 'Toplu IP statusu yenilenmedi. IP-ler pool daxilinde olmalidir.'];
    } elseif ($action === 'import_ip_csv') {
        $csvText = (string) ($_POST['csv_text'] ?? '');
        $tmpName = (string) ($_FILES['csv_file']['tmp_name'] ?? '');
        if ($tmpName !== '' && is_uploaded_file($tmpName)) {
            $fileText = file_get_contents($tmpName);
            if (is_string($fileText)) {
                $csvText .= "\n" . $fileText;
            }
        }
        $defaultStatus = ip_pool_allowed_status(clean_text((string) ($_POST['default_status'] ?? 'reserved')));
        $defaultType = ip_pool_allowed_type(clean_text((string) ($_POST['default_type'] ?? '')));
        $defaultNote = clean_text((string) ($_POST['default_note'] ?? 'CSV import'));
        $imported = 0;
        $failed = 0;
        $lines = preg_split('/\r\n|\r|\n/', trim($csvText)) ?: [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $columns = str_getcsv($line);
            $ip = clean_text((string) ($columns[0] ?? ''));
            if (strtolower($ip) === 'ip' || strtolower($ip) === 'ip_address') {
                continue;
            }
            $status = ip_pool_allowed_status(clean_text((string) ($columns[1] ?? $defaultStatus)), $defaultStatus);
            $type = ip_pool_allowed_type(clean_text((string) ($columns[2] ?? $defaultType)));
            if ($type === '') {
                $type = $defaultType;
            }
            $note = clean_text((string) ($columns[3] ?? $defaultNote));
            if (app_ip_mark_address($db, $ip, $status, null, '', $note, $type)) {
                $imported++;
            } else {
                $failed++;
            }
        }
        $flash = $imported > 0
            ? ['type' => 'success', 'message' => 'CSV import tamamlandi. Ugurlu: ' . $imported . ', xeta: ' . $failed]
            : ['type' => 'error', 'message' => 'CSV import ucun uygun IP tapilmadi. Format: ip,status,type,note'];
    }
}

$pools = $db instanceof PDO ? app_ip_pool_summaries($db) : [];
$available = $db instanceof PDO ? app_ip_available_addresses($db, '', 50) : [];
$assignments = [];
$history = [];
if ($db instanceof PDO) {
    $assignments = $db->query(
        'SELECT a.*, c.subscriber_code, c.full_name
         FROM customer_ip_assignments a
         INNER JOIN portal_accounts c ON c.id = a.customer_id
         WHERE a.active = 1
         ORDER BY a.assigned_at DESC
         LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $history = $db->query(
        'SELECT h.*, c.subscriber_code, c.full_name
         FROM ip_assignment_history h
         INNER JOIN portal_accounts c ON c.id = h.customer_id
         ORDER BY h.changed_at DESC
         LIMIT 30'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$totals = ['total' => 0, 'used' => 0, 'free' => 0, 'reserved' => 0, 'blacklist' => 0];
foreach ($pools as $pool) {
    $totals['total'] += (int) ($pool['total_count'] ?? 0);
    $totals['used'] += (int) ($pool['used_count'] ?? 0);
    $totals['free'] += (int) ($pool['free_count'] ?? 0);
    $totals['reserved'] += (int) ($pool['reserved_count'] ?? 0);
    $totals['blacklist'] += (int) ($pool['blacklist_count'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Pool idarəetməsi</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .ip-pool-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
        .ip-pool-card{border:1px solid rgba(15,23,42,.08);border-radius:22px;background:#fff;padding:18px;box-shadow:0 18px 44px rgba(15,23,42,.08)}
        .ip-pool-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
        .ip-pool-title h3{margin:0}
        .ip-progress{height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:12px 0}
        .ip-progress span{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#f97316);border-radius:999px}
        .ip-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .ip-kv div{border:1px solid #e2e8f0;border-radius:14px;padding:10px;background:#f8fafc}
        .ip-kv span{display:block;color:#64748b;font-size:.76rem;text-transform:uppercase;letter-spacing:.05em}
        .ip-kv strong{display:block;color:#0f172a;margin-top:3px}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('IP Pool idarəetməsi', 'ip-pools', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">RADIUS IP engine</span>
                <h1>Local, NAT public və Static IP pool idarəetməsi</h1>
                <p>Bu səhifə müştəri IP-lərini billingdən idarə etmək, boş/reserved/blacklist IP-ləri görmək və RADIUS cavablarına hazır saxlamaq üçündür.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="#create-pool">Pool yarat</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-billing.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>#pools">Legacy poollar</a>
                    <a class="portal-ghost-btn portal-inline-link" href="radius-active.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Online sessiyalar</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Cemi IP</span>
                <strong><?= e((string) $totals['total']); ?></strong>
                <small>Istifade: <?= e((string) $totals['used']); ?> / Bos: <?= e((string) $totals['free']); ?></small>
                <span>Risk</span>
                <strong><?= e((string) ($totals['reserved'] + $totals['blacklist'])); ?> qeyd</strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Pool', (string) count($pools), 'Aktiv IP araliqlari', 'fa-layer-group', 'blue'); ?>
            <?php billing_render_stat_card('Bos IP', (string) $totals['free'], 'Avtomatik allocation ucun', 'fa-circle-check', 'green'); ?>
            <?php billing_render_stat_card('Istifadede', (string) $totals['used'], 'Musteriye baglanmis IP', 'fa-user-check', 'orange'); ?>
            <?php billing_render_stat_card('Reserved', (string) $totals['reserved'], 'Avtomatik verilmir', 'fa-lock', 'navy'); ?>
            <?php billing_render_stat_card('Blacklist', (string) $totals['blacklist'], 'Verilmesi qadagandir', 'fa-ban', 'red'); ?>
            <?php billing_render_stat_card('Assignment', (string) count($assignments), 'Aktiv musteri IP baglantisi', 'fa-diagram-project', 'blue'); ?>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card" id="create-pool">
                <div class="portal-card-head"><h2>Yeni IP pool</h2><small>Local, NAT public və Static public pool yarat.</small></div>
                <form class="portal-stacked-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_pool">
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="name" placeholder="Pool adi" required>
                        <select name="type">
                            <option value="local">Local / CGNAT</option>
                            <option value="nat_public">NAT public</option>
                            <option value="static_public">Static public</option>
                        </select>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <select name="service_type">
                            <option value="pppoe">PPPoE</option>
                            <option value="ipoe">IPoE</option>
                        </select>
                        <input type="text" name="zone_name" placeholder="Zona">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="start_ip" placeholder="10.11.20.10" required>
                        <input type="text" name="end_ip" placeholder="10.11.20.254" required>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="gateway" placeholder="Gateway">
                        <input type="text" name="netmask" placeholder="255.255.255.0">
                    </div>
                    <div class="portal-form-row">
                        <input type="text" name="branch_name" placeholder="Filial / ofis">
                    </div>
                    <button class="portal-primary-btn" type="submit">Pool saxla</button>
                </form>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Manual IP statusu</h2><small>Reserved/blacklist/free statusunu operativ dəyiş.</small></div>
                <form class="portal-stacked-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="mark_ip">
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="ip_address" placeholder="94.20.46.10" required>
                        <select name="mark_status">
                            <option value="reserved">Reserved</option>
                            <option value="blacklist">Blacklist</option>
                            <option value="free">Free/release</option>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <select name="mark_type">
                            <option value="">Tipi avtomatik tap</option>
                            <option value="local">Local / CGNAT</option>
                            <option value="nat_public">NAT public</option>
                            <option value="static_public">Static public</option>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <input type="text" name="note" placeholder="Qeyd / sebeb">
                    </div>
                    <button class="portal-primary-btn" type="submit">Statusu yenile</button>
                </form>
            </article>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Toplu IP statusu</h2><small>Birden cox IP-ni reserve, blacklist ve ya free et.</small></div>
                <form class="portal-stacked-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="bulk_mark_ips">
                    <div class="portal-form-row">
                        <textarea name="bulk_ips" rows="5" placeholder="10.11.20.10&#10;10.11.20.11&#10;10.11.20.12" required></textarea>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <select name="mark_status">
                            <option value="reserved">Reserved</option>
                            <option value="blacklist">Blacklist</option>
                            <option value="free">Free/release</option>
                        </select>
                        <select name="mark_type">
                            <option value="">Tipi avtomatik tap</option>
                            <option value="local">Local / CGNAT</option>
                            <option value="nat_public">NAT public</option>
                            <option value="static_public">Static public</option>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <input type="text" name="note" placeholder="Toplu deyisiklik qeydi">
                    </div>
                    <button class="portal-primary-btn" type="submit">Toplu yenile</button>
                </form>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>CSV import</h2><small>Format: ip,status,type,note. Status: free/used/reserved/blacklist.</small></div>
                <form class="portal-stacked-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="import_ip_csv">
                    <div class="portal-form-row">
                        <textarea name="csv_text" rows="5" placeholder="ip,status,type,note&#10;94.20.46.10,reserved,static_public,VIP ucun saxla&#10;94.20.46.11,blacklist,static_public,problemli IP"></textarea>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <select name="default_status">
                            <option value="reserved">Default: Reserved</option>
                            <option value="blacklist">Default: Blacklist</option>
                            <option value="free">Default: Free</option>
                        </select>
                        <select name="default_type">
                            <option value="">Default tip: avtomatik</option>
                            <option value="local">Local / CGNAT</option>
                            <option value="nat_public">NAT public</option>
                            <option value="static_public">Static public</option>
                        </select>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="file" name="csv_file" accept=".csv,text/csv,text/plain">
                        <input type="text" name="default_note" placeholder="Default qeyd">
                    </div>
                    <button class="portal-primary-btn" type="submit">CSV import et</button>
                </form>
            </article>
        </section>

        <section class="portal-reveal">
            <div class="mgs-section-head">
                <div><h2>Pool statusu</h2><p>Utilization chart ve saylar.</p></div>
            </div>
            <div class="ip-pool-grid">
                <?php foreach ($pools as $pool): ?>
                    <?php $percent = min(100, max(0, (float) ($pool['utilization_percent'] ?? 0))); ?>
                    <article class="ip-pool-card">
                        <div class="ip-pool-title">
                            <div>
                                <h3><?= e((string) ($pool['name'] ?? '-')); ?></h3>
                                <small><?= e(ip_pool_label((string) ($pool['type'] ?? ''))); ?> / <?= e((string) ($pool['service_type'] ?? '-')); ?></small>
                            </div>
                            <span class="portal-pill"><?= e((string) ($pool['status'] ?? '-')); ?></span>
                        </div>
                        <div class="ip-progress"><span style="width: <?= e((string) $percent); ?>%"></span></div>
                        <div class="ip-kv">
                            <div><span>Araliq</span><strong><?= e((string) ($pool['start_ip'] ?? '-')); ?> - <?= e((string) ($pool['end_ip'] ?? '-')); ?></strong></div>
                            <div><span>Utilization</span><strong><?= e((string) $percent); ?>%</strong></div>
                            <div><span>Cemi</span><strong><?= e((string) ($pool['total_count'] ?? 0)); ?></strong></div>
                            <div><span>Bos</span><strong><?= e((string) ($pool['free_count'] ?? 0)); ?></strong></div>
                            <div><span>Used</span><strong><?= e((string) ($pool['used_count'] ?? 0)); ?></strong></div>
                            <div><span>Reserved/blacklist</span><strong><?= e((string) ((int) ($pool['reserved_count'] ?? 0) + (int) ($pool['blacklist_count'] ?? 0))); ?></strong></div>
                        </div>
                        <form method="post" class="portal-section-gap">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="generate_addresses">
                            <input type="hidden" name="pool_id" value="<?= e((string) ($pool['id'] ?? 0)); ?>">
                            <button type="submit" class="portal-ghost-btn">IP setirlerini yarat/yenile</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Bos IP-lər</h2><small>Dropdown-lar ucun ilk 50 bos IP.</small></div>
                <?php if ($available === []): ?>
                    <?php billing_render_empty_state('Bos IP siyahisi yoxdur', 'Pool IP setirlerini yarat ve ya araliq cox boyukdurse manual secim et.', 'fa-network-wired'); ?>
                <?php else: ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead><tr><th>IP</th><th>Pool</th><th>Tip</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($available as $ip): ?>
                                    <tr>
                                        <td><?= e((string) ($ip['ip_address'] ?? '-')); ?></td>
                                        <td><?= e((string) ($ip['pool_name'] ?? '-')); ?></td>
                                        <td><?= e(ip_pool_label((string) ($ip['pool_type'] ?? ''))); ?></td>
                                        <td><?= e(ip_pool_status_label((string) ($ip['status'] ?? 'free'))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Aktiv assignment</h2><small>Müştəriyə bağlı cari IP-lər.</small></div>
                <?php if ($assignments === []): ?>
                    <?php billing_render_empty_state('Assignment yoxdur', 'RADIUS sync veya profil IP deyisikliyi etdikde burada gorunecek.', 'fa-plug-circle-xmark'); ?>
                <?php else: ?>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead><tr><th>Musteri</th><th>Username</th><th>Local</th><th>NAT</th><th>Static</th></tr></thead>
                            <tbody>
                                <?php foreach ($assignments as $row): ?>
                                    <tr>
                                        <td><?= e((string) ($row['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($row['full_name'] ?? '')); ?></small></td>
                                        <td><?= e((string) ($row['service_username'] ?? '-')); ?></td>
                                        <td><?= e((string) ($row['local_ip'] ?? '-')); ?></td>
                                        <td><?= e((string) ($row['nat_public_ip'] ?? '-')); ?></td>
                                        <td><?= e((string) ($row['static_ip'] ?? '-')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </article>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
