<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

app_session_boot();
$user = app_portal_current_user();

if ($user === null) {
    header('Location: portal-login.php?status=auth_required');
    exit;
}

if (!in_array(($user['role'] ?? ''), ['employee', 'customer'], true)) {
    header('Location: portal.php?status=portal_bridge_forbidden');
    exit;
}

$target = clean_text($_GET['target'] ?? '');
$slug = clean_text($_GET['slug'] ?? '');

if ($target === 'billing') {
    $billingUrl = app_portal_billing_handoff_url($user);
    if ($billingUrl === null) {
        header('Location: portal.php?status=portal_billing_unavailable');
        exit;
    }

    header('Location: ' . $billingUrl);
    exit;
}

if ($target !== 'olt') {
    header('Location: portal.php?status=portal_bridge_forbidden');
    exit;
}

if (($user['role'] ?? '') !== 'employee') {
    header('Location: portal.php?status=portal_bridge_forbidden');
    exit;
}

if ($slug === '') {
    $links = app_portal_employee_links($user);
    $firstLink = $links[0] ?? null;
    if (!is_array($firstLink) || empty($firstLink['slug'])) {
        header('Location: portal.php?status=portal_bridge_forbidden');
        exit;
    }

    $slug = (string) $firstLink['slug'];
}

$link = app_portal_find_employee_link($user, $slug);
if ($link === null) {
    header('Location: portal.php?status=portal_bridge_forbidden');
    exit;
}

$handoffUrl = app_portal_olt_handoff_url($user, $link);
if ($handoffUrl === null) {
    header('Location: portal.php?status=portal_bridge_unavailable');
    exit;
}

header('Location: ' . $handoffUrl);
exit;
