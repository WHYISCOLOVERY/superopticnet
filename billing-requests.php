<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();
$query = clean_text((string) ($_GET['q'] ?? ''));
$area = clean_text((string) ($_GET['area'] ?? ''));
$status = clean_text((string) ($_GET['status'] ?? ''));
$requests = [];
$summary = ['total' => 0, 'open' => 0, 'closed' => 0, 'filtered' => 0];

if ($db instanceof PDO) {
    $summary['total'] = (int) $db->query('SELECT COUNT(*) FROM portal_tickets')->fetchColumn();
    $summary['closed'] = (int) $db->query("SELECT COUNT(*) FROM portal_tickets WHERE status = 'Baglandi'")->fetchColumn();
    $summary['open'] = max(0, $summary['total'] - $summary['closed']);

    $sql = 'SELECT * FROM portal_tickets WHERE 1=1';
    $params = [];
    if ($query !== '') {
        $sql .= ' AND (ticket_no LIKE :q OR subscriber_code LIKE :q OR customer_name LIKE :q OR phone LIKE :q OR subject LIKE :q OR message LIKE :q)';
        $params['q'] = '%' . $query . '%';
    }
    if ($area !== '') {
        $sql .= ' AND area = :area';
        $params['area'] = $area;
    }
    if ($status !== '') {
        $sql .= ' AND status = :status';
        $params['status'] = $status;
    }
    $sql .= ' ORDER BY opened_at DESC, id DESC LIMIT 200';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $requests = $statement->fetchAll() ?: [];
    $summary['filtered'] = count($requests);
} else {
    $allRequests = array_values(array_filter(app_portal_ticket_records(), 'is_array'));
    $summary['total'] = count($allRequests);
    foreach ($allRequests as $request) {
        $isClosed = in_array((string) ($request['status'] ?? ''), ['Baglandi', 'Baglandı', 'Bağlandı', 'Closed'], true);
        $summary[$isClosed ? 'closed' : 'open']++;
    }

    $requests = array_values(array_filter($allRequests, static function (array $request) use ($query, $area, $status): bool {
        if ($query !== '') {
            $haystack = strtolower(implode(' ', [
                (string) ($request['ticket_no'] ?? $request['id'] ?? ''),
                (string) ($request['subscriber_code'] ?? ''),
                (string) ($request['customer_name'] ?? ''),
                (string) ($request['phone'] ?? ''),
                (string) ($request['subject'] ?? ''),
                (string) ($request['message'] ?? ''),
            ]));
            if (!str_contains($haystack, strtolower($query))) {
                return false;
            }
        }
        if ($area !== '' && (string) ($request['area'] ?? '') !== $area) {
            return false;
        }
        if ($status !== '' && (string) ($request['status'] ?? '') !== $status) {
            return false;
        }

        return true;
    }));

    usort($requests, static function (array $a, array $b): int {
        return strtotime((string) ($b['opened_at'] ?? $b['updated_at'] ?? '')) <=> strtotime((string) ($a['opened_at'] ?? $a['updated_at'] ?? ''));
    });
    $requests = array_slice($requests, 0, 200);
    $summary['filtered'] = count($requests);
}

$areas = app_service_areas();
$baseUrl = 'billing-requests.php' . ($lang !== 'az' ? '?lang=' . rawurlencode($lang) : '');
$statusJoiner = $lang !== 'az' ? '&' : '?';
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müraciətlər</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('Müraciətlər', 'requests', $lang); ?>

    <main class="portal-layout">
        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">CRM / billing müraciətləri</span>
                <h1>Müştəri müraciətlərinin idarə olunması</h1>
                <p>Referans billingdəki müraciət siyahısı ayrıca saxlanırdı. Bu səhifə bizim CRM ticketlərini müştəri kodu, ərazi, status və mövzu üzrə göstərir.</p>
            </div>
            <div class="portal-status-card">
                <span>Açıq müraciət</span>
                <strong><?= e((string) $summary['open']); ?></strong>
                <small><?= e((string) $summary['closed']); ?> bağlı</small>
            </div>
        </section>

        <section class="portal-grid portal-grid-4 portal-reveal">
            <article class="portal-metric-card"><span>Cəmi</span><strong><?= e((string) $summary['total']); ?></strong><small>bütün ticketlər</small></article>
            <article class="portal-metric-card"><span>Açıq</span><strong><?= e((string) $summary['open']); ?></strong><small>operator/texnik işi</small></article>
            <article class="portal-metric-card"><span>Bağlı</span><strong><?= e((string) $summary['closed']); ?></strong><small>tamamlanmış</small></article>
            <article class="portal-metric-card"><span>Filtr nəticəsi</span><strong><?= e((string) $summary['filtered']); ?></strong><small>göstərilən</small></article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head"><h2>Müraciət üzrə filtrasiya</h2><small>Kod, ad, telefon, mövzu, ərazi və status üzrə axtar.</small></div>
            <div class="mgs-quick-filters" style="margin-bottom:14px;">
                <a class="mgs-chip<?= $status === '' ? ' is-active' : ''; ?>" href="<?= e($baseUrl); ?>">Hamısı</a>
                <a class="mgs-chip<?= $status === 'Aciqdir' ? ' is-active' : ''; ?>" href="<?= e($baseUrl . $statusJoiner . 'status=Aciqdir'); ?>">Açıq</a>
                <a class="mgs-chip<?= $status === 'Baglandi' ? ' is-active' : ''; ?>" href="<?= e($baseUrl . $statusJoiner . 'status=Baglandi'); ?>">Bağlı</a>
            </div>
            <form method="get" class="portal-stacked-form" id="billing-requests-filter" data-auto-filter data-auto-filter-targets="billing-requests-results">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row"><label>Axtarış</label><input type="text" name="q" value="<?= e($query); ?>" placeholder="kod, ad, telefon, mövzu"></div>
                    <div class="portal-form-row">
                        <label>Ərazi</label>
                        <select name="area">
                            <option value="">Bütün ərazilər</option>
                            <?php foreach ($areas as $areaName): ?>
                                <option value="<?= e($areaName); ?>" <?= $area === $areaName ? 'selected' : ''; ?>><?= e($areaName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Status</label>
                        <select name="status">
                            <option value="">Bütün statuslar</option>
                            <option value="Aciqdir" <?= $status === 'Aciqdir' ? 'selected' : ''; ?>>Açıqdır</option>
                            <option value="Baglandi" <?= $status === 'Baglandi' ? 'selected' : ''; ?>>Bağlandı</option>
                        </select>
                    </div>
                    <div class="billing-filter-actions">
                        <button type="submit" class="portal-primary-btn">Axtar</button>
                        <a class="portal-ghost-btn portal-inline-link" href="billing-requests.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Sıfırla</a>
                    </div>
                </div>
                <small data-auto-filter-status role="status" aria-live="polite"></small>
            </form>
        </section>

        <section class="portal-card portal-reveal" data-auto-filter-results="billing-requests-results" data-auto-filter-form="billing-requests-filter">
            <div class="portal-card-head"><h2>Müraciət siyahısı</h2><a class="portal-ghost-btn portal-inline-link" href="crm.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Tam CRM paneli</a></div>
            <?php if ($requests === []): ?>
                <?php billing_render_empty_state('Müraciət tapılmadı', 'Filtri dəyişin və ya CRM panelində yeni ticket yaradın.', 'fa-ticket'); ?>
            <?php else: ?>
                <div class="mgs-insight-grid">
                    <?php foreach (array_slice($requests, 0, 6) as $request): ?>
                        <article class="mgs-insight-card">
                            <span><?= e((string) ($request['ticket_no'] ?? $request['id'] ?? '-')); ?> / <?= e(billing_datetime($request['opened_at'] ?? $request['updated_at'] ?? null)); ?></span>
                            <strong><?= e((string) ($request['subject'] ?? 'Müraciət')); ?></strong>
                            <small><?= e((string) ($request['subscriber_code'] ?? '-')); ?> - <?= e((string) ($request['customer_name'] ?? '-')); ?></small>
                            <div><span class="portal-pill <?= e(billing_status_class((string) ($request['status'] ?? ''))); ?>"><?= e((string) ($request['status'] ?? '-')); ?></span></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Ticket</th><th>Müştəri</th><th>Ərazi</th><th>Problem</th><th>Məlumat</th><th>Status</th><th>Profil</th></tr></thead>
                    <tbody>
                        <?php if ($requests === []): ?><tr><td colspan="7">Müraciət tapılmadı.</td></tr><?php endif; ?>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td><?= e((string) ($request['ticket_no'] ?? $request['id'] ?? '-')); ?><br><small><?= e(billing_datetime($request['opened_at'] ?? $request['updated_at'] ?? null)); ?></small></td>
                                <td><?= e((string) ($request['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($request['customer_name'] ?? '-')); ?> / <?= e((string) ($request['phone'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($request['area'] ?? '-')); ?></td>
                                <td><?= e((string) ($request['subject'] ?? '-')); ?></td>
                                <td><?= e((string) ($request['message'] ?? '-')); ?></td>
                                <td><span class="portal-pill <?= e(billing_status_class((string) ($request['status'] ?? ''))); ?>"><?= e((string) ($request['status'] ?? '-')); ?></span></td>
                                <td>
                                    <?php if ((int) ($request['customer_account_id'] ?? 0) > 0): ?>
                                        <a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?id=<?= e((string) $request['customer_account_id']); ?>#profile-tickets">Aç</a>
                                    <?php elseif ((string) ($request['subscriber_code'] ?? '') !== ''): ?>
                                        <a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?code=<?= rawurlencode((string) $request['subscriber_code']); ?>#profile-tickets">Aç</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
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
