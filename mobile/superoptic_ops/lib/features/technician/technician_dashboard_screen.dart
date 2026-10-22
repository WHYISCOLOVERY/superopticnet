import 'dart:async';

import 'package:flutter/material.dart';
import '../../core/theme/app_theme.dart';
import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';
import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../../data/services/background_location_service.dart';
import '../../data/services/outbox_service.dart';
import '../../data/services/attendance_location_service.dart';
import '../auth/login_screen.dart';
import 'task_list_screen.dart';
import 'my_customers_screen.dart';
import 'portfolio_bonus_screen.dart';
import '../staff/modem_search_screen.dart';
import '../inventory/inventory_screen.dart';

class TechnicianDashboardScreen extends StatefulWidget {
  const TechnicianDashboardScreen({super.key});

  @override
  State<TechnicianDashboardScreen> createState() =>
      _TechnicianDashboardScreenState();
}

class _TechnicianDashboardScreenState extends State<TechnicianDashboardScreen> {
  bool _isOnDuty = false;
  bool _isLoading = true;
  Map<String, dynamic>? _dashboardData;
  String? _technicianName;
  Timer? _dispatchTimer;
  bool _offline = false;
  int _queuedCount = 0;

  @override
  void initState() {
    super.initState();
    _loadDashboard();
    _dispatchTimer = Timer.periodic(
      const Duration(seconds: 20),
      (_) => _loadDashboard(showLoader: false),
    );
  }

  @override
  void dispose() {
    _dispatchTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadDashboard({bool showLoader = true}) async {
    if (showLoader && mounted) setState(() => _isLoading = true);
    try {
      _technicianName =
          await SecureStorageService.getUserFullName() ?? 'Əməkdaş';
      await OutboxService.flushPending();
      final res = await ApiClient().dio.get(ApiEndpoints.technicianDashboard);
      if (res.statusCode == 200 && res.data['success'] == true) {
        final queued = await OutboxService.queuedCount();
        setState(() {
          _dashboardData = res.data['data'];
          _isOnDuty = _dashboardData?['employee']['is_on_duty'] ?? false;
          _offline = res.data['offline_cache'] == true;
          _queuedCount = queued;
        });

        // Sync background service with current duty status
        if (_isOnDuty) {
          await BackgroundLocationService.startDutyTracking();
        } else {
          await BackgroundLocationService.stopDutyTracking();
        }
      }
    } catch (_) {
      // Gracefully show cached or offline message
    } finally {
      if (mounted && showLoader) setState(() => _isLoading = false);
    }
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
      final response = await ApiClient().dio.post(
            ApiEndpoints.attendanceEvent,
            data: payload,
          );
      if (response.statusCode != 200 || response.data['success'] != true) {
        throw StateError('Server davamiyyət qeydini qəbul etmədi.');
      }
      setState(() => _isOnDuty = checkIn);
      if (checkIn) {
        await BackgroundLocationService.startDutyTracking();
      } else {
        await BackgroundLocationService.stopDutyTracking();
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(checkIn
                ? 'Gəliş vaxtınız və mövqeyiniz qeydə alındı.'
                : 'İş bitmə vaxtınız və mövqeyiniz qeydə alındı.')),
      );
      await _loadDashboard();
    } on DioException catch (error) {
      final status = error.response?.statusCode;
      if (status != null && status < 500 && status != 408 && status != 429) {
        if (!mounted) return;
        final response = error.response?.data;
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(response is Map
              ? '${response['error'] ?? 'Davamiyyət qəbul edilmədi.'}'
              : 'Davamiyyət qəbul edilmədi.'),
        ));
        return;
      }
      final accountId = await SecureStorageService.getUserId();
      if (payload != null && clientUuid != null && accountId != null) {
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
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'İnternet yoxdur. Qeyd telefonda gözləyir və bağlantı gələndə göndəriləcək.'),
        ));
        return;
      }
    } on AttendanceLocationException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.message)),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Davamiyyət qeydə alınmadı. Yenidən yoxlayın.'),
      ));
    } finally {
      await _loadDashboard();
    }
  }

  Future<void> _handleLogout() async {
    if (_isOnDuty) {
      try {
        await ApiClient()
            .dio
            .post(ApiEndpoints.dutyStatus, data: {'is_on_duty': false});
      } catch (_) {}
    }
    await BackgroundLocationService.stopDutyTracking();
    await SecureStorageService.clearAuth();
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final tasks = _dashboardData?['tasks'] ?? {};
    final customers = _dashboardData?['customers'] ?? {};
    final cash = _dashboardData?['cash_collected_today'] ?? {};
    final dispatchCards =
        (_dashboardData?['dispatch_cards'] as List<dynamic>? ?? const []);
    final employee = _dashboardData?['employee'];
    final rawAttendance = employee is Map ? employee['attendance'] : null;
    final attendance =
        rawAttendance is Map ? rawAttendance : <String, dynamic>{};
    final hasCheckedIn =
        (attendance['check_in_at'] ?? '').toString().isNotEmpty;
    final hasCheckedOut =
        (attendance['check_out_at'] ?? '').toString().isNotEmpty;

    return Scaffold(
      appBar: AppBar(
        title: Text(_technicianName ?? 'İş Masası'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadDashboard,
          ),
          IconButton(
            icon: const Icon(Icons.logout),
            onPressed: _handleLogout,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _loadDashboard,
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // Duty Status Banner
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 12),
                      decoration: BoxDecoration(
                        color: _isOnDuty
                            ? const Color(0xFF00E676).withValues(alpha: 0.15)
                            : Colors.red.withValues(alpha: 0.15),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(
                            color: _isOnDuty
                                ? const Color(0xFF00E676)
                                : Colors.redAccent),
                      ),
                      child: Row(
                        children: [
                          Icon(
                            _isOnDuty ? Icons.gps_fixed : Icons.gps_off,
                            color: _isOnDuty
                                ? const Color(0xFF00E676)
                                : Colors.redAccent,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  _isOnDuty
                                      ? 'Növbədəyəm (Aktiv)'
                                      : 'Növbədən Kənar (Qeyri-Aktiv)',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 15),
                                ),
                                Text(
                                  _isOnDuty
                                      ? 'GPS canlı izləmə aktivdir'
                                      : 'GPS izləmə dayandırılıb',
                                  style: const TextStyle(
                                      fontSize: 12, color: Colors.grey),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    if (_offline || _queuedCount > 0) ...[
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: Colors.amber.withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: Colors.amber),
                        ),
                        child: Text(
                          _offline
                              ? 'Oflayn rejim · son saxlanmış məlumatlar · $_queuedCount əməliyyat göndərilməyi gözləyir'
                              : '$_queuedCount əməliyyat sinxronlaşma növbəsindədir',
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                      ),
                      const SizedBox(height: 12),
                    ],
                    Row(children: [
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: hasCheckedIn
                              ? null
                              : () => _recordAttendance(true),
                          icon: const Icon(Icons.login),
                          label: const Text('Gəldim'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: !hasCheckedIn || hasCheckedOut
                              ? null
                              : () => _recordAttendance(false),
                          icon: const Icon(Icons.logout),
                          label: const Text('İş bitdi'),
                        ),
                      ),
                    ]),
                    if (hasCheckedIn) ...[
                      const SizedBox(height: 8),
                      Text(
                        'Gəliş: ${attendance['check_in_at']} · Çıxış: ${hasCheckedOut ? attendance['check_out_at'] : 'iş davam edir'}',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                            fontSize: 12, color: Colors.white70),
                      ),
                    ],
                    const SizedBox(height: 20),

                    if (dispatchCards.isNotEmpty) ...[
                      Row(
                        children: [
                          const Expanded(
                            child: Text(
                              'İndi icra ediləcək',
                              style: TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                          Text(
                            '20 san. canlı',
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: 0.55),
                              fontSize: 12,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 10),
                      for (final raw in dispatchCards.take(5))
                        _dispatchCard(Map<String, dynamic>.from(raw as Map)),
                      const SizedBox(height: 8),
                    ],

                    // Metrics Grid
                    const Text('Günün İcmalı',
                        style: TextStyle(
                            fontSize: 17, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(
                          child: _buildMetricCard(
                            title: 'Gözləyən İşlər',
                            value: '${tasks['pending'] ?? 0}',
                            icon: Icons.assignment_late_outlined,
                            color: Colors.amber,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _buildMetricCard(
                            title: 'İcrada Olan',
                            value: '${tasks['in_progress'] ?? 0}',
                            icon: Icons.engineering_outlined,
                            color: const Color(0xFF00BCD4),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(
                          child: _buildMetricCard(
                            title: 'Tamamlanan',
                            value: '${tasks['completed_today'] ?? 0}',
                            icon: Icons.check_circle_outline,
                            color: const Color(0xFF00E676),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _buildMetricCard(
                            title: 'Toplanan Nağd',
                            value:
                                '${(cash['amount_azn'] ?? 0.0).toStringAsFixed(1)} ₼',
                            icon: Icons.account_balance_wallet_outlined,
                            color: Colors.lightGreenAccent,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),

                    // Bound Customers Card
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: const Color(0xFF112240),
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Row(
                            children: [
                              Icon(Icons.people_alt_outlined,
                                  color: Color(0xFF00BCD4)),
                              SizedBox(width: 8),
                              Text('Mənə Bağlı Abunəçilər',
                                  style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16)),
                            ],
                          ),
                          const SizedBox(height: 12),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text('Cəmi: ${customers['total'] ?? 0} abunəçi',
                                  style: const TextStyle(fontSize: 14)),
                              Text(
                                'Borclu: ${customers['with_debt'] ?? 0} (${(customers['total_debt_azn'] ?? 0.0).toStringAsFixed(1)} ₼)',
                                style: const TextStyle(
                                    fontSize: 14,
                                    color: Colors.redAccent,
                                    fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 24),

                    // Primary Action Buttons
                    ElevatedButton.icon(
                      onPressed: () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (_) => const TaskListScreen()),
                        );
                      },
                      icon: const Icon(Icons.format_list_bulleted_outlined),
                      label: const Text('Günün Tapşırıqları və Qəzalar'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (_) => const MyCustomersScreen()),
                        );
                      },
                      icon: const Icon(Icons.people_outline),
                      label: const Text('Mənim Müştərilərim və Borc İcmalı'),
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 16),
                        side: const BorderSide(color: Color(0xFF00BCD4)),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12)),
                      ),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (_) => const PortfolioBonusScreen()),
                        );
                      },
                      icon: const Icon(Icons.workspace_premium_outlined),
                      label: const Text('Portfel, bonus və risk nəzarəti'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (_) => const ModemSearchScreen())),
                      icon: const Icon(Icons.router_outlined),
                      label: const Text('Müştəri kodu, MAC və ya SN ilə yoxla'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (_) => const InventoryScreen())),
                      icon: const Icon(Icons.inventory_2_outlined),
                      label: const Text('Anbarım, təhvillər və qalıqlar'),
                    ),
                  ],
                ),
              ),
            ),
    );
  }

  Widget _buildMetricCard({
    required String title,
    required String value,
    required IconData icon,
    required Color color,
  }) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFF112240),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: color, size: 28),
          const SizedBox(height: 10),
          Text(value,
              style: TextStyle(
                  fontSize: 22, fontWeight: FontWeight.w900, color: color)),
          const SizedBox(height: 4),
          Text(title, style: const TextStyle(fontSize: 12, color: Colors.grey)),
        ],
      ),
    );
  }

  Widget _dispatchCard(Map<String, dynamic> ticket) {
    final isAssigned = ticket['kind'] == 'assigned';
    final distance = ticket['distance_meters'] as num?;
    final priority = ticket['priority'] is Map
        ? Map<String, dynamic>.from(ticket['priority'] as Map)
        : <String, dynamic>{};
    final accent = isAssigned ? AppTheme.primary : AppTheme.emerald;
    final details = <String>[
      if ('${ticket['customer_name'] ?? ''}'.isNotEmpty)
        '${ticket['customer_name']}',
      if ('${ticket['area'] ?? ''}'.isNotEmpty) '${ticket['area']}',
      if (distance != null)
        distance < 1000
            ? '${distance.round()} m yaxınlıqda'
            : '${(distance / 1000).toStringAsFixed(1)} km yaxınlıqda',
    ];
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      color: accent.withValues(alpha: 0.10),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: accent.withValues(alpha: 0.65)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  isAssigned
                      ? Icons.assignment_turned_in_outlined
                      : Icons.near_me_outlined,
                  color: accent,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    isAssigned ? 'Sizə yönləndirildi' : 'Yaxın ərazidə iş',
                    style: TextStyle(
                      color: accent,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
                if (priority.isNotEmpty)
                  Text(
                    '${priority['label'] ?? 'normal'}'.toUpperCase(),
                    style: TextStyle(
                      color: accent,
                      fontWeight: FontWeight.w900,
                      fontSize: 11,
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              '${ticket['ticket_no']} · ${ticket['subject']}',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
            ),
            if (details.isNotEmpty) ...[
              const SizedBox(height: 5),
              Text(
                details.join(' · '),
                style: const TextStyle(color: Colors.white70),
              ),
            ],
            if (isAssigned && '${ticket['assigned_by'] ?? ''}'.isNotEmpty) ...[
              const SizedBox(height: 5),
              Text(
                'Yönləndirən: ${ticket['assigned_by']}',
                style: const TextStyle(color: Colors.white60, fontSize: 12),
              ),
            ],
            const SizedBox(height: 10),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: accent),
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const TaskListScreen()),
                ).then((_) => _loadDashboard(showLoader: false)),
                icon: const Icon(Icons.arrow_forward),
                label: Text(isAssigned ? 'Bileti aç' : 'Siyahıda bax'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
