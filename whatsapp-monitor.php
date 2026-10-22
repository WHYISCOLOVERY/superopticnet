<?php
declare(strict_types=1);

require_once __DIR__ . '/whatsapp-monitor-lib.php';

$lang = app_lang();
$user = billing_require_employee(false, 'whatsapp_monitor');
$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    echo 'Database unavailable';
    exit;
}
whatsapp_monitor_ensure_schema($db);

$flash = null;
$allowedViews = array_keys(whatsapp_monitor_tabs());
$view = in_array((string) ($_GET['view'] ?? 'overview'), $allowedViews, true)
    ? (string) ($_GET['view'] ?? 'overview')
    : 'overview';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = clean_text((string) ($_POST['action'] ?? ''));
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } else {
        $result = ['ok' => false, 'error' => 'unknown_action'];
        if ($action === 'save_number') {
            $result = whatsapp_monitor_save_number($db, $_POST, $user);
            $view = 'numbers';
        } elseif ($action === 'return_number') {
            $result = whatsapp_monitor_return_number($db, (int) ($_POST['number_id'] ?? 0), $user);
            $view = 'numbers';
        } elseif ($action === 'start_pairing') {
            $result = whatsapp_monitor_start_pairing($db, (int) ($_POST['number_id'] ?? 0), (int) ($_POST['device_id'] ?? 0), $user);
            $view = 'pairing';
        } elseif ($action === 'revoke_pairing') {
            $result = whatsapp_monitor_revoke_pairing($db, (int) ($_POST['number_id'] ?? 0), $user);
            $view = 'pairing';
        } elseif ($action === 'save_device') {
            $result = whatsapp_monitor_save_device($db, $_POST, $user);
            $view = 'devices';
        } elseif ($action === 'disable_device') {
            $result = whatsapp_monitor_disable_device($db, (int) ($_POST['device_id'] ?? 0), $user);
            $view = 'devices';
        } elseif ($action === 'create_case') {
            $result = whatsapp_monitor_create_case($db, $_POST, $user);
            $view = 'cases';
        } elseif ($action === 'link_customer') {
            $result = whatsapp_monitor_link_customer($db, (int) ($_POST['case_id'] ?? 0), (string) ($_POST['customer_query'] ?? ''), $user);
            $view = 'cases';
        } elseif ($action === 'case_status') {
            $result = whatsapp_monitor_update_case_status($db, (int) ($_POST['case_id'] ?? 0), clean_text((string) ($_POST['case_status'] ?? '')), $user);
            $view = 'cases';
        } elseif ($action === 'create_ticket') {
            $result = whatsapp_monitor_create_ticket($db, (int) ($_POST['case_id'] ?? 0), $user);
            $view = 'cases';
        } elseif ($action === 'save_settings') {
            $result = whatsapp_monitor_save_settings($db, $_POST, $user);
            $view = 'settings';
        }

        $flash = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => !empty($result['ok'])
                ? 'WhatsApp Monitor emeliyyati saxlanildi.'
                : 'Emeliyyat alinmadi: ' . (string) ($result['error'] ?? 'namelum_xeta'),
        ];
        if (!empty($result['ticket_no'])) {
            $flash['message'] .= ' Ticket: ' . (string) $result['ticket_no'];
        }
    }
}

$context = whatsapp_monitor_context($db, $user, $view, $lang);

?><!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WhatsApp Monitorinq - SuperOptik</title>
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <link rel="stylesheet" href="<?= e(app_asset('css/whatsapp-monitor.css')); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="portal-body">
    <?php billing_render_header('WhatsApp Monitorinq', 'messages', $lang); ?>
    <main class="portal-layout whatsapp-monitor-layout" data-whatsapp-monitor>
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">CRM / WhatsApp Monitorinq</span>
                <h1>Sirket WhatsApp nomreleri ve cihaz monitorinqi</h1>
                <p>Bu modul mesaj gondermir ve isci yerine cavab yazmir. Yalniz sirket nomreleri, sirket cihazlari, cavabsiz mesaj case-leri ve CRM ticket elaqesi ucun auditli monitorinq aparir.</p>
            </div>
            <div class="portal-status-card">
                <span>Rejim</span>
                <strong><?= !empty($context['can_manage']) ? 'Super admin' : (!empty($context['can_view_all']) ? 'Monitorinq' : 'Oz nomrelerim'); ?></strong>
                <small>Stream URL DB-de metadata kimi saxlanir, video DB-ye yazilmir.</small>
            </div>
        </section>

        <?php billing_render_workflow_guide('WhatsApp nomresini nezarete gotur', [
            ['title' => 'Nomreni yarat', 'text' => 'Sirket nomresi emekdasa baglanir ve monitorinq raziligi qeyd olunur.', 'icon' => 'fa-brands fa-whatsapp'],
            ['title' => 'QR oxut', 'text' => 'Her nomre ayrica QR sessiyasi ile qosulur. QR oxunmadan connected sayilmir.', 'icon' => 'fa-qrcode'],
            ['title' => 'Case ve ticket ac', 'text' => 'Cavabsiz mesaj varsa case yaradilir, musteri tapilanda CRM ticket-e cevrilir.', 'icon' => 'fa-ticket'],
        ], 'Bu axin isci adindan mesaj yazmir; yalniz sirket nomresinin veziyyetini ve cavabsiz muracietleri izleyir.'); ?>

        <?php require __DIR__ . '/views/whatsapp-monitor/module.php'; ?>
    </main>
    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <script src="<?= e(app_asset('script/whatsapp-monitor.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
