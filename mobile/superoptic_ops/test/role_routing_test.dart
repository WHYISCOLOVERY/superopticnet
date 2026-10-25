import 'package:flutter_test/flutter_test.dart';
import 'package:superoptic_ops/features/finance/finance_screen.dart';
import 'package:superoptic_ops/features/inventory/inventory_screen.dart';
import 'package:superoptic_ops/features/home/role_dashboard_screen.dart';
import 'package:superoptic_ops/features/home/role_home.dart';
import 'package:superoptic_ops/features/manager/manager_operations_screen.dart';
import 'package:superoptic_ops/features/technician/technician_dashboard_screen.dart';

void main() {
  test('employee roles open the correct mobile home', () {
    expect(homeForEmployeeRole('super_admin'), isA<ManagerOperationsScreen>());
    expect(homeForEmployeeRole('admin'), isA<ManagerOperationsScreen>());
    expect(homeForEmployeeRole('technician'), isA<TechnicianDashboardScreen>());
    expect(homeForEmployeeRole('operator'), isA<RoleDashboardScreen>());
    expect(homeForEmployeeRole('engineer'), isA<RoleDashboardScreen>());
    expect(homeForEmployeeRole('finance'), isA<FinanceScreen>());
    expect(homeForEmployeeRole('warehouse'), isA<InventoryScreen>());
    expect(homeForEmployeeRole('warehouse_manager'), isA<InventoryScreen>());
  });
}
