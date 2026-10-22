<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/billing-ui.php';

app_session_boot();
$lang = app_lang();
$status = $_GET['status'] ?? '';
$user = app_portal_current_user();

if ($user === null) {
    header('Location: portal-login.php?status=auth_required' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
    exit;
}

$flash = app_flash_message();
$isCustomer = $user['role'] === 'customer';
$snapshot = $isCustomer ? app_portal_customer_snapshot($user) : app_portal_employee_snapshot($user);
$employeeModuleKeys = $isCustomer ? [] : app_portal_employee_module_keys($user);
$employeeModuleCatalog = $isCustomer ? [] : app_portal_module_catalog($lang);

if (PHP_SAPI !== 'cli') {
    ob_start(static function (string $html): string {
        return str_replace(
            [
                'MÃ¼ÅŸtÉ™ri paneli',
                'ÆmÉ™kdaÅŸ paneli',
                'Ã‡Ä±xÄ±ÅŸ',
                'Telefon nÃ¶mrÉ™sini yenilÉ™',
                'MÃ¼raciÉ™t mÃ¶vzusu',
                'MÃ¼raciÉ™t mÉ™tni',
                'MÃ¼raciÉ™ti gÃ¶ndÉ™r',
                'Ã–dÉ™niÅŸlÉ™r',
                'Hesab Ã§Ä±xarÄ±ÅŸlarÄ±',
                'MÃ¼raciÉ™tlÉ™r',
                'NÃ¶vbÉ™',
                'Tez É™mÉ™liyyatlar',
                'BillingÉ™ keÃ§',
                'OLT idarÉ™etmÉ™sini aÃ§',
                'Kritik nÃ¶vbÉ™ni yoxla',
                'MÃ¼qavilÉ™',
                'Buradan iÅŸÉ™ daha sÃ¼rÉ™tli baÅŸlamaq Ã¼Ã§Ã¼n billing vÉ™ OLT keÃ§idlÉ™rini aÃ§Ä±n, prioritet nÃ¶vbÉ™ni gÃ¶rÃ¼n vÉ™ rolunuza uyÄŸun É™mÉ™liyyatlara keÃ§in.',
                'DiqqÉ™t tÉ™lÉ™b edÉ™n iÅŸlÉ™ri vÉ™ É™sas keÃ§idlÉ™ri Ã¶nÉ™ Ã§Ä±xarÄ±r.',
            ],
            [
                'Musteri paneli',
                'Emekdas paneli',
                'Cixis',
                'Telefon nomresini yenile',
                'Muraciet movzusu',
                'Muraciet metni',
                'Muracieti gonder',
                'Odenisler',
                'Hesab cixarislari',
                'Muracietler',
                'Novbe',
                'Tez emeliyyatlar',
                'Billinge kec',
                'OLT idareetmesini ac',
                'Kritik novbeni yoxla',
                'Muqavile',
                'Buradan ise daha suretli baslamaq ucun billing ve OLT kecidlerini acin, prioritet novbeni gorun ve rolunuza uygun emeliyyatlara kecin.',
                'Diqqet teleb eden isleri ve esas kecidleri one cixarir.',
            ],
            $html
        );
    });
}

$texts = [
    'az' => [
        'dashboard_customer' => 'MÃ¼ÅŸtÉ™ri paneli',
        'dashboard_employee' => 'ÆmÉ™kdaÅŸ paneli',
        'status' => 'Status',
        'role' => 'Rol',
        'logout' => 'Ã‡Ä±xÄ±ÅŸ',
        'customer_text' => '%s paketi Ã¼zrÉ™ É™sas mÉ™lumatlar, Ã¶dÉ™niÅŸlÉ™r vÉ™ dÉ™stÉ™k mÃ¼raciÉ™tlÉ™ri burada toplanÄ±r.',
        'employee_text' => 'Buradan iÅŸÉ™ daha sÃ¼rÉ™tli baÅŸlamaq Ã¼Ã§Ã¼n billing vÉ™ OLT keÃ§idlÉ™rini aÃ§Ä±n, prioritet nÃ¶vbÉ™ni gÃ¶rÃ¼n vÉ™ rolunuza uyÄŸun É™mÉ™liyyatlara keÃ§in.',
        'subscriber_code' => 'Abonent kodu',
        'download_speed' => 'YÃ¼klÉ™mÉ™ sÃ¼rÉ™ti',
        'upload_speed' => 'GÃ¶ndÉ™rmÉ™ sÃ¼rÉ™ti',
        'download_used' => 'YÃ¼klÉ™mÉ™ istifadÉ™si',
        'upload_used' => 'GÃ¶ndÉ™rmÉ™ istifadÉ™si',
        'active_profile' => 'Aktiv paket',
        'last_online' => 'Son aktivlik',
        'profile_block' => 'Profil mÉ™lumatlarÄ±',
        'new_request' => 'DÉ™stÉ™yÉ™ yaz',
        'name' => 'Ad soyad',
        'category' => 'Kateqoriya',
        'contract' => 'MÃ¼qavilÉ™',
        'address' => 'Ãœnvan',
        'email' => 'E-mail',
        'phone' => 'Telefon',
        'phone_update' => 'Telefon nÃ¶mrÉ™sini yenilÉ™',
        'phone_button' => 'NÃ¶mrÉ™ni dÉ™yiÅŸ',
        'ticket_subject' => 'MÃ¼raciÉ™t mÃ¶vzusu',
        'ticket_message' => 'MÃ¼raciÉ™t mÉ™tni',
        'ticket_send' => 'MÃ¼raciÉ™ti gÃ¶ndÉ™r',
        'payments' => 'Ã–dÉ™niÅŸlÉ™r',
        'statements' => 'Hesab Ã§Ä±xarÄ±ÅŸlarÄ±',
        'tickets' => 'MÃ¼raciÉ™tlÉ™r',
        'table_payment' => ['Tarix', 'MÉ™blÉ™ÄŸ', 'Ãœsul', 'Status'],
        'table_statement' => ['Period', 'AylÄ±q borc', 'Ã–dÉ™nib', 'QalÄ±q'],
        'employee_summary' => ['Billing', 'NÃ¶vbÉ™', 'Sizin rol', 'OLT keÃ§idlÉ™ri'],
        'employee_summary_small' => ['Abonent vÉ™ Ã¶dÉ™niÅŸ baxÄ±ÅŸÄ±', 'HazÄ±rda diqqÉ™t tÉ™lÉ™b edÉ™n iÅŸlÉ™r', 'SÉ™lahiyyÉ™t sÉ™viyyÉ™si', 'AÃ§maÄŸa hazÄ±r panellÉ™r'],
        'permissions' => 'Bu hesabla edÉ™ bildiklÉ™riniz',
        'handoffs' => 'Ä°ÅŸÉ™ baÅŸlamaq Ã¼Ã§Ã¼n keÃ§idlÉ™r',
        'billing' => 'Billing paneli',
        'billing_text' => 'Abonent, Ã¶dÉ™niÅŸ vÉ™ mÃ¼raciÉ™t baxÄ±ÅŸÄ±',
        'portal_type' => 'keÃ§id',
        'quick_actions' => 'Tez É™mÉ™liyyatlar',
        'quick_actions_help' => 'Æn Ã§ox istifadÉ™ olunan iÅŸlÉ™rÉ™ bir kliklÉ™ keÃ§in.',
        'quick_payments' => 'Ã–dÉ™niÅŸlÉ™rÉ™ bax',
        'quick_support' => 'DÉ™stÉ™yÉ™ yaz',
        'quick_phone' => 'NÃ¶mrÉ™ni yenilÉ™',
        'quick_billing' => 'BillingÉ™ keÃ§',
        'quick_olt' => 'OLT idarÉ™etmÉ™sini aÃ§',
        'quick_queue' => 'Kritik nÃ¶vbÉ™ni yoxla',
        'queue_text' => 'DiqqÉ™t tÉ™lÉ™b edÉ™n iÅŸlÉ™ri vÉ™ É™sas keÃ§idlÉ™ri Ã¶nÉ™ Ã§Ä±xarÄ±r.',
        'role_summary' => 'HazÄ±rkÄ± sÉ™lahiyyÉ™t',
        'support_note' => 'QÄ±sa mÃ¶vzu yazÄ±n, komanda geri dÃ¶nÃ¼ÅŸ etsin.',
        'handoff_note' => 'Rolunuza uyÄŸun panellÉ™r aÅŸaÄŸÄ±da aÃ§Ä±lÄ±r.',
    ],
    'en' => [
        'dashboard_customer' => 'Customer panel',
        'dashboard_employee' => 'Employee panel',
        'status' => 'Status',
        'role' => 'Role',
        'logout' => 'Log out',
        'customer_text' => 'Your core package details, payments and support requests for the %s plan are gathered here.',
        'employee_text' => 'Start faster from here: open billing or OLT access, review the priority queue and move into the tools available for your role.',
        'subscriber_code' => 'Subscriber code',
        'download_speed' => 'Download speed',
        'upload_speed' => 'Upload speed',
        'download_used' => 'Download usage',
        'upload_used' => 'Upload usage',
        'active_profile' => 'Active package',
        'last_online' => 'Last activity',
        'profile_block' => 'Profile details',
        'new_request' => 'Contact support',
        'name' => 'Full name',
        'category' => 'Category',
        'contract' => 'Contract',
        'address' => 'Address',
        'email' => 'E-mail',
        'phone' => 'Phone',
        'phone_update' => 'Update phone number',
        'phone_button' => 'Change number',
        'ticket_subject' => 'Request subject',
        'ticket_message' => 'Request message',
        'ticket_send' => 'Send request',
        'payments' => 'Payments',
        'statements' => 'Statements',
        'tickets' => 'Requests',
        'table_payment' => ['Date', 'Amount', 'Method', 'Status'],
        'table_statement' => ['Period', 'Monthly charge', 'Paid', 'Balance'],
        'employee_summary' => ['Billing', 'Queue', 'Your role', 'OLT handoffs'],
        'employee_summary_small' => ['Subscriber and payment view', 'Items needing attention now', 'Access level', 'Panels ready to open'],
        'permissions' => 'What this account can do',
        'handoffs' => 'Start with these links',
        'billing' => 'Billing panel',
        'billing_text' => 'Subscriber, payment and request view',
        'portal_type' => 'handoff',
        'quick_actions' => 'Quick actions',
        'quick_actions_help' => 'Jump into the most common tasks in one click.',
        'quick_payments' => 'View payments',
        'quick_support' => 'Contact support',
        'quick_phone' => 'Update phone',
        'quick_billing' => 'Open billing',
        'quick_olt' => 'Open OLT management',
        'quick_queue' => 'Review priority queue',
        'queue_text' => 'Highlights the most important work and links first.',
        'role_summary' => 'Current access',
        'support_note' => 'Write a short subject so the team can respond faster.',
        'handoff_note' => 'Panels available for your role are listed below.',
    ],
    'ru' => [
        'dashboard_customer' => 'ĞŸĞ°Ğ½ĞµĞ»ÑŒ ĞºĞ»Ğ¸ĞµĞ½Ñ‚Ğ°',
        'dashboard_employee' => 'ĞŸĞ°Ğ½ĞµĞ»ÑŒ ÑĞ¾Ñ‚Ñ€ÑƒĞ´Ğ½Ğ¸ĞºĞ°',
        'status' => 'Ğ¡Ñ‚Ğ°Ñ‚ÑƒÑ',
        'role' => 'Ğ Ğ¾Ğ»ÑŒ',
        'logout' => 'Ğ’Ñ‹Ñ…Ğ¾Ğ´',
        'customer_text' => 'Ğ—Ğ´ĞµÑÑŒ ÑĞ¾Ğ±Ñ€Ğ°Ğ½Ñ‹ Ğ¾ÑĞ½Ğ¾Ğ²Ğ½Ñ‹Ğµ Ğ´Ğ°Ğ½Ğ½Ñ‹Ğµ Ğ¿Ğ¾ Ñ‚Ğ°Ñ€Ğ¸Ñ„Ñƒ %s, Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹ Ğ¸ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ Ğ² Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºÑƒ.',
        'employee_text' => 'ĞĞ°Ñ‡Ğ¸Ğ½Ğ°Ğ¹Ñ‚Ğµ Ñ€Ğ°Ğ±Ğ¾Ñ‚Ñƒ Ğ±Ñ‹ÑÑ‚Ñ€ĞµĞµ: Ğ¾Ñ‚ĞºÑ€Ñ‹Ğ²Ğ°Ğ¹Ñ‚Ğµ billing Ğ¸ OLT, ÑĞ¼Ğ¾Ñ‚Ñ€Ğ¸Ñ‚Ğµ Ğ¿Ñ€Ğ¸Ğ¾Ñ€Ğ¸Ñ‚ĞµÑ‚Ğ½ÑƒÑ Ğ¾Ñ‡ĞµÑ€ĞµĞ´ÑŒ Ğ¸ Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´Ğ¸Ñ‚Ğµ Ğ² Ğ¸Ğ½ÑÑ‚Ñ€ÑƒĞ¼ĞµĞ½Ñ‚Ñ‹ Ğ¿Ğ¾ ÑĞ²Ğ¾ĞµĞ¹ Ñ€Ğ¾Ğ»Ğ¸.',
        'subscriber_code' => 'ĞšĞ¾Ğ´ Ğ°Ğ±Ğ¾Ğ½ĞµĞ½Ñ‚Ğ°',
        'download_speed' => 'Ğ¡ĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑŒ Ğ·Ğ°Ğ³Ñ€ÑƒĞ·ĞºĞ¸',
        'upload_speed' => 'Ğ¡ĞºĞ¾Ñ€Ğ¾ÑÑ‚ÑŒ Ğ¾Ñ‚Ğ¿Ñ€Ğ°Ğ²ĞºĞ¸',
        'download_used' => 'Ğ˜ÑĞ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ğ½Ğ¾ Ğ·Ğ°Ğ³Ñ€ÑƒĞ·ĞºĞ¸',
        'upload_used' => 'Ğ˜ÑĞ¿Ğ¾Ğ»ÑŒĞ·Ğ¾Ğ²Ğ°Ğ½Ğ¾ Ğ¾Ñ‚Ğ¿Ñ€Ğ°Ğ²ĞºĞ¸',
        'active_profile' => 'ĞĞºÑ‚Ğ¸Ğ²Ğ½Ñ‹Ğ¹ Ğ¿Ğ°ĞºĞµÑ‚',
        'last_online' => 'ĞŸĞ¾ÑĞ»ĞµĞ´Ğ½ÑÑ Ğ°ĞºÑ‚Ğ¸Ğ²Ğ½Ğ¾ÑÑ‚ÑŒ',
        'profile_block' => 'Ğ”Ğ°Ğ½Ğ½Ñ‹Ğµ Ğ¿Ñ€Ğ¾Ñ„Ğ¸Ğ»Ñ',
        'new_request' => 'ĞĞ°Ğ¿Ğ¸ÑĞ°Ñ‚ÑŒ Ğ² Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºÑƒ',
        'name' => 'Ğ˜Ğ¼Ñ Ğ¸ Ñ„Ğ°Ğ¼Ğ¸Ğ»Ğ¸Ñ',
        'category' => 'ĞšĞ°Ñ‚ĞµĞ³Ğ¾Ñ€Ğ¸Ñ',
        'contract' => 'Ğ”Ğ¾Ğ³Ğ¾Ğ²Ğ¾Ñ€',
        'address' => 'ĞĞ´Ñ€ĞµÑ',
        'email' => 'E-mail',
        'phone' => 'Ğ¢ĞµĞ»ĞµÑ„Ğ¾Ğ½',
        'phone_update' => 'ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚ÑŒ Ğ½Ğ¾Ğ¼ĞµÑ€ Ñ‚ĞµĞ»ĞµÑ„Ğ¾Ğ½Ğ°',
        'phone_button' => 'Ğ˜Ğ·Ğ¼ĞµĞ½Ğ¸Ñ‚ÑŒ Ğ½Ğ¾Ğ¼ĞµÑ€',
        'ticket_subject' => 'Ğ¢ĞµĞ¼Ğ° Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ',
        'ticket_message' => 'Ğ¢ĞµĞºÑÑ‚ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ',
        'ticket_send' => 'ĞÑ‚Ğ¿Ñ€Ğ°Ğ²Ğ¸Ñ‚ÑŒ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ğµ',
        'payments' => 'ĞĞ¿Ğ»Ğ°Ñ‚Ñ‹',
        'statements' => 'Ğ’Ñ‹Ğ¿Ğ¸ÑĞºĞ¸',
        'tickets' => 'ĞĞ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ',
        'table_payment' => ['Ğ”Ğ°Ñ‚Ğ°', 'Ğ¡ÑƒĞ¼Ğ¼Ğ°', 'Ğ¡Ğ¿Ğ¾ÑĞ¾Ğ±', 'Ğ¡Ñ‚Ğ°Ñ‚ÑƒÑ'],
        'table_statement' => ['ĞŸĞµÑ€Ğ¸Ğ¾Ğ´', 'ĞĞ°Ñ‡Ğ¸ÑĞ»ĞµĞ½Ğ¾', 'ĞĞ¿Ğ»Ğ°Ñ‡ĞµĞ½Ğ¾', 'ĞÑÑ‚Ğ°Ñ‚Ğ¾Ğº'],
        'employee_summary' => ['Billing', 'ĞÑ‡ĞµÑ€ĞµĞ´ÑŒ', 'Ğ’Ğ°ÑˆĞ° Ñ€Ğ¾Ğ»ÑŒ', 'ĞŸĞµÑ€ĞµÑ…Ğ¾Ğ´Ñ‹ OLT'],
        'employee_summary_small' => ['ĞŸÑ€Ğ¾ÑĞ¼Ğ¾Ñ‚Ñ€ Ğ°Ğ±Ğ¾Ğ½ĞµĞ½Ñ‚Ğ¾Ğ² Ğ¸ Ğ¾Ğ¿Ğ»Ğ°Ñ‚', 'Ğ§Ñ‚Ğ¾ Ñ‚Ñ€ĞµĞ±ÑƒĞµÑ‚ Ğ²Ğ½Ğ¸Ğ¼Ğ°Ğ½Ğ¸Ñ ÑĞµĞ¹Ñ‡Ğ°Ñ', 'Ğ£Ñ€Ğ¾Ğ²ĞµĞ½ÑŒ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿Ğ°', 'Ğ“Ğ¾Ñ‚Ğ¾Ğ²Ñ‹Ğµ Ğ¿Ğ°Ğ½ĞµĞ»Ğ¸'],
        'permissions' => 'Ğ§Ñ‚Ğ¾ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿Ğ½Ğ¾ ÑÑ‚Ğ¾Ğ¹ ÑƒÑ‡ĞµÑ‚Ğ½Ğ¾Ğ¹ Ğ·Ğ°Ğ¿Ğ¸ÑĞ¸',
        'handoffs' => 'ĞŸĞµÑ€ĞµÑ…Ğ¾Ğ´Ñ‹ Ğ´Ğ»Ñ Ğ½Ğ°Ñ‡Ğ°Ğ»Ğ° Ñ€Ğ°Ğ±Ğ¾Ñ‚Ñ‹',
        'billing' => 'ĞŸĞ°Ğ½ĞµĞ»ÑŒ billing',
        'billing_text' => 'ĞĞ±Ğ¾Ğ½ĞµĞ½Ñ‚Ñ‹, Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹ Ğ¸ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ñ',
        'portal_type' => 'Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´',
        'quick_actions' => 'Ğ‘Ñ‹ÑÑ‚Ñ€Ñ‹Ğµ Ğ´ĞµĞ¹ÑÑ‚Ğ²Ğ¸Ñ',
        'quick_actions_help' => 'ĞŸĞµÑ€ĞµÑ…Ğ¾Ğ´Ğ¸Ñ‚Ğµ Ğº ÑĞ°Ğ¼Ñ‹Ğ¼ Ñ‡Ğ°ÑÑ‚Ñ‹Ğ¼ Ğ·Ğ°Ğ´Ğ°Ñ‡Ğ°Ğ¼ Ğ² Ğ¾Ğ´Ğ¸Ğ½ ĞºĞ»Ğ¸Ğº.',
        'quick_payments' => 'ĞŸĞ¾ÑĞ¼Ğ¾Ñ‚Ñ€ĞµÑ‚ÑŒ Ğ¾Ğ¿Ğ»Ğ°Ñ‚Ñ‹',
        'quick_support' => 'ĞĞ°Ğ¿Ğ¸ÑĞ°Ñ‚ÑŒ Ğ² Ğ¿Ğ¾Ğ´Ğ´ĞµÑ€Ğ¶ĞºÑƒ',
        'quick_phone' => 'ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚ÑŒ Ğ½Ğ¾Ğ¼ĞµÑ€',
        'quick_billing' => 'ĞÑ‚ĞºÑ€Ñ‹Ñ‚ÑŒ billing',
        'quick_olt' => 'ĞÑ‚ĞºÑ€Ñ‹Ñ‚ÑŒ OLT',
        'quick_queue' => 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€Ğ¸Ñ‚ÑŒ Ğ¿Ñ€Ğ¸Ğ¾Ñ€Ğ¸Ñ‚ĞµÑ‚Ğ½ÑƒÑ Ğ¾Ñ‡ĞµÑ€ĞµĞ´ÑŒ',
        'queue_text' => 'ĞŸĞ¾ĞºĞ°Ğ·Ñ‹Ğ²Ğ°ĞµÑ‚ Ğ²Ğ°Ğ¶Ğ½Ñ‹Ğµ Ğ·Ğ°Ğ´Ğ°Ñ‡Ğ¸ Ğ¸ Ğ¾ÑĞ½Ğ¾Ğ²Ğ½Ñ‹Ğµ ÑÑÑ‹Ğ»ĞºĞ¸.',
        'role_summary' => 'Ğ¢ĞµĞºÑƒÑ‰Ğ¸Ğ¹ Ğ´Ğ¾ÑÑ‚ÑƒĞ¿',
        'support_note' => 'ĞšÑ€Ğ°Ñ‚ĞºĞ¾ Ğ¾Ğ¿Ğ¸ÑˆĞ¸Ñ‚Ğµ Ñ‚ĞµĞ¼Ñƒ, Ñ‡Ñ‚Ğ¾Ğ±Ñ‹ ĞºĞ¾Ğ¼Ğ°Ğ½Ğ´Ğ° Ğ¾Ñ‚Ğ²ĞµÑ‚Ğ¸Ğ»Ğ° Ğ±Ñ‹ÑÑ‚Ñ€ĞµĞµ.',
        'handoff_note' => 'ĞĞ¸Ğ¶Ğµ Ğ¿Ğ¾ĞºĞ°Ğ·Ğ°Ğ½Ñ‹ Ğ¿Ğ°Ğ½ĞµĞ»Ğ¸, Ğ´Ğ¾ÑÑ‚ÑƒĞ¿Ğ½Ñ‹Ğµ Ğ²Ğ°ÑˆĞµĞ¹ Ñ€Ğ¾Ğ»Ğ¸.',
    ],
];

$texts['az'] = [
    'dashboard_customer' => 'Musteri paneli',
    'dashboard_employee' => 'Emekdas paneli',
    'status' => 'Status',
    'role' => 'Rol',
    'logout' => 'Cixis',
    'customer_text' => '%s paketi uzre esas melumatlar, odenisler ve destek muracietleri burada toplanir.',
    'employee_text' => 'Buradan ise daha suretli baslamaq ucun billing ve OLT kecidlerini acin, prioritet novbeni gorun ve rolunuza uygun emeliyyatlara kecin.',
    'subscriber_code' => 'Abonent kodu',
    'download_speed' => 'Download sureti',
    'upload_speed' => 'Upload sureti',
    'download_used' => 'Download istifadesi',
    'upload_used' => 'Upload istifadesi',
    'active_profile' => 'Aktiv paket',
    'last_online' => 'Son aktivlik',
    'profile_block' => 'Profil melumatlari',
    'new_request' => 'Desteye yaz',
    'name' => 'Ad soyad',
    'category' => 'Kateqoriya',
    'contract' => 'Muqavile',
    'address' => 'Unvan',
    'email' => 'E-mail',
    'phone' => 'Telefon',
    'phone_update' => 'Telefon nomresini yenile',
    'phone_button' => 'Nomreni deyis',
    'ticket_subject' => 'Muraciet movzusu',
    'ticket_message' => 'Muraciet metni',
    'ticket_send' => 'Muracieti gonder',
    'payments' => 'Odenisler',
    'statements' => 'Hesab cixarislari',
    'tickets' => 'Muracietler',
    'table_payment' => ['Tarix', 'Mebleg', 'Usul', 'Status'],
    'table_statement' => ['Period', 'Ayliq borc', 'Odenib', 'Qaliq'],
    'employee_summary' => ['Billing', 'Novbe', 'Sizin rol', 'OLT kecidleri'],
    'employee_summary_small' => ['Abonent ve odenis baxisi', 'Hazirda diqqet teleb eden isler', 'Selahiyyet seviyesi', 'Acmaga hazir paneller'],
    'permissions' => 'Bu hesabla ede bildikleriniz',
    'handoffs' => 'Ise baslamaq ucun kecidler',
    'billing' => 'Billing paneli',
    'billing_text' => 'Abonent, odenis ve muraciet baxisi',
    'portal_type' => 'kecid',
    'quick_actions' => 'Tez emeliyyatlar',
    'quick_actions_help' => 'En cox istifade olunan islere bir klikle kecin.',
    'quick_payments' => 'Odenislere bax',
    'quick_support' => 'Desteye yaz',
    'quick_phone' => 'Nomreni yenile',
    'quick_billing' => 'Billinge kec',
    'quick_olt' => 'OLT idareetmesini ac',
    'quick_queue' => 'Kritik novbeni yoxla',
    'queue_text' => 'Diqqet teleb eden isleri ve esas kecidleri one cixarir.',
    'role_summary' => 'Hazirki selahiyyet',
    'support_note' => 'Qisa movzu yazin, komanda geri donus etsin.',
    'handoff_note' => 'Rolunuza uygun paneller asagida acilir.',
];

$texts['ru'] = $texts['en'];

$copy = $texts[$lang] ?? $texts['az'];
if ($lang === 'ru') {
    $copy = $texts['az'];
}
$ticketSubjects = app_portal_ticket_subjects();
$customerBilling = $isCustomer && is_array($snapshot['billing'] ?? null) ? $snapshot['billing'] : [];
$customerServices = $isCustomer && is_array($snapshot['internet_services'] ?? null)
    ? array_values(array_filter($snapshot['internet_services'], 'is_array'))
    : [];
$customerExtraServices = $isCustomer ? app_billing_extra_services($snapshot) : [];
if ($customerBilling !== []) {
    $customerExpiresAt = app_billing_payload_primary_expires_at(['internet_services' => $customerServices], $customerBilling);
    if ($customerExpiresAt !== '' && strtotime($customerExpiresAt) !== false) {
        $customerBilling['expires_at'] = date('Y-m-d H:i:s', strtotime($customerExpiresAt));
        $customerBilling['days_left'] = app_billing_days_left($customerExpiresAt);
        $customerBilling['remaining_days_raw'] = app_billing_remaining_days_text($customerExpiresAt);
    }
}
$customerOnts = $isCustomer && is_array($snapshot['ont_profiles'] ?? null)
    ? array_values(array_filter($snapshot['ont_profiles'], 'is_array'))
    : [];
$customerMessages = $isCustomer && is_array($snapshot['messages'] ?? null)
    ? array_values(array_filter($snapshot['messages'], 'is_array'))
    : [];
$customerServiceState = 'unknown';
if ($customerServices !== []) {
    $firstServiceStatus = strtolower((string) ($customerServices[0]['status'] ?? ''));
    $customerServiceState = in_array($firstServiceStatus, ['online', 'aktiv', 'active'], true) ? 'online' : 'offline';
} elseif ($customerBilling !== []) {
    $billingStatus = strtolower((string) ($customerBilling['status'] ?? ''));
    $customerServiceState = in_array($billingStatus, ['online', 'aktiv', 'active'], true) ? 'online' : 'offline';
}
$flashMap = [
    'az' => [
        'portal_phone_updated' => ['type' => 'success', 'message' => 'Nomre ugurla yenilendi. Billing inteqrasiyasi aktivdirse deyisiklik orada da gorunecek.'],
        'portal_phone_failed' => ['type' => 'error', 'message' => 'Nomreni yenilemek olmadi. Formati yeniden yoxlayin.'],
        'portal_ticket_created' => ['type' => 'success', 'message' => 'Muracietiniz qeyde alindi.'],
        'portal_ticket_failed' => ['type' => 'error', 'message' => 'Muracieti yaratmaq olmadi. Zehmet olmasa yeniden cehd edin.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu. Sehifeni yenileyib bir daha yoxlayin.'],
        'portal_bridge_unavailable' => ['type' => 'error', 'message' => 'Bu kecid hele hazir deyil. OLT giris ayarlari tamamlanmalidir.'],
        'portal_bridge_forbidden' => ['type' => 'error', 'message' => 'Bu hesab ucun uygun OLT kecidi tapilmadi.'],
        'portal_billing_unavailable' => ['type' => 'error', 'message' => 'Billing kecidi hazir deyil. Billing URL ve ya SSO ayarlari tamamlanmalidir.'],
        'portal_password_updated' => ['type' => 'success', 'message' => 'Portal sifresi yenilendi.'],
        'portal_password_failed' => ['type' => 'error', 'message' => 'Sifre yenilenmedi. Cari sifreni ve yeni sifreni yeniden yoxlayin.'],
        'operator_ticket_created' => ['type' => 'success', 'message' => 'Bilet operator panelinde yaradildi.'],
        'operator_ticket_failed' => ['type' => 'error', 'message' => 'Operator bileti yaradilmadi.'],
        'technician_ticket_closed' => ['type' => 'success', 'message' => 'Bilet ugurla baglandi.'],
        'technician_ticket_failed' => ['type' => 'error', 'message' => 'Bilet baglanmadi. Linki ve qeydi yoxlayin.'],
        'module_forbidden' => ['type' => 'error', 'message' => 'Bu modul sizin emekdas rolunuz ucun aciq deyil.'],
    ],
    'en' => [
        'portal_phone_updated' => ['type' => 'success', 'message' => 'Phone number updated successfully. If billing is active, the change will appear there as well.'],
        'portal_phone_failed' => ['type' => 'error', 'message' => 'Phone number could not be updated. Please recheck the format.'],
        'portal_ticket_created' => ['type' => 'success', 'message' => 'Your request has been recorded.'],
        'portal_ticket_failed' => ['type' => 'error', 'message' => 'The request could not be created. Please try again.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Session verification failed. Refresh the page and try again.'],
        'portal_bridge_unavailable' => ['type' => 'error', 'message' => 'This handoff is not ready yet. OLT login settings must be completed.'],
        'portal_bridge_forbidden' => ['type' => 'error', 'message' => 'No matching OLT handoff was found for this account.'],
        'portal_billing_unavailable' => ['type' => 'error', 'message' => 'Billing handoff is not ready. Complete the billing URL or SSO settings.'],
        'portal_password_updated' => ['type' => 'success', 'message' => 'Portal password updated.'],
        'portal_password_failed' => ['type' => 'error', 'message' => 'Password update failed.'],
        'operator_ticket_created' => ['type' => 'success', 'message' => 'Ticket created in CRM.'],
        'operator_ticket_failed' => ['type' => 'error', 'message' => 'CRM ticket could not be created.'],
        'technician_ticket_closed' => ['type' => 'success', 'message' => 'Ticket closed successfully.'],
        'technician_ticket_failed' => ['type' => 'error', 'message' => 'Ticket could not be closed.'],
        'module_forbidden' => ['type' => 'error', 'message' => 'This module is not available for your employee role.'],
    ],
    'ru' => [
        'portal_phone_updated' => ['type' => 'success', 'message' => 'ĞĞ¾Ğ¼ĞµÑ€ ÑƒÑĞ¿ĞµÑˆĞ½Ğ¾ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½. Ğ•ÑĞ»Ğ¸ billing Ğ°ĞºÑ‚Ğ¸Ğ²ĞµĞ½, Ğ¸Ğ·Ğ¼ĞµĞ½ĞµĞ½Ğ¸Ğµ Ğ¿Ğ¾ÑĞ²Ğ¸Ñ‚ÑÑ Ğ¸ Ñ‚Ğ°Ğ¼.'],
        'portal_phone_failed' => ['type' => 'error', 'message' => 'ĞĞµ ÑƒĞ´Ğ°Ğ»Ğ¾ÑÑŒ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚ÑŒ Ğ½Ğ¾Ğ¼ĞµÑ€. ĞŸÑ€Ğ¾Ğ²ĞµÑ€ÑŒÑ‚Ğµ Ñ„Ğ¾Ñ€Ğ¼Ğ°Ñ‚ ĞµÑ‰Ğµ Ñ€Ğ°Ğ·.'],
        'portal_ticket_created' => ['type' => 'success', 'message' => 'Ğ’Ğ°ÑˆĞµ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ğµ Ğ·Ğ°Ñ€ĞµĞ³Ğ¸ÑÑ‚Ñ€Ğ¸Ñ€Ğ¾Ğ²Ğ°Ğ½Ğ¾.'],
        'portal_ticket_failed' => ['type' => 'error', 'message' => 'ĞĞµ ÑƒĞ´Ğ°Ğ»Ğ¾ÑÑŒ ÑĞ¾Ğ·Ğ´Ğ°Ñ‚ÑŒ Ğ¾Ğ±Ñ€Ğ°Ñ‰ĞµĞ½Ğ¸Ğµ. ĞŸĞ¾Ğ¿Ñ€Ğ¾Ğ±ÑƒĞ¹Ñ‚Ğµ ĞµÑ‰Ğµ Ñ€Ğ°Ğ·.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'ĞŸÑ€Ğ¾Ğ²ĞµÑ€ĞºĞ° ÑĞµÑÑĞ¸Ğ¸ Ğ½Ğµ Ğ¿Ñ€Ğ¾ÑˆĞ»Ğ°. ĞĞ±Ğ½Ğ¾Ğ²Ğ¸Ñ‚Ğµ ÑÑ‚Ñ€Ğ°Ğ½Ğ¸Ñ†Ñƒ Ğ¸ Ğ¿Ğ¾Ğ²Ñ‚Ğ¾Ñ€Ğ¸Ñ‚Ğµ Ğ¿Ğ¾Ğ¿Ñ‹Ñ‚ĞºÑƒ.'],
        'portal_bridge_unavailable' => ['type' => 'error', 'message' => 'Ğ­Ñ‚Ğ¾Ñ‚ Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´ Ğ¿Ğ¾ĞºĞ° Ğ½Ğµ Ğ³Ğ¾Ñ‚Ğ¾Ğ². ĞÑƒĞ¶Ğ½Ğ¾ Ğ·Ğ°Ğ²ĞµÑ€ÑˆĞ¸Ñ‚ÑŒ Ğ½Ğ°ÑÑ‚Ñ€Ğ¾Ğ¹ĞºĞ¸ Ğ²Ñ…Ğ¾Ğ´Ğ° Ğ² OLT.'],
        'portal_bridge_forbidden' => ['type' => 'error', 'message' => 'Ğ”Ğ»Ñ ÑÑ‚Ğ¾Ğ¹ ÑƒÑ‡ĞµÑ‚Ğ½Ğ¾Ğ¹ Ğ·Ğ°Ğ¿Ğ¸ÑĞ¸ Ğ½Ğµ Ğ½Ğ°Ğ¹Ğ´ĞµĞ½ Ğ¿Ğ¾Ğ´Ñ…Ğ¾Ğ´ÑÑ‰Ğ¸Ğ¹ Ğ¿ĞµÑ€ĞµÑ…Ğ¾Ğ´ Ğ² OLT.'],
        'portal_billing_unavailable' => ['type' => 'error', 'message' => 'ĞŸĞµÑ€ĞµÑ…Ğ¾Ğ´ Ğ² billing Ğ¿Ğ¾ĞºĞ° Ğ½Ğµ Ğ³Ğ¾Ñ‚Ğ¾Ğ². Ğ—Ğ°Ğ²ĞµÑ€ÑˆĞ¸Ñ‚Ğµ URL Ğ¸Ğ»Ğ¸ SSO Ğ½Ğ°ÑÑ‚Ñ€Ğ¾Ğ¹ĞºĞ¸.'],
        'portal_password_updated' => ['type' => 'success', 'message' => 'ĞŸĞ°Ñ€Ğ¾Ğ»ÑŒ Ğ¿Ğ¾Ñ€Ñ‚Ğ°Ğ»Ğ° Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½.'],
        'portal_password_failed' => ['type' => 'error', 'message' => 'ĞŸĞ°Ñ€Ğ¾Ğ»ÑŒ Ğ½Ğµ Ğ¾Ğ±Ğ½Ğ¾Ğ²Ğ»ĞµĞ½.'],
        'operator_ticket_created' => ['type' => 'success', 'message' => 'Ğ‘Ğ¸Ğ»ĞµÑ‚ ÑĞ¾Ğ·Ğ´Ğ°Ğ½ Ğ² CRM.'],
        'operator_ticket_failed' => ['type' => 'error', 'message' => 'ĞĞµ ÑƒĞ´Ğ°Ğ»Ğ¾ÑÑŒ ÑĞ¾Ğ·Ğ´Ğ°Ñ‚ÑŒ Ğ±Ğ¸Ğ»ĞµÑ‚.'],
        'technician_ticket_closed' => ['type' => 'success', 'message' => 'Ğ‘Ğ¸Ğ»ĞµÑ‚ Ğ·Ğ°ĞºÑ€Ñ‹Ñ‚.'],
        'technician_ticket_failed' => ['type' => 'error', 'message' => 'ĞĞµ ÑƒĞ´Ğ°Ğ»Ğ¾ÑÑŒ Ğ·Ğ°ĞºÑ€Ñ‹Ñ‚ÑŒ Ğ±Ğ¸Ğ»ĞµÑ‚.'],
    ],
];

if (isset($flashMap[$lang][$status])) {
    $flash = $flashMap[$lang][$status];
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Optik Portal</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <header class="portal-topbar">
        <div class="portal-topbar-inner">
            <a href="index.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" class="portal-brand">
                <img src="images/favicon.png" alt="Super Optik Tech">
                <span>Super Optik Portal</span>
            </a>

            <div class="portal-topbar-actions">
                <div class="portal-user-chip">
                    <strong><?= e($user['name']); ?></strong>
                    <span><?= e($isCustomer ? ($user['category'] ?: $copy['dashboard_customer']) : ($user['employee_role'] ?: $copy['dashboard_employee'])); ?></span>
                </div>
                <form action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <button type="submit" class="portal-ghost-btn"><?= e($copy['logout']); ?></button>
                </form>
            </div>
        </div>
    </header>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?> portal-reveal" data-autohide-alert>
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($isCustomer): ?>
            <section class="portal-hero portal-reveal">
                <div>
                    <span class="portal-kicker"><?= e($copy['dashboard_customer']); ?></span>
                    <h1><?= e($snapshot['profile']['full_name']); ?></h1>
                    <p><?= e(sprintf($copy['customer_text'], $snapshot['profile']['plan'])); ?></p>
                </div>
                <div class="portal-status-card">
                    <span><?= e($copy['status']); ?></span>
                    <strong><?= e($snapshot['profile']['status']); ?></strong>
                    <small><?= e($copy['subscriber_code']); ?>: <?= e($snapshot['profile']['subscriber_code']); ?></small>
                </div>
            </section>

            <section class="portal-card portal-reveal">
                <div class="portal-card-head">
                    <h2><?= e($copy['quick_actions']); ?></h2>
                    <small><?= e($copy['quick_actions_help']); ?></small>
                </div>
                <div class="portal-quick-links portal-grid-3">
                    <a class="portal-link-card" href="#customer-payments">
                        <div>
                            <strong><?= e($copy['quick_payments']); ?></strong>
                            <span><?= e($copy['payments']); ?></span>
                        </div>
                    </a>
                    <a class="portal-link-card" href="#customer-support">
                        <div>
                            <strong><?= e($copy['quick_support']); ?></strong>
                            <span><?= e($copy['support_note']); ?></span>
                        </div>
                    </a>
                    <a class="portal-link-card" href="#customer-services">
                        <div>
                            <strong>Internet xetti</strong>
                            <span>PPPoE/IP, VLAN ve ONT yeri</span>
                        </div>
                    </a>
                    <a class="portal-link-card" href="#customer-phone">
                        <div>
                            <strong><?= e($copy['quick_phone']); ?></strong>
                            <span><?= e($copy['phone']); ?>: <?= e($snapshot['profile']['phone']); ?></span>
                        </div>
                    </a>
                    <a class="portal-link-card" href="#customer-tickets">
                        <div>
                            <strong>Muraciet tarixcesi</strong>
                            <span><?= e((string) count($snapshot['tickets'])); ?> bilet</span>
                        </div>
                    </a>
                    <a class="portal-link-card" href="#customer-messages">
                        <div>
                            <strong>Mesaj tarixcesi</strong>
                            <span><?= e((string) count($customerMessages)); ?> bildiris</span>
                        </div>
                    </a>
                </div>
            </section>

            <section class="portal-grid portal-grid-4 portal-reveal">
                <article class="portal-metric-card">
                    <span><?= e($copy['download_speed']); ?></span>
                    <strong><?= e($snapshot['traffic']['download_speed']); ?></strong>
                    <small><?= e($copy['active_profile']); ?></small>
                </article>
                <article class="portal-metric-card">
                    <span><?= e($copy['upload_speed']); ?></span>
                    <strong><?= e($snapshot['traffic']['upload_speed']); ?></strong>
                    <small><?= e($copy['active_profile']); ?></small>
                </article>
                <article class="portal-metric-card">
                    <span><?= e($copy['download_used']); ?></span>
                    <strong><?= e($snapshot['traffic']['download_used']); ?></strong>
                    <small><?= e($snapshot['traffic']['period']); ?></small>
                </article>
                <article class="portal-metric-card">
                    <span><?= e($copy['upload_used']); ?></span>
                    <strong><?= e($snapshot['traffic']['upload_used']); ?></strong>
                    <small><?= e($copy['last_online']); ?>: <?= e($snapshot['traffic']['last_online']); ?></small>
                </article>
            </section>

            <section class="portal-grid portal-grid-2 portal-reveal">
                <article class="portal-card" id="customer-phone">
                    <div class="portal-card-head">
                        <h2><?= e($copy['profile_block']); ?></h2>
                    </div>
                    <div class="portal-profile-list">
                        <div><span><?= e($copy['name']); ?></span><strong><?= e($snapshot['profile']['full_name']); ?></strong></div>
                        <div><span><?= e($copy['category']); ?></span><strong><?= e($snapshot['profile']['category']); ?></strong></div>
                        <div><span><?= e($copy['contract']); ?></span><strong><?= e($snapshot['profile']['contract_no']); ?></strong></div>
                        <div><span><?= e($copy['address']); ?></span><strong><?= e($snapshot['profile']['address']); ?></strong></div>
                        <div><span><?= e($copy['email']); ?></span><strong><?= e($snapshot['profile']['email']); ?></strong></div>
                        <div><span><?= e($copy['phone']); ?></span><strong><?= e($snapshot['profile']['phone']); ?></strong></div>
                    </div>

                    <form class="portal-inline-form" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post">
                        <input type="hidden" name="action" value="update_phone">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <label for="newPhone"><?= e($copy['phone_update']); ?></label>
                        <div class="portal-inline-inputs">
                            <input id="newPhone" type="tel" name="phone" placeholder="+994501234567" required>
                            <button type="submit"><?= e($copy['phone_button']); ?></button>
                        </div>
                    </form>

                    <form class="portal-inline-form" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post">
                        <input type="hidden" name="action" value="update_password">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <label for="currentPassword">Portal sifresini deyis</label>
                        <div class="portal-form-row">
                            <input id="currentPassword" type="password" name="current_password" placeholder="Cari sifre" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="password" name="new_password" placeholder="Yeni sifre" minlength="6" required>
                            <input type="password" name="confirm_password" placeholder="Yeni sifreni tesdiqle" minlength="6" required>
                        </div>
                        <button type="submit">Sifreni yenile</button>
                    </form>
                </article>

                <article class="portal-card" id="customer-support">
                    <div class="portal-card-head">
                        <h2><?= e($copy['new_request']); ?></h2>
                        <small>Movzu secin, qisa qeyd yazin ve bileti texnike yonlendirek.</small>
                    </div>
                    <form class="portal-stacked-form" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" method="post">
                        <input type="hidden" name="action" value="create_ticket">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <div class="portal-form-row">
                            <label for="ticketSubject"><?= e($copy['ticket_subject']); ?></label>
                            <select id="ticketSubject" name="subject" required>
                                <option value="">Movzu secin</option>
                                <?php foreach ($ticketSubjects as $subject): ?>
                                    <option value="<?= e($subject); ?>"><?= e($subject); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <label for="ticketMessage"><?= e($copy['ticket_message']); ?></label>
                            <textarea id="ticketMessage" name="message" rows="5" maxlength="160" placeholder="Qisa izah yazin..." required data-ticket-message></textarea>
                            <small class="portal-char-counter" data-char-counter>0 / 160</small>
                        </div>
                        <button type="submit" class="portal-primary-btn"><?= e($copy['ticket_send']); ?></button>
                    </form>
                </article>
            </section>

            <section class="portal-grid portal-grid-2 portal-reveal" id="customer-services">
                <article class="portal-card portal-service-card portal-service-<?= e($customerServiceState); ?>">
                    <div class="portal-card-head">
                        <h2>Xidmet ve balans</h2>
                        <small>Musteriye yalniz lazimi xidmet, balans ve bitme melumatlari gosterilir.</small>
                    </div>
                    <div class="portal-profile-list">
                        <div><span>Xidmet novu</span><strong><?= e((string) ($customerBilling['service_type'] ?? '-')); ?></strong></div>
                        <div><span>Balans</span><strong><?= e((string) ($customerBilling['balance'] ?? '-')); ?></strong></div>
                        <div><span>Aylig borc</span><strong><?= e((string) ($customerBilling['monthly_total'] ?? $customerBilling['monthly_fee'] ?? '-')); ?></strong></div>
                        <div><span>Elave xidmet</span><strong><?= e((string) ($customerBilling['extra_services_total'] ?? '0.00 AZN')); ?></strong></div>
                        <div><span>Bitme tarixi</span><strong><?= e((string) ($customerBilling['expires_at'] ?? '-')); ?></strong></div>
                        <div><span>Qalan gun</span><strong><?= e((string) (($customerBilling['remaining_days_raw'] ?? '') ?: ($customerBilling['days_left'] ?? '-'))); ?></strong></div>
                        <div><span>Xidmet statusu</span><strong><?= e((string) ($customerBilling['status'] ?? '-')); ?></strong></div>
                    </div>

                    <?php if ($customerExtraServices !== []): ?>
                        <div class="portal-table-wrap portal-section-gap">
                            <table class="portal-table">
                                <thead>
                                    <tr><th>Elave xidmet</th><th>Melumat</th><th>Qiymet</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customerExtraServices as $extraService): ?>
                                        <tr>
                                            <td><?= e((string) ($extraService['type_label'] ?? $extraService['title'] ?? '-')); ?></td>
                                            <td><?= e((string) ($extraService['details'] ?? $extraService['title'] ?? '-')); ?></td>
                                            <td><?= e(billing_money($extraService['price'] ?? 0)); ?></td>
                                            <td><span class="portal-pill"><?= e((string) ($extraService['status'] ?? '-')); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="portal-table-wrap portal-section-gap">
                        <table class="portal-table">
                            <thead>
                                <tr><th>Xidmet</th><th>Istifadeci</th><th>IP</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($customerServices === []): ?>
                                    <tr><td colspan="4">Internet xidmeti melumati tapilmadi.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($customerServices as $service): ?>
                                    <tr>
                                        <td><?= e((string) ($service['service_type'] ?? '-')); ?></td>
                                        <td><?= e((string) ($service['username'] ?? '-')); ?></td>
                                        <td><?= e((string) ($service['ip_address'] ?? '-')); ?></td>
                                        <td><span class="portal-pill"><?= e((string) ($service['status'] ?? '-')); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="portal-card">
                    <div class="portal-card-head">
                        <h2>ONT / modem melumatlari</h2>
                        <small>Musteri ucun SN, MAC ve modem temperaturu.</small>
                    </div>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead>
                                <tr><th>Modem</th><th>SN / MAC</th><th>Modem temperaturu</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($customerOnts === []): ?>
                                    <tr><td colspan="4">ONT/modem melumati tapilmadi.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($customerOnts as $ont): ?>
                                    <tr>
                                        <td>ONT / modem</td>
                                        <td><?= e((string) ($ont['serial'] ?? '-')); ?><br><small><?= e((string) ($ont['mac'] ?? '-')); ?></small></td>
                                        <td><?= e((string) ($ont['temperature'] ?? '-')); ?> C</td>
                                        <td><span class="portal-pill"><?= e((string) ($ont['status'] ?? '-')); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="portal-grid portal-grid-2 portal-reveal">
                <article class="portal-card" id="customer-payments">
                    <div class="portal-card-head">
                        <h2><?= e($copy['payments']); ?></h2>
                    </div>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead>
                                <tr><th><?= e($copy['table_payment'][0]); ?></th><th><?= e($copy['table_payment'][1]); ?></th><th><?= e($copy['table_payment'][2]); ?></th><th><?= e($copy['table_payment'][3]); ?></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($snapshot['payments'] as $payment): ?>
                                    <tr>
                                        <td><?= e($payment['date']); ?></td>
                                        <td><?= e($payment['amount']); ?></td>
                                        <td><?= e($payment['method']); ?></td>
                                        <td><?= e($payment['status']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="portal-card">
                    <div class="portal-card-head">
                        <h2><?= e($copy['statements']); ?></h2>
                    </div>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead>
                                <tr><th><?= e($copy['table_statement'][0]); ?></th><th><?= e($copy['table_statement'][1]); ?></th><th><?= e($copy['table_statement'][2]); ?></th><th><?= e($copy['table_statement'][3]); ?></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($snapshot['statements'] as $statement): ?>
                                    <?php
                                    $statementPeriod = (string) ($statement['period'] ?? $statement['date'] ?? '-');
                                    $statementCharge = (string) ($statement['charge'] ?? $statement['monthly_charge'] ?? $statement['amount'] ?? '0 AZN');
                                    $statementPaid = (string) ($statement['paid'] ?? $statement['paid_amount'] ?? $statement['payment'] ?? '0 AZN');
                                    $statementBalance = (string) ($statement['balance'] ?? $statement['remaining'] ?? $statement['new_balance'] ?? '0 AZN');
                                    ?>
                                    <tr>
                                        <td><?= e($statementPeriod); ?></td>
                                        <td><?= e($statementCharge); ?></td>
                                        <td><?= e($statementPaid); ?></td>
                                        <td><?= e($statementBalance); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="portal-card portal-reveal" id="customer-messages">
                <div class="portal-card-head">
                    <h2>Mesaj tarixcesi</h2>
                    <small>Odenis, bitme, modem xeberdarligi ve kampaniya bildirisleri burada gorunur.</small>
                </div>
                <div class="portal-table-wrap">
                    <table class="portal-table">
                        <thead>
                            <tr><th>Tarix</th><th>Kanal</th><th>Status</th><th>Mesaj</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($customerMessages === []): ?>
                                <tr><td colspan="4">Mesaj tarixcesi yoxdur.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($customerMessages as $message): ?>
                                <tr>
                                    <td><?= e((string) ($message['created_at'] ?? '')); ?></td>
                                    <td><?= e((string) ($message['channel'] ?? '-')); ?></td>
                                    <td><span class="portal-pill"><?= e((string) ($message['status'] ?? '-')); ?></span></td>
                                    <td><?= e((string) ($message['message_body'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="portal-card portal-reveal" id="customer-tickets">
                <div class="portal-card-head">
                    <h2><?= e($copy['tickets']); ?></h2>
                </div>
                <div class="portal-ticket-list">
                    <?php foreach ($snapshot['tickets'] as $ticket): ?>
                        <article class="portal-ticket-item">
                            <div class="portal-ticket-body">
                                <strong><?= e($ticket['id']); ?> - <?= e($ticket['subject']); ?></strong>
                                <p><?= e($ticket['message']); ?></p>
                                <div class="portal-ticket-details">
                                    <span><strong>Acilma:</strong> <?= e((string) ($ticket['opened_at'] ?? $ticket['updated_at'] ?? '')); ?></span>
                                    <span><strong>Erazi:</strong> <?= e((string) ($ticket['area'] ?? '-')); ?></span>
                                    <span><strong>Texnik:</strong> <?= e((string) ($ticket['assigned_technician_name'] ?? 'Teyin edilmeyib')); ?></span>
                                    <?php if ((string) ($ticket['resolved_at'] ?? '') !== ''): ?>
                                        <span><strong>Baglanma:</strong> <?= e((string) $ticket['resolved_at']); ?></span>
                                    <?php endif; ?>
                                    <?php if ((string) ($ticket['resolution_reason'] ?? '') !== ''): ?>
                                        <span><strong>Sebeb:</strong> <?= e((string) $ticket['resolution_reason']); ?></span>
                                    <?php endif; ?>
                                    <?php if ((string) ($ticket['resolution_note'] ?? '') !== ''): ?>
                                        <span><strong>Gorulen is:</strong> <?= e((string) $ticket['resolution_note']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="portal-ticket-meta">
                                <span><?= e($ticket['status']); ?></span>
                                <small>Son yenilenme: <?= e((string) ($ticket['updated_at'] ?? '')); ?></small>
                                <?php if ((string) ($ticket['handled_by'] ?? '') !== ''): ?>
                                    <small>Baxdi: <?= e((string) $ticket['handled_by']); ?></small>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="portal-hero portal-reveal">
                <div>
                    <span class="portal-kicker"><?= e($copy['dashboard_employee']); ?></span>
                    <h1><?= e($user['name']); ?></h1>
                    <p><?= e($copy['employee_text']); ?></p>
                </div>
                <div class="portal-status-card">
                    <span><?= e($copy['role']); ?></span>
                    <strong><?= e($snapshot['summary']['role_title']); ?></strong>
                    <small><?= e($snapshot['summary']['shift']); ?></small>
                </div>
            </section>

            <section class="portal-card portal-reveal" data-portal-modules>
                <div class="portal-card-head">
                    <h2>Is modullari</h2>
                    <small>Yalniz emekdas rolunuza uygun saheler gosterilir.</small>
                </div>
                <?php billing_render_workflow_guide('Gundelik isi buradan basladin', [
                    ['title' => 'Saheni sec', 'text' => 'Maliyye, HR, Anbar, Billing, Mesaj, Statistika ve Sebeke ayrica qruplasdirilib.', 'icon' => 'fa-layer-group'],
                    ['title' => 'Lazim olan emeliyyati ac', 'text' => 'Her modulda cedvel, forma, hesabat ve jurnal eyni qaydada gosterilecek.', 'icon' => 'fa-list-check'],
                    ['title' => 'Yadda saxla ve jurnali yoxla', 'text' => 'Deyisiklikler logda qalir; lazim olsa adminler kim ne edib gore bilir.', 'icon' => 'fa-shield-halved'],
                ], 'Meqsed odur ki, emekdas oz isine aid melumati basqa bolmelerde axtarmasin.'); ?>
                <div class="portal-quick-links portal-grid-4">
                    <?php foreach ($employeeModuleCatalog as $moduleKey => $module): ?>
                        <?php if (!in_array($moduleKey, $employeeModuleKeys, true)) { continue; } ?>
                        <a
                            class="portal-link-card"
                            href="<?= e((string) ($module['href'] ?? '#')); ?>"
                            data-portal-module="<?= e((string) $moduleKey); ?>"
                        >
                            <div>
                                <strong><?= e((string) ($module['label'] ?? $moduleKey)); ?></strong>
                                <span><?= e((string) ($module['description'] ?? '')); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if (app_portal_is_admin($user)): ?>
                <section class="portal-card portal-reveal">
                    <div class="portal-card-head">
                        <h2>Admin kecidleri</h2>
                        <small>Provider ayarlari ve emeliyyat jurnali ucun birbasa kecidler.</small>
                    </div>
                    <div class="portal-quick-links portal-grid-4">
                        <a class="portal-link-card" href="message-center.php?view=overview<?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>#sms-provider-settings">
                            <div>
                                <strong>SMS provider ayari</strong>
                                <span>Username ve password deyismek ucun</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="operation-logs.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong>Emeliyyat jurnali</strong>
                                <span>Kim neyi deyisib, niye deyisib ve ne vaxt edib</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="message-center.php?view=audit<?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong>Mesaj auditi</strong>
                                <span>SMS, WhatsApp, Telegram ve app gonderim cehdleri</span>
                            </div>
                        </a>
                    </div>
                </section>
            <?php endif; ?>

            <section class="portal-grid portal-grid-4 portal-reveal">
                <article class="portal-metric-card">
                    <span><?= e($copy['employee_summary'][0]); ?></span>
                    <strong><?= e($snapshot['summary']['billing_access']); ?></strong>
                    <small><?= e($copy['employee_summary_small'][0]); ?></small>
                </article>
                <article class="portal-metric-card">
                    <span><?= e($copy['employee_summary'][1]); ?></span>
                    <strong><?= e($snapshot['summary']['open_queue']); ?></strong>
                    <small><?= e($copy['employee_summary_small'][1]); ?></small>
                </article>
                <article class="portal-metric-card">
                    <span><?= e($copy['employee_summary'][2]); ?></span>
                    <strong><?= e($snapshot['summary']['role_title']); ?></strong>
                    <small><?= e($copy['employee_summary_small'][2]); ?></small>
                </article>
                <article class="portal-metric-card">
                    <span><?= e($copy['employee_summary'][3]); ?></span>
                    <strong>3 <?= e($copy['portal_type']); ?></strong>
                    <small><?= e($copy['employee_summary_small'][3]); ?></small>
                </article>
            </section>

            <section class="portal-card portal-reveal">
                <div class="portal-card-head">
                    <h2><?= e($copy['permissions']); ?></h2>
                </div>
                <div class="portal-badge-list">
                    <?php foreach ($snapshot['permissions'] as $permission): ?>
                        <span><?= e($permission); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if (false): ?>
                <article class="portal-card">
                    <div class="portal-card-head">
                        <h2><?= e($copy['handoffs']); ?></h2>
                        <small><?= e($copy['handoff_note']); ?></small>
                    </div>
                    <div class="portal-action-list">
                        <a class="portal-link-card" href="crm.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong>CRM paneli</strong>
                                <span>Operator, texnik ve online bilet axini</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="billing-dashboard.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong><?= e($copy['billing']); ?></strong>
                                <span><?= e($copy['billing_text']); ?></span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="message-center.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong>Mesaj merkezi</strong>
                                <span>Ferdi, qrup ve test mesajlari</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="operations-center.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong>Operations Center</strong>
                                <span>Single-pane kontrol merkezi, universal axtaris ve modul kecidleri</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="mgs-integration.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong>Sistem servislari</strong>
                                <span>Alarm, Telegram bot, backup worker ve dedup registry</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="billing-dashboard.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong>Billing idareetme</strong>
                                <span>Musteri, odenis, nagd kassa, Radius ve statistika</span>
                            </div>
                        </a>
                        <?php if (app_portal_is_admin($user)): ?>
                            <a class="portal-link-card" href="identity-integrations.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                                <div>
                                    <strong>MyGov ID inteqrasiyasi</strong>
                                    <span>SSO config, state ve identity mapping</span>
                                </div>
                            </a>
                            <a class="portal-link-card" href="radius-active.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                                <div>
                                    <strong>FreeRADIUS aktiv list</strong>
                                    <span>Online sessiya, trafik ve auth loglari</span>
                                </div>
                            </a>
                            <a class="portal-link-card" href="operation-logs.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                                <div>
                                    <strong>Emeliyyat jurnali</strong>
                                    <span>Kim neyi deyisib, niye deyisib ve protected ledger izleri</span>
                                </div>
                            </a>
                        <?php endif; ?>
                        <a class="portal-link-card" href="<?= e(app_mgs_bridge_url('ont')); ?>">
                            <div>
                                <strong>OLT / ONT idareetme</strong>
                                <span>SN, MAC, PON, optik deyerler, device discovery ve VLAN kecidleri</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="<?= e(app_mgs_bridge_url('inventory')); ?>">
                            <div>
                                <strong>Anbar paneli</strong>
                                <span>ONT/modem, kabel barabani, barkod scan ve kritik stok</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="<?= e(app_mgs_bridge_url('finance')); ?>">
                            <div>
                                <strong>Maliyye paneli</strong>
                                <span>Xerc, medaxil, satis, filial neticesi ve audit qeydlari</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="<?= e(app_mgs_bridge_url('technical')); ?>">
                            <div>
                                <strong>Texniki isler</strong>
                                <span>Ticketden task yaratma, saha planlama ve material istifadəsi</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="<?= e(app_mgs_bridge_url('hr')); ?>">
                            <div>
                                <strong>HR ve KPI paneli</strong>
                                <span>Davamiyyet, is qrafiki, mezuniyyet ve texnik performansi</span>
                            </div>
                        </a>
                    </div>
                </article>
                <?php endif; ?>
            </section>

            <section class="portal-card portal-reveal">
                <div class="portal-card-head">
                    <h2>Son bilet hereketleri</h2>
                    <small>Texnik assignment, acilma vaxti ve baglanma qeydi burada gorunur.</small>
                </div>
                <div class="portal-ticket-list">
                    <?php foreach (array_slice($snapshot['tickets'], 0, 8) as $ticket): ?>
                        <article class="portal-ticket-item">
                            <div class="portal-ticket-body">
                                <strong><?= e((string) ($ticket['id'] ?? '')); ?> - <?= e((string) ($ticket['subject'] ?? '')); ?></strong>
                                <p><?= e((string) ($ticket['customer_name'] ?? '')); ?> / <?= e((string) ($ticket['phone'] ?? '')); ?> / <?= e((string) ($ticket['area'] ?? '')); ?></p>
                                <div class="portal-ticket-details">
                                    <span><strong>Texnik:</strong> <?= e((string) ($ticket['assigned_technician_name'] ?? '')); ?></span>
                                    <span><strong>Acilma:</strong> <?= e((string) ($ticket['opened_at'] ?? '')); ?></span>
                                    <?php if ((string) ($ticket['resolved_at'] ?? '') !== ''): ?>
                                        <span><strong>Baglanma:</strong> <?= e((string) $ticket['resolved_at']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="portal-ticket-meta">
                                <span><?= e((string) ($ticket['status'] ?? '')); ?></span>
                                <?php if ((string) ($ticket['resolution_note'] ?? '') !== ''): ?>
                                    <small><?= e((string) ($ticket['resolution_note'] ?? '')); ?></small>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
