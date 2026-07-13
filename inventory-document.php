<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/inventory-operations-lib.php';

$user = billing_require_employee();
if (!app_portal_can_access_module($user, 'inventory') && !app_portal_is_admin($user)) {
    http_response_code(403);
    exit('Forbidden');
}

$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('Anbar bazasi aktiv deyil');
}

$document = app_inventory_document($db, max(0, (int) ($_GET['id'] ?? 0)));
if (!$document) {
    http_response_code(404);
    exit('Sened tapilmadi');
}

$company = (string) (app_config('portal_company_name') ?: 'Super Optik');
$documentType = (string) ($document['document_type'] ?? '');
$titles = [
    'intake' => 'Mal medaxil akti',
    'branch_transfer' => 'Filial transfer akti',
    'location_transfer' => 'Lokasiya transfer akti',
    'handover' => 'Tehvil-teslim akti',
    'external_return' => 'Tehvil-teslim qaytarma akti',
];
$statusLabels = [
    'draft' => 'Qaralama',
    'pending' => 'Tesdiq gozleyir',
    'approved' => 'Tesdiqlenib',
    'rejected' => 'Imtina edilib',
];
$reasonLabels = [
    'unneeded' => 'Lazim deyil',
    'defective' => 'Xarabdir',
    'wrong_delivery' => 'Yanlis teslim',
    'other' => 'Diger',
];
$dispositionLabels = [
    'return_to_supplier' => 'Tedarukcuya qaytarma',
    'external_transfer' => 'Xarici transfer',
    'writeoff' => 'Silinme',
];
$locationLabels = [
    'warehouse' => 'Anbar',
    'branch' => 'Filial',
    'employee' => 'Emekdas',
    'external' => 'Xarici teref',
    'company' => 'Sirket',
    'institution' => 'Qurum',
    'person' => 'Sexs',
];
$locationName = static function (?string $type, ?string $id, ?string $name = null) use ($locationLabels): string {
    $type = trim((string) $type);
    $label = $locationLabels[$type] ?? ($type !== '' ? $type : '-');
    $value = trim((string) ($name ?: $id ?: ''));
    return $value !== '' ? $label . ': ' . $value : $label;
};
$title = $titles[$documentType] ?? 'Tehvil-teslim akti';
$status = (string) ($document['status'] ?? '');
?>
<!doctype html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e((string) $document['document_no']); ?></title>
    <style>
        *{box-sizing:border-box}
        body{font-family:Arial,sans-serif;color:#14243a;margin:28px;background:#fff}
        .actions{display:flex;justify-content:flex-end;margin-bottom:18px}
        .actions button{border:0;border-radius:8px;background:#0f2a4a;color:#fff;font-weight:700;padding:10px 18px;cursor:pointer}
        .sheet{max-width:1080px;margin:0 auto}
        .head{text-align:center;border-bottom:2px solid #14243a;padding-bottom:14px;margin-bottom:18px}
        .head h1{margin:0;font-size:28px;letter-spacing:.04em}
        .head h2{margin:10px 0 6px;font-size:20px;text-transform:uppercase}
        .head strong{display:inline-block;margin-top:4px;font-size:16px}
        .status-line{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:10px}
        .pill{display:inline-block;border:1px solid #9caaba;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:700}
        .meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:18px 0}
        .meta div{border:1px solid #ccd5df;padding:10px;min-height:46px}
        .meta b{display:block;font-size:12px;text-transform:uppercase;color:#52637a;margin-bottom:4px}
        table{width:100%;border-collapse:collapse;margin-top:18px}
        th,td{border:1px solid #9caaba;padding:9px;text-align:left;overflow-wrap:anywhere;vertical-align:top}
        th{background:#edf2f7;font-size:12px;text-transform:uppercase}
        td:first-child,th:first-child{text-align:center;width:58px}
        .note{margin-top:14px;border:1px solid #ccd5df;padding:10px;min-height:52px}
        .sign{display:grid;grid-template-columns:1fr 1fr;gap:90px;margin-top:70px}
        .sign-box{display:grid;gap:24px}
        .line{border-top:1px solid #111;padding-top:8px;text-align:center;font-weight:700}
        .manual{display:grid;grid-template-columns:80px 1fr;gap:8px;color:#52637a;font-size:13px}
        @media print{.actions{display:none}body{margin:12mm}.sheet{max-width:none}}
        @media(max-width:720px){body{margin:12px}.meta,.sign{grid-template-columns:1fr}.sign{gap:54px}table{font-size:12px}.head h1{font-size:22px}}
    </style>
</head>
<body>
<div class="sheet">
    <div class="actions"><button type="button" onclick="print()">Cap et</button></div>
    <header class="head">
        <h1><?= e($company); ?></h1>
        <h2><?= e($title); ?></h2>
        <strong><?= e((string) $document['document_no']); ?></strong>
        <div class="status-line">
            <span class="pill"><?= e($statusLabels[$status] ?? $status ?: '-'); ?></span>
            <?php if ($documentType === 'external_return'): ?>
                <span class="pill"><?= e($dispositionLabels[(string) ($document['disposition'] ?? '')] ?? (string) ($document['disposition'] ?? '-')); ?></span>
            <?php endif; ?>
        </div>
    </header>

    <section class="meta" aria-label="Sened melumatlari">
        <div><b>Tarix</b><?= e((string) ($document['created_at'] ?? '-')); ?></div>
        <div><b>Status</b><?= e($statusLabels[$status] ?? $status ?: '-'); ?></div>
        <div><b>Kimden</b><?= e($locationName((string) ($document['source_location_type'] ?? ''), (string) ($document['source_location_id'] ?? ''))); ?></div>
        <div><b>Kime</b><?= e($locationName((string) ($document['destination_type'] ?? ''), (string) ($document['destination_id'] ?? ''), (string) ($document['destination_name'] ?? ''))); ?></div>
        <div><b>Yaradan</b><?= e((string) ($document['created_by_name'] ?? '-')); ?></div>
        <div><b>Tesdiqleyen</b><?= e((string) ($document['approved_by_name'] ?? '-')); ?><?= !empty($document['approved_at']) ? ' / ' . e((string) $document['approved_at']) : ''; ?></div>
        <?php if ($documentType === 'external_return'): ?>
            <div><b>Qaytarma sebebi</b><?= e($reasonLabels[(string) ($document['reason'] ?? '')] ?? (string) ($document['reason'] ?? '-')); ?></div>
            <div><b>Emeliyyat novu</b><?= e($dispositionLabels[(string) ($document['disposition'] ?? '')] ?? (string) ($document['disposition'] ?? '-')); ?></div>
        <?php endif; ?>
    </section>

    <table>
        <thead>
        <tr>
            <th>Sira</th>
            <th>Malin adi</th>
            <th>Miqdar</th>
            <th>MAC / SN</th>
            <th>Olcu vahidi</th>
            <th>Qiymet</th>
            <th>Qeyd</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ((array) $document['items'] as $item): ?>
            <tr>
                <td><?= e((string) ($item['line_no'] ?? '-')); ?></td>
                <td><?= e((string) ($item['item_name'] ?? '-')); ?></td>
                <td><?= e((string) ($item['quantity'] ?? '0')); ?></td>
                <td><?= e(trim((string) ($item['mac_address'] ?? '') . ' / ' . (string) ($item['serial_number'] ?? ''), ' /') ?: '-'); ?></td>
                <td><?= e((string) ($item['unit'] ?? 'eded')); ?></td>
                <td><?= e(billing_money($item['unit_price'] ?? 0)); ?></td>
                <td><?= e((string) ($item['note'] ?? '-')); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="note"><b>Qeyd:</b> <?= e((string) ($document['note'] ?? '')); ?></div>

    <div class="sign">
        <div class="sign-box">
            <div class="manual"><span>Ad/Soyad</span><span></span></div>
            <div class="manual"><span>Imza</span><span></span></div>
            <div class="line">Tehvil veren</div>
        </div>
        <div class="sign-box">
            <div class="manual"><span>Ad/Soyad</span><span></span></div>
            <div class="manual"><span>Imza</span><span></span></div>
            <div class="line">Tehvil alan</div>
        </div>
    </div>
</div>
</body>
</html>
