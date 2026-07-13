<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/mgs-integration-lib.php';

app_session_boot();
$lang = app_lang();
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$secret = app_config('portal_ticket_close_secret', app_config('olt_sso_shared_secret', 'superoptic-ticket-secret'));
$payload = $token !== '' ? app_portal_verify_token($token, $secret) : null;
$ticketId = is_array($payload) ? (string) ($payload['ticket_id'] ?? '') : '';
$ticket = $ticketId !== '' ? app_portal_ticket_find($ticketId) : null;
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $reason = clean_text((string) ($_POST['reason'] ?? ''));
    $note = clean_text((string) ($_POST['note'] ?? ''));

    if (!app_verify_csrf($csrfToken)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif ($token === '' || !$ticket) {
        $flash = ['type' => 'error', 'message' => 'Bilet tapilmadi ve ya link vaxti bitib.'];
    } else {
        $success = app_portal_close_ticket_by_token($token, $reason, $note);
        $inventoryFailed = false;
        if ($success) {
            app_mgs_record_ticket_close($ticket, $reason, $note);
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
            app_mgs_rebuild_employee_work_snapshots();
        }
        $redirectStatus = $success ? ($inventoryFailed ? 'technician_ticket_closed_inventory_failed' : 'technician_ticket_closed') : 'technician_ticket_failed';
        header('Location: technician-close.php?token=' . rawurlencode($token) . '&status=' . $redirectStatus . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
        exit;
    }
}

$status = (string) ($_GET['status'] ?? '');
if ($status === 'technician_ticket_closed') {
    $flash = ['type' => 'success', 'message' => 'Bilet ugurla baglandi.'];
} elseif ($status === 'technician_ticket_closed_inventory_failed') {
    $flash = ['type' => 'error', 'message' => 'Bilet baglandi, amma inventar stokdan azalmadi. Stok qaliqini anbardar yoxlamalidir.'];
} elseif ($status === 'technician_ticket_failed') {
    $flash = ['type' => 'error', 'message' => 'Bilet baglanmadi.'];
}

$closeReasons = app_portal_ticket_close_reasons();
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bileti bagla</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <main class="portal-layout portal-close-layout">
        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h1>Bileti bagla</h1>
                <small>Texnik qeydi CRM ve musteri paneline yazilacaq.</small>
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
                    </div>
                </div>

                <?php if ((string) ($ticket['status'] ?? '') === 'Baglandi'): ?>
                    <div class="portal-empty-state">
                        <strong>Bu bilet artiq baglanib.</strong>
                        <p><?= e((string) ($ticket['resolution_reason'] ?? '')); ?> / <?= e((string) ($ticket['resolution_note'] ?? '')); ?></p>
                    </div>
                <?php else: ?>
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="token" value="<?= e($token); ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
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
                        <button type="submit" class="portal-primary-btn">Bileti bagla</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
</body>
</html>
