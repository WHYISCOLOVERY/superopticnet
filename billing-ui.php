<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/copilot-widget.php';

function billing_require_employee(bool $adminOnly = false, ?string $requiredModule = null): array
{
    app_session_boot();
    $lang = app_lang();
    $user = app_portal_current_user();
    if ($user === null || ($user['role'] ?? '') !== 'employee' || ($adminOnly && !app_portal_is_admin($user))) {
        header('Location: portal-login.php?status=auth_required' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
        exit;
    }

    $requestModule = $requiredModule ?? app_portal_request_module(
        (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''),
        $_GET
    );
    if ($requestModule !== null && !app_portal_can_access_module($user, $requestModule)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="' . e($lang) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>403</title><link rel="stylesheet" href="' . e(app_asset('css/portal.css')) . '"></head><body><main class="portal-shell"><section class="portal-card"><h1>403</h1><p>Bu modula giris icazeniz yoxdur.</p><a class="portal-primary-btn portal-inline-link" href="portal.php">Esas portala qayit</a></section></main></body></html>';
        exit;
    }

    return $user;
}

function billing_nav_groups(string $lang): array
{
    $suffix = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
    return [
        'Overview' => [
            'icon' => 'fa-gauge-high',
            'links' => [
                ['key' => 'overview', 'label' => 'Umumi baxis', 'href' => 'billing-dashboard.php' . $suffix],
            ],
        ],
        'Customers' => [
            'icon' => 'fa-users',
            'links' => [
                ['key' => 'customers', 'label' => 'Musteriler', 'href' => 'billing-customers.php' . $suffix],
                ['key' => 'balances', 'label' => 'Balans ve borclar', 'href' => 'billing-balances.php' . $suffix],
                ['key' => 'services', 'label' => 'Xidmet aktivliyi', 'href' => 'billing-services.php' . $suffix],
            ],
        ],
        'Billing' => [
            'icon' => 'fa-wallet',
            'links' => [
                ['key' => 'packages', 'label' => 'Paketler', 'href' => 'billing-packages.php' . $suffix],
                ['key' => 'payments', 'label' => 'Odenisler', 'href' => 'billing-payments.php' . $suffix],
                ['key' => 'providers', 'label' => 'Provider inteqrasiyalari', 'href' => 'billing-providers.php' . $suffix],
                ['key' => 'documents', 'label' => 'Billing senedleri', 'href' => 'billing-documents.php' . $suffix],
            ],
        ],
        'Analysis' => [
            'icon' => 'fa-chart-line',
            'links' => [
                ['key' => 'reports', 'label' => 'Billing hesabatlari', 'href' => 'billing-reports.php' . $suffix],
                ['key' => 'statistics', 'label' => 'Billing statistikasi', 'href' => 'billing-statistics.php' . $suffix],
            ],
        ],
    ];

    /*
     * Legacy duplicate navigation kept disabled after the canonical two-level
     * menu return above. Do not re-enable; update the canonical array instead.
    $groups = [
        'Dashboard' => [
            'icon' => 'fa-gauge-high',
            'links' => [
            ['key' => 'operations', 'label' => 'Operations Center', 'href' => 'operations-center.php' . $suffix],
            ['key' => 'mgs-integration', 'label' => 'Sistem servislari', 'href' => 'mgs-integration.php' . $suffix],
            ['key' => 'dashboard', 'label' => 'Ana səhifə', 'href' => 'billing-dashboard.php' . $suffix],
            ['key' => 'statistics', 'label' => 'Statistika', 'href' => 'billing-statistics.php' . $suffix],
            ],
        ],
        'Müştərilər' => [
            'icon' => 'fa-users',
            'links' => [
            ['key' => 'customers', 'label' => 'Müştəri siyahısı', 'href' => 'billing-customers.php' . $suffix],
            ['key' => 'customer-create', 'label' => 'Yeni müştəri', 'href' => 'billing-customers.php' . ($suffix === '' ? '?' : $suffix . '&') . 'create=1'],
            ['key' => 'customers-active', 'label' => 'Aktiv müştərilər', 'href' => 'billing-customers.php' . ($suffix === '' ? '?' : $suffix . '&') . 'status_filter=billing_active'],
            ['key' => 'customers-risk', 'label' => 'Riskli müştərilər', 'href' => 'billing-customers.php' . ($suffix === '' ? '?' : $suffix . '&') . 'status_filter=billing_inactive'],
            ['key' => 'customer-intelligence', 'label' => 'Customer intelligence', 'href' => 'billing-customers.php' . ($suffix === '' ? '?' : $suffix . '&') . 'intel=full'],
            ],
        ],
        'Billing' => [
            'icon' => 'fa-wallet',
            'links' => [
            ['key' => 'payments', 'label' => 'Ödəniş siyahısı', 'href' => 'billing-payments.php' . $suffix],
            ['key' => 'cash', 'label' => 'Nağd ödəniş', 'href' => 'billing-cash-payment.php' . $suffix],
            ['key' => 'finance', 'label' => 'Maliyye / xercler', 'href' => 'operations-center.php?section=finance'],
            ['key' => 'profiles', 'label' => 'Paketlər', 'href' => 'radius-billing.php' . $suffix . '#tariffs'],
            ['key' => 'ip-pools', 'label' => 'IP pool engine', 'href' => 'ip-pools.php' . $suffix],
            ['key' => 'pools', 'label' => 'Legacy poollar', 'href' => 'radius-billing.php' . $suffix . '#pools'],
            ['key' => 'requests', 'label' => 'Müraciətlər', 'href' => 'billing-requests.php' . $suffix],
            ['key' => 'billing-check', 'label' => 'Billing yoxlama', 'href' => 'billing-operations-check.php' . $suffix],
            ],
        ],
        'Network / NOC' => [
            'icon' => 'fa-network-wired',
            'links' => [
            ['key' => 'noc-dashboard', 'label' => 'NOC Dashboard', 'href' => 'noc-devices.php?view=monitor' . ($suffix !== '' ? '&' . ltrim($suffix, '?') : '')],
            ['key' => 'tickets-auto', 'label' => 'Aktiv alarmlar', 'href' => 'crm.php' . $suffix],
            ['key' => 'root-cause', 'label' => 'Root cause analizi', 'href' => 'billing-statistics.php' . $suffix . '#network-quality'],
            ['key' => 'security-anomaly', 'label' => 'Security / abuse monitor', 'href' => 'noc-security.php' . $suffix],
            ['key' => 'network-definitions', 'label' => 'VLAN/MPLS definitions', 'href' => 'network-definitions.php' . $suffix],
            ['key' => 'mgs-mpls', 'label' => 'MPLS / VLAN panel', 'href' => 'network-definitions.php'],
            ['key' => 'device-discovery', 'label' => 'Discovery', 'href' => 'noc-devices.php?view=devices' . ($suffix !== '' ? '&' . ltrim($suffix, '?') : '')],
            ['key' => 'olt-bridge', 'label' => 'OLT / ONT paneli', 'href' => 'noc-devices.php?view=devices' . ($suffix !== '' ? '&' . ltrim($suffix, '?') : '')],
            ['key' => 'ont-search', 'label' => 'ONT axtaris', 'href' => 'noc-devices.php?view=ont-search' . ($suffix !== '' ? '&' . ltrim($suffix, '?') : '')],
            ['key' => 'optical', 'label' => 'Optik siqnal analizi', 'href' => 'billing-statistics.php' . $suffix . '#optical'],
            ],
        ],
        'RADIUS' => [
            'icon' => 'fa-satellite-dish',
            'links' => [
            ['key' => 'radius-active', 'label' => 'Online sessiyalar', 'href' => 'radius-active.php' . $suffix],
            ['key' => 'radius-nas', 'label' => 'NAS cihazları', 'href' => 'radius-nas.php' . $suffix],
            ['key' => 'radius-stats', 'label' => 'Reject/Auth statistikası', 'href' => 'billing-statistics.php' . $suffix . '#radius-stats'],
            ['key' => 'radius-logs', 'label' => 'RADIUS logları', 'href' => 'radius-logs.php' . $suffix],
            ['key' => 'radius-natlog', 'label' => 'NAT log axtarışı', 'href' => 'radius-natlog.php' . $suffix],
            ],
        ],
        'OLT / ONT' => [
            'icon' => 'fa-diagram-project',
            'links' => [
            ['key' => 'olt-list', 'label' => 'OLT siyahısı', 'href' => 'portal.php' . $suffix . '#olt-panel'],
            ['key' => 'ont-list', 'label' => 'ONT siyahısı', 'href' => 'portal.php' . $suffix . '#ont-panel'],
            ['key' => 'ont-search', 'label' => 'ONT axtaris', 'href' => 'noc-devices.php?view=ont-search' . ($suffix !== '' ? '&' . ltrim($suffix, '?') : '')],
            ['key' => 'olt-bridge', 'label' => 'OLT / ONT paneli', 'href' => 'noc-devices.php?view=devices' . ($suffix !== '' ? '&' . ltrim($suffix, '?') : '')],
            ['key' => 'optical', 'label' => 'Optik siqnal analizi', 'href' => 'billing-statistics.php' . $suffix . '#optical'],
            ],
        ],
        'Tickets' => [
            'icon' => 'fa-ticket',
            'links' => [
            ['key' => 'tickets', 'label' => 'Açıq ticketlər', 'href' => 'crm.php' . $suffix],
            ['key' => 'tickets-sla', 'label' => 'SLA gecikənlər', 'href' => 'crm.php' . $suffix . '#sla'],
            ['key' => 'tickets-closed', 'label' => 'Bağlanmış ticketlər', 'href' => 'crm.php' . $suffix . '#closed'],
            ['key' => 'technical-work', 'label' => 'Texniki isler', 'href' => 'operations-center.php?section=technical'],
            ],
        ],
        'Anbar' => [
            'icon' => 'fa-boxes-stacked',
            'links' => [
            ['key' => 'warehouse', 'label' => 'Avadanliq siyahisi', 'href' => 'operations-center.php?section=inventory'],
            ['key' => 'barcode', 'label' => 'Barkod scan', 'href' => 'operations-center.php?section=inventory&focus=barcode'],
            ['key' => 'critical-stock', 'label' => 'Kritik stok', 'href' => 'operations-center.php?section=inventory&focus=critical-stock'],
            ],
        ],
        'SMS & Bildirişlər' => [
            'icon' => 'fa-message',
            'links' => [
            ['key' => 'messages', 'label' => 'Mesaj mərkəzi', 'href' => 'message-center.php' . $suffix],
            ['key' => 'message-templates', 'label' => 'SMS şablonları', 'href' => 'message-center.php' . $suffix . '#templates'],
            ['key' => 'campaigns', 'label' => 'Kampaniyalar', 'href' => 'campaigns.php' . $suffix],
            ],
        ],
        'Analitika' => [
            'icon' => 'fa-chart-line',
            'links' => [
            ['key' => 'analytics-revenue', 'label' => 'Gəlir analitikası', 'href' => 'billing-statistics.php' . $suffix . '#revenue'],
            ['key' => 'analytics-churn', 'label' => 'Churn/risk', 'href' => 'billing-statistics.php' . $suffix . '#risk'],
            ['key' => 'analytics-traffic', 'label' => 'Trafik analizi', 'href' => 'billing-statistics.php' . $suffix . '#traffic'],
            ['key' => 'traffic-monetization', 'label' => 'Traffic monetization', 'href' => 'billing-statistics.php' . $suffix . '#traffic-monetization'],
            ['key' => 'mgs-stats', 'label' => 'MGS statistika', 'href' => 'billing-statistics.php'],
            ['key' => 'reports', 'label' => 'Statistika', 'href' => 'statistics-center.php' . $suffix],
            ],
        ],
        'HR & KPI' => [
            'icon' => 'fa-id-card-clip',
            'links' => [
            ['key' => 'hr', 'label' => 'Isciler', 'href' => 'operations-center.php?section=hr'],
            ['key' => 'attendance', 'label' => 'Davamiyyet', 'href' => 'operations-center.php?section=hr&focus=attendance'],
            ['key' => 'kpi', 'label' => 'KPI panel', 'href' => 'operations-center.php?section=hr&focus=kpi'],
            ['key' => 'users-roles', 'label' => 'Istifadeciler ve rollar', 'href' => 'admin-users.php'],
            ],
        ],
        'Ayarlar' => [
            'icon' => 'fa-sliders',
            'links' => [
            ['key' => 'settings-radius', 'label' => 'Rollar və icazələr', 'href' => 'portal.php' . $suffix . '#roles'],
            ['key' => 'identity-integrations', 'label' => 'MyGov ID / SSO', 'href' => 'identity-integrations.php' . $suffix],
            ['key' => 'copilot-context', 'label' => 'Copilot konteksti', 'href' => 'mgs-copilot-context.php' . $suffix],
            ['key' => 'area-code-prefixes', 'label' => 'Erazi kod prefixleri', 'href' => 'area-code-prefixes.php' . $suffix],
            ['key' => 'system-health', 'label' => 'System health', 'href' => 'system-health.php' . $suffix],
            ['key' => 'backup', 'label' => 'Backup', 'href' => 'portal.php' . $suffix . '#backup'],
            ],
        ],
    ];

    unset($groups['OLT / ONT']);

    return $groups;
    */
}

function billing_nav_group_label(string $groupTitle, array $group): string
{
    $canonical = trim($groupTitle);
    $canonicalLabels = [
        'Overview' => 'Umumi baxis',
        'Dashboard' => 'Dashboard',
        'Customers' => 'Musteriler',
        'Billing' => 'Billing',
        'Network' => 'Network',
        'NOC' => 'NOC',
        'Inventory' => 'Anbar',
        'HR' => 'HR',
        'AI' => 'AI',
        'Analytics' => 'Analitika',
        'Analysis' => 'Hesabat ve statistika',
        'Settings' => 'Ayarlar',
    ];
    if (isset($canonicalLabels[$canonical])) {
        return $canonicalLabels[$canonical];
    }

    $icon = (string) ($group['icon'] ?? '');

    return match ($icon) {
        'fa-gauge-high' => 'Dashboard',
        'fa-users' => 'Musteriler',
        'fa-wallet' => 'Billing',
        'fa-network-wired' => 'Network / NOC',
        'fa-satellite-dish' => 'RADIUS',
        'fa-diagram-project' => 'OLT / ONT',
        'fa-ticket' => 'Tickets',
        'fa-boxes-stacked' => 'Anbar',
        'fa-message' => 'SMS & Bildirisler',
        'fa-chart-line' => 'Analitika',
        'fa-id-card-clip' => 'HR & KPI',
        'fa-sliders' => 'Ayarlar',
        default => $groupTitle,
    };
}

function billing_nav_contains_active(array $groups, string $active): bool
{
    foreach ($groups as $group) {
        foreach (($group['links'] ?? []) as $link) {
            if ((string) ($link['key'] ?? '') === $active) {
                return true;
            }
        }
    }

    return false;
}

function billing_nav_active_key(string $active): string
{
    return match ($active) {
        'dashboard' => 'overview',
        'customer-create', 'customers-active', 'customers-risk', 'customer-intelligence' => 'customers',
        'profiles' => 'packages',
        'cash' => 'payments',
        default => $active,
    };
}

function billing_header_brand_href(string $lang, string $active, ?array $groups = null): string
{
    $suffix = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
    $groups ??= billing_nav_groups($lang);

    return billing_nav_contains_active($groups, billing_nav_active_key($active))
        ? 'billing-dashboard.php' . $suffix
        : 'portal.php' . $suffix;
}

function billing_nav_link_label(array $link): string
{
    $explicitLabel = trim((string) ($link['label'] ?? ''));
    $useExplicitKeys = [
        'noc-devices',
        'device-discovery',
        'radius-active',
        'settings-radius',
        'statistics',
    ];
    if ($explicitLabel !== '' && in_array((string) ($link['key'] ?? ''), $useExplicitKeys, true)) {
        return $explicitLabel;
    }

    return match ((string) ($link['key'] ?? '')) {
        'operations' => 'Operations Center',
        'mgs-integration' => 'Sistem servislari',
        'dashboard' => 'Ana sehife',
        'statistics' => 'Statistika',
        'customers' => 'Musteri siyahisi',
        'customer-create' => 'Yeni musteri',
        'customers-active' => 'Aktiv musteriler',
        'customers-risk' => 'Riskli musteriler',
        'customer-intelligence' => 'Customer intelligence',
        'payments' => 'Odenis siyahisi',
        'cash' => 'Nagd odenis',
        'profiles' => 'Paketler',
        'ip-pools' => 'IP pool engine',
        'pools' => 'Legacy poollar',
        'requests' => 'Muracietler',
        'billing-check' => 'Billing yoxlama',
        'noc-dashboard' => 'NOC Dashboard',
        'tickets-auto' => 'Aktiv alarmlar',
        'root-cause' => 'Root cause analizi',
        'noc-devices' => 'NOC cihaz paneli',
        'security-anomaly' => 'Security / abuse monitor',
        'network-definitions' => 'VLAN/MPLS definitions',
        'mgs-mpls' => 'MPLS / VLAN panel',
        'network-monitor' => 'Network monitor',
        'device-discovery' => 'Discovery',
        'radius-active' => 'Online sessiyalar',
        'radius-nas' => 'NAS cihazlari',
        'radius-stats' => 'Reject/Auth statistikasi',
        'radius-logs' => 'RADIUS loglari',
        'radius-natlog' => 'NAT log axtarisi',
        'olt-list' => 'OLT idareetme',
        'ont-list' => 'ONT siyahisi',
        'ont-search' => 'ONT axtaris',
        'olt-bridge' => 'OLT / ONT paneli',
        'optical' => 'Optik siqnal analizi',
        'tickets' => 'Aciq ticketler',
        'tickets-sla' => 'SLA gecikenler',
        'tickets-closed' => 'Baglanmis ticketler',
        'technical-work' => 'Texniki isler',
        'finance' => 'Maliyye / xercler',
        'warehouse' => 'Avadanliq siyahisi',
        'barcode' => 'Barkod scan',
        'critical-stock' => 'Kritik stok',
        'messages' => 'Mesaj merkezi',
        'message-templates' => 'SMS sablonlari',
        'campaigns' => 'Kampaniyalar',
        'analytics-revenue' => 'Gelir analitikasi',
        'analytics-churn' => 'Churn/risk',
        'analytics-traffic' => 'Trafik analizi',
        'traffic-monetization' => 'Traffic monetization',
        'mgs-stats' => 'MGS statistika',
        'reports' => 'Hesabatlar',
        'hr' => 'Isciler',
        'attendance' => 'Davamiyyet',
        'kpi' => 'KPI panel',
        'users-roles' => 'Istifadeciler ve rollar',
        'settings-radius' => 'Rollar ve icazeler',
        'identity-integrations' => 'MyGov ID / SSO',
        'copilot-context' => 'Copilot konteksti',
        'area-code-prefixes' => 'Filial ve erazi',
        'system-health' => 'System health',
        'backup' => 'Backup',
        default => (string) ($link['label'] ?? ''),
    };
}

function billing_nav_link_href(array $link): string
{
    return match ((string) ($link['key'] ?? '')) {
        'noc-dashboard' => 'noc-devices.php?view=monitor',
        'root-cause' => 'billing-statistics.php#network-quality',
        'noc-devices' => 'noc-devices.php?view=monitor',
        'security-anomaly' => 'noc-security.php',
        'mgs-mpls' => 'network-definitions.php',
        'network-monitor' => 'noc-devices.php?view=monitor',
        'device-discovery' => 'noc-devices.php?view=devices',
        'olt-list' => 'noc-devices.php?view=devices',
        'ont-list' => 'noc-devices.php?view=devices',
        'ont-search' => 'noc-devices.php?view=ont-search',
        'olt-bridge' => 'noc-devices.php?view=devices',
        'technical-work' => 'operations-center.php?section=technical',
        'finance' => 'operations-center.php?section=finance',
        'warehouse' => 'operations-center.php?section=inventory',
        'barcode' => 'operations-center.php?section=inventory&focus=barcode',
        'critical-stock' => 'operations-center.php?section=inventory&focus=critical-stock',
        'handover' => 'operations-center.php?section=inventory&inventory_tab=handover',
        'hr' => 'operations-center.php?section=hr',
        'attendance' => 'operations-center.php?section=hr&focus=attendance',
        'kpi' => 'operations-center.php?section=hr&focus=kpi',
        'users-roles' => 'admin-users.php',
        'mgs-stats' => 'billing-statistics.php',
        'reports' => 'statistics-center.php',
        'settings-radius' => 'admin-users.php',
        'identity-integrations' => 'identity-integrations.php',
        'copilot-context' => 'mgs-copilot-context.php',
        'backup' => 'operations-center.php?section=reports&focus=backup',
        default => (string) ($link['href'] ?? '#'),
    };
}

function billing_nav_icon_svg(string $icon): string
{
    $paths = match ($icon) {
        'fa-gauge-high' => '<path d="M4 15a8 8 0 1 1 16 0"/><path d="M12 15l4-5"/><path d="M7 15h.01M17 15h.01"/>',
        'fa-users' => '<path d="M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M3 20a5 5 0 0 1 10 0"/><path d="M11 20a5 5 0 0 1 10 0"/>',
        'fa-wallet' => '<path d="M4 7h15a2 2 0 0 1 2 2v9H4a2 2 0 0 1-2-2V7a3 3 0 0 1 3-3h12"/><path d="M16 13h5"/><path d="M18 13h.01"/>',
        'fa-network-wired' => '<path d="M12 4v5"/><path d="M5 15v-3h14v3"/><rect x="9" y="2" width="6" height="4" rx="1"/><rect x="2" y="16" width="6" height="4" rx="1"/><rect x="9" y="16" width="6" height="4" rx="1"/><rect x="16" y="16" width="6" height="4" rx="1"/>',
        'fa-satellite-dish' => '<path d="M4 18a8 8 0 0 0 8-8"/><path d="M4 14a4 4 0 0 0 4-4"/><path d="M6 20l4-4"/><path d="M14 4l6 6"/><path d="M15 9l4-4"/>',
        'fa-diagram-project' => '<rect x="3" y="4" width="6" height="5" rx="1"/><rect x="15" y="4" width="6" height="5" rx="1"/><rect x="9" y="15" width="6" height="5" rx="1"/><path d="M9 6.5h6"/><path d="M12 9v6"/>',
        'fa-ticket' => '<path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4V7Z"/><path d="M9 5v14"/>',
        'fa-boxes-stacked' => '<path d="M4 10l8-4 8 4-8 4-8-4Z"/><path d="M4 14l8 4 8-4"/><path d="M4 10v8l8 4 8-4v-8"/>',
        'fa-message' => '<path d="M4 5h16v11H8l-4 4V5Z"/><path d="M8 9h8"/><path d="M8 13h5"/>',
        'fa-chart-line' => '<path d="M4 19h16"/><path d="M5 16l4-5 4 3 6-8"/><path d="M19 6v5h-5"/>',
        'fa-id-card-clip' => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M9 5V3h6v2"/><circle cx="10" cy="12" r="2"/><path d="M7 17a3 3 0 0 1 6 0"/><path d="M15 11h3"/><path d="M15 15h3"/>',
        'fa-sliders' => '<path d="M4 6h10"/><path d="M18 6h2"/><path d="M4 12h2"/><path d="M10 12h10"/><path d="M4 18h8"/><path d="M16 18h4"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="14" cy="18" r="2"/>',
        default => '<circle cx="12" cy="12" r="7"/>',
    };

    return '<svg class="billing-nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths . '</svg>';
}

function billing_page_title(string $title): string
{
    $lower = strtolower($title);
    if (str_contains($lower, 'ana')) {
        return 'Billing ana sehife';
    }
    if (str_contains($lower, 'profil')) {
        return 'Musteri profili';
    }
    if (str_contains($lower, 'billing') && (str_contains($title, 'Ã') || str_contains($title, 'Å') || str_contains($title, 'É'))) {
        return 'Billing musterileri';
    }

    return match ($title) {
        'Nağd ödəniş' => 'Nagd odenis',
        default => $title,
    };
}

function billing_render_header(string $title, string $active, string $lang): void
{
    $active = billing_nav_active_key($active);
    $portalSuffix = $lang !== 'az' ? '?lang=' . rawurlencode($lang) : '';
    $navGroups = billing_nav_groups($lang);
    $brandHref = billing_header_brand_href($lang, $active, $navGroups);
    ?>
    <header class="portal-topbar">
        <div class="portal-topbar-inner">
            <a href="<?= e($brandHref); ?>" class="portal-brand">
                <img src="images/favicon.png" alt="Super Optik Tech">
                <span><?= e(billing_page_title($title)); ?></span>
            </a>
            <div class="portal-topbar-actions">
                <a class="portal-ghost-btn portal-inline-link" href="portal.php<?= e($portalSuffix); ?>">Esas portala qayit</a>
            </div>
        </div>
    </header>
    <?php if (!billing_nav_contains_active($navGroups, $active)) { return; } ?>
    <nav class="billing-shell-nav portal-reveal" data-module-group-nav aria-label="SuperOptic MGS menyusu">
        <?php foreach ($navGroups as $groupTitle => $group): ?>
            <?php
            $links = $group['links'] ?? [];
            $isOpen = false;
            foreach ($links as $link) {
                if (($link['key'] ?? '') === $active) {
                    $isOpen = true;
                    break;
                }
            }
            ?>
            <details class="billing-nav-group<?= $isOpen ? ' is-active-group' : ''; ?>" name="billing-nav" data-module-nav-group>
                <summary class="billing-nav-title">
                    <?= billing_nav_icon_svg((string) ($group['icon'] ?? 'fa-circle')); ?>
                    <span><?= e(billing_nav_group_label((string) $groupTitle, $group)); ?></span>
                </summary>
                <div class="billing-nav-links">
                    <?php foreach ($links as $link): ?>
                        <a class="<?= $active === $link['key'] ? 'is-active' : ''; ?>" href="<?= e(billing_nav_link_href($link)); ?>">
                            <?= e(billing_nav_link_label($link)); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    <script>
    (() => {
        const nav = document.currentScript.previousElementSibling;
        if (!nav || !nav.classList || !nav.classList.contains('billing-shell-nav')) {
            return;
        }
        const groups = Array.from(nav.querySelectorAll('.billing-nav-group'));
        const closeAll = (except = null) => {
            groups.forEach((group) => {
                if (group !== except) {
                    group.open = false;
                }
            });
        };
        const syncPopover = () => {
            const openGroup = groups.find((group) => group.open);
            nav.classList.toggle('has-open', Boolean(openGroup));
            if (!openGroup) {
                return;
            }
            const title = openGroup.querySelector('.billing-nav-title');
            if (!title) {
                return;
            }
            const rect = title.getBoundingClientRect();
            const width = Math.max(220, Math.min(360, Math.round(rect.width)));
            const top = Math.max(12, Math.min(window.innerHeight - 80, Math.round(rect.bottom + 8)));
            const maxLeft = Math.max(12, window.innerWidth - width - 12);
            const left = Math.max(12, Math.min(maxLeft, Math.round(rect.left)));
            nav.style.setProperty('--billing-nav-popover-top', `${top}px`);
            nav.style.setProperty('--billing-nav-popover-left', `${left}px`);
            nav.style.setProperty('--billing-nav-popover-width', `${width}px`);
        };
        const canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
        groups.forEach((group) => {
            group.addEventListener('toggle', () => {
                if (group.open) {
                    closeAll(group);
                }
                requestAnimationFrame(syncPopover);
            });
            if (canHover) {
                // Desktop: mouse üstünə gələndə aç (ox-klikə ehtiyac yoxdur).
                group.addEventListener('mouseenter', () => { closeAll(group); group.open = true; });
                const summary = group.querySelector('.billing-nav-title');
                if (summary) { summary.addEventListener('click', (event) => event.preventDefault()); }
            }
        });
        if (canHover) {
            let leaveTimer = null;
            nav.addEventListener('mouseleave', () => { leaveTimer = setTimeout(() => { closeAll(); syncPopover(); }, 150); });
            nav.addEventListener('mouseenter', () => { if (leaveTimer) { clearTimeout(leaveTimer); leaveTimer = null; } });
        }
        document.addEventListener('click', (event) => {
            if (!nav.contains(event.target)) {
                closeAll();
                syncPopover();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAll();
                syncPopover();
            }
        });
        window.addEventListener('resize', syncPopover, {passive: true});
        window.addEventListener('scroll', syncPopover, {passive: true});
        syncPopover();

    })();
    </script>
    <?php app_render_copilot_widget($lang); ?>
    <?php
}

function billing_render_stat_card(string $label, string $value, string $hint = '', string $icon = 'fa-chart-simple', string $tone = 'blue', string $href = ''): void
{
    $tag = $href !== '' ? 'a' : 'article';
    $attrs = $href !== '' ? ' href="' . e($href) . '"' : '';
    ?>
    <<?= $tag; ?> class="mgs-stat-card mgs-tone-<?= e($tone); ?>"<?= $attrs; ?>>
        <span class="mgs-stat-icon"><i class="fa-solid <?= e($icon); ?>"></i></span>
        <span class="mgs-stat-label"><?= e($label); ?></span>
        <strong><?= e($value); ?></strong>
        <?php if ($hint !== ''): ?><small><?= e($hint); ?></small><?php endif; ?>
    </<?= $tag; ?>>
    <?php
}

function billing_render_empty_state(string $title, string $text, string $icon = 'fa-inbox'): void
{
    ?>
    <div class="mgs-empty-state">
        <i class="fa-solid <?= e($icon); ?>"></i>
        <strong><?= e($title); ?></strong>
        <p><?= e($text); ?></p>
    </div>
    <?php
}

function billing_render_workflow_guide(string $title, array $steps, string $note = '', string $tone = 'info'): void
{
    $cleanSteps = [];
    foreach ($steps as $step) {
        if (!is_array($step)) {
            continue;
        }
        $stepTitle = trim((string) ($step['title'] ?? ''));
        $stepText = trim((string) ($step['text'] ?? ''));
        if ($stepTitle === '' && $stepText === '') {
            continue;
        }
        $cleanSteps[] = [
            'title' => $stepTitle,
            'text' => $stepText,
            'icon' => trim((string) ($step['icon'] ?? 'fa-circle-check')),
        ];
    }
    if ($cleanSteps === []) {
        return;
    }
    ?>
    <section class="portal-workflow-guide portal-workflow-<?= e($tone); ?>" aria-label="<?= e($title); ?>">
        <div class="portal-workflow-copy">
            <span class="portal-kicker">Sade is axini</span>
            <h2><?= e($title); ?></h2>
            <?php if ($note !== ''): ?><p><?= e($note); ?></p><?php endif; ?>
        </div>
        <div class="portal-workflow-steps">
            <?php foreach ($cleanSteps as $index => $step): ?>
                <?php
                $iconClass = (string) ($step['icon'] ?? 'fa-circle-check');
                if (!str_contains($iconClass, 'fa-solid') && !str_contains($iconClass, 'fa-regular') && !str_contains($iconClass, 'fa-brands')) {
                    $iconClass = 'fa-solid ' . $iconClass;
                }
                ?>
                <article class="portal-workflow-step">
                    <span class="portal-workflow-icon"><i class="<?= e($iconClass); ?>"></i></span>
                    <strong><?= e((string) ($index + 1)); ?>. <?= e($step['title']); ?></strong>
                    <?php if ($step['text'] !== ''): ?><small><?= e($step['text']); ?></small><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function billing_render_page_header(string $eyebrow, string $title, string $description = '', array $actions = []): void
{
    ?>
    <section class="mgs-page-header portal-reveal">
        <div class="mgs-page-header-copy">
            <?php if ($eyebrow !== ''): ?><span class="mgs-page-eyebrow"><?= e($eyebrow); ?></span><?php endif; ?>
            <h1><?= e($title); ?></h1>
            <?php if ($description !== ''): ?><p><?= e($description); ?></p><?php endif; ?>
        </div>
        <?php if ($actions !== []): ?>
            <div class="mgs-page-actions" aria-label="Sehife emeliyyatlari">
                <?php foreach ($actions as $action): ?>
                    <?php
                    if (!is_array($action)) {
                        continue;
                    }
                    $label = (string) ($action['label'] ?? '');
                    $href = (string) ($action['href'] ?? '');
                    $class = (string) ($action['class'] ?? 'portal-ghost-btn');
                    if ($label === '') {
                        continue;
                    }
                    ?>
                    <?php if ($href !== ''): ?>
                        <a class="<?= e($class); ?>" href="<?= e($href); ?>"><?= e($label); ?></a>
                    <?php else: ?>
                        <button class="<?= e($class); ?>" type="<?= e((string) ($action['type'] ?? 'button')); ?>"><?= e($label); ?></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function billing_render_status_badge(string $label, string $tone = 'neutral'): void
{
    $toneClass = match ($tone) {
        'success', 'online', 'active' => 'is-success',
        'danger', 'offline', 'inactive' => 'is-danger',
        'warning', 'risk', 'debt' => 'is-warning',
        'info', 'radius' => 'is-info',
        default => 'is-neutral',
    };
    ?>
    <span class="mgs-status-badge <?= e($toneClass); ?>"><?= e($label); ?></span>
    <?php
}

function billing_render_action_dropdown(string $label, array $actions): void
{
    ?>
    <details class="mgs-action-dropdown">
        <summary><?= e($label); ?></summary>
        <div class="mgs-action-dropdown-menu">
            <?php foreach ($actions as $action): ?>
                <?php
                if (!is_array($action)) {
                    continue;
                }
                $actionLabel = (string) ($action['label'] ?? '');
                $href = (string) ($action['href'] ?? '');
                $tone = (string) ($action['tone'] ?? '');
                if ($actionLabel === '') {
                    continue;
                }
                ?>
                <?php if ($href !== ''): ?>
                    <a class="<?= $tone === 'danger' ? 'is-danger' : ''; ?>" href="<?= e($href); ?>"><?= e($actionLabel); ?></a>
                <?php else: ?>
                    <button class="<?= $tone === 'danger' ? 'is-danger' : ''; ?>" type="button"><?= e($actionLabel); ?></button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </details>
    <?php
}

function billing_render_quick_filter_chips(array $chips): void
{
    if ($chips === []) {
        return;
    }
    ?>
    <div class="mgs-quick-filter-chips" aria-label="Suretli filterler">
        <?php foreach ($chips as $chip): ?>
            <?php
            if (!is_array($chip)) {
                continue;
            }
            $label = (string) ($chip['label'] ?? '');
            $href = (string) ($chip['href'] ?? '#');
            $active = !empty($chip['active']);
            if ($label === '') {
                continue;
            }
            ?>
            <a class="mgs-chip<?= $active ? ' is-active' : ''; ?>" href="<?= e($href); ?>"><?= e($label); ?></a>
        <?php endforeach; ?>
    </div>
    <?php
}

function billing_percent(mixed $value, mixed $total): int
{
    $value = max(0.0, (float) $value);
    $total = max(0.0, (float) $total);
    if ($total <= 0.0) {
        return 0;
    }

    return max(2, min(100, (int) round(($value / $total) * 100)));
}

function billing_money(mixed $value): string
{
    if ($value === null || $value === '') {
        return '0.00 AZN';
    }

    return number_format((float) $value, 2, '.', '') . ' AZN';
}

function billing_bytes(mixed $value): string
{
    if ($value === null || $value === '') {
        return '0 B';
    }

    $bytes = (float) $value;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, $index === 0 ? 0 : 2, '.', '') . ' ' . $units[$index];
}

function billing_datetime(mixed $value): string
{
    if ($value === null || trim((string) $value) === '') {
        return '-';
    }
    $timestamp = strtotime((string) $value);

    return $timestamp === false ? (string) $value : date('d.m.Y H:i', $timestamp);
}

function billing_parse_money_value(mixed $value): float
{
    if (is_numeric($value)) {
        return (float) $value;
    }

    $text = str_replace(',', '.', (string) $value);
    if (preg_match('/-?\d+(?:\.\d+)?/', $text, $match) !== 1) {
        return 0.0;
    }

    return (float) $match[0];
}

function billing_parse_traffic_bytes(mixed $value): float
{
    if (is_numeric($value)) {
        return (float) $value;
    }

    $text = trim((string) $value);
    if (preg_match('/([\d.,]+)\s*(TB|GB|MB|KB|B)?/i', $text, $match) !== 1) {
        return 0.0;
    }

    $amount = (float) str_replace(',', '.', $match[1]);
    $unit = strtoupper((string) ($match[2] ?? 'B'));
    $multiplier = match ($unit) {
        'TB' => 1024 ** 4,
        'GB' => 1024 ** 3,
        'MB' => 1024 ** 2,
        'KB' => 1024,
        default => 1,
    };

    return $amount * $multiplier;
}

function billing_mock_date_to_sql(mixed $value, string $fallback = ''): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return $fallback !== '' ? $fallback : date('Y-m-d H:i:s');
    }

    $formats = ['d.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $text);
        if ($date instanceof DateTime) {
            if ($format === 'd.m.Y') {
                $date->setTime(12, 0);
            }

            return $date->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($text);
    return $timestamp === false ? ($fallback !== '' ? $fallback : date('Y-m-d H:i:s')) : date('Y-m-d H:i:s', $timestamp);
}

function billing_mock_customer_rows(): array
{
    if (!app_demo_fixtures_enabled()) {
        return [];
    }

    $data = app_mock_portal_data();
    $rows = [];
    $index = 0;

    foreach (($data['customers'] ?? []) as $login => $customer) {
        if (!is_array($customer)) {
            continue;
        }

        $dashboard = is_array($customer['dashboard'] ?? null) ? $customer['dashboard'] : [];
        $profile = is_array($dashboard['profile'] ?? null) ? $dashboard['profile'] : [];
        $traffic = is_array($dashboard['traffic'] ?? null) ? $dashboard['traffic'] : [];
        $payments = is_array($dashboard['payments'] ?? null) ? array_values(array_filter($dashboard['payments'], 'is_array')) : [];
        $statements = is_array($dashboard['statements'] ?? null) ? array_values(array_filter($dashboard['statements'], 'is_array')) : [];
        $lastPayment = $payments[0] ?? [];
        $lastStatement = $statements[0] ?? [];
        $subscriberCode = (string) ($customer['subscriber_code'] ?? $profile['subscriber_code'] ?? $login);
        $status = (string) ($profile['status'] ?? 'Aktiv');
        $isActive = !str_contains(strtolower($status), 'deaktiv');
        $index++;

        $rows[] = [
            'id' => $index,
            'login' => $login,
            'full_name' => (string) ($profile['full_name'] ?? $customer['name'] ?? $login),
            'subscriber_code' => $subscriberCode,
            'contract_no' => (string) ($profile['contract_no'] ?? $subscriberCode),
            'phone' => (string) ($profile['phone'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'category' => (string) ($profile['category'] ?? $customer['category'] ?? ''),
            'area' => (string) ($profile['area'] ?? ''),
            'address' => (string) ($profile['address'] ?? ''),
            'plan' => (string) ($profile['plan'] ?? ''),
            'status' => $status,
            'is_active' => $isActive ? 1 : 0,
            'username' => $subscriberCode,
            'service_type' => 'pppoe',
            'assigned_ip' => '10.11.' . (20 + $index) . '.' . (10 + $index),
            'static_ip' => '',
            'radius_status' => $isActive ? 'active' : 'expired',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+' . (20 + $index) . ' days')),
            'tariff_name' => (string) ($profile['plan'] ?? 'Demo tarif'),
            'remaining_days_raw' => (string) (20 + $index) . ' gun',
            'debt_raw' => (string) ($lastStatement['balance'] ?? '0 AZN'),
            'last_payment_at' => billing_mock_date_to_sql($lastPayment['date'] ?? ''),
            'payment_count' => count($payments),
            'download_bytes' => billing_parse_traffic_bytes($traffic['download_used'] ?? 0),
            'upload_bytes' => billing_parse_traffic_bytes($traffic['upload_used'] ?? 0),
            'mock_source' => true,
        ];
    }

    return $rows;
}

function billing_mock_payment_rows(): array
{
    if (!app_demo_fixtures_enabled()) {
        return [];
    }

    $rows = [];
    $index = 0;

    foreach (billing_mock_customer_rows() as $customer) {
        $record = app_mock_portal_data()['customers'][$customer['login']] ?? null;
        $payments = is_array($record['dashboard']['payments'] ?? null) ? array_values(array_filter($record['dashboard']['payments'], 'is_array')) : [];
        foreach ($payments as $payment) {
            $index++;
            $rows[] = [
                'id' => $index,
                'account_id' => null,
                'subscriber_code' => (string) ($customer['subscriber_code'] ?? ''),
                'contract_no' => (string) ($customer['contract_no'] ?? ''),
                'transaction_id' => 'MOCK-' . str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                'payment_method' => (string) ($payment['method'] ?? 'Mock'),
                'amount' => billing_parse_money_value($payment['amount'] ?? 0),
                'status' => (string) ($payment['status'] ?? 'Tesdiqlendi'),
                'created_at' => billing_mock_date_to_sql($payment['date'] ?? ''),
                'full_name' => (string) ($customer['full_name'] ?? ''),
                'phone' => (string) ($customer['phone'] ?? ''),
                'area' => (string) ($customer['area'] ?? ''),
            ];
        }
    }

    usort($rows, static fn (array $a, array $b): int => strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']));
    return $rows;
}

function billing_mock_radius_rows(): array
{
    if (!app_demo_fixtures_enabled()) {
        return [];
    }

    $rows = [];
    $index = 0;

    foreach (billing_mock_customer_rows() as $customer) {
        $index++;
        $rows[] = [
            'username' => (string) ($customer['username'] ?? $customer['subscriber_code'] ?? ''),
            'service_type' => (string) ($customer['service_type'] ?? 'pppoe'),
            'assigned_ip' => (string) ($customer['assigned_ip'] ?? ''),
            'static_ip' => (string) ($customer['static_ip'] ?? ''),
            'status' => (string) ($customer['radius_status'] ?? 'active'),
            'expires_at' => (string) ($customer['expires_at'] ?? ''),
            'account_id' => (int) ($customer['id'] ?? 0),
            'full_name' => (string) ($customer['full_name'] ?? ''),
            'subscriber_code' => (string) ($customer['subscriber_code'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
            'tariff_name' => (string) ($customer['tariff_name'] ?? $customer['plan'] ?? ''),
            'download_bytes' => (float) ($customer['download_bytes'] ?? 0),
            'upload_bytes' => (float) ($customer['upload_bytes'] ?? 0),
            'sessions_24h' => 1 + $index,
            'framedipaddress' => (string) ($customer['assigned_ip'] ?? ''),
            'callingstationid' => 'MOCK-MAC-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'nasipaddress' => '85.132.57.218',
            'nasportid' => 'TenGigabitEthernet1/2/0.' . (1000 + $index),
            'acctstarttime' => date('Y-m-d H:i:s', strtotime('-' . (2 + $index) . ' hours')),
            'acctupdatetime' => date('Y-m-d H:i:s', strtotime('-' . $index . ' minutes')),
        ];
    }

    return $rows;
}

function billing_status_class(string $status): string
{
    $value = strtolower($status);
    if (str_contains($value, 'active') || str_contains($value, 'aktiv')) {
        return 'portal-pill-success';
    }
    if (str_contains($value, 'expired') || str_contains($value, 'deaktiv') || str_contains($value, 'suspend')) {
        return 'portal-pill-danger';
    }

    return '';
}
