<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feature-flags-lib.php';

function app_stage3_workspaces_enabled(?PDO $db = null, array $user = []): bool
{
    $db = $db ?? (function_exists('app_db') ? app_db() : null);
    if (!$db instanceof PDO) {
        return false;
    }
    try {
        return app_feature_flag_enabled($db, 'stage3_module_workspaces', $user, false);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Filter workspace cards by the actor's module access.
 *
 * Each card may declare a `module` key from the portal module catalog.
 * Cards without a module stay visible. When the Stage 3 flag is disabled
 * the list is returned unchanged so legacy behavior is preserved.
 *
 * @param list<array<string,mixed>> $cards
 * @return list<array<string,mixed>>
 */
function app_stage3_filter_workspace_cards(array $cards, array $user, ?PDO $db = null): array
{
    if (!app_stage3_workspaces_enabled($db, $user)) {
        return $cards;
    }
    if (app_portal_is_admin($user)) {
        return $cards;
    }
    return array_values(array_filter($cards, static function ($card) use ($user): bool {
        if (!is_array($card)) {
            return false;
        }
        $module = trim((string) ($card['module'] ?? ''));
        if ($module === '') {
            return true;
        }
        return app_portal_can_access_module($user, $module);
    }));
}
