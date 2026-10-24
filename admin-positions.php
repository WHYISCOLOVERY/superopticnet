<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hr-position-service.php';
require_once __DIR__ . '/ui-language-lib.php';
require_once __DIR__ . '/design-system-lib.php';

app_session_boot();
$lang = app_lang();
$user = app_portal_current_user();
if ($user === null || ($user['role'] ?? '') !== 'employee' || !app_portal_is_admin($user)) {
    header('Location: portal.php?status=auth_required' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
    exit;
}

$db = app_db();
if (!$db instanceof PDO || !app_hr_position_schema_ready($db)) {
    http_response_code(503);
    $schemaReady = false;
    $positions = [];
    $editing = null;
} else {
    $schemaReady = true;
    $positions = app_hr_positions($db, false);
    $editing = app_hr_position_by_id($db, max(0, (int) ($_GET['edit'] ?? 0)));
}
$selectedModules = is_array($editing) ? app_hr_position_module_keys($editing) : [];
$moduleCatalog = app_portal_module_catalog($lang);
$status = clean_text((string) ($_GET['status'] ?? ''));
$statusMessages = [
    'position_saved' => ['success', 'Vəzifə və icazə şablonu saxlanıldı.'],
    'position_title_required' => ['error', 'Vəzifənin adı mütləqdir.'],
    'position_reason_required' => ['error', 'Dəyişiklik səbəbi mütləqdir.'],
    'position_duplicate_title' => ['error', 'Bu adda vəzifə artıq mövcuddur.'],
    'position_invalid_account_type' => ['error', 'Hesab tipi düzgün seçilməyib.'],
    'position_invalid_employee_role' => ['error', 'Portal rolu düzgün seçilməyib.'],
    'position_position_not_found' => ['error', 'Vəzifə tapılmadı.'],
    'position_schema_not_ready' => ['error', 'Vəzifə kataloqu migration-u tətbiq edilməyib.'],
    'position_save_failed' => ['error', 'Vəzifə saxlanılmadı. Əməliyyat jurnalını yoxlayın.'],
];
$flash = $statusMessages[$status] ?? null;
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vəzifələr və icazələr</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <link rel="stylesheet" href="<?= e(app_asset('css/design-system.css')); ?>">
    <style>
        .position-layout{display:grid;grid-template-columns:minmax(300px,430px) minmax(0,1fr);gap:18px;align-items:start}
        .position-form{display:grid;gap:14px}
        .position-module-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .position-module-item{display:flex;align-items:center;gap:9px;min-width:0;padding:10px 12px;border:1px solid rgba(148,163,184,.28);border-radius:8px;background:#fff}
        .position-module-item input{width:auto;min-height:auto;margin:0}
        .position-module-item span{min-width:0;overflow-wrap:anywhere}
        .position-state{display:inline-flex;align-items:center;min-height:26px;padding:4px 8px;border-radius:6px;background:#e5eaf0;font-size:.78rem;font-weight:800}
        .position-state.is-active{background:#e3f3eb;color:#087a55}
        @media(max-width:900px){.position-layout{grid-template-columns:1fr}}
        @media(max-width:560px){.position-module-grid{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body"<?= app_design_system_body_attribute(); ?>>
    <header class="portal-topbar">
        <div class="portal-topbar-inner">
            <a href="operations-center.php?section=hr&hr_tab=employees" class="portal-brand">
                <img src="images/favicon.png" alt="Super Optik Tech">
                <span>Vəzifələr və icazələr</span>
            </a>
            <div class="portal-topbar-actions">
                <a class="portal-ghost-btn portal-inline-link" href="admin-users.php">İstifadəçi icazələri</a>
                <a class="portal-ghost-btn portal-inline-link" href="operations-center.php?section=hr&hr_tab=employees">HR-a qayıt</a>
            </div>
        </div>
    </header>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash[0]); ?>">
                <span><?= e($flash[1]); ?></span>
            </div>
        <?php endif; ?>
        <?php if (!$schemaReady): ?>
            <div class="portal-alert portal-alert-error">
                <span>Vəzifə kataloqu hazır deyil. `20260729_170_hr_position_catalog` migration-u tətbiq edilməlidir.</span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">Admin paneli</span>
                <h1>Vəzifə kataloqu və icazə şablonları</h1>
                <p>HR bu siyahıdan vəzifə seçir. Vəzifənin rol və modul şablonu əməkdaş hesabına yalnız admin təsdiqi ilə tətbiq olunur.</p>
            </div>
            <div class="portal-status-card">
                <span>Aktiv vəzifə</span>
                <strong><?= e((string) count(array_filter($positions, static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1))); ?></strong>
                <small>Audit olunan admin dəyişiklikləri</small>
            </div>
        </section>

        <div class="position-layout">
            <section class="portal-card">
                <div class="portal-card-head">
                    <div>
                        <strong><?= is_array($editing) ? 'Vəzifəni düzəlt' : 'Yeni vəzifə yarat'; ?></strong>
                        <small>Rol və modul seçimi icazə şablonudur.</small>
                    </div>
                    <?php if (is_array($editing)): ?>
                        <a class="portal-ghost-btn portal-inline-link" href="admin-positions.php">Yeni</a>
                    <?php endif; ?>
                </div>
                <form class="position-form" action="portal-actions.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save_hr_position">
                    <input type="hidden" name="position_id" value="<?= e((string) ($editing['id'] ?? '')); ?>">
                    <div class="portal-form-row">
                        <label for="positionTitle">Vəzifənin adı</label>
                        <input id="positionTitle" name="title" value="<?= e((string) ($editing['title'] ?? '')); ?>" required>
                    </div>
                    <div class="portal-form-row">
                        <label for="positionDescription">Qısa izah</label>
                        <textarea id="positionDescription" name="description" rows="3"><?= e((string) ($editing['description'] ?? '')); ?></textarea>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <div>
                            <label for="positionAccountType">Məsləhət görülən hesab tipi</label>
                            <?php $selectedAccountType = (string) ($editing['recommended_account_type'] ?? 'operator'); ?>
                            <select id="positionAccountType" name="recommended_account_type">
                                <?php foreach (['operator' => 'Operator', 'technician' => 'Texnik', 'support' => 'Support', 'admin' => 'Admin'] as $key => $label): ?>
                                    <option value="<?= e($key); ?>" <?= $selectedAccountType === $key ? 'selected' : ''; ?>><?= e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="positionEmployeeRole">Məsləhət görülən portal rolu</label>
                            <?php $selectedRole = (string) ($editing['recommended_employee_role'] ?? ''); ?>
                            <select id="positionEmployeeRole" name="recommended_employee_role">
                                <option value="">Rol admin tərəfindən seçiləcək</option>
                                <?php foreach (app_hr_position_role_keys() as $roleKey): ?>
                                    <option value="<?= e($roleKey); ?>" <?= $selectedRole === $roleKey ? 'selected' : ''; ?>><?= e(app_ui_label($roleKey)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label>Modul icazə şablonu</label>
                        <div class="position-module-grid">
                            <?php foreach ($moduleCatalog as $moduleKey => $module): ?>
                                <label class="position-module-item">
                                    <input type="checkbox" name="module_keys[]" value="<?= e((string) $moduleKey); ?>" <?= in_array((string) $moduleKey, $selectedModules, true) ? 'checked' : ''; ?>>
                                    <span><?= e((string) ($module['label'] ?? $moduleKey)); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="portal-form-row">
                        <label for="positionReason">Yaratma və ya dəyişiklik səbəbi</label>
                        <textarea id="positionReason" name="change_reason" rows="2" required placeholder="Məsələn: NOC əməkdaşları üçün standart giriş şablonu"></textarea>
                    </div>
                    <label class="position-module-item">
                        <input type="checkbox" name="is_active" value="1" <?= !is_array($editing) || (int) ($editing['is_active'] ?? 0) === 1 ? 'checked' : ''; ?>>
                        <span>Vəzifə aktivdir və HR seçimində görünür</span>
                    </label>
                    <button class="portal-primary-btn" type="submit"<?= !$schemaReady ? ' disabled' : ''; ?>>Yadda saxla</button>
                </form>
            </section>

            <section class="portal-card">
                <div class="portal-card-head">
                    <div>
                        <strong>Mövcud vəzifələr</strong>
                        <small>Silinmir; tarixçəni qorumaq üçün deaktiv edilir.</small>
                    </div>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>Vəzifə</th>
                                <th>Rol şablonu</th>
                                <th>Modullar</th>
                                <th>Status</th>
                                <th>Əməliyyat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($positions as $position): ?>
                                <tr>
                                    <td><strong><?= e((string) ($position['title'] ?? '-')); ?></strong><br><small><?= e((string) ($position['description'] ?? '')); ?></small></td>
                                    <td><?= e((string) (($position['recommended_employee_role'] ?? '') !== '' ? app_ui_label((string) $position['recommended_employee_role']) : 'Admin seçəcək')); ?></td>
                                    <td><?= e(implode(', ', array_map(static fn (string $key): string => (string) ($moduleCatalog[$key]['label'] ?? $key), (array) ($position['module_keys'] ?? [])))); ?></td>
                                    <td><span class="position-state<?= (int) ($position['is_active'] ?? 0) === 1 ? ' is-active' : ''; ?>"><?= (int) ($position['is_active'] ?? 0) === 1 ? 'Aktiv' : 'Deaktiv'; ?></span></td>
                                    <td><a class="portal-ghost-btn portal-inline-link" href="admin-positions.php?edit=<?= e((string) ($position['id'] ?? 0)); ?>">Düzəlt</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($positions === []): ?>
                                <tr><td colspan="5">Vəzifə yaradılmayıb.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <script src="<?= e(app_asset('script/design-system.js')); ?>" defer></script>
</body>
</html>
