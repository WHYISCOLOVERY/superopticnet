<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/mgs-integration-lib.php';
require_once __DIR__ . '/ticket-resolution-lib.php';

app_session_boot();
$lang = app_lang();
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$payload = $token !== '' ? app_ticket_resolution_token_payload($token) : null;
$ticketId = is_array($payload) ? (string) ($payload['ticket_id'] ?? '') : '';
$ticket = $ticketId !== '' ? app_portal_ticket_find($ticketId) : null;
$user = app_portal_current_user();
$resolutionRequestId = (string) ($_POST['resolution_request_id'] ?? bin2hex(random_bytes(16)));
$actorAllowed = is_array($user) && is_array($ticket) && app_ticket_resolution_actor_allowed([
    'assigned_technician_account_id' => (int) ($ticket['assigned_technician_id'] ?? 0),
], is_array($payload) ? $payload : [], $user);
$flash = null;

if (is_array($payload) && $user === null) {
    $_SESSION['portal_login_return_to'] = 'technician-close.php?token=' . rawurlencode($token);
    header('Location: staff-login.php?status=auth_required');
    exit;
}

if ($user !== null && is_array($ticket) && !$actorAllowed) {
    $flash = ['type' => 'error', 'message' => 'Bu bilet yalniz teyin olunmus texnik ve ya admin terefinden yoxlanila biler.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $reason = clean_text((string) ($_POST['reason'] ?? ''));
    $note = clean_text((string) ($_POST['note'] ?? ''));
    $reportedState = clean_text((string) ($_POST['reported_state'] ?? ''));
    $verificationMode = clean_text((string) ($_POST['verification_mode'] ?? 'auto'));

    if (!app_verify_csrf($csrfToken)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($token === '' || !$ticket || !is_array($user)) {
        $flash = ['type' => 'error', 'message' => 'Bilet tapilmadi ve ya link vaxti bitib.'];
    } else {
        $resolution = app_ticket_resolution_request(
            $token,
            $reason,
            $note,
            $reportedState,
            $verificationMode,
            $user,
            $resolutionRequestId
        );
        $success = !empty($resolution['ok']);
        $closed = !empty($resolution['closed']);
        $inventoryFailed = false;
        if ($closed && $reportedState === 'resolved') {
            $inventorySku = clean_text((string) ($_POST['inventory_sku'] ?? ''));
            $inventoryQuantity = (float) ($_POST['inventory_quantity'] ?? 0);
            if ($inventorySku !== '' && $inventoryQuantity > 0) {
                $inventoryResult = app_mgs_record_inventory_movement([
                    'sku' => $inventorySku,
                    'item_name' => clean_text((string) ($_POST['inventory_item_name'] ?? $inventorySku)),
                    'category' => clean_text((string) ($_POST['inventory_category'] ?? 'ticket')),
                    'unit' => clean_text((string) ($_POST['inventory_unit'] ?? 'eded')),
                    'quantity' => $inventoryQuantity,
                    'movement_type' => 'customer_install',
                    'ticket_no' => (string) ($ticket['id'] ?? $ticket['ticket_no'] ?? ''),
                    'customer_account_id' => (int) ($ticket['customer_account_id'] ?? $ticket['customer_id'] ?? 0),
                    'technician_account_id' => (int) ($ticket['assigned_technician_account_id'] ?? $ticket['assigned_technician_id'] ?? 0),
                    'actor_account_id' => (int) ($ticket['assigned_technician_account_id'] ?? $ticket['assigned_technician_id'] ?? 0),
                    'actor_name' => (string) ($ticket['assigned_technician_name'] ?? 'technician'),
                    'note' => 'Ticket baglanarken istifade olunan inventar',
                ], true);
                $inventoryFailed = empty($inventoryResult['ok']);
            }
        }
        $redirectStatus = match (true) {
            !$success => 'technician_ticket_failed',
            $inventoryFailed => 'technician_ticket_inventory_failed',
            $closed => 'technician_ticket_closed',
            (string) ($resolution['status'] ?? '') === 'manual_review_required' => 'technician_ticket_review_required',
            default => 'technician_ticket_still_open',
        };
        header('Location: technician-close.php?token=' . rawurlencode($token) . '&status=' . $redirectStatus . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
        exit;
    }
}

$status = (string) ($_GET['status'] ?? '');
if ($status === 'technician_ticket_closed') {
    $flash = ['type' => 'success', 'message' => 'Bilet ugurla baglandi.'];
} elseif ($status === 'technician_ticket_closed_inventory_failed') {
    $flash = ['type' => 'error', 'message' => 'Bilet baglandi, amma inventar stokdan azalmadi. Stok qaliqini anbardar yoxlamalidir.'];
} elseif ($status === 'technician_ticket_inventory_failed') {
    $flash = ['type' => 'error', 'message' => 'Yoxlama qeyde alindi, amma inventar stokdan azalmadi. Anbardar stok qaligini yoxlamalidir.'];
} elseif ($status === 'technician_ticket_review_required') {
    $flash = ['type' => 'error', 'message' => 'Avtomatik yoxlama bu problem novune tetbiq edilmir. Bilet aciq saxlanildi ve mesul sexse yoxlama xeberdarligi gonderildi.'];
} elseif ($status === 'technician_ticket_still_open') {
    $flash = ['type' => 'error', 'message' => 'Problem hell olunmus kimi tesdiqlenmedi. Bilet aciq saxlanildi ve tekrar yoxlama novbesine qaytarildi.'];
} elseif ($status === 'technician_ticket_failed') {
    $flash = ['type' => 'error', 'message' => 'Baglanma sorgusu qeyde alinmadi. Melumatlari ve icazenizi yoxlayin.'];
}

$closeReasons = app_portal_ticket_close_reasons();
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bileti yoxla ve bagla</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <main class="portal-layout portal-close-layout">
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h1>Bileti yoxla ve bagla</h1>
                <small>Texnik qeydi, avtomatik olculer ve yekun netice CRM-de saxlanilacaq.</small>
            </div>

            <?php if ($flash !== null): ?>
                <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                    <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?= e($flash['message']); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$ticket): ?>
                <div class="portal-empty-state">
                    <strong>Link etibarsizdir</strong>
                    <p>Bilet tapilmadi, linkin vaxti bitib ve ya bilet artiq baglanib.</p>
                </div>
            <?php else: ?>
                <div class="portal-ticket-summary-card">
                    <div>
                        <strong><?= e((string) ($ticket['id'] ?? '')); ?> - <?= e((string) ($ticket['subject'] ?? '')); ?></strong>
                        <p><?= e((string) ($ticket['customer_name'] ?? '')); ?> / <?= e((string) ($ticket['phone'] ?? '')); ?></p>
                    </div>
                    <div class="portal-ticket-details">
                        <span><strong>Erazi:</strong> <?= e((string) ($ticket['area'] ?? '')); ?></span>
                        <span><strong>Texnik:</strong> <?= e((string) ($ticket['assigned_technician_name'] ?? '')); ?></span>
                        <span><strong>Acilma:</strong> <?= e((string) ($ticket['opened_at'] ?? '')); ?></span>
                        <span><strong>Son yoxlama:</strong> <?= e((string) ($ticket['resolution_verification_status'] ?? 'none')); ?></span>
                        <span><strong>Cehd:</strong> <?= e((string) ($ticket['verification_attempts'] ?? 0)); ?></span>
                    </div>
                </div>

                <?php if ((string) ($ticket['status'] ?? '') === 'Baglandi'): ?>
                    <div class="portal-empty-state">
                        <strong>Bu bilet artiq baglanib.</strong>
                        <p><?= e((string) ($ticket['resolution_reason'] ?? '')); ?> / <?= e((string) ($ticket['resolution_note'] ?? '')); ?></p>
                    </div>
                <?php elseif (!$actorAllowed): ?>
                    <div class="portal-empty-state">
                        <strong>Bu bilet sizin uzre deyil</strong>
                        <p>Baglanma yoxlamasini yalniz teyin olunmus texnik ve ya admin icra ede biler.</p>
                    </div>
                <?php else: ?>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="token" value="<?= e($token); ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="resolution_request_id" value="<?= e($resolutionRequestId); ?>">
                        <div class="portal-form-row">
                            <label for="reason">Baglanma sebebi</label>
                            <select id="reason" name="reason" required>
                                <option value="">Sebeb secin</option>
                                <?php foreach ($closeReasons as $reason): ?>
                                    <option value="<?= e($reason); ?>"><?= e($reason); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <label for="note">Gorulen is</label>
                            <textarea id="note" name="note" rows="5" placeholder="Meselen: Fastconnector deyisdirildi, signal normallasdi." required></textarea>
                        </div>
                        <div class="portal-form-row">
                            <label for="reported_state">Cari netice</label>
                            <select id="reported_state" name="reported_state" required>
                                <option value="">Neticeni secin</option>
                                <option value="resolved">Problem hell olunub</option>
                                <option value="unresolved">Problem davam edir</option>
                            </select>
                            <small>ONT temperaturu ve signal telemetriyasi, xidmet statusu ve RADIUS sessiyasi problem novune uygun avtomatik yoxlanilir.</small>
                        </div>
                        <?php if (is_array($user) && app_portal_is_admin($user)): ?>
                            <input type="hidden" name="verification_mode" value="manual_override">
                            <p class="hint">Admin olaraq bileti birbasa baglaya bilersiniz, avtomatik telemetriya yoxlamasi teleb olunmur.</p>
                        <?php else: ?>
                            <input type="hidden" name="verification_mode" value="auto">
                        <?php endif; ?>
                        <div class="portal-form-row">
                            <label>Istifade olunan inventar (optional)</label>
                            <div class="portal-grid-slim">
                                <input type="text" name="inventory_sku" placeholder="SKU / barkod">
                                <input type="text" name="inventory_item_name" placeholder="Mal adi">
                            </div>
                        </div>
                        <div class="portal-form-row">
                            <div class="portal-grid-slim">
                                <input type="number" name="inventory_quantity" min="0" step="0.01" placeholder="Say">
                                <input type="text" name="inventory_unit" value="eded" placeholder="Vahid">
                            </div>
                            <small>SKU ve say yazilsa, bilet baglananda anbar qaliqindan avtomatik cixilacaq.</small>
                        </div>
                        <button type="submit" class="portal-primary-btn">Yoxla ve baglanma sorgusu yarat</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
