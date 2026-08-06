<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/mgs-integration-lib.php';

$lang = app_lang();
$user = billing_require_employee();
$db = app_db();
$flash = null;
$botTestResult = null;
$backupWorkerResult = null;

if ($db instanceof PDO) {
    app_mgs_ensure_schema($db);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf($csrfToken)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (!$db instanceof PDO) {
        $flash = ['type' => 'error', 'message' => 'Database baglantisi aktiv deyil.'];
    } elseif ($action === 'sync_bots') {
        $result = app_mgs_sync_telegram_bot_registry($db);
        $flash = ['type' => 'success', 'message' => 'Telegram bot registry yenilendi: ' . (int) $result['configured'] . '/' . (int) $result['synced'] . ' hazir.'];
    } elseif ($action === 'save_custom_bot') {
        if (!app_portal_is_admin($user)) {
            $flash = ['type' => 'error', 'message' => 'Bot elave etmek ucun admin icazesi lazimdir.'];
        } else {
            $result = app_mgs_upsert_custom_telegram_bot($db, [
                'bot_key' => (string) ($_POST['custom_bot_key'] ?? ''),
                'display_name' => (string) ($_POST['custom_display_name'] ?? ''),
                'purpose' => (string) ($_POST['custom_purpose'] ?? ''),
                'chat_id' => (string) ($_POST['custom_chat_id'] ?? ''),
            ], $user);
            $flash = [
                'type' => !empty($result['ok']) ? 'success' : 'error',
                'message' => !empty($result['ok'])
                    ? 'Custom Telegram bot saxlandi: ' . (string) ($result['bot_key'] ?? '')
                    : 'Bot saxlanmadi: ' . (string) ($result['reason'] ?? 'unknown'),
            ];
        }
    } elseif ($action === 'delete_custom_bot') {
        if (!app_portal_is_admin($user)) {
            $flash = ['type' => 'error', 'message' => 'Bot silmek ucun admin icazesi lazimdir.'];
        } else {
            $result = app_mgs_delete_custom_telegram_bot($db, (string) ($_POST['bot_key'] ?? ''), $user);
            $flash = [
                'type' => !empty($result['ok']) ? 'success' : 'error',
                'message' => !empty($result['ok'])
                    ? 'Custom Telegram bot silindi: ' . (string) ($result['bot_key'] ?? '')
                    : 'Bot silinmedi: ' . (string) ($result['reason'] ?? 'unknown'),
            ];
        }
    } elseif ($action === 'seed_learning') {
        $result = app_mgs_seed_device_learning_profiles($db, $user);
        $flash = ['type' => 'success', 'message' => 'Avadanliq oyrenme profilleri yenilendi: ' . (int) $result['seeded'] . ' profil.'];
    } elseif ($action === 'sync_process_registry') {
        $result = app_mgs_sync_process_registry($db, $user);
        $flash = ['type' => 'success', 'message' => 'Proses registry yenilendi: ' . (int) $result['ready'] . '/' . (int) $result['synced'] . ' hazir.'];
    } elseif ($action === 'set_management_path') {
        $enabled = (string) ($_POST['enabled'] ?? '0') === '1';
        $result = app_mgs_set_management_path($enabled, $user, $db);
        $flash = ['type' => !empty($result['ok']) ? 'success' : 'error', 'message' => $enabled ? 'VLAN 550 monitorinq yolu aktiv edildi. Cihazlar probe ucun hazirdir.' : 'VLAN 550 monitorinq yolu sonduruldu. Daxili offline alarm yaratmaq bloklandi.'];
    } elseif ($action === 'probe_devices') {
        $result = app_mgs_refresh_device_reachability($db, true, $user);
        $flash = ['type' => !empty($result['ok']) ? 'success' : 'error', 'message' => 'Avadanliq probe: ' . (int) ($result['reachable'] ?? 0) . '/' . (int) ($result['probed'] ?? 0) . ' reachable.'];
    } elseif ($action === 'test_bot') {
        $apply = isset($_POST['apply_test']) && app_portal_is_admin($user);
        $botKey = clean_text((string) ($_POST['bot_key'] ?? 'network'));
        $message = trim((string) ($_POST['message'] ?? ''));
        $destination = trim((string) ($_POST['destination'] ?? ''));
        $botTestResult = app_mgs_send_telegram_bot_test($botKey, $message, $destination, $apply);
        app_mgs_record_operation_action([
            'action_type' => 'telegram_bot_test',
            'entity_type' => 'telegram_bot',
            'entity_id' => $botKey,
            'status' => $apply ? (!empty($botTestResult['sent']) ? 'executed' : 'failed') : 'dry_run',
            'actor' => $user,
            'summary' => 'Telegram bot test: ' . $botKey,
            'payload' => $botTestResult,
        ]);
        $flash = [
            'type' => (!$apply || !empty($botTestResult['sent'])) ? 'success' : 'error',
            'message' => $apply
                ? (!empty($botTestResult['sent']) ? 'TEST MESAJI Telegram botuna gonderildi.' : 'TEST MESAJI gonderilmedi: ' . (string) ($botTestResult['reason'] ?? 'xeta'))
                : 'Telegram bot dry-run yoxlamasi tamamlandi. Real mesaj gonderilmedi.',
        ];
    } elseif ($action === 'record_test_alarm') {
        $result = app_mgs_record_alarm_event([
            'alarm_type' => 'INTEGRATION_SMOKE',
            'severity' => 'warning',
            'source' => 'mgs-integration-ui',
            'object_type' => 'system',
            'object_id' => 'spog',
            'title' => 'SPoG alarm/message integration test',
            'device_name' => 'SuperOptic MGS',
            'human_problem' => 'Alarm, ticket ve mesaj modullari test rejiminde birlesdirildi',
            'recommendation' => 'Bu test real bilet yaratmir; message queue-de TEST statusu ile gorunmelidir.',
            'telegram_bot_key' => 'network',
        ], true, true, $user, $db);
        $flash = ['type' => !empty($result['ok']) ? 'success' : 'error', 'message' => !empty($result['ok']) ? 'Test alarm ve TEST mesaj qeyd edildi.' : 'Test alarm yazilmadi.'];
    } elseif ($action === 'inventory_test') {
        $apply = isset($_POST['apply_inventory']) && app_portal_is_admin($user);
        $result = app_mgs_record_inventory_movement([
            'sku' => clean_text((string) ($_POST['sku'] ?? 'TEST-FASTCONNECTOR')),
            'item_name' => clean_text((string) ($_POST['item_name'] ?? 'Fast connector SC/APC')),
            'category' => clean_text((string) ($_POST['category'] ?? 'fiber')),
            'unit' => clean_text((string) ($_POST['unit'] ?? 'eded')),
            'quantity' => (float) ($_POST['quantity'] ?? 1),
            'movement_type' => clean_text((string) ($_POST['movement_type'] ?? 'issue')),
            'ticket_no' => clean_text((string) ($_POST['ticket_no'] ?? '')),
            'actor_account_id' => (int) ($user['id'] ?? 0),
            'actor_name' => (string) ($user['full_name'] ?? $user['name'] ?? $user['login'] ?? 'operator'),
            'note' => 'SPoG inventory integration UI test',
        ], $apply);
        $flash = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => $apply
                ? (!empty($result['ok']) ? 'Inventar hereketi DB-de post edildi.' : 'Inventar yazilmadi: ' . (string) ($result['reason'] ?? 'xeta'))
                : 'Inventar hereketi dry-run kimi yoxlandi; stok azalmadi.',
        ];
    } elseif ($action === 'run_backup_worker') {
        $apply = isset($_POST['apply_backup']) && app_portal_is_admin($user);
        $backupType = clean_text((string) ($_POST['backup_type'] ?? 'profile_snapshot'));
        $limit = (int) ($_POST['limit'] ?? 5);
        $backupWorkerResult = app_mgs_run_backup_worker($db, $apply, $limit, $backupType, $user);
        $flash = [
            'type' => !empty($backupWorkerResult['ok']) ? 'success' : 'error',
            'message' => !empty($backupWorkerResult['ok'])
                ? 'Backup worker ' . ($apply ? 'apply' : 'dry-run') . ': ' . (int) ($backupWorkerResult['checked'] ?? 0) . ' cihaz, stored ' . (int) ($backupWorkerResult['stored'] ?? 0) . ', duplicate ' . (int) ($backupWorkerResult['duplicates'] ?? 0) . ', skipped ' . (int) ($backupWorkerResult['skipped'] ?? 0) . '.'
                : 'Backup worker xetasi: ' . (string) ($backupWorkerResult['reason'] ?? 'unknown'),
        ];
    } elseif ($action === 'rebuild_employee_snapshots') {
        $result = app_mgs_rebuild_employee_work_snapshots($db);
        $flash = ['type' => !empty($result['ok']) ? 'success' : 'error', 'message' => 'Isci nezaret snapshot: ' . (int) ($result['updated'] ?? 0) . ' emekdas yenilendi.'];
    }
}

$summary = app_mgs_summary($db, false, $user);
$bridge = app_mgs_bridge_status();
$view = clean_text((string) ($_GET['view'] ?? 'overview'));
$view = in_array($view, ['overview', 'processes', 'actions', 'bots', 'devices', 'backups', 'logs'], true) ? $view : 'overview';
$bots = $view === 'bots' ? app_mgs_telegram_bot_rows($db) : [];
$editBotKey = app_mgs_normalize_bot_key((string) ($_GET['edit_bot'] ?? ''));
$editingBot = null;
if ($view === 'bots' && $editBotKey !== '' && app_portal_is_admin($user)) {
    foreach ($bots as $botRow) {
        if (!is_array($botRow) || (string) ($botRow['bot_key'] ?? '') !== $editBotKey) {
            continue;
        }
        $botMetadata = app_db_json_decode((string) ($botRow['metadata_json'] ?? ''), []);
        if (empty($botMetadata['custom'])) {
            break;
        }
        $editingBot = $botRow;
        $chatConfigKey = (string) ($botRow['chat_config_key'] ?? '');
        $editingBot['chat_id'] = $chatConfigKey !== '' ? app_mgs_runtime_setting($chatConfigKey) : '';
        break;
    }
}
$devices = $view === 'devices' ? app_mgs_device_learning_rows($db, $user) : [];
$alarms = $view === 'logs' ? app_mgs_noc_recent_rows('mgs_alarm_events', 12, $user) : [];
$operations = $view === 'logs' ? app_mgs_noc_recent_rows('mgs_operation_actions', 12, $user) : [];
$inventory = $view === 'logs' ? app_mgs_recent_rows('inventory_stock_items', 12) : [];
$employeeSnapshots = $view === 'logs' ? app_mgs_recent_rows('employee_work_snapshots', 12) : [];
$backups = $view === 'backups' ? app_mgs_noc_recent_rows('device_config_backups', 12, $user) : [];
$processRows = $view === 'processes' ? app_mgs_noc_recent_rows('mgs_process_registry', 20, $user) : [];
$dedupRows = $view === 'processes' ? app_mgs_noc_recent_rows('mgs_dedup_registry', 12, $user) : [];
$managementPathReady = app_mgs_management_path_ready();
$suffix = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
$mgsModules = app_mgs_bridge_modules();
$viewBaseParams = [];
if ($lang !== 'az') {
    $viewBaseParams['lang'] = $lang;
}
$viewHref = static function (string $targetView) use ($viewBaseParams): string {
    return 'mgs-integration.php?' . http_build_query($viewBaseParams + ['view' => $targetView]);
};
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem servislari</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .mgs-integration-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:18px}
        .mgs-integration-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .mgs-integration-list{display:grid;gap:10px}
        .mgs-integration-row{display:grid;grid-template-columns:minmax(130px,.7fr) minmax(0,1.2fr) 130px;gap:12px;align-items:center;padding:12px;border:1px solid rgba(148,163,184,.24);border-radius:16px;background:#fff}
        .mgs-integration-row small{color:#64748b}
        .mgs-integration-modules{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
        .mgs-integration-module{padding:14px;border-radius:18px;background:#fff;border:1px solid rgba(42,182,165,.22);box-shadow:0 14px 32px rgba(17,38,63,.08);font-weight:900;color:#10233f;text-decoration:none}
        .mgs-integration-module small{display:block;margin-top:5px;color:#64748b;font-weight:700}
        .mgs-integration-code{display:block;padding:12px;border-radius:14px;background:#0f172a;color:#dbeafe;white-space:normal;word-break:break-word;font-size:.78rem}
        .mgs-view-switch{display:flex;flex-wrap:wrap;gap:10px}
        .mgs-view-switch .mgs-chip{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 16px;border-radius:999px;background:#eef5f8;color:#10233f;text-decoration:none;font-weight:900;border:1px solid rgba(148,163,184,.22)}
        .mgs-view-switch .mgs-chip.is-active{background:#0f7f8b;color:#fff;box-shadow:0 12px 28px rgba(15,127,139,.18)}
        .mgs-overview-links{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}
        .mgs-overview-link{padding:16px;border-radius:18px;background:#f8fbfd;border:1px solid rgba(148,163,184,.2);text-decoration:none;color:#10233f;font-weight:900}
        .mgs-overview-link small{display:block;margin-top:6px;color:#64748b;font-weight:700}
        .mgs-bot-admin-grid{display:grid;grid-template-columns:minmax(320px,.8fr) minmax(0,1.2fr);gap:18px;align-items:start}
        .mgs-bot-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .mgs-bot-form label{display:grid;gap:7px;color:#64748b;font-weight:900;font-size:.82rem;text-transform:uppercase;letter-spacing:.03em}
        .mgs-bot-form input,.mgs-bot-form textarea{width:100%;min-height:48px;border:1px solid rgba(148,163,184,.28);border-radius:16px;padding:12px 14px;background:#fff;color:#10233f;font:inherit;font-weight:800;outline:none}
        .mgs-bot-form textarea{grid-column:1/-1;min-height:96px;resize:vertical}
        .mgs-bot-form input:focus,.mgs-bot-form textarea:focus{border-color:rgba(15,127,139,.6);box-shadow:0 0 0 4px rgba(42,182,165,.14)}
        .mgs-bot-form-actions{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
        .mgs-bot-delete-form{margin-top:14px;padding:14px;border:1px dashed #fecaca;border-radius:18px;background:#fff7f7;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end}
        .mgs-bot-delete-form label{display:grid;gap:7px;color:#991b1b;font-weight:900;font-size:.82rem;text-transform:uppercase;letter-spacing:.03em}
        .mgs-bot-delete-form input{width:100%;border:1px solid #fecaca;border-radius:14px;padding:12px 14px;font:inherit;background:#fff;color:#10233f;font-weight:800;outline:none}
        .mgs-bot-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .mgs-bot-actions form{margin:0}
        .mgs-bot-note{margin-top:12px;color:#64748b;font-weight:700;line-height:1.55}
        .mgs-bot-type{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:#eef5f8;color:#0f7f8b;font-weight:900;font-size:.78rem}
        .mgs-bot-type.is-system{color:#10233f;background:#edf2f7}
        .system-health-warning{background:#fff7d6;color:#8a5a00;border-color:rgba(245,158,11,.22)}
        @media (max-width:1100px){.mgs-integration-grid,.mgs-integration-actions,.mgs-integration-modules,.mgs-overview-links,.mgs-bot-admin-grid,.mgs-bot-form,.mgs-bot-delete-form{grid-template-columns:1fr}.mgs-integration-row{grid-template-columns:1fr}}
    </style>
</head>
<body class="portal-body">
    <?php billing_render_header('Sistem servislari', 'mgs-integration', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e((string) $flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e((string) $flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="mgs-command-hero portal-reveal">
            <div>
                <span class="portal-kicker">Single pane of glass</span>
                <h1>Ticket, alarm, mesaj, avadanliq oyrenme ve emeliyyatlar</h1>
                <p>Bu panel PHP billing shell-ini, MGS backend API qatini, Telegram botlarini, inventory hereketlerini ve isci performans nezaretini vahid yerde birlesdirir.</p>
                <div class="mgs-command-actions">
                    <a class="portal-primary-btn portal-inline-link" href="message-center.php<?= e($suffix); ?>">Mesaj merkezi</a>
                    <a class="portal-ghost-btn portal-inline-link" href="crm.php<?= e($suffix); ?>">Ticket CRM</a>
                    <a class="portal-ghost-btn portal-inline-link" href="system-health.php<?= e($suffix); ?>">System health</a>
                    <a class="portal-ghost-btn portal-inline-link" href="mgs-copilot-context.php" target="_blank" rel="noopener">Copilot konteksti</a>
                </div>
            </div>
            <div class="mgs-command-panel">
                <span>Servis qati</span>
                <strong><?= $bridge['online'] ? 'Hazirdir' : 'Offline'; ?></strong>
                <small><?= e((string) $bridge['url']); ?></small>
                <span>Botlar</span>
                <strong><?= e((string) ($summary['bots_ready'] ?? 0)); ?>/<?= e((string) ($summary['bots_total'] ?? 0)); ?></strong>
            </div>
        </section>

        <section class="mgs-stat-grid portal-reveal">
            <?php billing_render_stat_card('Open alarm', (string) ($summary['alarms_open'] ?? 0), 'dedup aktiv', 'fa-triangle-exclamation', 'orange'); ?>
            <?php billing_render_stat_card('Test alarm', (string) ($summary['alarms_test'] ?? 0), 'real bilet yaratmir', 'fa-vial', 'blue'); ?>
            <?php billing_render_stat_card('Operation today', (string) ($summary['operations_today'] ?? 0), 'audit/action', 'fa-list-check', 'navy'); ?>
            <?php billing_render_stat_card('Device profile', (string) ($summary['device_profiles'] ?? 0), 'auto-learning', 'fa-network-wired', 'green'); ?>
            <?php billing_render_stat_card('Backup', (string) ($summary['device_backup_versions'] ?? $summary['device_backups'] ?? 0), 'hash-dedup versiya', 'fa-box-archive', 'blue'); ?>
            <?php billing_render_stat_card('Mgmt path wait', (string) ($summary['devices_waiting_path'] ?? 0), 'VLAN/route gozleyir', 'fa-route', 'orange'); ?>
            <?php billing_render_stat_card('Isci snapshot', (string) ($summary['employee_snapshots'] ?? 0), 'bugun', 'fa-id-card-clip', 'blue'); ?>
            <?php billing_render_stat_card('VLAN 550', $managementPathReady ? 'Aktiv' : 'Passiv', $managementPathReady ? 'probe icaze var' : 'offline alarm blok', 'fa-ethernet', $managementPathReady ? 'green' : 'orange'); ?>
            <?php billing_render_stat_card('Proses registry', (string) ($summary['processes_ready'] ?? 0) . '/' . (string) ($summary['processes_total'] ?? 0), 'Copilot hazirliq', 'fa-diagram-project', 'navy'); ?>
            <?php billing_render_stat_card('Dedup key', (string) ($summary['dedup_keys'] ?? 0), 'tekrar bloklama', 'fa-clone', 'blue'); ?>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Sistem servislari bolmeleri</h2>
                <small>Default sehife yalniz xulase yukleyir; agir proses, bot, cihaz, backup ve jurnal cedvelleri secilen bolmede acilir.</small>
            </div>
            <div class="mgs-view-switch">
                <?php foreach ([
                    'overview' => 'Overview',
                    'processes' => 'Prosesler',
                    'actions' => 'Actions',
                    'bots' => 'Botlar',
                    'devices' => 'Cihaz oyrenme',
                    'backups' => 'Backup',
                    'logs' => 'Jurnallar',
                ] as $targetView => $label): ?>
                    <a class="mgs-chip<?= $view === $targetView ? ' is-active' : ''; ?>" href="<?= e($viewHref($targetView)); ?>"><?= e($label); ?></a>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($view === 'overview'): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>MGS backend modullari</h2>
                <small>Butun `oltidareetme` funksiyalari arxada API/runtime kimi qalir, ustde ise native billing shell render olunur.</small>
            </div>
            <div class="mgs-integration-modules">
                <?php foreach ($mgsModules as $module): ?>
                    <a class="mgs-integration-module" href="<?= e((string) ($module['url'] ?? '#')); ?>">
                        <?= e((string) ($module['title'] ?? '-')); ?>
                        <small><?= e($bridge['online'] ? 'Servis qati hazirdir' : 'Node runtime offline-dir'); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="mgs-overview-links">
                <a class="mgs-overview-link" href="<?= e($viewHref('processes')); ?>">Proses registry <small>Dedup, owner ve readiness xeritasi</small></a>
                <a class="mgs-overview-link" href="<?= e($viewHref('actions')); ?>">Test ve sync actions <small>Bot, alarm, backup, inventory dry-run</small></a>
                <a class="mgs-overview-link" href="<?= e($viewHref('logs')); ?>">Jurnallar <small>Alarm, operation, isci ve inventar current state</small></a>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($view === 'processes'): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Vahid proses registry</h2>
                <small>Copilot/autonomy qatinin oxuyacagi esas proses xeritasi. Her axinin sahibi, dedup qaydasi ve hazirliq statusu buradadir.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Proses</th><th>Qrup</th><th>Modullar</th><th>Status</th><th>Dedup qaydasi</th><th>Qeyd</th></tr></thead>
                    <tbody>
                        <?php foreach ($processRows as $process): ?>
                            <tr>
                                <td><strong><?= e((string) ($process['title'] ?? '-')); ?></strong><br><small><?= e((string) ($process['process_key'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($process['process_group'] ?? '-')); ?><br><small><?= e((string) ($process['owner_role'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($process['source_module'] ?? '-')); ?> -> <?= e((string) ($process['target_module'] ?? '-')); ?></td>
                                <td><span class="portal-pill <?= ((string) ($process['status'] ?? '') === 'ready') ? 'portal-pill-success' : 'system-health-warning'; ?>"><?= e((string) ($process['status'] ?? '-')); ?></span><br><small><?= e((string) ($process['readiness_state'] ?? '')); ?></small></td>
                                <td><?= e((string) ($process['dedup_policy'] ?? '-')); ?></td>
                                <td><?= e((string) ($process['notes'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Dedup ve spam qoruma registry</h2>
                <small>Alarm, bot, ticket ve inventory axinleri eyni problem ucun tekrar bilet/mesaj yaratmamalidir.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Dedup key</th><th>Domen</th><th>Basliq</th><th>Status</th><th>Say</th><th>Son gorunme</th></tr></thead>
                    <tbody>
                        <?php foreach ($dedupRows as $dedup): ?>
                            <tr>
                                <td><code><?= e(mb_substr((string) ($dedup['dedup_key'] ?? '-'), 0, 18)); ?>...</code></td>
                                <td><?= e((string) ($dedup['dedup_domain'] ?? '-')); ?></td>
                                <td><?= e((string) ($dedup['title'] ?? '-')); ?></td>
                                <td><span class="portal-pill"><?= e((string) ($dedup['status'] ?? '-')); ?></span></td>
                                <td><?= e((string) ($dedup['seen_count'] ?? '0')); ?></td>
                                <td><?= e((string) ($dedup['last_seen_at'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($dedupRows === []): ?>
                            <tr><td colspan="6">Dedup qeyd yoxdur. Test alarm yaradanda burada gorunecek.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($view === 'actions'): ?>
        <section class="mgs-integration-grid portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Test ve sync emeliyyatlari</h2>
                    <small>Default olaraq dry-run; real Telegram ucun admin `apply` secmelidir.</small>
                </div>
                <div class="mgs-integration-actions">
                    <form method="post" class="portal-stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="sync_bots">
                        <button class="portal-primary-btn" type="submit">Telegram botlari sync et</button>
                    </form>
                    <form method="post" class="portal-stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="seed_learning">
                        <button class="portal-primary-btn" type="submit">Avadanliq oyrenmeni yenile</button>
                    </form>
                    <form method="post" class="portal-stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="sync_process_registry">
                        <button class="portal-primary-btn" type="submit">Proses registry sync et</button>
                    </form>
                    <form method="post" class="portal-stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="set_management_path">
                        <input type="hidden" name="enabled" value="<?= $managementPathReady ? '0' : '1'; ?>">
                        <button class="portal-ghost-btn" type="submit"><?= $managementPathReady ? 'VLAN 550 yolu passiv et' : 'VLAN 550 yolu aktiv et'; ?></button>
                    </form>
                    <form method="post" class="portal-stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="probe_devices">
                        <button class="portal-ghost-btn" type="submit">Cihaz reachable probe et</button>
                    </form>
                    <form method="post" class="portal-stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="record_test_alarm">
                        <button class="portal-ghost-btn" type="submit">Test alarm + TEST mesaj yarat</button>
                    </form>
                    <form method="post" class="portal-stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="rebuild_employee_snapshots">
                        <button class="portal-ghost-btn" type="submit">Isci performans snapshot</button>
                    </form>
                    <form method="post" class="portal-stacked-form">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="run_backup_worker">
                        <select name="backup_type">
                            <option value="profile_snapshot">Profile snapshot</option>
                            <option value="inventory_snapshot">Inventory snapshot</option>
                            <option value="running_config">Running-config (VLAN 550 lazimdir)</option>
                            <option value="startup_config">Startup-config (VLAN 550 lazimdir)</option>
                        </select>
                        <input type="number" name="limit" min="1" max="50" value="5" aria-label="Backup limit">
                        <?php if (app_portal_is_admin($user)): ?>
                            <label class="portal-checkbox"><input type="checkbox" name="apply_backup" value="1"> Backup-i DB-de saxla</label>
                        <?php endif; ?>
                        <button class="portal-ghost-btn" type="submit">Backup worker yoxla</button>
                    </form>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Telegram bot test</h2>
                    <small>Mesaj mutleq TEST MESAJI prefix-i ile gedir.</small>
                </div>
                <form method="post" class="portal-stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="test_bot">
                    <div class="portal-form-row">
                        <label>Bot</label>
                        <select name="bot_key">
                            <?php foreach (app_mgs_telegram_bot_definitions() as $botKey => $definition): ?>
                                <option value="<?= e((string) $botKey); ?>"><?= e((string) $definition['display_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <label>Destination override</label>
                        <input type="text" name="destination" placeholder="bos qalarsa config chat istifade olunur">
                    </div>
                    <div class="portal-form-row">
                        <label>Mesaj</label>
                        <textarea name="message" rows="3" maxlength="640" placeholder="TEST MESAJI avtomatik elave olunur"></textarea>
                    </div>
                    <?php if (app_portal_is_admin($user)): ?>
                        <label class="portal-checkbox"><input type="checkbox" name="apply_test" value="1"> Real TEST MESAJI gonder</label>
                    <?php endif; ?>
                    <button class="portal-primary-btn" type="submit">Botu yoxla</button>
                </form>
                <?php if (is_array($botTestResult)): ?>
                    <code class="mgs-integration-code"><?= e(json_encode($botTestResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></code>
                <?php endif; ?>
                <?php if (is_array($backupWorkerResult)): ?>
                    <code class="mgs-integration-code"><?= e(json_encode($backupWorkerResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></code>
                <?php endif; ?>
            </article>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Inventar hereket testi</h2>
                    <small>Dry-run stok azaltmir; apply yalniz admin ucundur.</small>
                </div>
                <form method="post" class="portal-stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="inventory_test">
                    <div class="portal-form-row portal-grid-slim">
                        <input name="sku" value="TEST-FASTCONNECTOR" placeholder="SKU">
                        <input name="item_name" value="Fast connector SC/APC" placeholder="Mal adi">
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <input name="quantity" type="number" step="0.01" min="0.01" value="1">
                        <select name="movement_type">
                            <option value="issue">Texnike ver</option>
                            <option value="customer_install">Musteriye bagla</option>
                            <option value="return">Geri al</option>
                            <option value="in">Medaxil</option>
                        </select>
                    </div>
                    <div class="portal-form-row">
                        <input name="ticket_no" placeholder="Ticket nomresi optional">
                    </div>
                    <?php if (app_portal_is_admin($user)): ?>
                        <label class="portal-checkbox"><input type="checkbox" name="apply_inventory" value="1"> Stoka real yaz</label>
                    <?php endif; ?>
                    <button class="portal-ghost-btn" type="submit">Inventar axinini yoxla</button>
                </form>
            </article>

            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Backend runtime status</h2>
                    <small>Node MGS offline olsa, native shell runtime error verir, amma yalanci alarm yaratmir.</small>
                </div>
                <div class="mgs-integration-list">
                    <div class="mgs-integration-row">
                        <strong><?= e($bridge['online'] ? 'Online' : 'Offline'); ?></strong>
                        <small><?= e((string) $bridge['message']); ?></small>
                        <span class="portal-pill <?= $bridge['online'] ? 'portal-pill-success' : 'system-health-warning'; ?>"><?= e((string) $bridge['host'] . ':' . (string) $bridge['port']); ?></span>
                    </div>
                    <code class="mgs-integration-code">D:\xampp\htdocs\oltidareetme - npm.cmd run build / npm.cmd start</code>
                </div>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($view === 'bots'): ?>
        <section class="mgs-bot-admin-grid portal-reveal">
            <article class="portal-card" id="telegram-bot-form">
                <div class="portal-card-head">
                    <h2><?= $editingBot !== null ? 'Telegram botunu yenilə' : 'Telegram botu əlavə et'; ?></h2>
                    <small>Custom botlar buradan əlavə olunur və yenilənir. Sistem botları qorunur və silinmir.</small>
                </div>
                <div class="mgs-bot-note">
                    1. Bot tokenini server environment-də saxlayın. 2. Bot açarı, Chat ID və məqsədi bu formada yazın. 3. Registry-ni yeniləyib statusun <strong>ready</strong> olduğunu yoxlayın.
                </div>
                <?php if (app_portal_is_admin($user)): ?>
                    <form class="mgs-bot-form" method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_custom_bot">
                        <label>
                            Bot açarı
                            <input name="custom_bot_key" value="<?= e((string) ($editingBot['bot_key'] ?? '')); ?>" placeholder="meselen: saha_bot" maxlength="48" required <?= $editingBot !== null ? 'readonly' : ''; ?>>
                        </label>
                        <label>
                            Bot adı
                            <input name="custom_display_name" value="<?= e((string) ($editingBot['display_name'] ?? '')); ?>" placeholder="Meselen: Saha Botu" maxlength="120">
                        </label>
                        <div class="mgs-bot-note">
                            Token portalda qəbul edilmir. Əvvəlcə server environment-də
                            <code>TELEGRAM_CUSTOM_&lt;BOT_ACARI&gt;_TOKEN</code> yaradılmalıdır.
                        </div>
                        <label>
                            Chat ID
                            <input name="custom_chat_id" value="<?= e((string) ($editingBot['chat_id'] ?? '')); ?>" placeholder="-100..." maxlength="20" required>
                        </label>
                        <label>
                            Vəzifə / məqsəd
                            <textarea name="custom_purpose" placeholder="Bu bot hansı qrupa və hansı prosesə xidmət edir?"><?= e((string) ($editingBot['purpose'] ?? '')); ?></textarea>
                        </label>
                        <div class="mgs-bot-form-actions">
                            <button class="portal-primary-btn" type="submit"><?= $editingBot !== null ? 'Dəyişiklikləri saxla' : 'Botu saxla'; ?></button>
                            <?php if ($editingBot !== null): ?>
                                <a class="portal-ghost-btn portal-inline-link" href="<?= e($viewHref('bots')); ?>#telegram-bot-form">Yeni bot forması</a>
                            <?php endif; ?>
                            <span class="mgs-bot-note">Token yalnız environment-dən oxunur; DB, audit və HTML-ə yazılmır. Mövcud botu yeniləmək üçün eyni bot açarını yazın.</span>
                        </div>
                    </form>
                    <form class="mgs-bot-delete-form" method="post" data-ui-confirm="Bu custom bot silinsin? Sistem botlari silinmir." data-ui-confirm-title="Custom botu sil">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete_custom_bot">
                        <label>
                            Custom bot sil
                            <input name="bot_key" placeholder="meselen: saha_bot" maxlength="48" required>
                        </label>
                        <button class="portal-ghost-btn" type="submit">Botu sil</button>
                    </form>
                <?php else: ?>
                    <?php billing_render_empty_state('Admin icazəsi lazımdır', 'Bot əlavə etmək və silmək yalnız admin roluna açıqdır.', 'fa-lock'); ?>
                <?php endif; ?>
            </article>

            <article class="portal-card">
                <div class="portal-card-head">
                    <div>
                        <h2>Telegram bot registry</h2>
                        <small>Tokenlər göstərilmir; yalnız config statusu, masked destination və idarə əməliyyatları görünür.</small>
                    </div>
                    <?php if (app_portal_is_admin($user)): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="sync_bots">
                            <button class="portal-ghost-btn" type="submit">Registry-ni yenilə</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Bot</th><th>Məqsəd</th><th>Status</th><th>Destination</th><th>Son test</th><th>Əməliyyat</th></tr></thead>
                        <tbody>
                            <?php foreach ($bots as $bot): ?>
                                <?php
                                $metadata = app_db_json_decode((string) ($bot['metadata_json'] ?? ''), []);
                                $isCustomBot = !empty($metadata['custom']);
                                $botKey = (string) ($bot['bot_key'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e((string) ($bot['display_name'] ?? '-')); ?></strong><br>
                                        <small><?= e($botKey !== '' ? $botKey : '-'); ?></small><br>
                                        <span class="mgs-bot-type<?= $isCustomBot ? '' : ' is-system'; ?>"><?= $isCustomBot ? 'custom' : 'sistem'; ?></span>
                                    </td>
                                    <td><?= e((string) ($bot['purpose'] ?? '-')); ?></td>
                                    <td><span class="portal-pill <?= ((string) ($bot['status'] ?? '') === 'ready') ? 'portal-pill-success' : 'system-health-warning'; ?>"><?= e((string) ($bot['status'] ?? '-')); ?></span></td>
                                    <td><?= e((string) ($bot['destination_masked'] ?? '-')); ?></td>
                                    <td><?= e((string) ($bot['last_test_at'] ?? '-')); ?></td>
                                    <td>
                                        <div class="mgs-bot-actions">
                                            <?php if ($isCustomBot && app_portal_is_admin($user)): ?>
                                                <a class="portal-ghost-btn portal-inline-link" href="<?= e($viewHref('bots') . '&edit_bot=' . rawurlencode($botKey)); ?>#telegram-bot-form">Düzəlt</a>
                                                <form method="post" data-ui-confirm="Bu custom bot silinsin?" data-ui-confirm-title="Custom botu sil">
                                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="delete_custom_bot">
                                                    <input type="hidden" name="bot_key" value="<?= e($botKey); ?>">
                                                    <button class="portal-ghost-btn" type="submit">Sil</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="portal-pill"><?= $isCustomBot ? 'Admin lazımdır' : 'Sistem botu'; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($bots === []): ?><tr><td colspan="6">Bot registry boşdur. Sync bot registry əməliyyatını çalışdırın.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($view === 'devices'): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Avadanliq oyrenme profilleri</h2>
                <small>UI duymeleri bu capability map uzerinden acilmalidir; monitor path yoxdursa offline bilet yaradilmir.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Avadanliq</th><th>IP</th><th>Tip/vendor</th><th>Adapter</th><th>Reachability</th><th>Confidence</th></tr></thead>
                    <tbody>
                        <?php foreach ($devices as $device): ?>
                            <tr>
                                <td><strong><?= e((string) ($device['device_name'] ?? '-')); ?></strong><br><small><?= e((string) ($device['device_key'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($device['ip_address'] ?? '-')); ?></td>
                                <td><?= e((string) ($device['device_type'] ?? '-')); ?> / <?= e((string) ($device['vendor'] ?? '-')); ?> <?= e((string) ($device['model'] ?? '')); ?></td>
                                <td><?= e((string) ($device['adapter_key'] ?? '-')); ?></td>
                                <td><span class="portal-pill <?= ((string) ($device['reachability_state'] ?? '') === 'reachable') ? 'portal-pill-success' : 'system-health-warning'; ?>"><?= e((string) ($device['reachability_state'] ?? '-')); ?></span></td>
                                <td><?= e((string) ($device['confidence_score'] ?? '0')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($view === 'backups'): ?>
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Device backup lifecycle</h2>
                <small>Hash eynidirse yeni config saxlanmir; last_checked_at yenilenir. Running/startup config VLAN 550 yolu olmadan skip edilir.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Avadanliq</th><th>IP</th><th>Tip</th><th>Status</th><th>Olcu</th><th>Diff</th><th>Son yoxlama</th></tr></thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td><strong><?= e((string) ($backup['device_name'] ?? '-')); ?></strong><br><small><?= e((string) ($backup['device_key'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($backup['ip_address'] ?? '-')); ?></td>
                                <td><?= e((string) ($backup['backup_type'] ?? '-')); ?></td>
                                <td><span class="portal-pill <?= ((string) ($backup['status'] ?? '') === 'stored') ? 'portal-pill-success' : 'system-health-warning'; ?>"><?= e((string) ($backup['status'] ?? '-')); ?></span></td>
                                <td><?= e((string) ($backup['size_bytes'] ?? '0')); ?> B</td>
                                <td><?= e((string) ($backup['diff_summary'] ?? '-')); ?></td>
                                <td><?= e((string) ($backup['last_checked_at'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($backups === []): ?><tr><td colspan="7">Backup yoxdur. Backup worker dry-run veya apply ile yoxlanila biler.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($view === 'logs'): ?>
        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Son alarmlar</h2><small>Dedup alarm jurnali.</small></div>
                <div class="mgs-integration-list">
                    <?php foreach ($alarms as $alarm): ?>
                        <div class="mgs-integration-row">
                            <strong><?= e((string) ($alarm['title'] ?? '-')); ?></strong>
                            <small><?= e((string) ($alarm['alarm_type'] ?? '-')); ?> / <?= e((string) ($alarm['object_id'] ?? '-')); ?></small>
                            <span class="portal-pill"><?= e((string) ($alarm['status'] ?? '-')); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($alarms === []): ?><?php billing_render_empty_state('Alarm yoxdur', 'Test alarm duymesi ile axini yoxlaya bilersiniz.', 'fa-bell'); ?><?php endif; ?>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Son emeliyyatlar</h2><small>Ticket, inventory, bot ve learning action jurnali.</small></div>
                <div class="mgs-integration-list">
                    <?php foreach ($operations as $operation): ?>
                        <div class="mgs-integration-row">
                            <strong><?= e((string) ($operation['action_type'] ?? '-')); ?></strong>
                            <small><?= e((string) ($operation['summary'] ?? '-')); ?></small>
                            <span class="portal-pill"><?= e((string) ($operation['status'] ?? '-')); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($operations === []): ?><?php billing_render_empty_state('Emeliyyat yoxdur', 'Sync/test duymeleri action jurnali yaradacaq.', 'fa-list-check'); ?><?php endif; ?>
                </div>
            </article>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head"><h2>Isci nezareti</h2><small>Ticket baglama ve inventar hereketlerine gore bugunku snapshot.</small></div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Emekdas</th><th>Aciq</th><th>Bagli</th><th>Inventar</th><th>Bal</th></tr></thead>
                        <tbody>
                            <?php foreach ($employeeSnapshots as $snapshot): ?>
                                <tr>
                                    <td><?= e((string) ($snapshot['employee_name'] ?? '-')); ?></td>
                                    <td><?= e((string) ($snapshot['open_ticket_count'] ?? '0')); ?></td>
                                    <td><?= e((string) ($snapshot['closed_ticket_count'] ?? '0')); ?></td>
                                    <td><?= e((string) ($snapshot['inventory_issued_count'] ?? '0')); ?></td>
                                    <td><span class="portal-pill"><?= e((string) ($snapshot['performance_score'] ?? '0')); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($employeeSnapshots === []): ?><tr><td colspan="5">Snapshot yoxdur.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head"><h2>Inventar current state</h2><small>Bu cedvel stokun son veziyyetini gosterir.</small></div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>SKU</th><th>Mal</th><th>Kateqoriya</th><th>Qaliq</th></tr></thead>
                        <tbody>
                            <?php foreach ($inventory as $item): ?>
                                <tr>
                                    <td><?= e((string) ($item['sku'] ?? '-')); ?></td>
                                    <td><?= e((string) ($item['item_name'] ?? '-')); ?></td>
                                    <td><?= e((string) ($item['category'] ?? '-')); ?></td>
                                    <td><span class="portal-pill"><?= e((string) ($item['quantity_on_hand'] ?? '0')); ?> <?= e((string) ($item['unit'] ?? '')); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($inventory === []): ?><tr><td colspan="4">Inventar current state bosdur.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
        <?php endif; ?>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <script src="<?= e(app_asset('script/design-system.js')); ?>" defer></script>
</body>
</html>
