<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$user = billing_require_employee(true);
$db = app_db();
$lang = app_lang();
$active = 'area-code-prefixes';
$flash = null;

if (!$db instanceof PDO) {
    $flash = ['type' => 'error', 'message' => 'Database baglantisi yoxdur.'];
} else {
    app_db_seed_area_code_prefixes($db);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db instanceof PDO) {
    if (!app_csrf_validate((string) ($_POST['csrf_token'] ?? ''))) {
        $flash = ['type' => 'error', 'message' => 'CSRF token yanlisdir.'];
    } elseif (clean_text((string) ($_POST['action'] ?? '')) === 'save_branch') {
        $branchName = clean_text((string) ($_POST['branch_name'] ?? ''));
        $branchArea = clean_text((string) ($_POST['branch_area'] ?? ''));
        $branchAddress = clean_text((string) ($_POST['branch_address'] ?? ''));
        $branchNote = clean_text((string) ($_POST['branch_note'] ?? ''));
        if ($branchName === '') {
            $flash = ['type' => 'error', 'message' => 'Filial adi mutleqdir.'];
        } else {
            app_db_upsert_branch($db, $branchName, $branchArea, $branchAddress, $branchNote, 'active');
            $flash = ['type' => 'success', 'message' => $branchName . ' filial kimi saxlandi.'];
        }
    } elseif (clean_text((string) ($_POST['action'] ?? '')) === 'save_area_code_prefix') {
        $areaName = clean_text((string) ($_POST['area_name'] ?? ''));
        $codePrefix = preg_replace('/\D+/', '', (string) ($_POST['code_prefix'] ?? '')) ?? '';
        $note = clean_text((string) ($_POST['note'] ?? ''));
        if ($areaName === '' || $codePrefix === '' || strlen($codePrefix) > 6) {
            $flash = ['type' => 'error', 'message' => 'Erazi adi ve 1-6 reqemli kod prefixi mutleqdir.'];
        } else {
            app_db_upsert_area_code_prefix($db, $areaName, $codePrefix, $note);
            $flash = ['type' => 'success', 'message' => $areaName . ' ucun kod prefixi saxlandi.'];
        }
    }
}

$areas = app_service_areas();
$rows = [];
$branches = [];
if ($db instanceof PDO) {
    app_db_seed_branches($db);
    $branches = app_db_branches($db, false);
    $dbAreas = $db->query("SELECT DISTINCT area FROM portal_accounts WHERE role = 'customer' AND area IS NOT NULL AND area <> '' ORDER BY area LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $areas = array_values(array_unique(array_filter(array_merge($areas, array_map('strval', $dbAreas)))));
    sort($areas);
    $prefixRows = $db->query('SELECT area_name, area_key, code_prefix, note, updated_at FROM customer_area_code_prefixes WHERE is_active = 1 ORDER BY area_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $prefixByKey = [];
    foreach ($prefixRows as $prefixRow) {
        $key = (string) ($prefixRow['area_key'] ?? app_db_area_code_key((string) ($prefixRow['area_name'] ?? '')));
        if ($key !== '') {
            $prefixByKey[$key] = $prefixRow;
        }
    }

    $displayAreas = $areas;
    foreach ($prefixRows as $prefixRow) {
        if ((string) ($prefixRow['note'] ?? '') !== 'Default area customer code prefix') {
            $displayAreas[] = (string) ($prefixRow['area_name'] ?? '');
        }
    }
    $seenAreaKeys = [];
    foreach (array_values(array_filter(array_unique($displayAreas))) as $areaName) {
        $key = app_db_area_code_key((string) $areaName);
        if ($key === '' || isset($seenAreaKeys[$key])) {
            continue;
        }
        $seenAreaKeys[$key] = true;
        $prefixRow = $prefixByKey[$key] ?? [];
        $prefix = app_db_area_code_prefix($db, (string) $areaName);
        if ($prefix === '') {
            continue;
        }
        $rows[] = [
            'area_name' => $areaName,
            'code_prefix' => $prefix,
            'next_code' => app_db_next_customer_code_for_area($db, $areaName),
            'note' => (string) ($prefixRow['note'] ?? ''),
            'updated_at' => (string) ($prefixRow['updated_at'] ?? ''),
        ];
    }
}

$activeBranchCount = count(array_filter($branches, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'active'));
$prefixCount = count($rows);
$knownAreaCount = count($areas);
$nextCodeSample = $rows[0]['next_code'] ?? '-';
?>
<!doctype html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Filial ve erazi idareetmesi</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('Filial ve erazi idareetmesi', $active, $lang); ?>
    <main class="portal-layout area-code-page">
        <?php if (is_array($flash)): ?>
            <div class="portal-alert portal-alert-<?= e((string) $flash['type']); ?>"><?= e((string) $flash['message']); ?></div>
        <?php endif; ?>

        <section class="portal-hero billing-hero">
            <div>
                <span class="portal-kicker">Filial, erazi ve musteri kod generatoru</span>
                <h1>Filiallar ve eraziye gore musteri kod sirasi</h1>
                <p>Filiallar inventory, finance ve HR select-lerinde istifade olunur. Erzi prefixi musteri yaradilan zaman novbeti bos kodu avtomatik verir.</p>
            </div>
            <div class="portal-hero-card">
                <span>Aktiv filial</span>
                <strong><?= e((string) $activeBranchCount); ?></strong>
                <small>Kod formati: prefix + 0000. Meselen 232 => 2320000.</small>
            </div>
        </section>

        <section class="portal-grid portal-grid-4 area-code-summary">
            <article class="portal-metric-card">
                <span><i class="fa-solid fa-building"></i> Filiallar</span>
                <strong><?= e((string) $activeBranchCount); ?></strong>
                <small>Aktiv filial</small>
            </article>
            <article class="portal-metric-card">
                <span><i class="fa-solid fa-map-location-dot"></i> Eraziler</span>
                <strong><?= e((string) $knownAreaCount); ?></strong>
                <small>Select ve hesabatlarda istifade olunur</small>
            </article>
            <article class="portal-metric-card">
                <span><i class="fa-solid fa-hashtag"></i> Prefixler</span>
                <strong><?= e((string) $prefixCount); ?></strong>
                <small>Musteri kod generatoru</small>
            </article>
            <article class="portal-metric-card">
                <span><i class="fa-solid fa-forward-step"></i> Novbeti kod</span>
                <strong><?= e((string) $nextCodeSample); ?></strong>
                <small>Birinci aktiv prefix numunesi</small>
            </article>
        </section>

        <section class="portal-grid portal-grid-2 area-code-form-grid">
            <article class="portal-card area-code-form-card">
            <div class="portal-card-head">
                <h2><i class="fa-solid fa-building"></i> Filial elave / yenile</h2>
                <small>Inventory, maliyye, HR ve davamiyyet modullari bu filial siyahisindan oxuyur.</small>
            </div>
            <form method="post" class="portal-stacked-form">
                <input type="hidden" name="action" value="save_branch">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <div class="portal-form-grid">
                    <label>Filial adi
                        <input name="branch_name" placeholder="Meselen: Baki" required>
                    </label>
                    <label>Esas erazi
                        <input list="areaNames" name="branch_area" placeholder="Meselen: Kurdexani">
                    </label>
                    <label>Unvan
                        <input name="branch_address" placeholder="Opsional filial unvani">
                    </label>
                    <label>Qeyd
                        <input name="branch_note" placeholder="Opsional">
                    </label>
                </div>
                <button type="submit" class="portal-primary-btn portal-action-btn"><i class="fa-solid fa-floppy-disk"></i> Filiali saxla</button>
            </form>
            </article>

            <article class="portal-card area-code-form-card">
            <div class="portal-card-head">
                <h2><i class="fa-solid fa-hashtag"></i> Prefix elave / yenile</h2>
                <small>Yeni erazi yaradanda bu sehifede kod baslangic prefixini qeyd edin.</small>
            </div>
            <form method="post" class="portal-stacked-form">
                <input type="hidden" name="action" value="save_area_code_prefix">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <div class="portal-form-grid">
                    <label>Erazi
                        <input list="areaNames" name="area_name" placeholder="Meselen: Qaracuxur" required>
                    </label>
                    <label>Kod prefixi
                        <input name="code_prefix" inputmode="numeric" pattern="[0-9]{1,6}" placeholder="100" required>
                    </label>
                    <label>Qeyd
                        <input name="note" placeholder="Opsional">
                    </label>
                </div>
                <datalist id="areaNames">
                    <?php foreach ($areas as $area): ?>
                        <option value="<?= e($area); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <button type="submit" class="portal-primary-btn portal-action-btn"><i class="fa-solid fa-floppy-disk"></i> Prefixi saxla</button>
            </form>
            </article>
        </section>

        <section class="portal-card">
            <div class="portal-card-head">
                <h2>Filiallar</h2>
                <small>Operations Center daxilindeki filial select-leri bu cedvelden dolur.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Filial</th>
                            <th>Erazi</th>
                            <th>Status</th>
                            <th>Qeyd</th>
                            <th>Yenilenme</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): ?>
                            <tr>
                                <td><strong><?= e((string) ($branch['branchName'] ?? $branch['name'] ?? '-')); ?></strong><br><small><?= e((string) ($branch['address'] ?? '')); ?></small></td>
                                <td><?= e((string) ($branch['areaName'] ?? $branch['area'] ?? '-')); ?></td>
                                <td><span class="portal-pill"><?= e((string) ($branch['status'] ?? '-')); ?></span></td>
                                <td><?= e((string) (($branch['note'] ?? '') ?: '-')); ?></td>
                                <td><?= e((string) (($branch['updatedAt'] ?? '') ?: '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="portal-card">
            <div class="portal-card-head">
                <h2>Aktiv prefixler</h2>
                <small>Bu siyahi musteri yaradilis formasinda avtomatik kod vermek ucun istifade olunur.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Erazi</th>
                            <th>Prefix</th>
                            <th>Novbeti kod</th>
                            <th>Qeyd</th>
                            <th>Yenilenme</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><strong><?= e((string) $row['area_name']); ?></strong></td>
                                <td><span class="portal-pill"><?= e((string) $row['code_prefix']); ?></span></td>
                                <td><?= e((string) $row['next_code']); ?></td>
                                <td><?= e((string) ($row['note'] ?: '-')); ?></td>
                                <td><?= e((string) ($row['updated_at'] ?: '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
