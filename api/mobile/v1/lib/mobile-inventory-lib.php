<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/inventory-operations-lib.php';

function app_mobile_inventory_identity(PDO $db, array $user): array
{
    // Existing warehouse handovers used full_name as the legacy location key.
    // Accept it only when it identifies exactly one account.
    $name = trim((string)($user['full_name'] ?? ''));
    $user['inventory_legacy_name'] = (string)($user['login'] ?? '');
    if ($name !== '') {
        $stmt = $db->prepare('SELECT id FROM portal_accounts WHERE full_name = ?');
        $stmt->execute([$name]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) === 1 && (int)$ids[0] === (int)$user['id']) $user['inventory_legacy_name'] = $name;
    }
    return $user;
}

function app_mobile_inventory_owned(array $row, array $user, string $typeField, string $idField): bool
{
    $type = (string)($row[$typeField] ?? '');
    $owner = (string)($row[$idField] ?? '');
    return ($type === 'employee' && $owner === (string)$user['id'])
        || ($type === 'technician' && $owner !== '' && in_array($owner, [(string)($user['login'] ?? ''), (string)($user['inventory_legacy_name'] ?? '')], true));
}

function app_mobile_inventory_options(PDO $db, array $user): array
{
    $user = app_mobile_inventory_identity($db, $user);
    $owners = [(string)$user['id'], (string)($user['login'] ?? ''), $user['inventory_legacy_name']];
    $assets = $db->prepare('SELECT id, asset_code, product_id, serial_number, mac_address FROM inventory_assets WHERE ((current_location_type = "employee" AND current_location_id = ?) OR (current_location_type = "technician" AND current_location_id IN (?, ?))) AND lifecycle_status <> "installed" AND condition_status NOT IN ("damaged","faulty","scrapped") ORDER BY asset_code');
    $assets->execute($owners);
    $drums = $db->prepare('SELECT id, drum_number, remaining_length_meters - reserved_length_meters AS available_meters FROM inventory_cable_drums WHERE ((location_type = "employee" AND location_id = ?) OR (location_type = "technician" AND location_id IN (?, ?))) AND remaining_length_meters > reserved_length_meters ORDER BY drum_number');
    $drums->execute($owners);
    $stock = $db->prepare('SELECT b.item_id AS id, i.item_name, i.unit, b.available_quantity FROM inventory_location_balances b JOIN inventory_stock_items i ON i.id = b.item_id WHERE b.location_type = "employee" AND b.location_id = ? AND b.available_quantity > 0 AND NOT EXISTS (SELECT 1 FROM inventory_assets a WHERE a.product_id = i.id) ORDER BY i.item_name');
    $stock->execute([(string)$user['id']]);
    return ['assets' => $assets->fetchAll(PDO::FETCH_ASSOC), 'drums' => $drums->fetchAll(PDO::FETCH_ASSOC), 'stock' => $stock->fetchAll(PDO::FETCH_ASSOC)];
}

/** Caller owns the ticket/outbox transaction. Every failure must roll it back. */
function app_mobile_inventory_consume(PDO $db, array $user, array $ticket, array $payload): void
{
    if (!$db->inTransaction()) throw new LogicException('Inventory consumption requires a transaction.');
    $materials = $payload['materials_used'] ?? [];
    $assetId = (int)($payload['equipment']['asset_id'] ?? 0);
    if (!is_array($materials)) throw new InvalidArgumentException('Material siyahısı düzgün deyil.');
    if ($materials === [] && $assetId === 0) return;
    $customer = trim((string)($ticket['subscriber_code'] ?? ''));
    if ($customer === '') throw new InvalidArgumentException('Material sərfi üçün biletə müştəri kodu bağlanmalıdır.');
    $ticketNo = (string)$ticket['ticket_no'];
    $tech = (string)$user['login'];
    $user = app_mobile_inventory_identity($db, $user);
    foreach ($materials as $index => $material) {
        $qty = filter_var($material['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($qty === false || !is_finite($qty) || $qty <= 0) throw new InvalidArgumentException('Material miqdarı düzgün deyil.');
        $drumId = (int)($material['cable_drum_id'] ?? 0);
        $productId = (int)($material['item_id'] ?? 0);
        if ($drumId > 0) {
            $stmt = $db->prepare('SELECT * FROM inventory_cable_drums WHERE id = ? FOR UPDATE');
            $stmt->execute([$drumId]);
            $drum = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$drum || !app_mobile_inventory_owned($drum, $user, 'location_type', 'location_id')) throw new InvalidArgumentException('Seçilmiş baraban sizin adınızda deyil.');
            if ($qty > (float)$drum['remaining_length_meters'] - (float)$drum['reserved_length_meters']) throw new InvalidArgumentException('Barabanda kifayət qədər sərbəst kabel yoxdur.');
            $db->prepare('UPDATE inventory_cable_drums SET remaining_length_meters = remaining_length_meters - ? WHERE id = ?')->execute([$qty, $drumId]);
            $productId = (int)$drum['product_id'];
            $movementId = app_mgs_inventory_record_lifecycle_movement($db, [
                'movement_type' => 'customer_install', 'product_id' => $productId,
                'cable_drum_id' => $drumId, 'length_meters' => $qty,
                'from_location_type' => $drum['location_type'], 'from_location_id' => $drum['location_id'],
                'to_location_type' => 'customer', 'to_location_id' => $customer,
                'customer_code' => $customer, 'technician_code' => $tech, 'ticket_no' => $ticketNo,
                'performed_by' => $tech, 'reason' => 'mobile_ticket_resolution',
                'idempotency_key' => 'mobile-material:' . $payload['client_uuid'] . ':' . $index,
            ]);
            $unit = 'metr';
        } else {
            if ($productId <= 0) throw new InvalidArgumentException('İstifadə olunan materialı adınızdakı qalıqdan seçin.');
            $serialized = $db->prepare('SELECT id FROM inventory_assets WHERE product_id = ? LIMIT 1');
            $serialized->execute([$productId]);
            if ($serialized->fetchColumn()) throw new InvalidArgumentException('Seriyalı cihazı ONT / router siyahısından seçin.');
            $result = app_inventory_consume_employee_stock($db, [
                'item_id' => $productId, 'employee_id' => (int)$user['id'], 'quantity' => $qty,
                'customer_code' => $customer, 'ticket_no' => $ticketNo, 'note' => 'Mobil bilet təhvili',
            ], $user);
            if (empty($result['ok'])) throw new InvalidArgumentException('Material qalığı kifayət etmir və ya sərfiyyat qeydə alınmadı.');
            $unitStmt = $db->prepare('SELECT unit FROM inventory_stock_items WHERE id = ?');
            $unitStmt->execute([$productId]);
            $unit = (string)$unitStmt->fetchColumn();
            $movementId = null;
        }
        $db->prepare('INSERT INTO inventory_work_order_materials (ticket_no, customer_code, technician_code, product_id, cable_drum_id, quantity, length_meters, unit, movement_id, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $ticketNo, $customer, $tech, $productId ?: null, $drumId ?: null,
            $drumId > 0 ? null : $qty, $drumId > 0 ? $qty : null, $unit, $movementId, app_db_json_encode($material),
        ]);
    }
    if ($assetId > 0) {
        $stmt = $db->prepare('SELECT * FROM inventory_assets WHERE id = ? FOR UPDATE');
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset || !app_mobile_inventory_owned($asset, $user, 'current_location_type', 'current_location_id')) throw new InvalidArgumentException('Seçilmiş cihaz sizin adınızda deyil.');
        if (in_array($asset['condition_status'], ['damaged', 'faulty', 'scrapped'], true)) throw new InvalidArgumentException('Bu cihaz quraşdırma üçün yararlı deyil.');
        $balance = $db->prepare('SELECT id FROM inventory_location_balances WHERE item_id = ? AND location_type = "employee" AND location_id = ? FOR UPDATE');
        $balance->execute([(int)$asset['product_id'], (string)$user['id']]);
        $employeeStock = $asset['current_location_type'] === 'employee' || $balance->fetchColumn() !== false;
        if ($employeeStock) {
            $consumed = app_inventory_consume_employee_stock($db, [
                'item_id' => (int)$asset['product_id'], 'employee_id' => (int)$user['id'], 'quantity' => 1,
                'customer_code' => $customer, 'ticket_no' => $ticketNo,
                'serial_number' => $asset['serial_number'], 'mac_address' => $asset['mac_address'],
            ], $user);
            if (empty($consumed['ok'])) throw new InvalidArgumentException('Cihazın anbar qalığı uyğun deyil. Anbardar yoxlamalıdır.');
        }
        app_mgs_inventory_install_asset($db, $assetId, $customer, $tech, $ticketNo, 'company', !$employeeStock);
        if ($employeeStock) {
            // Quantity was already posted by the canonical consumption service.
            // Keep a serial-specific lifecycle reference without counting it twice.
            app_mgs_inventory_record_lifecycle_movement($db, [
                'movement_type' => 'asset_assignment_reference', 'asset_id' => $assetId,
                'product_id' => (int)$asset['product_id'],
                'from_location_type' => 'employee', 'from_location_id' => (string)$user['id'],
                'to_location_type' => 'customer', 'to_location_id' => $customer,
                'customer_code' => $customer, 'technician_code' => $tech, 'ticket_no' => $ticketNo,
                'performed_by' => $tech, 'idempotency_key' => 'mobile-asset:' . $payload['client_uuid'],
            ]);
        }
    }
}
