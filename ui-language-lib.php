<?php
declare(strict_types=1);

function app_ui_label(string $key, ?string $fallback = null): string
{
    static $labels = [
        'dashboard' => 'Ümumi baxış',
        'details' => 'Təfərrüatlar',
        'export_csv' => 'CSV ixracı',
        'import' => 'İdxal',
        'settings_key' => 'Ayar açarı',
        'problem_key' => 'Problem açarı',
        'severity' => 'Ciddilik dərəcəsi',
        'value_type' => 'Dəyər tipi',
        'source' => 'Mənbə',
        'data_analytics' => 'Məlumat analitikası',
        'analytics_center' => 'Analitika mərkəzi',
        'backup_worker' => 'Yedəkləmə xidməti',
        'operational_snapshot' => 'Billing, müştəri, RADIUS, bonus və mesaj məlumatlarının əməliyyat xülasəsi.',
        'inspection' => 'Yoxlama',
        'inspection_results' => 'Yoxlama nəticələri',
        'asset' => 'Avadanlıq',
        'serial_mac' => 'Serial / MAC',
        'return_ticket' => 'Qaytarma sənədi',
        'record_id' => 'Qeyd ID',
        'period' => 'Dövr',
        'base_salary' => 'Baza maaş',
        'finance_entry' => 'Maliyyə qeydi',
        'action' => 'Əməliyyat',
        'actor' => 'İcra edən',
        'entity' => 'Obyekt',
        'gross_salary' => 'Gross maaş',
        'net_salary' => 'Net maaş',
        'salary_mode' => 'Maaş tipi',
        'income_tax' => 'Gəlir vergisi',
        'employer_total_cost' => 'Şirkətin tam xərci',
        'status' => 'Vəziyyət',
        'imported_at' => 'İdxal tarixi',
        'manual_adjustment' => 'Əl ilə düzəliş',
        'bonus_audit_journal' => 'Bonus jurnalı',
        'legal_profile_preview' => 'Qanuni profil yoxlaması',
        'finance_bridge' => 'Maliyyə bağlantısı',
        'preview_gross_base' => 'Gross baza yoxlaması',
        'preview_employee_deductions' => 'İşçi tutulmaları',
        'preview_employer_total_cost' => 'Şirkətin ümumi xərci',
        'provider_terminal_revenue' => 'Provayder və terminal gəlirləri',
        'radius_status' => 'RADIUS vəziyyəti',
        'bonus_periods' => 'Bonus dövrləri',
        'stock_intake' => 'Anbara mədaxil',
        'branch_transfer' => 'Filiala transfer',
        'customer_install' => 'Müştəri quraşdırması',
        'technician_handover' => 'Texnikə təhvil',
        'technician_return' => 'Texnikdən qaytarma',
        'external_return' => 'Xarici qaytarma',
    ];

    return $labels[$key] ?? $fallback ?? $key;
}

function app_ui_action(string $key, ?string $fallback = null): string
{
    static $actions = [
        'create' => 'Yarat',
        'save' => 'Saxla',
        'apply' => 'Tətbiq et',
        'approve' => 'Təsdiqlə',
        'send_for_approval' => 'Təsdiqə göndər',
        'pay' => 'Ödəniş et',
        'calculate' => 'Hesabla',
        'recalculate' => 'Yenidən hesabla',
        'calculate_recalculate' => 'Hesabla / yenidən hesabla',
        'export' => 'İxrac et',
        'export_csv' => 'CSV ixrac et',
        'import' => 'İdxal et',
        'link' => 'Bağla',
        'view_rules' => 'Qaydalara bax',
        'view_audit' => 'Jurnala bax',
        'print' => 'Çap et',
        'add' => 'Əlavə et',
        'update_status' => 'Vəziyyəti yenilə',
    ];

    return $actions[$key] ?? $fallback ?? $key;
}

function app_ui_status(string $status, ?string $fallback = null): string
{
    static $statuses = [
        '' => 'Bütün vəziyyətlər',
        'active' => 'Aktiv',
        'inactive' => 'Passiv',
        'disabled' => 'Passiv',
        'suspended' => 'Dayandırılıb',
        'expired' => 'Vaxtı bitib',
        'open' => 'Açıq',
        'partial' => 'Qismən',
        'closed' => 'Bağlı',
        'draft' => 'Qaralama',
        'posted' => 'Qeydə alınıb',
        'calculated' => 'Hesablanıb',
        'recalculated' => 'Yenidən hesablanıb',
        'pending' => 'Gözləmədə',
        'pending_approval' => 'Təsdiq gözləyir',
        'planned' => 'Planlanıb',
        'dry_run' => 'Yoxlama rejimi',
        'queued' => 'Növbədədir',
        'sent' => 'Göndərilib',
        'skipped' => 'Buraxılıb',
        'executed' => 'İcra edilib',
        'approved' => 'Təsdiqlənib',
        'paid' => 'Ödənib',
        'payable' => 'Ödəniləcək',
        'excluded' => 'Hesablanmır',
        'cancelled' => 'Ləğv edilib',
        'void' => 'Ləğv edilib',
        'complete' => 'Tamam',
        'completed' => 'Tamamlanıb',
        'missing' => 'Çatışmır',
        'imported' => 'İdxal edilib',
        'failed' => 'Uğursuz',
        'new' => 'Yeni',
        'seen' => 'Görülüb',
        'in_progress' => 'İcradadır',
        'not_allowed' => 'İcazə yoxdur',
        'qr' => 'QR gözləyir',
        'connected' => 'Qoşulub',
        'normal' => 'Normal',
        'present' => 'İşdədir',
        'late' => 'Gecikib',
        'absent' => 'İşə gəlməyib',
        'leave' => 'Məzuniyyətdədir',
        'travel' => 'Ezamiyyətdədir',
        'available' => 'Mövcuddur',
        'assigned' => 'Təhkim edilib',
        'returned' => 'Qaytarılıb',
        'damaged' => 'Xətalıdır',
        'warning' => 'Xəbərdarlıq',
        'in_stock' => 'Anbardadır',
        'out_of_stock' => 'Stokda yoxdur',
        'reserved' => 'Rezerv edilib',
        'repair' => 'Təmirdədir',
        'scrapped' => 'Silinib',
        'employee' => 'Əməkdaş',
        'network' => 'Şəbəkə',
        'customer' => 'Müştəri',
        'unknown' => 'Bilinmir',
        'none' => 'Yoxdur',
    ];

    $normalized = strtolower(trim($status));
    return $statuses[$normalized] ?? $fallback ?? ($status !== '' ? $status : '-');
}

function app_ui_message(string $key, ?string $fallback = null): string
{
    static $messages = [
        'empty' => 'Məlumat tapılmadı.',
        'no_records' => 'Bu bölmə üçün qeyd yoxdur.',
        'filter_hint' => 'Filtri dəyişib yenidən yoxlayın.',
        'saved' => 'Qeyd saxlanıldı.',
        'updated' => 'Məlumat yeniləndi.',
        'completed' => 'Əməliyyat tamamlandı.',
        'failed' => 'Əməliyyat alınmadı.',
        'validation_failed' => 'Daxil etdiyiniz məlumatları yoxlayın.',
        'forbidden' => 'Bu əməliyyat üçün icazəniz yoxdur.',
    ];

    return $messages[$key] ?? $fallback ?? $key;
}

function app_ui_select_status_options(array $keys): array
{
    $options = [];
    foreach ($keys as $key) {
        $options[(string) $key] = app_ui_status((string) $key);
    }
    return $options;
}
