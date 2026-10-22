import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../../core/theme/app_theme.dart';
import '../auth/login_screen.dart';
import '../staff/modem_search_screen.dart';
import '../staff/noc_monitor_screen.dart';
import '../finance/finance_screen.dart';
import '../manager/manager_fleet_map_screen.dart';
import '../inventory/inventory_screen.dart';
import '../notifications/notification_center_screen.dart';
import '../staff/operator_tickets_screen.dart';
import '../staff/customer_create_screen.dart';
import '../staff/billing_summary_screen.dart';
import '../../data/services/background_location_service.dart';
import '../../data/services/outbox_service.dart';
import '../../data/services/attendance_location_service.dart';

class RoleDashboardScreen extends StatefulWidget {
  final String role;

  const RoleDashboardScreen({super.key, required this.role});

  @override
  State<RoleDashboardScreen> createState() => _RoleDashboardScreenState();
}

class _RoleDashboardScreenState extends State<RoleDashboardScreen> {
  String _fullName = 'Əməkdaş';
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _metrics = {};
  List<dynamic> _items = [];
  List<String> _capabilities = [];
  bool _isOnDuty = false;
  bool _offline = false;
  int _queuedCount = 0;

  bool get _isEngineer =>
      {'engineer', 'noc', 'network_engineer'}.contains(widget.role);
  bool get _isNightOperator => widget.role == 'night_operator';
  bool get _canSeeBilling => {
        'operator',
        'engineer',
        'noc',
        'network_engineer',
      }.contains(widget.role);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    _fullName = await SecureStorageService.getUserFullName() ?? 'Əməkdaş';
    _capabilities = await SecureStorageService.getCapabilities();
    try {
      final response = await ApiClient().dio.get(ApiEndpoints.staffDashboard);
      final data = response.data['data'] as Map<String, dynamic>;
      final queued = await OutboxService.queuedCount();
      if (!mounted) return;
      setState(() {
        _metrics = data['metrics'] as Map<String, dynamic>? ?? {};
        _items = data['items'] as List<dynamic>? ?? [];
        _offline = response.data['offline_cache'] == true;
        _queuedCount = queued;
        _capabilities =
            (data['capabilities'] as List<dynamic>? ?? _capabilities)
                .map((value) => '$value')
                .toList();
      });
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'İş masası məlumatları yüklənmədi.');
      }
    }
    try {
      await _refreshAttendanceOnly();
    } catch (_) {
      // Dashboard tools remain usable while attendance status is retried.
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _refreshAttendanceOnly() async {
    final attendance = await ApiClient().dio.get(ApiEndpoints.attendanceStatus);
    final onDuty = attendance.data['data']?['is_on_duty'] == true;
    if (mounted) setState(() => _isOnDuty = onDuty);
    if (onDuty) {
      await BackgroundLocationService.startDutyTracking();
    } else {
      await BackgroundLocationService.stopDutyTracking();
    }
  }

  Future<void> _logout() async {
    if (_isOnDuty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Çıxış üçün əvvəl “İş bitdi” düyməsi ilə növbəni tamamlayın.',
            ),
          ),
        );
      }
      return;
    }
    await SecureStorageService.clearAuth();
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  Future<void> _recordAttendance(bool checkIn) async {
    Map<String, dynamic>? payload;
    String? clientUuid;
    try {
      final position = await AttendanceLocationService.capture();
      clientUuid = const Uuid().v4();
      payload = {
        'client_uuid': clientUuid,
        'event_type': checkIn ? 'check_in' : 'check_out',
        'latitude': position.latitude,
        'longitude': position.longitude,
        'accuracy': position.accuracy,
        'is_mock': position.isMocked ? 1 : 0,
        'recorded_at': position.timestamp.toUtc().toIso8601String(),
      };
      await ApiClient().dio.post(ApiEndpoints.attendanceEvent, data: payload);
      setState(() => _isOnDuty = checkIn);
      if (checkIn) {
        await BackgroundLocationService.startDutyTracking();
      } else {
        await BackgroundLocationService.stopDutyTracking();
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(checkIn
              ? 'Növbə başladı. GPS iş vaxtında daim aktiv qalmalıdır.'
              : 'İş bitməsi qeydə alındı.'),
        ));
      }
    } on DioException catch (error) {
      final status = error.response?.statusCode ?? 0;
      if ((status == 0 || status >= 500 || status == 408 || status == 429) &&
          payload != null &&
          clientUuid != null) {
        final accountId = await SecureStorageService.getUserId();
        if (accountId != null) {
          await OutboxService.enqueue(
            clientUuid: clientUuid,
            accountId: accountId,
            endpoint: ApiEndpoints.attendanceEvent,
            payload: payload,
          );
          setState(() => _isOnDuty = checkIn);
          if (checkIn) {
            await BackgroundLocationService.startDutyTracking();
          } else {
            await BackgroundLocationService.stopDutyTracking();
          }
          return;
        }
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(
              '${error.response?.data?['error'] ?? 'Davamiyyət qəbul edilmədi.'}'),
        ));
      }
    } on AttendanceLocationException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(error.message),
        ));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Davamiyyət qeydə alınmadı. Yenidən yoxlayın.'),
        ));
      }
    } finally {
      try {
        await _refreshAttendanceOnly();
      } catch (_) {}
    }
  }

  @override
  Widget build(BuildContext context) {
    final title = _isEngineer
        ? 'Mühəndis iş masası'
        : _isNightOperator
            ? 'Gecə operatoru'
            : 'Operator iş masası';
    return Scaffold(
      appBar: AppBar(
        title: Text(title),
        actions: [
          IconButton(
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(
                  builder: (_) => const NotificationCenterScreen()),
            ),
            icon: const Icon(Icons.notifications_outlined),
          ),
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
          IconButton(onPressed: _logout, icon: const Icon(Icons.logout)),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(18, 12, 18, 28),
                children: [
                  if (_offline || _queuedCount > 0) ...[
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.amber.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: Colors.amber),
                      ),
                      child: Text(_offline
                          ? 'Oflayn rejim · $_queuedCount əməliyyat göndərilməyi gözləyir'
                          : '$_queuedCount əməliyyat sinxronlaşma növbəsindədir'),
                    ),
                    const SizedBox(height: 12),
                  ],
                  if (_error != null) ...[
                    _ErrorState(message: _error!, retry: _load),
                    const SizedBox(height: 12),
                  ],
                  Container(
                    padding: const EdgeInsets.all(20),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(28),
                      gradient: LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: _isEngineer
                            ? const [Color(0xFF3B214F), Color(0xFF101B2E)]
                            : const [Color(0xFF1D3B66), Color(0xFF101B2E)],
                      ),
                      border: Border.all(color: AppTheme.border),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _isEngineer ? 'Şəbəkə nəzarəti' : 'Operator növbəsi',
                          style: const TextStyle(
                            color: AppTheme.mutedText,
                            fontWeight: FontWeight.w800,
                            fontSize: 12,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          'Salam, $_fullName',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 25,
                            fontWeight: FontWeight.w900,
                            letterSpacing: -0.8,
                          ),
                        ),
                        const SizedBox(height: 10),
                        Text(
                          _isEngineer
                              ? 'Anlamlandırılmış alarm, təsir və texniki addımlar.'
                              : 'Bilet, mesaj və abunəçi sorğuları bir növbədə.',
                          style: const TextStyle(
                            color: AppTheme.mutedText,
                            height: 1.35,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 18),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Row(children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                _isOnDuty ? 'Növbədəsiniz' : 'Növbə bağlıdır',
                                style: const TextStyle(
                                    fontWeight: FontWeight.w900),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                _isOnDuty
                                    ? 'GPS arxa fonda aktivdir. İş vaxtında söndürülə bilməz.'
                                    : 'İşə başlayanda mövqeyiniz qeydə alınacaq.',
                                style: const TextStyle(
                                    color: AppTheme.mutedText, fontSize: 12),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 10),
                        FilledButton(
                          onPressed: () => _recordAttendance(!_isOnDuty),
                          child: Text(_isOnDuty ? 'İş bitdi' : 'İşdəyəm'),
                        ),
                      ]),
                    ),
                  ),
                  const SizedBox(height: 14),
                  _metricGrid(),
                  const SizedBox(height: 14),
                  FilledButton.icon(
                    onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => const NocMonitorScreen())),
                    icon: const Icon(Icons.monitor_heart_outlined),
                    label: const Text('NOC monitoru · cihazlar və alarmlar'),
                  ),
                  const SizedBox(height: 10),
                  FilledButton.icon(
                    onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => const ModemSearchScreen())),
                    icon: const Icon(Icons.router_outlined),
                    label: const Text('Kod, MAC və ya SN ilə modem yoxla'),
                  ),
                  if (_canSeeBilling) ...[
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => const BillingSummaryScreen()),
                      ),
                      icon: const Icon(Icons.account_balance_wallet_outlined),
                      label: const Text('Billing icmalı · müştəri və online'),
                    ),
                  ],
                  if (!_isEngineer) ...[
                    const SizedBox(height: 10),
                    FilledButton.icon(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => const CustomerCreateScreen()),
                      ),
                      icon: const Icon(Icons.person_add_alt_1),
                      label: const Text('Yeni müştəri yarat'),
                    ),
                    const SizedBox(height: 10),
                    FilledButton.icon(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => const OperatorTicketsScreen()),
                      ),
                      icon: const Icon(Icons.add_task),
                      label: const Text('Bilet yarat, izlə və yönləndir'),
                    ),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => const ManagerFleetMapScreen(),
                        ),
                      ),
                      icon: const Icon(Icons.near_me_outlined),
                      label:
                          const Text('Canlı xəritə · yaxın əməkdaş və bilet'),
                    ),
                  ],
                  if (_capabilities.contains('warehouse') ||
                      _capabilities.contains('warehouse_manager')) ...[
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => const InventoryScreen()),
                      ),
                      icon: const Icon(Icons.inventory_2_outlined),
                      label: const Text('Anbar və avadanlıq dövriyyəsi'),
                    ),
                  ],
                  if (_capabilities.contains('finance')) ...[
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => const FinanceScreen()),
                      ),
                      icon: const Icon(Icons.account_balance_wallet_outlined),
                      label: const Text('Maliyyə və hesabatlar'),
                    ),
                  ],
                  const SizedBox(height: 20),
                  Text(_isEngineer ? 'Canlı alarmlar' : 'Əməliyyat növbəsi',
                      style: const TextStyle(
                          fontSize: 17, fontWeight: FontWeight.w900)),
                  const SizedBox(height: 8),
                  if (_items.isEmpty)
                    const Card(
                      child: Padding(
                        padding: EdgeInsets.all(24),
                        child: Center(child: Text('Aktiv qeyd yoxdur.')),
                      ),
                    ),
                  ..._items.map(
                      (raw) => _operationCard(raw as Map<String, dynamic>)),
                ],
              ),
            ),
    );
  }

  Widget _metricGrid() {
    final entries = _metrics.entries.toList();
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 12,
        mainAxisSpacing: 12,
        childAspectRatio: 1.28,
      ),
      itemCount: entries.length,
      itemBuilder: (context, index) {
        final entry = entries[index];
        final isCritical =
            entry.key.contains('critical') || entry.key.contains('failed');
        final color = isCritical ? AppTheme.danger : AppTheme.primary;
        return Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppTheme.panel,
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: AppTheme.border),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Icon(_metricIcon(entry.key), color: color),
              Text('${entry.value}',
                  style: const TextStyle(
                      fontSize: 27,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -0.8)),
              Text(_metricLabel(entry.key),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style:
                      const TextStyle(fontSize: 12, color: AppTheme.mutedText)),
            ],
          ),
        );
      },
    );
  }

  Widget _operationCard(Map<String, dynamic> item) {
    final severity = '${item['severity']}';
    final color = _severityColor(severity);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(15),
                  ),
                  child: Icon(
                    _isEngineer
                        ? Icons.warning_amber_rounded
                        : Icons.send_outlined,
                    color: color,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${item['title']}',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w900),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${item['group'] ?? item['subtitle'] ?? ''}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            color: AppTheme.mutedText, fontSize: 12),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Text('${item['status']}',
                    style: TextStyle(
                        color: color,
                        fontSize: 10,
                        fontWeight: FontWeight.w900)),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              '${item['message']}',
              maxLines: 5,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(height: 1.35),
            ),
            const SizedBox(height: 8),
            Text(
              'Son yenilənmə: ${item['occurred_at']}',
              style: const TextStyle(color: AppTheme.mutedText, fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }

  Color _severityColor(String severity) => switch (severity) {
        'emergency' || 'critical' || 'high' => AppTheme.danger,
        'warning' || 'medium' => AppTheme.warning,
        _ => AppTheme.primary,
      };

  IconData _metricIcon(String key) {
    if (key.contains('alarm')) return Icons.crisis_alert;
    if (key.contains('ticket')) return Icons.confirmation_number_outlined;
    if (key.contains('failed')) return Icons.error_outline;
    if (key.contains('message')) return Icons.send_outlined;
    return Icons.analytics_outlined;
  }

  String _metricLabel(String key) {
    const labels = {
      'open_alarms': 'Açıq alarm',
      'critical_alarms': 'Kritik alarm',
      'acknowledged_alarms': 'Qəbul edilmiş',
      'active_tickets': 'Aktiv bilet',
      'open_tickets': 'Açıq bilet',
      'in_progress_tickets': 'İcrada',
      'queued_messages': 'Göndəriş növbəsi',
      'failed_messages': 'Uğursuz göndəriş',
      'sent_today': 'Bu gün göndərilib',
    };
    return labels[key] ?? key;
  }
}

class _ErrorState extends StatelessWidget {
  final String message;
  final Future<void> Function() retry;

  const _ErrorState({required this.message, required this.retry});

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.cloud_off_outlined, size: 48),
              const SizedBox(height: 12),
              Text(message),
              const SizedBox(height: 12),
              FilledButton(
                  onPressed: retry, child: const Text('Yenidən yoxla')),
            ],
          ),
        ),
      );
}
