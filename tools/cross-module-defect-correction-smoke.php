<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$billingPayments = (string) file_get_contents($root . '/billing-payments.php');
$portalJs = (string) file_get_contents($root . '/script/portal.js');
$returnsInspection = (string) file_get_contents($root . '/views/inventory/returns-inspection.php');
$whatsappCss = (string) file_get_contents($root . '/css/whatsapp-monitor.css');
$hrFinanceBridgeSmoke = (string) file_get_contents($root . '/tools/hr-finance-bridge-smoke.php');
$errors = [];

$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

// Billing provider CSV import: CSRF + MIME/extension + size hardening.
foreach ([
    "if (!app_verify_csrf(is_string(\$csrfToken) ? \$csrfToken : null)) {",
    '$uploadSize > $providerCsvMaxBytes',
    "\$uploadExtension !== 'csv'",
    'in_array($uploadMime, $providerCsvAllowedMime, true)',
    'name="csrf_token" value="<?= e(app_csrf_token()); ?>"',
] as $marker) {
    $expect(str_contains($billingPayments, $marker), 'billing_csv_import_guard_missing:' . $marker);
}

// Billing payment export/print toolbar must be wired, not dead buttons.
foreach ([
    'id="billing-payments-table"',
    'data-table-toolbar data-target-table="billing-payments-table"',
    'data-table-action="copy"',
    'data-table-action="excel"',
    'data-table-action="csv"',
    'data-table-action="print"',
] as $marker) {
    $expect(str_contains($billingPayments, $marker), 'billing_export_toolbar_marker_missing:' . $marker);
}
foreach ([
    "actionButton = event.target.closest('[data-table-action]')",
    "action === 'print'",
    "action === 'copy'",
    "navigator.clipboard?.writeText",
    "action === 'csv' || action === 'excel'",
] as $marker) {
    $expect(str_contains($portalJs, $marker), 'portal_js_table_export_marker_missing:' . $marker);
}

// Inventory serialized return + inspection: Stage 1 service exists but had no UI entry point.
foreach ([
    'action" value="receive_inventory_asset_return"',
    'action" value="inspect_inventory_asset_return"',
    "\$canWrite=!empty(\$inventoryContext['data']['can_write'])",
    'name="inspection_status" required',
    'value="repair">Temire gonder',
    'value="scrap">Yararsiz - silinsin',
    'name="idempotency_key"',
] as $marker) {
    $expect(str_contains($returnsInspection, $marker), 'inventory_return_inspection_ui_marker_missing:' . $marker);
}
// Write actions must stay behind the existing can_write gate (warehouse/admin only), not shown to read-only roles.
$expect(str_contains($returnsInspection, "if(\$view==='returns'&&\$canWrite):"), 'inventory_new_return_form_not_gated_by_can_write');
$expect(str_contains($returnsInspection, "if(\$canWrite&&(string)(\$row['return_status']??'')==='received')"), 'inventory_inspect_action_not_gated_by_can_write');

// WhatsApp monitor: multi-line message text must preserve line breaks (was collapsing via default white-space).
$expect(str_contains($whatsappCss, '.wa-message > div') && str_contains($whatsappCss, 'white-space: pre-wrap'), 'whatsapp_message_line_break_style_missing');

// HR -> Finance bridge smoke test must send the mandatory reason argument the service requires.
$expect(
    str_contains($hrFinanceBridgeSmoke, "'employee_role' => 'hr'")
        && str_contains($hrFinanceBridgeSmoke, "'approved', \$hrActor, \$db, 'Smoke test approval'"),
    'hr_finance_bridge_smoke_missing_reason_on_approve'
);
$expect(
    str_contains($hrFinanceBridgeSmoke, "'paid', \$hrActor, \$db, 'Smoke test payout'"),
    'hr_finance_bridge_smoke_missing_reason_on_payout'
);

foreach (['Ãƒ', 'Ã‚', 'Ã‰', 'Ã…', 'Ã†', 'Ã„', '�'] as $marker) {
    $expect(!str_contains($billingPayments . $portalJs . $returnsInspection . $whatsappCss, $marker), 'mojibake_found:' . $marker);
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
