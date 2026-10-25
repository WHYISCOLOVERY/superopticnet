import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import 'outbox_service.dart';

class OfflineSyncService {
  static bool _running = false;

  static Future<void> syncNow() async {
    if (_running) return;
    _running = true;
    try {
      await OutboxService.flushPending();
      final role = await SecureStorageService.getUserRole() ?? '';
      final capabilities = await SecureStorageService.getCapabilities();
      final requests = <Future<dynamic>>[];
      if (['technician', 'engineer', 'team_lead'].contains(role)) {
        requests.add(ApiClient().dio.get(ApiEndpoints.technicianDashboard));
        requests.add(ApiClient().dio.get(ApiEndpoints.technicianTasks));
        requests.add(ApiClient().dio.get(ApiEndpoints.myCustomers));
        requests.add(ApiClient().dio.get('/technician/inventory-options.php'));
      }
      if (['operator', 'night_operator', 'support', 'noc', 'network_engineer']
          .contains(role)) {
        requests.add(ApiClient().dio.get(ApiEndpoints.staffDashboard));
        requests.add(ApiClient().dio.get(ApiEndpoints.staffTickets,
            queryParameters: const {'status': 'active', 'query': ''}));
        requests.add(ApiClient().dio.get(ApiEndpoints.staffTickets,
            queryParameters: const {'status': 'completed', 'query': ''}));
        requests.add(ApiClient().dio.get(ApiEndpoints.staffNocMonitor));
      }
      if (['admin', 'manager', 'super_admin', 'director', 'team_lead']
          .contains(role)) {
        requests.add(ApiClient().dio.get(ApiEndpoints.managerDashboard));
        requests.add(ApiClient().dio.get(ApiEndpoints.managerTaskBoard));
        requests.add(ApiClient().dio.get(ApiEndpoints.managerFleetLive));
      }
      if (capabilities.contains('finance') ||
          ['admin', 'super_admin', 'director'].contains(role)) {
        for (final range in ['today', 'week', 'month', 'year']) {
          requests.add(ApiClient()
              .dio
              .get('/finance/ledger.php', queryParameters: {'range': range}));
        }
      }
      await Future.wait(requests.map((request) async {
        try {
          await request;
        } catch (_) {}
      }));
    } finally {
      _running = false;
    }
  }
}
