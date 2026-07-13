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
        $isClosed = in_array((string) ($request['status'] ?? ''), ['Baglandi', 'BaglandÄ±', 'BaÄŸlandÄ±', 'Closed'], true);
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
    <title>MÃ¼raciÉ™tlÉ™r</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('MÃ¼raciÉ™tlÉ™r', 'requests', $lang); ?>

    <main class="portal-layout">
        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">CRM / billing mÃ¼raciÉ™tlÉ™ri</span>
                <h1>MÃ¼ÅŸtÉ™ri mÃ¼raciÉ™tlÉ™rinin idarÉ™ olunmasÄ±</h1>
                <p>Referans billingdÉ™ki mÃ¼raciÉ™t siyahÄ±sÄ± ayrÄ±ca saxlanÄ±rdÄ±. Bu sÉ™hifÉ™ bizim CRM ticketlÉ™rini mÃ¼ÅŸtÉ™ri kodu, É™razi, status vÉ™ mÃ¶vzu Ã¼zrÉ™ gÃ¶stÉ™rir.</p>
            </div>
            <div class="portal-status-card">
                <span>AÃ§Ä±q mÃ¼raciÉ™t</span>
                <strong><?= e((string) $summary['open']); ?></strong>
                <small><?= e((string) $summary['closed']); ?> baÄŸlÄ±</small>
            </div>
        </section>

        <section class="portal-grid portal-grid-4 portal-reveal">
            <article class="portal-metric-card"><span>CÉ™mi</span><strong><?= e((string) $summary['total']); ?></strong><small>bÃ¼tÃ¼n ticketlÉ™r</small></article>
            <article class="portal-metric-card"><span>AÃ§Ä±q</span><strong><?= e((string) $summary['open']); ?></strong><small>operator/texnik iÅŸi</small></article>
            <article class="portal-metric-card"><span>BaÄŸlÄ±</span><strong><?= e((string) $summary['closed']); ?></strong><small>tamamlanmÄ±ÅŸ</small></article>
            <article class="portal-metric-card"><span>Filtr nÉ™ticÉ™si</span><strong><?= e((string) $summary['filtered']); ?></strong><small>gÃ¶stÉ™rilÉ™n</small></article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head"><h2>MÃ¼raciÉ™t Ã¼zrÉ™ filtrasiya</h2><small>Kod, ad, telefon, mÃ¶vzu, É™razi vÉ™ status Ã¼zrÉ™ axtar.</small></div>
            <div class="mgs-quick-filters" style="margin-bottom:14px;">
                <a class="mgs-chip<?= $status === '' ? ' is-active' : ''; ?>" href="<?= e($baseUrl); ?>">HamÄ±sÄ±</a>
                <a class="mgs-chip<?= $status === 'Aciqdir' ? ' is-active' : ''; ?>" href="<?= e($baseUrl . $statusJoiner . 'status=Aciqdir'); ?>">AÃ§Ä±q</a>
                <a class="mgs-chip<?= $status === 'Baglandi' ? ' is-active' : ''; ?>" href="<?= e($baseUrl . $statusJoiner . 'status=Baglandi'); ?>">BaÄŸlÄ±</a>
            </div>
            <form method="get" class="portal-stacked-form">
                <?php if ($lang !== 'az'): ?><input type="hidden" name="lang" value="<?= e($lang); ?>"><?php endif; ?>
                <div class="billing-filter-grid">
                    <div class="portal-form-row"><label>AxtarÄ±ÅŸ</label><input type="text" name="q" value="<?= e($query); ?>" placeholder="kod, ad, telefon, mÃ¶vzu"></div>
                    <div class="portal-form-row">
                        <label>Ærazi</label>
                        <select name="area">
                            <option value="">BÃ¼tÃ¼n É™razilÉ™r</option>
                            <?php foreach ($areas as $areaName): ?>
                                <option value="<?= e($areaName); ?>" <?= $area === $areaName ? 'selected' : ''; ?>><?= e($areaName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Status</label>
                        <select name="status">
                            <option value="">BÃ¼tÃ¼n statuslar</option>
                            <option value="Aciqdir" <?= $status === 'Aciqdir' ? 'selected' : ''; ?>>AÃ§Ä±qdÄ±r</option>
                            <option value="Baglandi" <?= $status === 'Baglandi' ? 'selected' : ''; ?>>BaÄŸlandÄ±</option>
                        </select>
                    </div>
                    <div class="billing-filter-actions">
                        <button type="submit" class="portal-primary-btn">Axtar</button>
                        <a class="portal-ghost-btn portal-inline-link" href="billing-requests.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">SÄ±fÄ±rla</a>
                    </div>
                </div>
            </form>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head"><h2>MÃ¼raciÉ™t siyahÄ±sÄ±</h2><a class="portal-ghost-btn portal-inline-link" href="crm.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Tam CRM paneli</a></div>
            <?php if ($requests === []): ?>
                <?php billing_render_empty_state('MÃ¼raciÉ™t tapÄ±lmadÄ±', 'Filtri dÉ™yiÅŸin vÉ™ ya CRM panelindÉ™ yeni ticket yaradÄ±n.', 'fa-ticket'); ?>
            <?php else: ?>
                <div class="mgs-insight-grid">
                    <?php foreach (array_slice($requests, 0, 6) as $request): ?>
                        <article class="mgs-insight-card">
                            <span><?= e((string) ($request['ticket_no'] ?? $request['id'] ?? '-')); ?> / <?= e(billing_datetime($request['opened_at'] ?? $request['updated_at'] ?? null)); ?></span>
                            <strong><?= e((string) ($request['subject'] ?? 'MÃ¼raciÉ™t')); ?></strong>
                            <small><?= e((string) ($request['subscriber_code'] ?? '-')); ?> - <?= e((string) ($request['customer_name'] ?? '-')); ?></small>
                            <div><span class="portal-pill <?= e(billing_status_class((string) ($request['status'] ?? ''))); ?>"><?= e((string) ($request['status'] ?? '-')); ?></span></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Ticket</th><th>MÃ¼ÅŸtÉ™ri</th><th>Ærazi</th><th>Problem</th><th>MÉ™lumat</th><th>Status</th><th>Profil</th></tr></thead>
                    <tbody>
                        <?php if ($requests === []): ?><tr><td colspan="7">MÃ¼raciÉ™t tapÄ±lmadÄ±.</td></tr><?php endif; ?>
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
                                        <a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?id=<?= e((string) $request['customer_account_id']); ?>#profile-tickets">AÃ§</a>
                                    <?php elseif ((string) ($request['subscriber_code'] ?? '') !== ''): ?>
                                        <a class="portal-ghost-btn portal-inline-link" href="billing-customer-profile.php?code=<?= rawurlencode((string) $request['subscriber_code']); ?>#profile-tickets">AÃ§</a>
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
