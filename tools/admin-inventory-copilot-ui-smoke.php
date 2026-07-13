<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$root = dirname(__DIR__);
$failures = [];

function smoke_file(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    return (string) file_get_contents($path);
}

function smoke_assert(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
    }
}

$adminUsers = smoke_file($root . '/admin-users.php');
$operations = smoke_file($root . '/operations-center.php');
$inventoryViews = $operations
    . smoke_file($root . '/views/inventory/module.php')
    . smoke_file($root . '/views/inventory/catalog.php')
    . smoke_file($root . '/views/inventory/movements.php')
    . smoke_file($root . '/views/inventory/cable-drums.php');
$billingUi = smoke_file($root . '/billing-ui.php');
$copilotWidget = smoke_file($root . '/copilot-widget.php');
$copilotApi = smoke_file($root . '/api/copilot/agent-chat.php');
$telegramBot = smoke_file($root . '/tools/process-telegram-modem-search-bot.php');

smoke_assert($adminUsers !== '', 'admin-users.php missing', $failures);
smoke_assert(str_contains($adminUsers, 'data-admin-users-employee-mode'), 'employee-only admin form marker missing', $failures);
smoke_assert(str_contains($adminUsers, 'name="role" value="employee"'), 'employee form must submit fixed employee role', $failures);
smoke_assert(!str_contains($adminUsers, '<option value="customer"'), 'employee creation form/filter must not expose customer option', $failures);
smoke_assert(!str_contains($adminUsers, 'name="subscriber_code"'), 'employee form must not request subscriber code', $failures);
smoke_assert(!str_contains($adminUsers, 'name="contract_no"'), 'employee form must not request contract number', $failures);
smoke_assert(!str_contains($adminUsers, 'name="category"'), 'employee form must not request customer category', $failures);
smoke_assert(!str_contains($adminUsers, 'name="plan"'), 'employee form must not request customer plan/package', $failures);
smoke_assert(str_contains($adminUsers, 'Emekdas siyahisi'), 'employee list heading missing', $failures);
smoke_assert(str_contains($adminUsers, 'name="telegram_chat_id"'), 'employee form must store Telegram chat ID for Superpilot access', $failures);
smoke_assert(str_contains($adminUsers, 'Redakte et'), 'each employee row must expose a visible Redakte et action', $failures);

smoke_assert($operations !== '', 'operations-center.php missing', $failures);
smoke_assert(str_contains($inventoryViews, 'data-inventory-movements'), 'inventory movement panel marker missing', $failures);
smoke_assert(str_contains($inventoryViews, 'name="movement_type"'), 'inventory movement type select missing', $failures);
foreach (['Medaxil', 'Texnike verilis', 'Geri qaytarma', 'Serfiyyat'] as $label) {
    smoke_assert(str_contains($inventoryViews, $label), 'inventory movement label missing: ' . $label, $failures);
}
foreach (['inventory_new_sku', 'inventory_new_item_name', 'inventory_category_code', 'Cari stok'] as $marker) {
    smoke_assert(str_contains($inventoryViews, $marker), 'inventory quick-create/balance marker missing: ' . $marker, $failures);
}
foreach (['data-inventory-module', 'data-inventory-catalog', 'Kateqoriya elave et', 'data-inventory-cable-drums'] as $marker) {
    smoke_assert(str_contains($inventoryViews, $marker), 'inventory module UI marker missing: ' . $marker, $failures);
}

smoke_assert($billingUi !== '', 'billing-ui.php missing', $failures);
smoke_assert(str_contains($billingUi, 'copilot-widget.php'), 'billing header must include Superpilot widget component', $failures);
smoke_assert($copilotWidget !== '', 'copilot-widget.php missing', $failures);
smoke_assert(str_contains($copilotWidget, 'billing-copilot-widget'), 'global Superpilot widget marker missing', $failures);
smoke_assert(str_contains($copilotWidget, 'Superpilot'), 'Copilot UI must be renamed to Superpilot', $failures);
smoke_assert(str_contains($copilotWidget, 'api/copilot/agent-chat.php'), 'Superpilot widget must call agent-chat API', $failures);
smoke_assert(str_contains($copilotWidget, 'width:min(390px,calc(100vw - 24px))'), 'Superpilot panel must be compact right-side drawer width', $failures);
smoke_assert(str_contains($copilotWidget, 'top:96px;bottom:18px'), 'Superpilot panel must avoid covering top menu/header', $failures);
smoke_assert(str_contains($copilotWidget, 'pointer-events:none'), 'Superpilot widget shell must not block underlying page', $failures);
foreach (['hansi suallara cavab verirsen', 'NOC', 'anbar', 'traffic monetization'] as $marker) {
    smoke_assert(str_contains($copilotWidget, $marker), 'Superpilot widget help marker missing: ' . $marker, $failures);
}
smoke_assert($copilotApi !== '', 'api/copilot/agent-chat.php missing', $failures);
smoke_assert(str_contains($copilotApi, 'app_copilot_agent_handle_message'), 'Superpilot API must use agent action handler', $failures);
smoke_assert(str_contains($copilotApi, 'telegram_chat_id'), 'Superpilot API must carry employee Telegram chat ID into actor context', $failures);
smoke_assert($telegramBot !== '', 'Telegram modem/Superpilot bot worker missing', $failures);
smoke_assert(str_contains($telegramBot, 'app_superpilot_employee_by_telegram_chat_id'), 'Telegram bot must authorize employee-specific chat IDs', $failures);
smoke_assert(str_contains($telegramBot, 'Superpilot hazirdir'), 'Telegram bot help text must use Superpilot name', $failures);

$copilotEngine = smoke_file($root . '/copilot-engine.php');
smoke_assert($copilotEngine !== '', 'copilot-engine.php missing', $failures);
foreach (['Analiz:', 'Subut:', 'Tovsiye:', 'Novbeti addim:'] as $marker) {
    smoke_assert(str_contains($copilotEngine, $marker), 'Superpilot customer answer marker missing: ' . $marker, $failures);
}

$payload = [
    'status' => $failures === [] ? 'ok' : 'failed',
    'failures' => $failures,
    'checked_at' => date(DATE_ATOM),
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
