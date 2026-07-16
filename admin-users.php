<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/copilot-widget.php';
require_once __DIR__ . '/ui-language-lib.php';

app_session_boot();
$lang = app_lang();
$user = app_portal_current_user();

if ($user === null || ($user['role'] ?? '') !== 'employee' || !app_portal_is_admin($user)) {
    header('Location: portal.php?status=auth_required' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
    exit;
}
$isSuperAdminActor = app_portal_is_super_admin($user);
$canDeleteAccounts = $isSuperAdminActor;

$flash = app_flash_message();
$status = (string) ($_GET['status'] ?? '');
$statusMap = [
    'account_saved' => ['type' => 'success', 'message' => 'Istifadeci yadda saxlanildi.'],
    'account_deleted' => ['type' => 'success', 'message' => 'Istifadeci silindi.'],
    'account_failed' => ['type' => 'error', 'message' => 'Istifadeci saxlanmadi.'],
    'account_forbidden' => ['type' => 'error', 'message' => 'Bu rol deyisikliyine icazeniz yoxdur.'],
    'protected_account' => ['type' => 'error', 'message' => 'Qorunan super admin hesabi adi admin terefinden deyisdirile bilmez.'],
    'account_reason_required' => ['type' => 'error', 'message' => 'Rol, status ve ya silme emeliyyati ucun sebeb mutleq yazilmalidir.'],
    'import_done' => ['type' => 'success', 'message' => 'Import tamamlandi.'],
    'import_failed' => ['type' => 'error', 'message' => 'Import ugursuz oldu.'],
];
if (isset($statusMap[$status])) {
    $flash = $statusMap[$status];
}
if ($status === 'import_done' || $status === 'import_failed') {
    $created = (int) ($_GET['created'] ?? 0);
    $updated = (int) ($_GET['updated'] ?? 0);
    $errors = clean_text((string) ($_GET['errors'] ?? ''));
    $flash = [
        'type' => $status === 'import_done' ? 'success' : 'error',
        'message' => trim(
            ($status === 'import_done' ? 'Import tamamlandi.' : 'Import qismen/ugursuz oldu.') .
            ' Yeni: ' . $created . ', yenilenen: ' . $updated .
            ($errors !== '' ? ' | ' . $errors : '')
        ),
    ];
}

$accounts = app_db_accounts([
    'q' => $_GET['q'] ?? '',
    'role' => 'employee',
    'account_type' => $_GET['type_filter'] ?? '',
]);
if ($accounts === [] && !app_db_available()) {
    $mockData = app_mock_portal_data();
    $mockAccounts = [];
    $index = 0;
    foreach (($mockData['employees'] ?? []) as $login => $employee) {
        if (!is_array($employee)) {
            continue;
        }
        $role = (string) ($employee['employee_role'] ?? 'operator');
        $index++;
        $mockAccounts[] = [
            'id' => $index,
            'login' => (string) $login,
            'role' => 'employee',
            'account_type' => $role === 'noc' ? 'admin' : $role,
            'employee_role' => $role,
            'full_name' => (string) ($employee['name'] ?? $login),
            'subscriber_code' => '',
            'email' => '',
            'phone' => '',
            'area' => '',
            'address' => '',
            'category' => '',
            'plan' => '',
            'status' => 'Aktiv',
            'responsible_areas' => [],
        ];
    }
    foreach (($mockData['technicians'] ?? []) as $technician) {
        if (!is_array($technician)) {
            continue;
        }
        $index++;
        $mockAccounts[] = [
            'id' => $index,
            'login' => strtolower(str_replace(' ', '.', (string) ($technician['name'] ?? 'tech'))) . '@local',
            'role' => 'employee',
            'account_type' => 'technician',
            'employee_role' => 'technician',
            'full_name' => (string) ($technician['name'] ?? 'Texnik'),
            'subscriber_code' => '',
            'email' => '',
            'phone' => '',
            'area' => (string) (($technician['areas'][0] ?? '') ?: ''),
            'address' => '',
            'category' => '',
            'plan' => '',
            'status' => 'Aktiv',
            'responsible_areas' => array_values(array_filter((array) ($technician['areas'] ?? []))),
        ];
    }

    $q = strtolower(trim((string) ($_GET['q'] ?? '')));
    $typeFilter = trim((string) ($_GET['type_filter'] ?? ''));
    $accounts = array_values(array_filter($mockAccounts, static function (array $row) use ($q, $typeFilter): bool {
        if ((string) ($row['role'] ?? '') !== 'employee') {
            return false;
        }
        if ($typeFilter !== '' && (string) ($row['account_type'] ?? '') !== $typeFilter) {
            return false;
        }
        if ($q !== '') {
            return str_contains(strtolower(implode(' ', [
                (string) ($row['login'] ?? ''),
                (string) ($row['full_name'] ?? ''),
                (string) ($row['subscriber_code'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['phone'] ?? ''),
            ])), $q);
        }

        return true;
    }));
}
$areas = app_service_areas();
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? app_db_account_by_id($editId) : null;
if ($editing !== null && (string) ($editing['role'] ?? '') !== 'employee') {
    $editing = null;
}
$editingAreas = $editing !== null ? app_db_account_areas((int) $editing['id']) : [];
$editingPayload = $editing !== null ? app_db_account_payload($editing) : [];
$editingEmployeeMeta = is_array($editingPayload['employee'] ?? null) ? $editingPayload['employee'] : [];
$editingCompensation = $editing !== null ? app_mgs_hr_employee_compensation((int) $editing['id']) : [];
$editingEmployeeSalary = is_array($editingEmployeeMeta['compensation'] ?? null) ? $editingEmployeeMeta['compensation'] : [];
$currentEmployeeSalaryMode = (string) ($editingCompensation['salary_mode'] ?? $editingEmployeeSalary['salary_mode'] ?? 'gross');
if (!in_array($currentEmployeeSalaryMode, ['gross', 'net'], true)) {
    $currentEmployeeSalaryMode = 'gross';
}
$currentEmployeeGrossSalary = (string) ($editingCompensation['gross_salary'] ?? $editingEmployeeSalary['gross_salary'] ?? '');
$currentEmployeeNetSalary = (string) ($editingCompensation['net_salary'] ?? $editingEmployeeSalary['net_salary'] ?? '');
$currentEmployeeSalaryEffectiveFrom = (string) ($editingCompensation['effective_from'] ?? $editingEmployeeSalary['effective_from'] ?? date('Y-m-d'));

$summary = [
    'total' => count($accounts),
    'employees' => count(array_filter($accounts, static fn (array $row): bool => (string) ($row['role'] ?? '') === 'employee')),
    'operators' => count(array_filter($accounts, static fn (array $row): bool => (string) ($row['account_type'] ?? '') === 'operator')),
    'admins' => count(array_filter($accounts, static fn (array $row): bool => (string) ($row['account_type'] ?? '') === 'admin')),
    'technicians' => count(array_filter($accounts, static fn (array $row): bool => (string) ($row['account_type'] ?? '') === 'technician')),
];
$openEmployeeModal = $editing !== null || $status === 'account_failed';
$openImportModal = $status === 'import_failed';
$viewMode = in_array((string) ($_GET['view'] ?? 'table'), ['table', 'cards'], true)
    ? (string) ($_GET['view'] ?? 'table')
    : 'table';
$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalAccounts = count($accounts);
$totalPages = max(1, (int) ceil(($totalAccounts > 0 ? $totalAccounts : 1) / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$visibleAccounts = array_slice($accounts, $offset, $perPage);
$queryBase = $_GET;
unset($queryBase['page']);
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Istifadeci idareetmesi</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .admin-users-tools{display:flex;flex-direction:column;gap:16px}
        .admin-users-tools.portal-reveal,
        .admin-users-tools.portal-reveal.is-visible{transform:none!important}
        .admin-users-modal{display:none}
        .admin-users-modal[open]{
            display:flex;
            flex-direction:column;
            align-items:center;
            position:fixed;
            inset:0;
            z-index:2147483200;
            margin:0;
            padding:clamp(14px,2vw,28px);
            overflow-y:auto;
            border:0;
            border-radius:0;
            background:rgba(15,31,52,.58);
            backdrop-filter:blur(10px);
            isolation:isolate;
        }
        .admin-users-modal[open] .admin-users-modal-summary,
        .admin-users-modal[open] .admin-users-modal-inner{
            width:100%;
            max-width:1060px;
            margin:0 auto;
            box-sizing:border-box;
            background:rgba(255,255,255,.98);
        }
        .admin-users-modal[open] .admin-users-modal-summary{
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:14px;
            padding:16px 18px;
            border:1px solid rgba(17,38,64,.12);
            border-radius:22px 22px 0 0;
            list-style:none;
            cursor:default;
        }
        .admin-users-modal[open] .admin-users-modal-summary > *{min-width:0}
        .admin-users-modal[open] .admin-users-modal-summary small{overflow-wrap:anywhere}
        .admin-users-modal[open] .admin-users-modal-summary::-webkit-details-marker{display:none}
        .admin-users-modal[open] .admin-users-modal-inner{
            overflow:visible;
            padding:18px;
            border:1px solid rgba(17,38,64,.12);
            border-top:0;
            border-radius:0 0 22px 22px;
            box-shadow:0 28px 70px rgba(13,31,54,.28);
        }
        .admin-users-filter-card{max-width:none}
        .admin-users-form .portal-form-row{margin-bottom:16px}
        .admin-users-form .portal-grid-slim{grid-template-columns:repeat(2,minmax(0,1fr))}
        .admin-users-form .portal-grid-slim > *{min-width:0}
        .admin-users-form input,
        .admin-users-form select,
        .admin-users-form textarea{min-width:0;max-width:100%;box-sizing:border-box}
        .admin-area-check-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px}
        .admin-area-check{display:flex;align-items:center;gap:8px;min-width:0;min-height:38px;padding:9px 10px;border:1px solid rgba(17,38,64,.1);border-radius:12px;background:rgba(255,255,255,.72);font-weight:700;color:#16304f}
        .admin-area-check input{flex:0 0 16px!important;width:16px!important;height:16px!important;min-width:16px!important;max-width:16px!important;margin:0}
        .admin-area-check span{min-width:0;overflow-wrap:anywhere;white-space:normal}
        .admin-users-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .admin-users-inline{display:flex;gap:14px;flex-wrap:wrap;align-items:center}
        .admin-users-inline > *{flex:1 1 180px}
        .admin-users-table{min-width:0;table-layout:fixed}
        .admin-users-table td,.admin-users-table th{white-space:normal;overflow-wrap:anywhere;word-break:break-word}
        .admin-users-table th:nth-child(1),.admin-users-table td:nth-child(1){width:58px}
        .admin-users-table th:nth-child(2),.admin-users-table td:nth-child(2){width:22%}
        .admin-users-table th:nth-child(3),.admin-users-table td:nth-child(3){width:17%}
        .admin-users-table th:nth-child(4),.admin-users-table td:nth-child(4){width:22%}
        .admin-users-table th:nth-child(5),.admin-users-table td:nth-child(5){width:22%}
        .admin-users-table th:nth-child(6),.admin-users-table td:nth-child(6){width:90px}
        .admin-users-table th:nth-child(7),.admin-users-table td:nth-child(7){width:150px}
        .admin-users-table .portal-primary-btn,.admin-users-table .portal-ghost-btn{padding:9px 12px;font-size:.86rem}
        .admin-users-table form{margin:0}
        .admin-users-table .admin-users-actions{gap:6px}
        .admin-users-table small{display:block;line-height:1.35}
        .admin-users-table .admin-pill{padding:6px 9px;font-size:.78rem;line-height:1.2;max-width:100%;white-space:normal}
        .admin-users-table .admin-users-areas small{
            display:-webkit-box;
            -webkit-line-clamp:3;
            -webkit-box-orient:vertical;
            overflow:hidden;
        }
        .admin-users-table .admin-users-contact small{
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .admin-users-danger-menu{width:100%;max-width:260px}
        .admin-users-danger-menu summary{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:9px 12px;border-radius:999px;background:#eef3f8;color:#10223b;font-weight:900;cursor:pointer}
        .admin-users-delete-form{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;width:100%;margin-top:8px}
        .admin-users-delete-form input{width:100%;min-width:0;box-sizing:border-box;border:1px solid rgba(16,34,59,.12);border-radius:12px;padding:9px 10px;background:#fff;color:#10223b;font:inherit}
        .admin-users-delete-form button{white-space:nowrap}
        .admin-users-table .admin-users-danger-menu{max-width:none}
        .admin-users-table .admin-users-delete-form{grid-template-columns:1fr}
        .admin-users-view-switch{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .admin-users-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:14px}
        .admin-users-card{display:flex;flex-direction:column;gap:10px;padding:16px;border:1px solid rgba(17,38,64,.1);border-radius:22px;background:rgba(255,255,255,.8)}
        .admin-users-card-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
        .admin-users-card-meta{display:grid;gap:6px;color:#60708d}
        .admin-users-pagination{margin-top:16px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
        .admin-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;background:#eef4fb;color:#16304f;font-weight:600}
        .admin-pill.is-employee{background:#e6f8f7;color:#0a6a6b}
        .admin-pill.is-admin{background:#fff0e8;color:#c86b2a}
        .admin-pill.is-super-admin{background:#10233f;color:#fff}
        body.admin-modal-open{overflow:hidden}
        .admin-users-modal-close{border:0;cursor:pointer}
        @media (max-width: 700px){
            .admin-users-modal[open]{padding:8px 0}
            .admin-users-modal[open] .admin-users-modal-summary,
            .admin-users-modal[open] .admin-users-modal-inner{width:calc(100vw - 14px)}
            .admin-users-modal[open] .admin-users-modal-summary{position:sticky;top:8px;z-index:2;padding:12px;border-radius:18px 18px 0 0}
            .admin-users-modal[open] .admin-users-modal-summary small{display:none}
            .admin-users-modal[open] .admin-users-modal-inner{padding:12px;border-radius:0 0 18px 18px}
            .admin-users-modal .portal-card-head small{display:none}
            .admin-users-table th:nth-child(n),.admin-users-table td:nth-child(n){width:auto}
            .admin-users-table .admin-pill{font-size:.72rem;padding:5px 7px}
            .admin-users-table .portal-primary-btn,.admin-users-table .portal-ghost-btn{min-height:30px;padding:6px 8px;font-size:.76rem}
            .admin-users-actions,.admin-users-view-switch{display:grid;grid-template-columns:1fr;gap:7px;overflow:visible;flex-wrap:wrap;scrollbar-width:none}
            .admin-users-actions > *,.admin-users-view-switch > *{width:100%;max-width:100%;justify-content:center}
            .admin-users-actions::-webkit-scrollbar,.admin-users-view-switch::-webkit-scrollbar{display:none;width:0;height:0}
        }
        @media (max-width: 480px){
            .admin-users-table,.admin-users-table thead,.admin-users-table tbody,.admin-users-table tr,.admin-users-table th,.admin-users-table td{display:block;width:100%}
            .admin-users-table thead{display:none}
            .admin-users-table tr{padding:12px;border-bottom:1px solid rgba(16,35,63,.08)}
            .admin-users-table td{padding:6px 0;border-bottom:0}
            .admin-users-table td::before{content:attr(data-label);display:block;margin-bottom:3px;color:#64748b;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
        }
        @media (max-width: 720px){.admin-users-form .portal-grid-slim{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body<?= ($openEmployeeModal || $openImportModal) ? ' admin-modal-open' : ''; ?>">
    <header class="portal-topbar">
        <div class="portal-topbar-inner">
            <a href="portal.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" class="portal-brand">
                <img src="images/favicon.png" alt="Super Optik Tech">
                <span>Istifadeci idareetmesi</span>
            </a>
            <div class="portal-topbar-actions">
                <a class="portal-ghost-btn portal-inline-link" href="portal.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Portala qayit</a>
            </div>
        </div>
    </header>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>" data-autohide-alert>
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">Admin paneli</span>
                <h1>Emekdas, operator, texnik ve admin hesablarini idare et</h1>
                <p>Bu sehife yalniz daxili emekdas hesablarina aiddir. Musteri yaratma, paket, muqavile ve abonent kodu billing musteri modulunda qalir.</p>
            </div>
            <div class="portal-status-card">
                <span>Admin login</span>
                <strong><?= e((string) ($user['login'] ?? '')); ?></strong>
                <small>DB + import + sync icazesi</small>
            </div>
        </section>

        <section class="portal-grid portal-grid-4 portal-reveal">
            <article class="portal-metric-card"><span>Emekdas</span><strong><?= e((string) $summary['employees']); ?></strong><small>Operator, texnik, admin</small></article>
            <article class="portal-metric-card"><span>Operator</span><strong><?= e((string) $summary['operators']); ?></strong><small>Call center ve billing</small></article>
            <article class="portal-metric-card"><span>Texnik</span><strong><?= e((string) $summary['technicians']); ?></strong><small>Erazi mesuliyyeti ile</small></article>
            <article class="portal-metric-card"><span>Admin</span><strong><?= e((string) $summary['admins']); ?></strong><small>Tam sistem icazesi</small></article>
        </section>

        <section class="mgs-action-strip portal-reveal">
            <article class="mgs-action-tile"><i class="fa-solid fa-user-headset"></i><strong>Operator</strong><small>Musteri profili, odenis, ticket acma ve mesaj gonderme axini.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-screwdriver-wrench"></i><strong>Texnik</strong><small>Erazi, ticket baglama, inventar tehvil ve KPI hesablanmasi.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-network-wired"></i><strong>NOC / muhendis</strong><small>OLT, router, alarm, backup ve discovery icazeleri.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-user-shield"></i><strong>Admin</strong><small>Rollar, MyGov ID mapping, import ve audit nezareti.</small></article>
        </section>

        <section class="admin-users-actions portal-reveal">
            <button type="button" class="portal-primary-btn" data-open-admin-modal="employee-modal">
                <i class="fa-solid fa-user-plus"></i> Yeni emekdas
            </button>
            <button type="button" class="portal-ghost-btn portal-inline-link" data-open-admin-modal="import-modal">
                <i class="fa-solid fa-file-import"></i> Import
            </button>
            <a class="portal-ghost-btn portal-inline-link" href="operation-logs.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                <i class="fa-solid fa-clipboard-list"></i> Emeliyyat jurnali
            </a>
        </section>

        <section class="admin-users-tools portal-reveal">
            <div class="admin-users-modal" id="employee-modal" role="dialog" aria-modal="true" data-admin-employee-modal<?= $openEmployeeModal ? ' open' : ''; ?>>
                <div class="admin-users-modal-summary">
                    <span>
                        <strong><?= $editing !== null ? 'Istifadeciye duzelis et' : 'Yeni istifadeci yarat'; ?></strong>
                        <small>Yalniz emekdas hesablari: operator, technician, support ve admin.</small>
                    </span>
                    <button type="button" class="portal-ghost-btn portal-inline-link admin-users-modal-close" data-close-admin-modal>
                        <i class="fa-solid fa-xmark"></i> Bagla
                    </button>
                </div>
                <div class="admin-users-modal-inner" data-admin-modal-panel>
                <form class="admin-users-form" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post" data-admin-users-employee-mode>
                    <input type="hidden" name="action" value="save_account">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="account_id" value="<?= e((string) ($editing['id'] ?? '')); ?>">
                    <input type="hidden" name="account_scope" value="employee">
                    <input type="hidden" name="role" value="employee">

                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="accountLogin">Login</label>
                            <input id="accountLogin" type="text" name="login" value="<?= e((string) ($editing['login'] ?? '')); ?>" required>
                        </div>
                        <div>
                            <label for="accountPassword">Sifre <?= $editing !== null ? '(bos saxla = deyisme)' : ''; ?></label>
                            <input id="accountPassword" type="text" name="password" value="">
                        </div>
                    </div>

                    <div class="portal-form-row">
                        <label for="accountName">Ad soyad</label>
                        <input id="accountName" type="text" name="full_name" value="<?= e((string) ($editing['full_name'] ?? '')); ?>" required>
                    </div>

                    <?php if ((string) ($editing['role'] ?? '') === 'employee' && (string) ($editing['job_title'] ?? '') !== ''): ?>
                        <div class="portal-form-row">
                            <label for="accountJobTitle">HR vezifesi</label>
                            <input id="accountJobTitle" type="text" value="<?= e((string) ($editing['job_title'] ?? '')); ?>" readonly>
                            <small>Vezife HR melumatidir; bu sehifede yalniz portal rolu ve icaze verilir.</small>
                        </div>
                    <?php endif; ?>

                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="accountType">Tip</label>
                            <?php $currentType = (string) ($editing['account_type'] ?? 'operator'); ?>
                            <select id="accountType" name="account_type">
                                <option value="operator" <?= $currentType === 'operator' ? 'selected' : ''; ?>>Operator</option>
                                <option value="technician" <?= $currentType === 'technician' ? 'selected' : ''; ?>>Technician</option>
                                <option value="admin" <?= $currentType === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="support" <?= $currentType === 'support' ? 'selected' : ''; ?>>Support</option>
                            </select>
                        </div>
                        <div>
                            <label for="employeeRole">Employee role</label>
                            <?php $currentEmployeeRole = (string) ($editing['employee_role'] ?? ''); ?>
                            <select id="employeeRole" name="employee_role">
                                <option value="">Yoxdur</option>
                                <option value="support" <?= $currentEmployeeRole === 'support' ? 'selected' : ''; ?>><?= e(app_ui_label('support')); ?></option>
                                <option value="operator" <?= $currentEmployeeRole === 'operator' ? 'selected' : ''; ?>><?= e(app_ui_label('operator')); ?></option>
                                <option value="technician" <?= $currentEmployeeRole === 'technician' ? 'selected' : ''; ?>><?= e(app_ui_label('technician')); ?></option>
                                <option value="engineer" <?= $currentEmployeeRole === 'engineer' ? 'selected' : ''; ?>><?= e(app_ui_label('engineer')); ?></option>
                                <option value="finance" <?= $currentEmployeeRole === 'finance' ? 'selected' : ''; ?>><?= e(app_ui_label('finance')); ?></option>
                                <option value="warehouse" <?= $currentEmployeeRole === 'warehouse' ? 'selected' : ''; ?>><?= e(app_ui_label('warehouse')); ?></option>
                                <option value="warehouse_manager" <?= $currentEmployeeRole === 'warehouse_manager' ? 'selected' : ''; ?>><?= e(app_ui_label('warehouse_manager')); ?></option>
                                <option value="hr" <?= $currentEmployeeRole === 'hr' ? 'selected' : ''; ?>><?= e(app_ui_label('hr')); ?></option>
                                <option value="night_operator" <?= $currentEmployeeRole === 'night_operator' ? 'selected' : ''; ?>><?= e(app_ui_label('night_operator')); ?></option>
                                <option value="admin" <?= $currentEmployeeRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <?php if ($isSuperAdminActor): ?>
                                    <option value="super_admin" <?= $currentEmployeeRole === 'super_admin' ? 'selected' : ''; ?>>Super admin</option>
                                <?php endif; ?>
                                <option value="noc" <?= $currentEmployeeRole === 'noc' ? 'selected' : ''; ?>><?= e(app_ui_label('noc')); ?></option>
                                <option value="sales" <?= $currentEmployeeRole === 'sales' ? 'selected' : ''; ?>><?= e(app_ui_label('sales')); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="accountEmail">E-mail</label>
                            <input id="accountEmail" type="email" name="email" value="<?= e((string) ($editing['email'] ?? '')); ?>">
                        </div>
                        <div>
                            <label for="accountPhone">Telefon</label>
                            <input id="accountPhone" type="text" name="phone" value="<?= e((string) ($editing['phone'] ?? '')); ?>">
                        </div>
                    </div>

                    <div class="portal-form-row">
                        <label for="telegramChatId">Telegram chat ID</label>
                        <input id="telegramChatId" type="text" name="telegram_chat_id" value="<?= e((string) ($editingEmployeeMeta['telegram_chat_id'] ?? '')); ?>" placeholder="-1003976043672 veya ferdi chat ID">
                        <small>Superpilot bu ID ile emekdasi Telegram bot sorgularina ve tesdiq axinlarina baglayacaq.</small>
                    </div>

                    <div class="portal-form-row" data-admin-employee-compensation>
                        <label>Sicil bali ve maas bazasi</label>
                        <div class="portal-grid-slim">
                            <div>
                                <label for="employeeSalaryMode">Maas tipi</label>
                                <select id="employeeSalaryMode" name="employee_salary_mode">
                                    <option value="gross" <?= $currentEmployeeSalaryMode === 'gross' ? 'selected' : ''; ?>>Gross</option>
                                    <option value="net" <?= $currentEmployeeSalaryMode === 'net' ? 'selected' : ''; ?>>Net</option>
                                </select>
                            </div>
                            <div>
                                <label for="employeeSalaryEffectiveFrom">Baslama tarixi</label>
                                <input id="employeeSalaryEffectiveFrom" type="date" name="employee_salary_effective_from" value="<?= e($currentEmployeeSalaryEffectiveFrom); ?>">
                            </div>
                        </div>
                        <div class="portal-grid-slim" style="margin-top:10px">
                            <div>
                                <label for="employeeGrossSalary">Gross maas</label>
                                <input id="employeeGrossSalary" type="number" min="0" step="0.01" name="employee_gross_salary" value="<?= e($currentEmployeeGrossSalary); ?>">
                            </div>
                            <div>
                                <label for="employeeNetSalary">Net maas</label>
                                <input id="employeeNetSalary" type="number" min="0" step="0.01" name="employee_net_salary" value="<?= e($currentEmployeeNetSalary); ?>">
                            </div>
                        </div>
                        <small>Sicil bali HR modulunda 100 baldan hesablanir; maas bazasi vergi, gelir ve xerc hesabatlarina oturulur.</small>
                    </div>

                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="accountArea">Esas erazi</label>
                            <select id="accountArea" name="area">
                                <option value="">Secin</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?= e($area); ?>" <?= ((string) ($editing['area'] ?? '') === $area) ? 'selected' : ''; ?>><?= e($area); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="accountStatus">Status</label>
                            <?php $currentStatus = (string) ($editing['status'] ?? 'Aktiv'); ?>
                            <select id="accountStatus" name="status">
                                <option value="Aktiv" <?= $currentStatus === 'Aktiv' ? 'selected' : ''; ?>>Aktiv</option>
                                <option value="Passiv" <?= $currentStatus === 'Passiv' ? 'selected' : ''; ?>>Passiv</option>
                            </select>
                        </div>
                    </div>

                    <div class="portal-form-row">
                        <label for="accountAddress">Unvan</label>
                        <input id="accountAddress" type="text" name="address" value="<?= e((string) ($editing['address'] ?? '')); ?>">
                    </div>

                    <div class="portal-form-row">
                        <label for="privilegeChangeReason">Rol/status deyisikliyi sebebi</label>
                        <textarea id="privilegeChangeReason" name="privilege_change_reason" rows="2" placeholder="Rol, icaze ve ya aktiv status deyisirse sebeb mutleq yazilmalidir."></textarea>
                    </div>

                    <div class="portal-form-row">
                        <label>Texnik mesul erazileri</label>
                        <div class="admin-area-check-grid" id="responsibleAreas" data-responsible-area-grid>
                            <?php foreach ($areas as $area): ?>
                                <label class="admin-area-check">
                                    <input type="checkbox" name="responsible_areas[]" value="<?= e($area); ?>" <?= in_array($area, $editingAreas, true) ? 'checked' : ''; ?>>
                                    <span><?= e($area); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="portal-form-row">
                        <label class="admin-users-inline">
                            <input type="checkbox" name="is_active" value="1" <?= ((int) ($editing['is_active'] ?? 1) === 1) ? 'checked' : ''; ?>>
                            <span>Hesab aktivdir</span>
                        </label>
                    </div>

                    <div class="admin-users-actions">
                        <button type="submit" class="portal-primary-btn">Yadda saxla</button>
                        <?php if ($editing !== null): ?>
                            <a class="portal-ghost-btn portal-inline-link" href="admin-users.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Yeni qeyd</a>
                        <?php endif; ?>
                    </div>
                </form>
                </div>
            </div>

            <div class="admin-users-modal" id="import-modal" role="dialog" aria-modal="true"<?= $openImportModal ? ' open' : ''; ?>>
                <div class="admin-users-modal-summary">
                    <span>
                        <strong>Import</strong>
                        <small>CSV/XLSX faylindan emekdas hesablarini yukle.</small>
                    </span>
                    <button type="button" class="portal-ghost-btn portal-inline-link admin-users-modal-close" data-close-admin-modal>
                        <i class="fa-solid fa-xmark"></i> Bagla
                    </button>
                </div>
                <div class="admin-users-modal-inner">
                <div class="portal-card-head">
                    <h2>Import</h2>
                    <small>CSV ve ya XLSX faylindan hesablari DB-ye yaz. API import ucun ayrica endpoint de elave olunub.</small>
                </div>
                <form class="portal-stacked-form" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_accounts">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <div class="portal-form-row">
                        <label for="importFile">Fayl</label>
                        <input id="importFile" type="file" name="import_file" accept=".csv,.xlsx" required>
                    </div>
                    <div class="portal-form-row">
                        <small>Desteklenen kolonlar: login, password, role, account_type, employee_role, full_name, email, phone, area, address, status, areas, telegram_chat_id</small>
                    </div>
                    <button type="submit" class="portal-primary-btn">Import et</button>
                </form>
                </div>
            </div>

            <article class="portal-card admin-users-filter-card">
                <div class="portal-card-head">
                    <h2>Filter</h2>
                    <small>DB daxilindeki hesablari axtar.</small>
                </div>
                <form class="portal-stacked-form" method="get" id="admin-users-filter" data-auto-filter data-auto-filter-targets="admin-users-results">
                    <?php if ($lang !== 'az'): ?>
                        <input type="hidden" name="lang" value="<?= e($lang); ?>">
                    <?php endif; ?>
                    <div class="portal-form-row">
                        <input type="text" name="q" value="<?= e((string) ($_GET['q'] ?? '')); ?>" placeholder="Ad, login, abonent kodu, email">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <select name="role_filter">
                            <option value="">Butun rollar</option>
                            <option value="employee" selected>Employee</option>
                        </select>
                        <select name="type_filter">
                            <option value="">Butun tipler</option>
                            <option value="operator" <?= ((string) ($_GET['type_filter'] ?? '') === 'operator') ? 'selected' : ''; ?>>Operator</option>
                            <option value="technician" <?= ((string) ($_GET['type_filter'] ?? '') === 'technician') ? 'selected' : ''; ?>>Technician</option>
                            <option value="admin" <?= ((string) ($_GET['type_filter'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <select name="view">
                            <option value="table" <?= $viewMode === 'table' ? 'selected' : ''; ?>>Cedvel gorunumu</option>
                            <option value="cards" <?= $viewMode === 'cards' ? 'selected' : ''; ?>>Kart gorunumu</option>
                        </select>
                        <select name="per_page">
                            <?php foreach ([25, 50, 100] as $size): ?>
                                <option value="<?= e((string) $size); ?>" <?= $perPage === $size ? 'selected' : ''; ?>><?= e((string) $size); ?> qeyd</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-users-actions">
                        <button type="submit" class="portal-primary-btn">Goster</button>
                        <a class="portal-ghost-btn portal-inline-link" href="admin-users.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Sifirla</a>
                    </div>
                    <small data-auto-filter-status role="status" aria-live="polite"></small>
                </form>
            </article>
        </section>

        <section class="portal-card portal-reveal" data-auto-filter-results="admin-users-results" data-auto-filter-form="admin-users-filter">
            <div class="portal-card-head">
                <h2>Emekdas siyahisi</h2>
                <div class="admin-users-view-switch">
                    <small><?= e((string) $totalAccounts); ?> qeyd, sehife <?= e((string) $page); ?>/<?= e((string) $totalPages); ?></small>
                    <?php
                    $tableParams = $queryBase + ['view' => 'table', 'page' => 1];
                    $cardParams = $queryBase + ['view' => 'cards', 'page' => 1];
                    ?>
                    <a class="portal-ghost-btn portal-inline-link<?= $viewMode === 'table' ? ' is-active' : ''; ?>" href="admin-users.php?<?= e(http_build_query($tableParams)); ?>">Cedvel</a>
                    <a class="portal-ghost-btn portal-inline-link<?= $viewMode === 'cards' ? ' is-active' : ''; ?>" href="admin-users.php?<?= e(http_build_query($cardParams)); ?>">Kart</a>
                </div>
            </div>
            <?php if ($viewMode === 'cards'): ?>
                <div class="admin-users-card-grid">
                    <?php foreach ($visibleAccounts as $account): ?>
                        <?php $accountPayload = app_db_account_payload($account); $accountEmployeeMeta = is_array($accountPayload['employee'] ?? null) ? $accountPayload['employee'] : []; ?>
                        <article class="admin-users-card">
                            <div class="admin-users-card-head">
                                <div>
                                    <strong><?= e((string) ($account['full_name'] ?? '')); ?></strong><br>
                                    <small><?= e((string) ($account['login'] ?? '')); ?></small>
                                </div>
                                <?php $cardEmployeeRole = (string) ($account['employee_role'] ?? ''); ?>
                                <span class="admin-pill <?= ((string) ($account['role'] ?? '') === 'employee') ? 'is-employee' : ''; ?> <?= ((string) ($account['account_type'] ?? '') === 'admin') ? 'is-admin' : ''; ?> <?= $cardEmployeeRole === 'super_admin' ? 'is-super-admin' : ''; ?>">
                                    <?= $cardEmployeeRole === 'super_admin' ? 'Super admin' : e(app_ui_term((string) ($account['employee_role'] ?? $account['account_type'] ?? ''))); ?>
                                </span>
                            </div>
                            <div class="admin-users-card-meta">
                                <span>ID: <?= e((string) ($account['id'] ?? '')); ?></span>
                                <span>Elaqe: <?= e(trim((string) ($account['phone'] ?? '') . ' ' . (string) ($account['email'] ?? '')) ?: '-'); ?></span>
                                <span>Erazi: <?= e((string) ($account['area'] ?? '-')); ?></span>
                                <span>Vəziyyət: <?= e(app_ui_status((string) ($account['status'] ?? '-'))); ?></span>
                                <?php if ((string) ($accountEmployeeMeta['telegram_chat_id'] ?? '') !== ''): ?>
                                    <span>Telegram: <?= e((string) ($accountEmployeeMeta['telegram_chat_id'] ?? '')); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-users-actions">
                                <a class="portal-primary-btn portal-inline-link" href="admin-users.php?edit=<?= e((string) ($account['id'] ?? '')); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">Redakte et</a>
                                <?php if ($canDeleteAccounts && (string) ($account['login'] ?? '') !== 's.abdullayev@superoptic.net'): ?>
                                    <details class="admin-users-danger-menu">
                                        <summary>Sil</summary>
                                        <form class="admin-users-delete-form" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post" onsubmit="return confirm('Silinsin?');">
                                            <input type="hidden" name="action" value="delete_account">
                                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                            <input type="hidden" name="account_id" value="<?= e((string) ($account['id'] ?? '')); ?>">
                                            <input type="text" name="delete_reason" required placeholder="Silme sebebi">
                                            <button type="submit" class="portal-ghost-btn portal-inline-link">Tesdiqle</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
            <div class="portal-table-wrap">
                <table class="portal-table admin-users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Ad / login</th>
                            <th>Rol</th>
                            <th>Elaqe</th>
                            <th>Erazi</th>
                            <th>Status</th>
                            <th>Emeliyyat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visibleAccounts as $account): ?>
                            <tr>
                                <td data-label="ID"><?= e((string) ($account['id'] ?? '')); ?></td>
                                <td data-label="Ad / login">
                                    <strong><?= e((string) ($account['full_name'] ?? '')); ?></strong><br>
                                    <small><?= e((string) ($account['login'] ?? '')); ?></small><br>
                                    <?php if ((string) ($account['subscriber_code'] ?? '') !== ''): ?>
                                        <small>ABN: <?= e((string) ($account['subscriber_code'] ?? '')); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Rol">
                                    <?php $rowEmployeeRole = (string) ($account['employee_role'] ?? ''); ?>
                                    <span class="admin-pill <?= ((string) ($account['role'] ?? '') === 'employee') ? 'is-employee' : ''; ?> <?= ((string) ($account['account_type'] ?? '') === 'admin') ? 'is-admin' : ''; ?> <?= $rowEmployeeRole === 'super_admin' ? 'is-super-admin' : ''; ?>">
                                        <?= $rowEmployeeRole === 'super_admin'
                                            ? 'Super admin'
                                            : e(app_ui_term((string) ($account['employee_role'] ?? $account['account_type'] ?? ''))); ?>
                                    </span>
                                </td>
                                <td data-label="Elaqe" class="admin-users-contact">
                                    <small><?= e((string) ($account['email'] ?? '')); ?></small><br>
                                    <small><?= e((string) ($account['phone'] ?? '')); ?></small>
                                    <?php $accountPayload = app_db_account_payload($account); $accountEmployeeMeta = is_array($accountPayload['employee'] ?? null) ? $accountPayload['employee'] : []; ?>
                                    <?php if ((string) ($accountEmployeeMeta['telegram_chat_id'] ?? '') !== ''): ?>
                                        <br><small>Telegram: <?= e((string) ($accountEmployeeMeta['telegram_chat_id'] ?? '')); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Erazi" class="admin-users-areas">
                                    <strong><?= e((string) ($account['area'] ?? '')); ?></strong><br>
                                    <small><?= e(implode(', ', (array) ($account['responsible_areas'] ?? []))); ?></small>
                                </td>
                                <td data-label="Status"><?= e(app_ui_status((string) ($account['status'] ?? ''))); ?></td>
                                <td data-label="Emeliyyat">
                                    <div class="admin-users-actions">
                                        <a class="portal-primary-btn portal-inline-link" href="admin-users.php?edit=<?= e((string) ($account['id'] ?? '')); ?><?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">Redakte et</a>
                                        <?php if ($canDeleteAccounts && (string) ($account['login'] ?? '') !== 's.abdullayev@superoptic.net'): ?>
                                            <details class="admin-users-danger-menu">
                                                <summary>Sil</summary>
                                                <form class="admin-users-delete-form" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post" onsubmit="return confirm('Silinsin?');">
                                                    <input type="hidden" name="action" value="delete_account">
                                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                    <input type="hidden" name="account_id" value="<?= e((string) ($account['id'] ?? '')); ?>">
                                                    <input type="text" name="delete_reason" required placeholder="Silme sebebi">
                                                    <button type="submit" class="portal-ghost-btn portal-inline-link">Tesdiqle</button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <nav class="admin-users-pagination portal-pagination" aria-label="Emekdas sehifeleri">
                <?php
                $prevParams = $queryBase + ['page' => max(1, $page - 1)];
                $nextParams = $queryBase + ['page' => min($totalPages, $page + 1)];
                ?>
                <a class="portal-ghost-btn portal-inline-link<?= $page <= 1 ? ' portal-disabled-link' : ''; ?>" href="admin-users.php?<?= e(http_build_query($prevParams)); ?>">Evvelki</a>
                <span><?= e((string) $page); ?> / <?= e((string) $totalPages); ?>, <?= e((string) $perPage); ?> qeyd</span>
                <a class="portal-ghost-btn portal-inline-link<?= $page >= $totalPages ? ' portal-disabled-link' : ''; ?>" href="admin-users.php?<?= e(http_build_query($nextParams)); ?>">Novbeti</a>
            </nav>
        </section>
    </main>

    <?php app_render_copilot_widget($lang); ?>
    <script>
    (() => {
        const modalOpeners = new WeakMap();
        const focusableItems = (modal) => Array.from(modal.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((item) => item.offsetParent !== null);
        const openModal = (id, opener = null) => {
            const modal = document.getElementById(id);
            if (!modal) return;
            modalOpeners.set(modal, opener || document.activeElement);
            modal.setAttribute('open', 'open');
            document.body.classList.add('admin-modal-open');
            focusableItems(modal)[0]?.focus({ preventScroll: true });
        };
        const closeModal = (modal) => {
            if (!modal) return;
            modal.removeAttribute('open');
            if (!document.querySelector('.admin-users-modal[open]')) {
                document.body.classList.remove('admin-modal-open');
            }
            const opener = modalOpeners.get(modal);
            if (opener instanceof HTMLElement && opener.isConnected) opener.focus({ preventScroll: true });
            modalOpeners.delete(modal);
        };
        document.querySelectorAll('[data-open-admin-modal]').forEach((button) => {
            button.addEventListener('click', () => openModal(button.dataset.openAdminModal || '', button));
        });
        document.querySelectorAll('[data-close-admin-modal]').forEach((button) => {
            button.addEventListener('click', () => closeModal(button.closest('.admin-users-modal')));
        });
        document.querySelectorAll('.admin-users-modal').forEach((modal) => {
            modal.querySelector('.admin-users-modal-summary')?.addEventListener('click', (event) => event.stopPropagation());
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeModal(modal);
            });
        });
        document.addEventListener('keydown', (event) => {
            const modal = document.querySelector('.admin-users-modal[open]');
            if (!modal) return;
            if (event.key === 'Escape') {
                closeModal(modal);
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = focusableItems(modal);
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    })();
    </script>
    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
