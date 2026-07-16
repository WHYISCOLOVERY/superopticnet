<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

app_session_boot();
$lang = app_lang();
$user = app_portal_current_user();

if ($user === null || ($user['role'] ?? '') !== 'employee') {
    header('Location: portal-login.php?status=auth_required' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
    exit;
}

$flash = app_flash_message();
$status = (string) ($_GET['status'] ?? '');
$statusMap = [
    'operator_ticket_created' => ['type' => 'success', 'message' => 'Bilet CRM panelinde yaradildi.'],
    'operator_ticket_failed' => ['type' => 'error', 'message' => 'Bilet yaradilmadi.'],
    'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'],
];
if (isset($statusMap[$status])) {
    $flash = $statusMap[$status];
}

function crm_ticket_context_query(array $ticket): string
{
    foreach (['subscriber_code', 'phone', 'customer_name', 'id'] as $field) {
        $value = trim((string) ($ticket[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function crm_ticket_append_lang(string $url, string $lang): string
{
    if ($lang === 'az') {
        return $url;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . 'lang=' . rawurlencode($lang);
}

function crm_ticket_profile_url(array $ticket, string $lang): string
{
    $customerId = (int) ($ticket['customer_id'] ?? 0);
    if ($customerId <= 0) {
        return '';
    }

    return crm_ticket_append_lang('billing-customer-profile.php?id=' . rawurlencode((string) $customerId) . '#profile-tickets', $lang);
}

function crm_ticket_operations_url(array $ticket, string $lang): string
{
    $query = crm_ticket_context_query($ticket);
    return crm_ticket_append_lang('operations-center.php?section=search&q=' . rawurlencode($query), $lang);
}

function crm_ticket_message_url(array $ticket, string $lang): string
{
    $query = crm_ticket_context_query($ticket);
    return crm_ticket_append_lang('message-center.php?q=' . rawurlencode($query), $lang);
}

$areas = app_service_areas();
$subjects = app_portal_ticket_subjects();
$db = app_db();
$recentCustomers = [];
$openCreatePanel = (string) ($_GET['create'] ?? '') === '1' || $status === 'operator_ticket_failed';
if ($openCreatePanel && $db instanceof PDO) {
    try {
        $recentCustomers = $db->query(
            "SELECT id, login, subscriber_code, contract_no, full_name, phone, area, address, category, plan, status
             FROM portal_accounts
             WHERE role = 'customer'
             ORDER BY updated_at DESC, id DESC
             LIMIT 80"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $recentCustomers = [];
    }
}
if ($openCreatePanel && $recentCustomers === []) {
    $recentCustomers = array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'login' => (string) ($row['login'] ?? ''),
            'subscriber_code' => (string) ($row['subscriber_code'] ?? ''),
            'contract_no' => (string) ($row['contract_no'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'area' => (string) ($row['area'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'plan' => (string) ($row['plan'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
        ];
    }, billing_mock_customer_rows());
}
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
$requestedPage = max(1, (int) ($_GET['page'] ?? 1));
$crmTicketFilters = [
    'q' => $_GET['q'] ?? '',
    'area' => $_GET['area'] ?? '',
    'status' => $_GET['status_filter'] ?? '',
    'subject' => $_GET['subject'] ?? '',
    'assigned_technician_name' => $_GET['technician'] ?? '',
];
$totalTickets = $openCreatePanel ? 0 : app_portal_crm_ticket_count($crmTicketFilters);
$totalPages = max(1, (int) ceil(($totalTickets > 0 ? $totalTickets : 1) / $perPage));
$page = min($requestedPage, $totalPages);
$tickets = $openCreatePanel ? [] : app_portal_crm_tickets($crmTicketFilters + [
    'limit' => $perPage,
    'offset' => ($page - 1) * $perPage,
]);
$stats = $openCreatePanel ? ['total' => 0, 'open' => 0, 'closed' => 0] : app_portal_ticket_stats();
$technicians = app_portal_mock_technicians();
$technicianNames = array_values(array_unique(array_map(
    static fn ($item): string => is_array($item) ? (string) ($item['name'] ?? '') : '',
    $technicians
)));
$technicianNames = array_values(array_filter($technicianNames));
sort($technicianNames);
$ticketColumns = [
    'Açıq' => [],
    'İşdə' => [],
    'Gecikir' => [],
    'Bağlı' => [],
];
foreach ($tickets as $ticket) {
    $ticketStatus = (string) ($ticket['status'] ?? '');
    $resolvedAt = trim((string) ($ticket['resolved_at'] ?? ''));
    if ($ticketStatus === 'Baglandi' || $resolvedAt !== '') {
        $ticketColumns['Bağlı'][] = $ticket;
    } elseif (trim((string) ($ticket['assigned_technician_name'] ?? '')) !== '') {
        $ticketColumns['İşdə'][] = $ticket;
    } else {
        $ticketColumns['Açıq'][] = $ticket;
    }
}
$activeTechnicianCount = 0;
foreach ($technicianNames as $name) {
    foreach ($tickets as $ticket) {
        if ((string) ($ticket['assigned_technician_name'] ?? '') === $name && (string) ($ticket['status'] ?? '') !== 'Baglandi') {
            $activeTechnicianCount++;
            break;
        }
    }
}
$visibleTickets = $tickets;
$queryBase = $_GET;
unset($queryBase['page']);
$viewMode = in_array((string) ($_GET['view'] ?? 'kanban'), ['kanban', 'table'], true)
    ? (string) ($_GET['view'] ?? 'kanban')
    : 'kanban';
$kanbanParams = $_GET + ['view' => 'kanban'];
$tableParams = $_GET + ['view' => 'table'];
unset($kanbanParams['page'], $tableParams['page']);
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator CRM</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .portal-crm-table td,.portal-crm-table th{overflow-wrap:anywhere}
        .portal-crm-table td:nth-child(5) small,
        .portal-crm-table td:nth-child(6) small{
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
        }
        @media (max-width:700px){
            .portal-crm-table .mgs-ticket-actions.is-table{gap:4px;flex-wrap:wrap}
            .portal-crm-table .mgs-ticket-actions.is-table a{padding:5px 7px;font-size:.72rem}
        }
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('Operator CRM', 'tickets', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">Operator CRM</span>
                <h1>Muracietlerin vahid idareetme paneli</h1>
                <p>Online muracietler, operatorun yaratdigi biletler, eraziye gore texnik teyinatlari ve baglanma tarixcesi burada gorunur.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="crm.php?create=1<?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">Yeni bilet yarat</a>
                    <a class="portal-ghost-btn portal-inline-link<?= $viewMode === 'kanban' ? ' is-active' : ''; ?>" href="crm.php?<?= e(http_build_query($kanbanParams)); ?>">Kanban</a>
                    <a class="portal-ghost-btn portal-inline-link<?= $viewMode === 'table' ? ' is-active' : ''; ?>" href="crm.php?<?= e(http_build_query($tableParams)); ?>">Cedvel</a>
                    <a class="portal-ghost-btn portal-inline-link" href="problem-statistics.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Statistika</a>
                    <?php if (app_portal_is_admin($user)): ?>
                        <a class="portal-ghost-btn portal-inline-link" href="admin-users.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">İstifadəçi idarəsi</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Aktiv novbe</span>
                <strong><?= e((string) $stats['open']); ?> aciq bilet</strong>
                <small>Cem: <?= e((string) $stats['total']); ?> / Bagli: <?= e((string) $stats['closed']); ?></small>
                <span>Aktiv texnik</span>
                <strong><?= e((string) $activeTechnicianCount); ?></strong>
            </div>
        </section>

        <?php if (!$openCreatePanel): ?>
        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Cəmi bilet', (string) $stats['total'], 'Tarixçə daxil olmaqla', 'fa-ticket', 'blue'); ?>
            <?php billing_render_stat_card('Açıq bilet', (string) $stats['open'], 'Texnikə yönləndirilməli', 'fa-circle-exclamation', 'orange'); ?>
            <?php billing_render_stat_card('İşdə olan', (string) count($ticketColumns['İşdə']), 'Texnik üzərində', 'fa-person-digging', 'navy'); ?>
            <?php billing_render_stat_card('Bağlı bilet', (string) $stats['closed'], 'Qeyd və səbəblə bağlanıb', 'fa-circle-check', 'green'); ?>
            <?php billing_render_stat_card('Aktiv texnik', (string) $activeTechnicianCount, 'Açıq iş görən əməkdaş', 'fa-users-gear', 'blue'); ?>
            <?php billing_render_stat_card('Nəticə', (string) count($tickets), 'Cari filtr nəticəsi', 'fa-filter', 'navy'); ?>
        </section>
        <?php endif; ?>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <?php if ($openCreatePanel): ?>
            <article class="portal-card" id="crm-create">
                <div class="portal-card-head">
                    <div>
                        <h2>Yeni bilet yarat</h2>
                        <small>Operator daxil etdikde bilet avtomatik eraziye uygun texnike duser.</small>
                    </div>
                    <a class="portal-ghost-btn portal-inline-link" href="crm.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Bagla</a>
                </div>
                <form class="portal-stacked-form" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post">
                    <input type="hidden" name="action" value="create_operator_ticket">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="customer_account_id" value="" data-customer-account-id-input>
                    <div data-crm-customer-lookup>
                    <script type="application/json" data-crm-recent-customers><?= e(json_encode(array_values($recentCustomers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></script>
                    <datalist id="crmCustomerOptions">
                        <?php foreach ($recentCustomers as $customer): ?>
                            <?php $code = trim((string) ($customer['subscriber_code'] ?? '')); ?>
                            <?php if ($code === '') { continue; } ?>
                            <option value="<?= e($code); ?>"><?= e(trim(implode(' / ', array_filter([
                                (string) ($customer['full_name'] ?? ''),
                                (string) ($customer['phone'] ?? ''),
                                (string) ($customer['area'] ?? ''),
                            ])))); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                        <datalist id="crmAreaOptions">
                            <?php foreach ($areas as $area): ?>
                                <option value="<?= e($area); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="text" name="customer_name" placeholder="Musteri adi soyadi" required data-customer-name-input>
                        <input
                            type="text"
                            name="subscriber_code"
                            placeholder="Abonent kodu / telefon / ad"
                            list="crmCustomerOptions"
                            autocomplete="off"
                            data-customer-code-input
                        >
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input type="tel" name="phone" placeholder="+994501234567" required data-customer-phone-input>
                        <select data-crm-area-select>
                            <option value="">Siyahidan erazi secin</option>
                            <?php foreach ($areas as $area): ?>
                                <option value="<?= e($area); ?>"><?= e($area); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input
                            type="text"
                            name="area"
                            placeholder="Erazi secin ve ya yazin"
                            list="crmAreaOptions"
                            required
                            data-customer-area-input
                            data-crm-area-input
                        >
                    </div>
                    <div class="portal-form-row">
                        <input type="text" name="address" placeholder="Unvan" required data-customer-address-input>
                    </div>
                    <article class="portal-muted-card" data-crm-customer-preview hidden>
                        <strong data-crm-customer-preview-title>Musteri secilmeyib</strong>
                        <p data-crm-customer-preview-meta>Kod, ad ve ya telefon daxil edin. Tek netice olduqda melumatlar avtomatik dolacaq.</p>
                    </article>
                    <p class="form-help">Abonent kodunu siyahidan secdikde ad, telefon, erazi ve unvan avtomatik dolur. Istersek saheleri elle de deyise bilersiniz.</p>
                    <div class="portal-form-row">
                        <select name="subject" required>
                            <option value="">Muraciet movzusunu secin</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= e($subject); ?>"><?= e($subject); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <textarea name="message" rows="5" maxlength="160" placeholder="Qisa qeyd..." required data-ticket-message></textarea>
                        <small class="portal-char-counter" data-char-counter>0 / 160</small>
                    </div>
                    <button type="submit" class="portal-primary-btn">Bileti yarat</button>
                </form>
            </article>
            <?php endif; ?>

            <?php if (!$openCreatePanel): ?>
            <article class="mgs-filter-panel">
                <div class="portal-card-head">
                    <h2>Filtr ve axtaris</h2>
                    <small>Abonent, erazi, texnik ve status uzre axtaris edin.</small>
                </div>
                <form class="portal-stacked-form" method="get" id="crm-filter" data-auto-filter data-auto-filter-targets="crm-results">
                    <?php if ($lang !== 'az'): ?>
                        <input type="hidden" name="lang" value="<?= e($lang); ?>">
                    <?php endif; ?>
                    <div class="portal-form-row">
                        <input type="text" name="q" value="<?= e((string) ($_GET['q'] ?? '')); ?>" placeholder="Abonent, telefon, ID, movzu">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <select name="area">
                            <option value="">Butun eraziler</option>
                            <?php foreach ($areas as $area): ?>
                                <option value="<?= e($area); ?>" <?= ((string) ($_GET['area'] ?? '') === $area) ? 'selected' : ''; ?>><?= e($area); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="subject">
                            <option value="">Butun movzular</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= e($subject); ?>" <?= ((string) ($_GET['subject'] ?? '') === $subject) ? 'selected' : ''; ?>><?= e($subject); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <select name="technician">
                            <option value="">Butun texnikler</option>
                            <?php foreach ($technicianNames as $name): ?>
                                <option value="<?= e($name); ?>" <?= ((string) ($_GET['technician'] ?? '') === $name) ? 'selected' : ''; ?>><?= e($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status_filter">
                            <option value="">Butun statuslar</option>
                            <option value="Aciqdir" <?= ((string) ($_GET['status_filter'] ?? '') === 'Aciqdir') ? 'selected' : ''; ?>>Aciqdir</option>
                            <option value="Baglandi" <?= ((string) ($_GET['status_filter'] ?? '') === 'Baglandi') ? 'selected' : ''; ?>>Baglandi</option>
                        </select>
                    </div>
                    <div class="portal-inline-inputs">
                        <button type="submit" class="portal-primary-btn">Filtrle</button>
                        <a class="portal-ghost-btn portal-inline-link" href="crm.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Sifirla</a>
                    </div>
                    <small data-auto-filter-status role="status" aria-live="polite"></small>
                </form>
            </article>
            <?php endif; ?>
        </section>

        <?php if (!$openCreatePanel && $viewMode === 'kanban'): ?>
        <section class="portal-reveal" data-auto-filter-results="crm-results" data-auto-filter-form="crm-filter">
            <div class="mgs-section-head">
                <div>
                    <h2>Kanban baxış</h2>
                    <p>Operator, texnik və admin üçün status üzrə sürətli iş axını.</p>
                </div>
                <span class="mgs-status-badge"><?= e((string) count($tickets)); ?> nəticə</span>
            </div>
            <div class="mgs-kanban">
                <?php foreach ($ticketColumns as $columnTitle => $columnTickets): ?>
                    <article class="mgs-kanban-column">
                        <h2><?= e($columnTitle); ?> <span class="portal-pill"><?= e((string) count($columnTickets)); ?></span></h2>
                        <?php if ($columnTickets === []): ?>
                            <?php billing_render_empty_state('Boş sütun', 'Bu statusda müraciət yoxdur.', 'fa-inbox'); ?>
                        <?php endif; ?>
                        <?php foreach (array_slice($columnTickets, 0, 8) as $ticket): ?>
                            <?php
                                $profileUrl = crm_ticket_profile_url($ticket, $lang);
                                $operationsUrl = crm_ticket_operations_url($ticket, $lang);
                                $messageUrl = crm_ticket_message_url($ticket, $lang);
                            ?>
                            <div class="mgs-ticket-card">
                                <strong>#<?= e((string) ($ticket['id'] ?? '')); ?> · <?= e((string) ($ticket['subject'] ?? '-')); ?></strong>
                                <p><?= e((string) ($ticket['customer_name'] ?? '-')); ?> / <?= e((string) ($ticket['phone'] ?? '-')); ?></p>
                                <small><?= e((string) ($ticket['area'] ?? '-')); ?> · <?= e((string) ($ticket['assigned_technician_name'] ?? 'Təyin edilməyib')); ?></small>
                                <span class="portal-status-pill <?= ((string) ($ticket['status'] ?? '') === 'Baglandi') ? 'is-closed' : 'is-open'; ?>"><?= e((string) ($ticket['status'] ?? '-')); ?></span>
                                <div class="mgs-ticket-actions">
                                    <?php if ($profileUrl !== ''): ?><a href="<?= e($profileUrl); ?>">Müştəri 360</a><?php endif; ?>
                                    <a href="<?= e($operationsUrl); ?>">SPoG</a>
                                    <a href="<?= e($messageUrl); ?>">SMS</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!$openCreatePanel && $viewMode === 'table'): ?>
        <section class="mgs-data-card portal-reveal" data-auto-filter-results="crm-results" data-auto-filter-form="crm-filter">
            <div class="mgs-section-head">
                <div><h2>Biletlər</h2><p>Ətraflı axtarış üçün cədvəl görünüşü.</p></div>
            </div>
            <?php if ($tickets === []): ?>
                <?php billing_render_empty_state('Bilet tapılmadı', 'Seçilmiş filtrə uyğun müraciət yoxdur.', 'fa-ticket'); ?>
            <?php else: ?>
            <div class="portal-table-wrap">
                <table class="portal-table portal-crm-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Erazi / tarix</th>
                            <th>Texnik</th>
                            <th>Movzu</th>
                            <th>Musteri</th>
                            <th>Baglanma</th>
                            <th>Status</th>
                            <th>Əməliyyat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visibleTickets as $ticket): ?>
                            <?php
                                $profileUrl = crm_ticket_profile_url($ticket, $lang);
                                $operationsUrl = crm_ticket_operations_url($ticket, $lang);
                                $messageUrl = crm_ticket_message_url($ticket, $lang);
                            ?>
                            <tr>
                                <td><?= e((string) ($ticket['id'] ?? '')); ?></td>
                                <td>
                                    <strong><?= e((string) ($ticket['area'] ?? '')); ?></strong><br>
                                    <small><?= e((string) ($ticket['opened_at'] ?? '')); ?></small>
                                </td>
                                <td><?= e((string) ($ticket['assigned_technician_name'] ?? '')); ?></td>
                                <td><?= e((string) ($ticket['subject'] ?? '')); ?></td>
                                <td>
                                    <strong><?= e((string) ($ticket['customer_name'] ?? '')); ?></strong><br>
                                    <small><?= e((string) ($ticket['phone'] ?? '')); ?></small><br>
                                    <small><?= e((string) ($ticket['address'] ?? '')); ?></small>
                                </td>
                                <td>
                                    <?php if ((string) ($ticket['resolved_at'] ?? '') !== ''): ?>
                                        <strong><?= e((string) ($ticket['resolved_at'] ?? '')); ?></strong><br>
                                        <small><?= e((string) ($ticket['resolution_reason'] ?? '')); ?></small><br>
                                        <small><?= e((string) ($ticket['resolution_note'] ?? '')); ?></small>
                                    <?php else: ?>
                                        <small>Baglanmayib</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="portal-status-pill <?= ((string) ($ticket['status'] ?? '') === 'Baglandi') ? 'is-closed' : 'is-open'; ?>">
                                        <?= e((string) ($ticket['status'] ?? '')); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="mgs-ticket-actions is-table">
                                        <?php if ($profileUrl !== ''): ?><a href="<?= e($profileUrl); ?>">360</a><?php endif; ?>
                                        <a href="<?= e($operationsUrl); ?>">SPoG</a>
                                        <a href="<?= e($messageUrl); ?>">SMS</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <nav class="portal-pagination" aria-label="Bilet sehifeleri">
                <?php
                $prevParams = $queryBase + ['page' => max(1, $page - 1)];
                $nextParams = $queryBase + ['page' => min($totalPages, $page + 1)];
                ?>
                <a class="portal-ghost-btn portal-inline-link<?= $page <= 1 ? ' portal-disabled-link' : ''; ?>" href="crm.php?<?= e(http_build_query($prevParams)); ?>">Evvelki</a>
                <span><?= e((string) $page); ?> / <?= e((string) $totalPages); ?>, <?= e((string) $perPage); ?> qeyd</span>
                <a class="portal-ghost-btn portal-inline-link<?= $page >= $totalPages ? ' portal-disabled-link' : ''; ?>" href="crm.php?<?= e(http_build_query($nextParams)); ?>">Novbeti</a>
            </nav>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
