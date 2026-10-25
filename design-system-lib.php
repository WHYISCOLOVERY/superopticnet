<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_design_system_enabled(?PDO $db = null): bool
{
    $db = $db ?? (function_exists('app_db') ? app_db() : null);
    if (!$db instanceof PDO) {
        return false;
    }
    try {
        $statement = $db->query(
            'SELECT is_enabled,rollout_percent
               FROM app_feature_flags
              WHERE flag_key = "stage2_design_system"
              LIMIT 1'
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            && (int) ($row['is_enabled'] ?? 0) === 1
            && (float) ($row['rollout_percent'] ?? 0) > 0;
    } catch (Throwable) {
        return false;
    }
}

function app_design_system_body_attribute(?PDO $db = null): string
{
    return app_design_system_enabled($db)
        ? ' data-design-system="v2"'
        : '';
}

function app_design_system_state(
    string $tone,
    string $title,
    string $message,
    string $actionLabel = '',
    string $actionHref = ''
): string {
    $allowed = ['empty', 'loading', 'error', 'success', 'info'];
    $tone = in_array($tone, $allowed, true) ? $tone : 'info';
    $icon = match ($tone) {
        'empty' => 'fa-inbox',
        'loading' => 'fa-spinner',
        'error' => 'fa-triangle-exclamation',
        'success' => 'fa-circle-check',
        default => 'fa-circle-info',
    };
    $action = '';
    if ($actionLabel !== '' && $actionHref !== '') {
        $action = '<a class="ui-button ui-button-secondary" href="'
            . e($actionHref) . '">' . e($actionLabel) . '</a>';
    }
    return '<section class="ui-state ui-state-' . e($tone) . '" role="'
        . ($tone === 'error' ? 'alert' : 'status') . '">'
        . '<i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i>'
        . '<div><strong>' . e($title) . '</strong><p>' . e($message) . '</p></div>'
        . $action . '</section>';
}
