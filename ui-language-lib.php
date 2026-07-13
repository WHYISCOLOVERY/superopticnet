<?php
declare(strict_types=1);

function app_ui_label(string $key, ?string $fallback = null): string
{
    static $labels = [
        'dashboard' => 'Umumi baxis',
        'details' => 'Detallar',
        'export_csv' => 'CSV ixrac',
        'import' => 'Import et',
        'settings_key' => 'Ayar acari',
        'problem_key' => 'Problem acari',
        'severity' => 'Agirliq',
        'value_type' => 'Deyer tipi',
        'source' => 'Menbe',
        'data_analytics' => 'Data analitika',
        'analytics_center' => 'Analitika merkezi',
        'backup_worker' => 'Backup xidmeti',
        'operational_snapshot' => 'Billing, musteri, RADIUS, bonus ve mesaj datasi uzre yigilmis emeliyyat xulasesi.',
        'inspection' => 'Yoxlama',
        'inspection_results' => 'Yoxlama neticeleri',
        'asset' => 'Avadanliq',
        'serial_mac' => 'Serial / MAC',
        'return_ticket' => 'Qaytarma bileti',
        'record_id' => 'Qeyd ID',
        'period' => 'Dovr',
        'base_salary' => 'Baza maas',
        'finance_entry' => 'Maliyye qeydi',
        'action' => 'Emeliyyat',
        'actor' => 'Icra eden',
        'entity' => 'Obyekt',
        'gross_salary' => 'Gross maas',
        'net_salary' => 'Net maas',
        'salary_mode' => 'Maas tipi',
        'income_tax' => 'Gelir vergisi',
        'employer_total_cost' => 'Sirketin tam xerci',
        'status' => 'Veziyyet',
        'imported_at' => 'Import tarixi',
        'manual_adjustment' => 'El duzelisi',
        'bonus_audit_journal' => 'Bonus jurnali',
        'legal_profile_preview' => 'Qanuni profil yoxlamasi',
        'finance_bridge' => 'Maliyye baglantisi',
        'preview_gross_base' => 'Gross baza yoxlamasi',
        'preview_employee_deductions' => 'Isci tutulmasi yoxlamasi',
        'preview_employer_total_cost' => 'Sirket xerci yoxlamasi',
        'provider_terminal_revenue' => 'Provider / terminal gelirleri',
        'radius_status' => 'RADIUS veziyyeti',
        'bonus_periods' => 'Bonus dovrleri',
    ];

    return $labels[$key] ?? $fallback ?? $key;
}

function app_ui_action(string $key, ?string $fallback = null): string
{
    static $actions = [
        'create' => 'Yarat',
        'save' => 'Saxla',
        'apply' => 'Tetbiq et',
        'approve' => 'Tesdiqle',
        'send_for_approval' => 'Tesdiqe gonder',
        'pay' => 'Odenis et',
        'calculate' => 'Hesabla',
        'recalculate' => 'Yeniden hesabla',
        'calculate_recalculate' => 'Hesabla / yeniden hesabla',
        'export' => 'Ixrac et',
        'export_csv' => 'CSV ixrac et',
        'import' => 'Import et',
        'link' => 'Bagla',
        'view_rules' => 'Qaydalara bax',
        'view_audit' => 'Jurnala bax',
        'print' => 'Cap et',
        'add' => 'Elave et',
        'update_status' => 'Statusu yenile',
    ];

    return $actions[$key] ?? $fallback ?? $key;
}

function app_ui_status(string $status, ?string $fallback = null): string
{
    static $statuses = [
        '' => 'Butun statuslar',
        'active' => 'Aktiv',
        'inactive' => 'Passiv',
        'disabled' => 'Passiv',
        'suspended' => 'Dayandirilmis',
        'expired' => 'Vaxti bitib',
        'open' => 'Aciq',
        'partial' => 'Qismen',
        'closed' => 'Bagli',
        'draft' => 'Qaralama',
        'calculated' => 'Hesablanib',
        'recalculated' => 'Yeniden hesablanib',
        'pending' => 'Gozlemede',
        'pending_approval' => 'Tesdiq gozleyir',
        'planned' => 'Planlanib',
        'dry_run' => 'Yoxlama rejimi',
        'queued' => 'Novbededir',
        'sent' => 'Gonderilib',
        'skipped' => 'Buraxilib',
        'executed' => 'Icra edilib',
        'approved' => 'Tesdiqlenib',
        'paid' => 'Odenib',
        'payable' => 'Odenilecek',
        'excluded' => 'Hesablanmir',
        'cancelled' => 'Legv edilib',
        'void' => 'Legv edilib',
        'complete' => 'Tamam',
        'completed' => 'Tamamlanib',
        'missing' => 'Catismir',
        'imported' => 'Import edilib',
        'failed' => 'Ugursuz',
        'new' => 'Yeni',
        'seen' => 'Gorulub',
        'in_progress' => 'Icradadir',
        'not_allowed' => 'Icaze yoxdur',
        'qr' => 'QR gozleyir',
        'connected' => 'Qosulub',
        'normal' => 'Normal',
        'employee' => 'Emekdas',
        'network' => 'Sebeke',
        'customer' => 'Musteri',
        'unknown' => 'Bilinmir',
        'none' => 'Yoxdur',
    ];

    $normalized = strtolower(trim($status));
    return $statuses[$normalized] ?? $fallback ?? ($status !== '' ? $status : '-');
}

function app_ui_select_status_options(array $keys): array
{
    $options = [];
    foreach ($keys as $key) {
        $options[(string) $key] = app_ui_status((string) $key);
    }
    return $options;
}
