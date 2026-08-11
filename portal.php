<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/design-system-lib.php';
require_once __DIR__ . '/ui-language-lib.php';
require_once __DIR__ . '/communication-consent-lib.php';

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
                'Müştəri paneli',
                'Əməkdaş paneli',
                'Çıxış',
                'Telefon nömrəsini yenilə',
                'Müraciət mövzusu',
                'Müraciət mətni',
                'Müraciəti göndər',
                'Ödənişlər',
                'Hesab çıxarışları',
                'Müraciətlər',
                'Növbə',
                'Tez əməliyyatlar',
                'Billingə keç',
                'OLT idarəetməsini aç',
                'Kritik növbəni yoxla',
                'Müqavilə',
                'Buradan işə daha sürətli başlamaq üçün billing və OLT keçidlərini açın, prioritet növbəni görün və rolunuza uyğun əməliyyatlara keçin.',
                'Diqqət tələb edən işləri və əsas keçidləri önə çıxarır.',
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
        'dashboard_customer' => 'Müştəri paneli',
        'dashboard_employee' => 'Əməkdaş paneli',
        'status' => 'Status',
        'role' => 'Rol',
        'logout' => 'Çıxış',
        'customer_text' => '%s paketi üzrə əsas məlumatlar, ödənişlər və dəstək müraciətləri burada toplanır.',
        'employee_text' => 'Buradan işə daha sürətli başlamaq üçün billing və OLT keçidlərini açın, prioritet növbəni görün və rolunuza uyğun əməliyyatlara keçin.',
        'subscriber_code' => 'Abonent kodu',
        'download_speed' => 'Yükləmə sürəti',
        'upload_speed' => 'Göndərmə sürəti',
        'download_used' => 'Yükləmə istifadəsi',
        'upload_used' => 'Göndərmə istifadəsi',
        'active_profile' => 'Aktiv paket',
        'last_online' => 'Son aktivlik',
        'profile_block' => 'Profil məlumatları',
        'new_request' => 'Dəstəyə yaz',
        'name' => 'Ad soyad',
        'category' => 'Kateqoriya',
        'contract' => 'Müqavilə',
        'address' => 'Ünvan',
        'email' => 'E-mail',
        'phone' => 'Telefon',
        'phone_update' => 'Telefon nömrəsini yenilə',
        'phone_button' => 'Nömrəni dəyiş',
        'ticket_subject' => 'Müraciət mövzusu',
        'ticket_message' => 'Müraciət mətni',
        'ticket_send' => 'Müraciəti göndər',
        'payments' => 'Ödənişlər',
        'statements' => 'Hesab çıxarışları',
        'tickets' => 'Müraciətlər',
        'table_payment' => ['Tarix', 'Məbləğ', 'Üsul', 'Status'],
        'table_statement' => ['Period', 'Aylıq borc', 'Ödənib', 'Qalıq'],
        'employee_summary' => ['Billing', 'Növbə', 'Sizin rol', 'OLT keçidləri'],
        'employee_summary_small' => ['Abonent və ödəniş baxışı', 'Hazırda diqqət tələb edən işlər', 'Səlahiyyət səviyyəsi', 'Açmağa hazır panellər'],
        'permissions' => 'Bu hesabla edə bildikləriniz',
        'handoffs' => 'İşə başlamaq üçün keçidlər',
        'billing' => 'Billing paneli',
        'billing_text' => 'Abonent, ödəniş və müraciət baxışı',
        'portal_type' => 'keçid',
        'quick_actions' => 'Tez əməliyyatlar',
        'quick_actions_help' => 'Ən çox istifadə olunan işlərə bir kliklə keçin.',
        'quick_payments' => 'Ödənişlərə bax',
        'quick_support' => 'Dəstəyə yaz',
        'quick_phone' => 'Nömrəni yenilə',
        'quick_billing' => 'Billingə keç',
        'quick_olt' => 'OLT idarəetməsini aç',
        'quick_queue' => 'Kritik növbəni yoxla',
        'queue_text' => 'Diqqət tələb edən işləri və əsas keçidləri önə çıxarır.',
        'role_summary' => 'Hazırkı səlahiyyət',
        'support_note' => 'Qısa mövzu yazın, komanda geri dönüş etsin.',
        'handoff_note' => 'Rolunuza uyğun panellər aşağıda açılır.',
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
        'dashboard_customer' => 'Панель клиента',
        'dashboard_employee' => 'Панель сотрудника',
        'status' => 'Статус',
        'role' => 'Роль',
        'logout' => 'Выход',
        'customer_text' => 'Здесь собраны основные данные по тарифу %s, оплаты и обращения в поддержку.',
        'employee_text' => 'Начинайте работу быстрее: открывайте billing и OLT, смотрите приоритетную очередь и переходите в инструменты по своей роли.',
        'subscriber_code' => 'Код абонента',
        'download_speed' => 'Скорость загрузки',
        'upload_speed' => 'Скорость отправки',
        'download_used' => 'Использовано загрузки',
        'upload_used' => 'Использовано отправки',
        'active_profile' => 'Активный пакет',
        'last_online' => 'Последняя активность',
        'profile_block' => 'Данные профиля',
        'new_request' => 'Написать в поддержку',
        'name' => 'Имя и фамилия',
        'category' => 'Категория',
        'contract' => 'Договор',
        'address' => 'Адрес',
        'email' => 'E-mail',
        'phone' => 'Телефон',
        'phone_update' => 'Обновить номер телефона',
        'phone_button' => 'Изменить номер',
        'ticket_subject' => 'Тема обращения',
        'ticket_message' => 'Текст обращения',
        'ticket_send' => 'Отправить обращение',
        'payments' => 'Оплаты',
        'statements' => 'Выписки',
        'tickets' => 'Обращения',
        'table_payment' => ['Дата', 'Сумма', 'Способ', 'Статус'],
        'table_statement' => ['Период', 'Начислено', 'Оплачено', 'Остаток'],
        'employee_summary' => ['Billing', 'Очередь', 'Ваша роль', 'Переходы OLT'],
        'employee_summary_small' => ['Просмотр абонентов и оплат', 'Что требует внимания сейчас', 'Уровень доступа', 'Готовые панели'],
        'permissions' => 'Что доступно этой учетной записи',
        'handoffs' => 'Переходы для начала работы',
        'billing' => 'Панель billing',
        'billing_text' => 'Абоненты, оплаты и обращения',
        'portal_type' => 'переход',
        'quick_actions' => 'Быстрые действия',
        'quick_actions_help' => 'Переходите к самым частым задачам в один клик.',
        'quick_payments' => 'Посмотреть оплаты',
        'quick_support' => 'Написать в поддержку',
        'quick_phone' => 'Обновить номер',
        'quick_billing' => 'Открыть billing',
        'quick_olt' => 'Открыть OLT',
        'quick_queue' => 'Проверить приоритетную очередь',
        'queue_text' => 'Показывает важные задачи и основные ссылки.',
        'role_summary' => 'Текущий доступ',
        'support_note' => 'Кратко опишите тему, чтобы команда ответила быстрее.',
        'handoff_note' => 'Ниже показаны панели, доступные вашей роли.',
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
$customerMarketingOptedOut = false;
if ($isCustomer && app_db_available()) {
    $consentDb = app_db();
    if ($consentDb instanceof PDO) {
        $consentAccount = app_db_account_by_id((int) ($user['id'] ?? 0));
        $customerPhoneForConsent = is_array($consentAccount) ? (string) ($consentAccount['phone'] ?? '') : '';
        if ($customerPhoneForConsent !== '') {
            $customerMarketingOptedOut = app_communication_is_opted_out($consentDb, $customerPhoneForConsent, 'sms');
        }
    }
}
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
        'portal_consent_updated' => ['type' => 'success', 'message' => 'Bildiris tercihiniz yenilendi.'],
        'portal_consent_failed' => ['type' => 'error', 'message' => 'Terciheiniz saxlanmadi. Zehmet olmasa yeniden cehd edin.'],
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
        'portal_consent_updated' => ['type' => 'success', 'message' => 'Your notification preference was updated.'],
        'portal_consent_failed' => ['type' => 'error', 'message' => 'Preference could not be saved. Please try again.'],
    ],
    'ru' => [
        'portal_phone_updated' => ['type' => 'success', 'message' => 'Номер успешно обновлен. Если billing активен, изменение появится и там.'],
        'portal_phone_failed' => ['type' => 'error', 'message' => 'Не удалось обновить номер. Проверьте формат еще раз.'],
        'portal_ticket_created' => ['type' => 'success', 'message' => 'Ваше обращение зарегистрировано.'],
        'portal_ticket_failed' => ['type' => 'error', 'message' => 'Не удалось создать обращение. Попробуйте еще раз.'],
        'csrf_failed' => ['type' => 'error', 'message' => 'Проверка сессии не прошла. Обновите страницу и повторите попытку.'],
        'portal_bridge_unavailable' => ['type' => 'error', 'message' => 'Этот переход пока не готов. Нужно завершить настройки входа в OLT.'],
        'portal_bridge_forbidden' => ['type' => 'error', 'message' => 'Для этой учетной записи не найден подходящий переход в OLT.'],
        'portal_billing_unavailable' => ['type' => 'error', 'message' => 'Переход в billing пока не готов. Завершите URL или SSO настройки.'],
        'portal_password_updated' => ['type' => 'success', 'message' => 'Пароль портала обновлен.'],
        'portal_password_failed' => ['type' => 'error', 'message' => 'Пароль не обновлен.'],
        'operator_ticket_created' => ['type' => 'success', 'message' => 'Билет создан в CRM.'],
        'operator_ticket_failed' => ['type' => 'error', 'message' => 'Не удалось создать билет.'],
        'technician_ticket_closed' => ['type' => 'success', 'message' => 'Билет закрыт.'],
        'technician_ticket_failed' => ['type' => 'error', 'message' => 'Не удалось закрыть билет.'],
        'portal_consent_updated' => ['type' => 'success', 'message' => 'Ваши настройки уведомлений обновлены.'],
        'portal_consent_failed' => ['type' => 'error', 'message' => 'Не удалось сохранить настройки. Попробуйте еще раз.'],
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
    <link rel="stylesheet" href="<?= e(app_asset('css/design-system.css')); ?>">
</head>
<body class="portal-body"<?= app_design_system_body_attribute(); ?>>
    <header class="portal-topbar">
        <div class="portal-topbar-inner">
            <a href="<?= e(app_config('public_site_url', 'https://superoptic.net/')); ?>" class="portal-brand">
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
                        <h2>Sebeke</h2>
                        <small>Modeminizin qosulma veziyyeti. Texniki detallar (seriya, MAC ve s.) yalniz destek xidmetine gorunur.</small>
                    </div>
                    <div class="portal-table-wrap">
                        <table class="portal-table">
                            <thead>
                                <tr><th>Modem</th><th>Veziyyet</th><th>Son yenilenme</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($customerOnts === []): ?>
                                    <tr><td colspan="3">Modem melumati tapilmadi.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($customerOnts as $ont):
                                    $ontStatusRaw = strtolower(trim((string) ($ont['status'] ?? '')));
                                    $ontIsOnline = in_array($ontStatusRaw, ['online', 'active', 'up', 'run', 'working', 'used_working'], true);
                                    $ontIsOffline = in_array($ontStatusRaw, ['offline', 'down', 'disabled', 'lost'], true);
                                    $ontStatusLabel = $ontIsOnline ? 'Onlayn' : ($ontIsOffline ? 'Oflayn' : 'Namelum');
                                    $ontStatusToneClass = $ontIsOnline ? ' portal-pill-success' : ($ontIsOffline ? ' portal-pill-danger' : '');
                                ?>
                                    <tr>
                                        <td>ONT / modem</td>
                                        <td><span class="portal-pill<?= e($ontStatusToneClass); ?>"><?= e($ontStatusLabel); ?></span></td>
                                        <td><?= e((string) ($ont['optical_synced_at'] ?? '-')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($customerOnts !== [] && !array_filter($customerOnts, static fn (array $o): bool => in_array(strtolower(trim((string) ($o['status'] ?? ''))), ['online', 'active', 'up', 'run', 'working', 'used_working'], true))): ?>
                        <small>Sebeke ile bagli problem oldugunu dusunursunuzse, <a href="#customer-support">destek bolmesinden</a> muraciet aca bilersiniz.</small>
                    <?php endif; ?>
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
                <?php if ($customerPhoneForConsent !== ''): ?>
                <form method="post" action="portal-actions.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" class="portal-stacked-form portal-section-gap">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <input type="hidden" name="action" value="update_marketing_consent">
                    <div class="portal-form-row">
                        <label><input type="checkbox" name="marketing_consent" value="1" <?= $customerMarketingOptedOut ? '' : 'checked'; ?>> Kampaniya ve teklif SMS-lerini qebul etmek isteyirem</label>
                    </div>
                    <small>Odenis, xidmet ve bilet bildirisleri bu ayardan asili olmayaraq gonderilir.</small>
                    <button class="portal-ghost-btn" type="submit">Yadda saxla</button>
                </form>
                <?php endif; ?>
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
                        <a class="portal-link-card" href="message-center.php?view=settings<?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>#sms-provider-settings">
                            <div>
                                <strong>Mesaj kanallari</strong>
                                <span>SMS, WhatsApp ve xarici gonderis hazirligini yoxla</span>
                            </div>
                        </a>
                        <a class="portal-link-card" href="mgs-integration.php?view=bots<?= $lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''; ?>">
                            <div>
                                <strong>Telegram botlari ve qruplar</strong>
                                <span>Bot elave et, yenile, qrup Chat ID-sini ve hazirliq statusunu idare et</span>
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
                        <?php if (app_portal_is_super_admin($user)): ?>
                            <a class="portal-link-card" href="api-integrations.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">
                                <div>
                                    <strong>API inteqrasiyalari</strong>
                                    <span>Emanat, United Payment ve yeni provider acarlarini yarat, rotasiya et ve legv et</span>
                                </div>
                            </a>
                        <?php endif; ?>
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
    <script src="<?= e(app_asset('script/design-system.js')); ?>" defer></script>
</body>
</html>
