import 'package:flutter/material.dart';

import '../manager/manager_operations_screen.dart';
import '../technician/technician_dashboard_screen.dart';
import 'role_dashboard_screen.dart';
import '../finance/finance_screen.dart';
import '../inventory/inventory_screen.dart';

Widget homeForEmployeeRole(String? role) {
  final normalized = (role ?? '').trim().toLowerCase();
  if (normalized == 'finance') return const FinanceScreen();
  if ({'warehouse', 'warehouse_manager'}.contains(normalized)) {
    return const InventoryScreen();
  }

  if ({'super_admin', 'admin', 'manager', 'director', 'team_lead'}
      .contains(normalized)) {
    return const ManagerOperationsScreen();
  }
  if ({'technician', 'field_technician'}.contains(normalized)) {
    return const TechnicianDashboardScreen();
  }

  return RoleDashboardScreen(
      role: normalized.isEmpty ? 'employee' : normalized);
}
