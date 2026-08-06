<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_operations_action_module(string $action): ?string
{
    $actions = [
        'network' => [
            'scan_network','create_monitor_device','create_technical_task',
            'read_device','read_device_sensitive','read_noc_logs','read_monitor_map','update_monitor_map',
            'probe_network','probe_device','run_olt_rules','preview_device_backup','apply_device_backup',
            'read_ont_live','execute_ont_control','update_monitoring_mode',
            'update_device_access','update_vendor_defaults','update_command_map',
            'execute_device_command','update_management_path','seed_device_profiles',
            'sync_process_registry','record_noc_alarm',
            'read_security_anomaly','run_security_anomaly_scan',
            'read_router','manage_router','bind_router',
        ],
        'inventory' => [
            'create_inventory_category','create_inventory_movement','create_inventory_handover',
            'create_inventory_intake','create_inventory_branch_transfer','update_inventory_item',
            'deactivate_inventory_item','adjust_inventory_stock','create_inventory_handover_local',
            'confirm_inventory_handover','return_inventory_from_employee',
            'create_inventory_external_request','approve_inventory_request','reject_inventory_request',
            'receive_inventory_asset_return','inspect_inventory_asset_return',
            'create_inventory_repair','update_inventory_repair_status',
        ],
        'finance' => [
            'create_finance_income','void_finance_income','save_finance_counterparty',
            'link_finance_counterparty','create_finance_entry','save_asset_depreciation',
            'create_finance_debt_record','record_finance_debt_payment','import_finance_e_invoice',
            'calculate_employee_bonus_period','change_employee_bonus_period_status',
            'save_employee_bonus_setting','save_employee_bonus_salary_tier',
            'save_employee_bonus_active_day_bracket','save_employee_bonus_quality_rule',
            'save_customer_bonus_assignment','save_employee_bonus_adjustment',
            'change_employee_bonus_adjustment_status',
        ],
        'hr' => [
            'create_hr_employee','update_hr_employee','save_attendance_profile',
            'record_attendance_event','save_hr_employee_compensation','record_hr_score_event',
            'create_hr_payroll_record','create_hr_leave_request','create_hr_travel_request',
            'create_hr_employee_document','update_hr_record_status','record_hr_finance_expense',
        ],
        'messaging' => ['send_bot_test_message'],
    ];
    foreach ($actions as $module => $moduleActions) {
        if (in_array($action, $moduleActions, true)) return $module;
    }
    return null;
}

function app_operations_action_allowed(array $actor, string $action): bool
{
    $module = app_operations_action_module($action);
    if ($module === null) return false;
    if (app_portal_is_admin($actor)) return true;
    $employeeRole = strtolower(trim((string) ($actor['employee_role'] ?? '')));
    if ($module === 'finance') return $employeeRole === 'finance';
    if ($module === 'hr') return $action === 'record_attendance_event' || $employeeRole === 'hr';
    if ($module === 'inventory') {
        if ($action === 'confirm_inventory_handover') return true;
        if (in_array($action, ['approve_inventory_request','reject_inventory_request'], true)) return $employeeRole === 'warehouse_manager';
        return in_array($employeeRole, ['warehouse','warehouse_manager'], true);
    }
    return app_portal_can_access_module($actor, $module);
}
