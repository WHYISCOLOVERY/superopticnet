<?php
require_once __DIR__ . '/../config.php';
$db = app_db();

// Check inventory_work_order_materials
echo "=== inventory_work_order_materials ===\n";
try {
    $r = $db->query('DESCRIBE inventory_work_order_materials');
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Also check billing_payments columns we use in manager dashboard
echo "\n=== billing_payments (relevant cols) ===\n";
try {
    $r = $db->query('DESCRIBE billing_payments');
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . ' | ' . $row['Type'] . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Check portal_tickets columns
echo "\n=== portal_tickets (relevant cols) ===\n";
try {
    $r = $db->query('DESCRIBE portal_tickets');
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . ' | ' . $row['Type'] . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
