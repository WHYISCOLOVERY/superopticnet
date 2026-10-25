import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../../core/theme/app_theme.dart';
import '../../data/services/outbox_service.dart';
import '../auth/login_screen.dart';
import 'manager_attendance_report_screen.dart';
import 'manager_fleet_map_screen.dart';
import 'manager_geofences_screen.dart';
import 'manager_task_board_screen.dart';
import 'manager_account_security_screen.dart';
import 'manager_operator_presence_screen.dart';
import '../notifications/notification_center_screen.dart';
import '../finance/finance_screen.dart';
import '../inventory/inventory_screen.dart';
import '../staff/modem_search_screen.dart';
import '../staff/noc_monitor_screen.dart';
import '../staff/billing_summary_screen.dart';

class ManagerOperationsScreen extends StatefulWidget {
  const ManagerOperationsScreen({super.key});

  @override
  State<ManagerOperationsScreen> createState() =>
      _ManagerOperationsScreenState();
}

class _ManagerOperationsScreenState extends State<ManagerOperationsScreen> {
  bool _loading = true;
  String? _error;
  String _managerName = 'Rəhbər';
  String _managerRole = '';
  Map<String, dynamic> _metrics = {};
  List<dynamic> _technicians = [];
  List<dynamic> _fieldTeam = [];
  List<dynamic> _tickets = [];
  List<dynamic> _proofs = [];
  List<dynamic> _notifications = [];
  bool _offline = false;
  int _queuedCount = 0;

  bool get _canSeeBilling => {
        'super_admin',
        'admin',
        'manager',
        'director',
      }.contains(_managerRole.trim().toLowerCase());

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
    try {
      _managerName = await SecureStorageService.getUserFullName() ?? 'Rəhbər';
      _managerRole = await SecureStorageService.getUserRole() ?? '';
      final response = await ApiClient().dio.get(ApiEndpoints.managerDashboard);
      final data = response.data['data'] as Map<String, dynamic>;
      final queued = await OutboxService.queuedCount();
      if (!mounted) return;
      setState(() {
        _metrics = data['metrics'] as Map<String, dynamic>? ?? {};
        _technicians = data['assignable_employees'] as List<dynamic>? ??
            data['technicians'] as List<dynamic>? ??
            [];
        _fieldTeam = data['field_team'] as List<dynamic>? ??
            data['technicians'] as List<dynamic>? ??
            [];
        _tickets = data['tickets'] as List<dynamic>? ?? [];
        _proofs = data['pending_proofs'] as List<dynamic>? ?? [];
        _notifications = data['notifications'] as List<dynamic>? ?? [];
        _offline = response.data['offline_cache'] == true;
        _queuedCount = queued;
      });
    } catch (_) {
      if (mounted) setState(() => _error = 'Əməliyyat məlumatları yüklənmədi.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _logout() async {
    await SecureStorageService.clearAuth();
    if (!mounted) return;
    Navigator.pushReplacement(
        context, MaterialPageRoute(builder: (_) => const LoginScreen()));
  }

  Future<void> _assignTicket(Map<String, dynamic> ticket) async {
    int? selectedId;
    final instructions = TextEditingController();
    final submitted = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: Text('${ticket['ticket_no']} tapşırığını təyin et'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<int>(
                decoration:
                    const InputDecoration(labelText: 'Texnik / mühəndis'),
                items: _technicians.map((item) {
                  final tech = item as Map<String, dynamic>;
                  return DropdownMenuItem<int>(
                    value: tech['id'] as int,
                    child: Text('${tech['full_name']} · ${tech['area']}'),
                  );
                }).toList(),
                onChanged: (value) => setDialogState(() => selectedId = value),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: instructions,
                maxLines: 3,
                decoration: const InputDecoration(
                    labelText: 'İcra qeydi',
                    hintText: 'Ünvan, prioritet və əlavə göstəriş'),
              ),
            ],
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Ləğv et')),
            FilledButton(
              onPressed: selectedId == null
                  ? null
                  : () => Navigator.pop(context, true),
              child: const Text('Tapşır'),
            ),
          ],
        ),
      ),
    );
    if (submitted != true || selectedId == null) return;
    final payload = <String, dynamic>{
      'client_uuid': const Uuid().v4(),
      'ticket_id': ticket['id'],
      'technician_account_id': selectedId,
      'priority': 'normal',
      'instructions': instructions.text.trim(),
    };
    final queued = await _postOrQueue(
      ApiEndpoints.managerAssignTask,
      payload,
      'Tapşırıq əməkdaşa təyin edildi.',
      'İnternet yoxdur. Təyinat telefonda növbəyə yazıldı.',
    );
    if (!queued) await _load();
  }

  Future<void> _createTask() async {
    final subject = TextEditingController();
    final description = TextEditingController();
    final area = TextEditingController();
    final dueDate = TextEditingController();
    final formKey = GlobalKey<FormState>();
    int? selectedId;
    String priority = 'normal';
    bool submitting = false;
    final submitted = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: AppTheme.ink,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(30)),
      ),
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => Padding(
          padding: EdgeInsets.only(
            left: 18,
            right: 18,
            top: 10,
            bottom: MediaQuery.of(context).viewInsets.bottom + 18,
          ),
          child: SingleChildScrollView(
            child: Form(
              key: formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: Container(
                      width: 44,
                      height: 5,
                      decoration: BoxDecoration(
                        color: AppTheme.border,
                        borderRadius: BorderRadius.circular(999),
                      ),
                    ),
                  ),
                  const SizedBox(height: 18),
                  Row(
                    children: [
                      Container(
                        width: 52,
                        height: 52,
                        decoration: BoxDecoration(
                          color: AppTheme.primary.withValues(alpha: 0.18),
                          borderRadius: BorderRadius.circular(18),
                        ),
                        child:
                            const Icon(Icons.add_task, color: AppTheme.primary),
                      ),
                      const SizedBox(width: 14),
                      const Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Yeni tapşırıq ver',
                              style: TextStyle(
                                fontSize: 22,
                                fontWeight: FontWeight.w900,
                                letterSpacing: -0.6,
                              ),
                            ),
                            SizedBox(height: 3),
                            Text(
                              'İşin səbəbini, ərazini və icraçını aydın yazın.',
                              style: TextStyle(
                                color: AppTheme.mutedText,
                                height: 1.3,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),
                  TextFormField(
                    controller: subject,
                    textInputAction: TextInputAction.next,
                    validator: (value) => (value ?? '').trim().isEmpty
                        ? 'Başlıq tələb olunur'
                        : null,
                    decoration: const InputDecoration(
                      labelText: 'Başlıq',
                      hintText: 'Məs: Astara OLT3 PON itkiləri',
                      prefixIcon: Icon(Icons.short_text),
                    ),
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: description,
                    minLines: 4,
                    maxLines: 6,
                    validator: (value) => (value ?? '').trim().isEmpty
                        ? 'İşin izahı tələb olunur'
                        : null,
                    decoration: const InputDecoration(
                      labelText: 'İşin izahı',
                      hintText:
                          'Nə baş verib, kim təsirlənib, texnik nəyi yoxlamalıdır?',
                      alignLabelWithHint: true,
                      prefixIcon: Icon(Icons.notes_outlined),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: area,
                          textInputAction: TextInputAction.next,
                          validator: (value) => (value ?? '').trim().isEmpty
                              ? 'Ərazi tələb olunur'
                              : null,
                          decoration: const InputDecoration(
                            labelText: 'Ərazi',
                            hintText: 'Neftçala, Astara...',
                            prefixIcon: Icon(Icons.location_on_outlined),
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: TextFormField(
                          controller: dueDate,
                          keyboardType: TextInputType.datetime,
                          decoration: const InputDecoration(
                            labelText: 'Son tarix',
                            hintText: '2026-10-05',
                            prefixIcon: Icon(Icons.event_outlined),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  const Text(
                    'Prioritet',
                    style: TextStyle(fontWeight: FontWeight.w900),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      _priorityChoice(
                        label: 'Aşağı',
                        value: 'low',
                        selected: priority,
                        color: AppTheme.mutedText,
                        onSelected: (value) =>
                            setDialogState(() => priority = value),
                      ),
                      _priorityChoice(
                        label: 'Normal',
                        value: 'normal',
                        selected: priority,
                        color: AppTheme.primary,
                        onSelected: (value) =>
                            setDialogState(() => priority = value),
                      ),
                      _priorityChoice(
                        label: 'Yüksək',
                        value: 'high',
                        selected: priority,
                        color: AppTheme.warning,
                        onSelected: (value) =>
                            setDialogState(() => priority = value),
                      ),
                      _priorityChoice(
                        label: 'Kritik',
                        value: 'critical',
                        selected: priority,
                        color: AppTheme.danger,
                        onSelected: (value) =>
                            setDialogState(() => priority = value),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<int>(
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'İcraçı',
                      hintText: 'İstəyə bağlı',
                      prefixIcon: Icon(Icons.engineering_outlined),
                    ),
                    items: _technicians.map((item) {
                      final tech = item as Map<String, dynamic>;
                      return DropdownMenuItem<int>(
                        value: tech['id'] as int,
                        child: Text(
                          '${tech['full_name']} · ${tech['area']}',
                          overflow: TextOverflow.ellipsis,
                        ),
                      );
                    }).toList(),
                    onChanged: (value) =>
                        setDialogState(() => selectedId = value),
                  ),
                  const SizedBox(height: 18),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: submitting
                              ? null
                              : () => Navigator.pop(context, false),
                          child: const Text('Ləğv et'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: submitting
                              ? null
                              : () {
                                  if (formKey.currentState?.validate() !=
                                      true) {
                                    return;
                                  }
                                  setDialogState(() => submitting = true);
                                  Navigator.pop(context, true);
                                },
                          icon: submitting
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child:
                                      CircularProgressIndicator(strokeWidth: 2),
                                )
                              : const Icon(Icons.send_outlined),
                          label: const Text('Tapşırıq ver'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
    if (submitted != true) return;
    final payload = <String, dynamic>{
      'client_uuid': const Uuid().v4(),
      'subject': subject.text.trim(),
      'message': description.text.trim(),
      'area': area.text.trim(),
      'due_date': dueDate.text.trim(),
      'priority': priority,
      if (selectedId != null) 'technician_account_id': selectedId,
    };
    final queued = await _postOrQueue(
      ApiEndpoints.managerCreateTask,
      payload,
      'Tapşırıq yaradıldı.',
      'İnternet yoxdur. Tapşırıq telefonda saxlanıldı və bağlantıda yaradılacaq.',
    );
    if (!queued) await _load();
  }

  Future<bool> _postOrQueue(
    String endpoint,
    Map<String, dynamic> payload,
    String successMessage,
    String queuedMessage,
  ) async {
    final accountId = await SecureStorageService.getUserId();
    if (accountId == null) throw StateError('Aktiv sessiya tapılmadı.');
    final clientUuid = '${payload['client_uuid']}';
    await OutboxService.enqueue(
      clientUuid: clientUuid,
      accountId: accountId,
      endpoint: endpoint,
      payload: payload,
    );
    try {
      await ApiClient().dio.post(endpoint, data: payload);
      await OutboxService.markCompleted(clientUuid);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(successMessage)));
      }
      return false;
    } on DioException catch (error) {
      if (error.response != null) {
        await OutboxService.markCompleted(clientUuid);
        if (mounted) {
          final data = error.response?.data;
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(data is Map
                ? '${data['error'] ?? 'Əməliyyat qəbul edilmədi.'}'
                : 'Əməliyyat qəbul edilmədi.'),
          ));
        }
        return false;
      }
      await OutboxService.recordFailure(clientUuid, error);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(queuedMessage)));
      }
      return true;
    }
  }

  Widget _priorityChoice({
    required String label,
    required String value,
    required String selected,
    required Color color,
    required ValueChanged<String> onSelected,
  }) {
    final isSelected = selected == value;
    return ChoiceChip(
      selected: isSelected,
      label: Text(label),
      avatar: Icon(
        isSelected ? Icons.local_fire_department : Icons.circle,
        size: isSelected ? 18 : 8,
        color: isSelected ? color : AppTheme.mutedText,
      ),
      selectedColor: color.withValues(alpha: 0.18),
      side: BorderSide(color: isSelected ? color : AppTheme.border),
      onSelected: (_) => onSelected(value),
    );
  }

  Future<void> _reviewProof(Map<String, dynamic> proof, String decision) async {
    final note = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title:
            Text(decision == 'approved' ? 'Sübutu təsdiqlə' : 'Sübutu rədd et'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${proof['ticket_no']} · ${proof['technician_name']}'),
            const SizedBox(height: 12),
            TextField(
              controller: note,
              maxLines: 3,
              decoration: InputDecoration(
                labelText: decision == 'approved'
                    ? 'Qeyd (istəyə bağlı)'
                    : 'Rədd səbəbi',
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Ləğv et')),
          FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Təsdiqlə')),
        ],
      ),
    );
    if (confirmed != true) return;
    if (decision == 'rejected' && note.text.trim().isEmpty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Rədd səbəbini yazın.')));
      }
      return;
    }
    try {
      await ApiClient().dio.post(ApiEndpoints.managerReviewProof, data: {
        'proof_id': proof['id'],
        'decision': decision,
        'note': note.text.trim(),
      });
      await _load();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(decision == 'approved'
                  ? 'OTP təsdiqi olmayan sübut qəbul edilə bilməz.'
                  : 'Sübut qərarı saxlanmadı.')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      key: ValueKey('operations-tabs-${_canSeeBilling ? 5 : 4}'),
      length: _canSeeBilling ? 5 : 4,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Əməliyyat Mərkəzi'),
          actions: [
            IconButton(
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => const NotificationCenterScreen()),
              ),
              icon: const Icon(Icons.notifications_active_outlined),
            ),
            IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
            IconButton(onPressed: _logout, icon: const Icon(Icons.logout)),
          ],
          bottom: TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [
              const Tab(icon: Icon(Icons.dashboard_outlined), text: 'İcmal'),
              const Tab(
                  icon: Icon(Icons.assignment_outlined), text: 'Tapşırıqlar'),
              const Tab(
                  icon: Icon(Icons.fact_check_outlined), text: 'Təsdiqlər'),
              const Tab(
                  icon: Icon(Icons.notifications_outlined), text: 'Hadisələr'),
              if (_canSeeBilling)
                const Tab(
                    icon: Icon(Icons.account_balance_wallet_outlined),
                    text: 'Billing'),
            ],
          ),
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? _ErrorState(message: _error!, retry: _load)
                : TabBarView(children: [
                    _overview(),
                    _taskList(),
                    _proofList(),
                    _notificationList(),
                    if (_canSeeBilling) const BillingSummaryView(),
                  ]),
      ),
    );
  }

  Widget _overview() {
    final activeTickets = _intMetric('active_tickets');
    final criticalAlarms = _intMetric('critical_alarms');
    final onDuty = _intMetric('on_duty_employees');
    final pendingProofs = _intMetric('pending_proofs');
    final oldTickets = _intMetric('old_open_24h');
    return RefreshIndicator(
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
                  ? 'Oflayn rejim · son saxlanmış məlumatlar · $_queuedCount əməliyyat növbədədir'
                  : '$_queuedCount əməliyyat sinxronlaşma növbəsindədir'),
            ),
            const SizedBox(height: 12),
          ],
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(28),
              gradient: const LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Color(0xFF173B67),
                  Color(0xFF17233B),
                  Color(0xFF0B1220)
                ],
              ),
              border: Border.all(color: AppTheme.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'SuperOptic Field Command',
                            style: TextStyle(
                              color: AppTheme.mutedText,
                              fontWeight: FontWeight.w700,
                              fontSize: 12,
                            ),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            'Salam, $_managerName',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 25,
                              fontWeight: FontWeight.w900,
                              letterSpacing: -0.8,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 12, vertical: 8),
                      decoration: BoxDecoration(
                        color: AppTheme.success.withValues(alpha: 0.13),
                        borderRadius: BorderRadius.circular(999),
                        border: Border.all(
                            color: AppTheme.success.withValues(alpha: 0.35)),
                      ),
                      child: Text(
                        '$onDuty növbədə',
                        style: const TextStyle(
                          color: AppTheme.success,
                          fontWeight: FontWeight.w800,
                          fontSize: 12,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 18),
                Row(
                  children: [
                    Expanded(
                      child: _heroNumber(
                        '$activeTickets',
                        'aktiv iş',
                        criticalAlarms > 0 ? AppTheme.warning : Colors.white,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: _heroNumber(
                        '$criticalAlarms',
                        'kritik alarm',
                        criticalAlarms > 0
                            ? AppTheme.danger
                            : AppTheme.mutedText,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Text(
                  oldTickets > 0
                      ? '$oldTickets tapşırıq 24 saatdan çox açıqdır. Prioritetləşdirilməlidir.'
                      : 'Köhnə açıq bilet riski görünmür. Növbə normaldır.',
                  style:
                      const TextStyle(color: AppTheme.mutedText, height: 1.35),
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          GridView.count(
            crossAxisCount: 2,
            crossAxisSpacing: 12,
            mainAxisSpacing: 12,
            childAspectRatio: 1.28,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            children: [
              _metricTile('Təyinatsız', _intMetric('unassigned_tickets'),
                  Icons.person_off_outlined, AppTheme.danger),
              _metricTile('İcrada', _intMetric('in_progress_tickets'),
                  Icons.engineering_outlined, AppTheme.primary),
              _metricTile('Bu gün bitən', _intMetric('completed_today'),
                  Icons.task_alt, AppTheme.success),
              _metricTile('Sübut gözləyir', pendingProofs,
                  Icons.fact_check_outlined, AppTheme.warning),
            ],
          ),
          const SizedBox(height: 18),
          const Text(
            'İdarəetmə',
            style: TextStyle(fontSize: 17, fontWeight: FontWeight.w900),
          ),
          const SizedBox(height: 10),
          _primaryTaskCard(),
          const SizedBox(height: 12),
          _wideShortcut(
            title: 'NOC şəbəkə monitoru',
            subtitle:
                'OLT, router və şəbəkə cihazlarının vəziyyəti, yükü, xətaları və tarixçəsi.',
            icon: Icons.monitor_heart_outlined,
            color: AppTheme.danger,
            onTap: () => Navigator.push(context,
                MaterialPageRoute(builder: (_) => const NocMonitorScreen())),
          ),
          const SizedBox(height: 12),
          _wideShortcut(
            title: 'Müştəri, modem və problem arxivi',
            subtitle:
                'Kod, MAC, SN və adla axtar; əvvəlki problemləri və həlləri gör.',
            icon: Icons.manage_search_outlined,
            color: AppTheme.primary,
            onTap: () => Navigator.push(context,
                MaterialPageRoute(builder: (_) => const ModemSearchScreen())),
          ),
          const SizedBox(height: 12),
          _wideShortcut(
            title: 'Maliyyə və hesabatlar',
            subtitle: 'Gəlir, xərc, səbəblər və tarix üzrə qrafiklər.',
            icon: Icons.account_balance_wallet_outlined,
            color: AppTheme.accent,
            onTap: () => Navigator.push(context,
                MaterialPageRoute(builder: (_) => const FinanceScreen())),
          ),
          const SizedBox(height: 12),
          _wideShortcut(
            title: 'Anbar və avadanlıq dövriyyəsi',
            subtitle: 'Təhvil, əməkdaş qalığı, qaytarma və təsdiqlər.',
            icon: Icons.inventory_2_outlined,
            color: AppTheme.primary,
            onTap: () => Navigator.push(context,
                MaterialPageRoute(builder: (_) => const InventoryScreen())),
          ),
          const SizedBox(height: 12),
          _wideShortcut(
            title: 'Əməkdaş task board',
            subtitle: 'Kimə nə verilib, nəyi bitirib, nəyi saxlayıb.',
            icon: Icons.view_kanban_outlined,
            color: AppTheme.accent,
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const ManagerTaskBoardScreen()),
            ),
          ),
          const SizedBox(height: 10),
          _wideShortcut(
            title: 'Canlı xəritə və izləmə',
            subtitle: 'Kim haradadır, növbədədir, ərazidən çıxıbsa görün.',
            icon: Icons.map_outlined,
            color: AppTheme.primary,
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const ManagerFleetMapScreen()),
            ),
          ),
          const SizedBox(height: 10),
          _wideShortcut(
            title: 'İş əraziləri və sərhədlər',
            subtitle: 'Əməkdaşları əraziyə bağla, geofence qaydalarını qur.',
            icon: Icons.edit_location_alt_outlined,
            color: AppTheme.success,
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const ManagerGeofencesScreen()),
            ),
          ),
          const SizedBox(height: 10),
          _wideShortcut(
            title: 'Davamiyyət və gecikmə',
            subtitle: 'Gəliş, çıxış, gecikmə və tez getmə statistikası.',
            icon: Icons.bar_chart_outlined,
            color: AppTheme.warning,
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(
                  builder: (_) => const ManagerAttendanceReportScreen()),
            ),
          ),
          const SizedBox(height: 10),
          _wideShortcut(
            title: 'Gecə operatoru və saatlıq aktivlik',
            subtitle: 'Növbə, istirahət saatları və buraxılmış girişlər.',
            icon: Icons.nightlight_round,
            color: AppTheme.primary,
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(
                  builder: (_) => const ManagerOperatorPresenceScreen()),
            ),
          ),
          if (_managerRole == 'super_admin') ...[
            const SizedBox(height: 10),
            _wideShortcut(
              title: 'Hesablar və giriş nəzarəti',
              subtitle: 'Giriş jurnalı, aktiv cihazlar və parol sıfırlama.',
              icon: Icons.admin_panel_settings_outlined,
              color: AppTheme.danger,
              onTap: () => Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => const ManagerAccountSecurityScreen()),
              ),
            ),
          ],
          const SizedBox(height: 18),
          Row(
            children: [
              Expanded(
                child: Text(
                  'Sahə komandası (${_fieldTeam.length})',
                  style: const TextStyle(
                      fontSize: 17, fontWeight: FontWeight.w900),
                ),
              ),
              TextButton(
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                      builder: (_) => const ManagerFleetMapScreen()),
                ),
                child: const Text('Xəritədə bax'),
              ),
            ],
          ),
          const SizedBox(height: 10),
          ..._fieldTeam.take(6).map((item) {
            final tech = item as Map<String, dynamic>;
            return Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: _technicianTile(tech),
            );
          }),
        ],
      ),
    );
  }

  int _intMetric(String key) {
    final value = _metrics[key];
    if (value is num) return value.toInt();
    return int.tryParse('$value') ?? 0;
  }

  Widget _heroNumber(String value, String label, Color color) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.07),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: Colors.white.withValues(alpha: 0.10)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              value,
              style: TextStyle(
                color: color,
                fontSize: 34,
                fontWeight: FontWeight.w900,
                letterSpacing: -1.2,
              ),
            ),
            Text(label, style: const TextStyle(color: AppTheme.mutedText)),
          ],
        ),
      );

  Widget _metricTile(String label, int value, IconData icon, Color color) {
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
          Icon(icon, color: color),
          Text(
            '$value',
            style: const TextStyle(
              fontSize: 28,
              fontWeight: FontWeight.w900,
              letterSpacing: -0.8,
            ),
          ),
          Text(label, style: const TextStyle(color: AppTheme.mutedText)),
        ],
      ),
    );
  }

  Widget _primaryTaskCard() {
    return Material(
      color: Colors.transparent,
      borderRadius: BorderRadius.circular(26),
      child: InkWell(
        onTap: _createTask,
        borderRadius: BorderRadius.circular(26),
        child: Ink(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(26),
            gradient: const LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [Color(0xFF2563EB), Color(0xFF4F46E5), Color(0xFF6D28D9)],
            ),
            boxShadow: [
              BoxShadow(
                color: AppTheme.primary.withValues(alpha: 0.28),
                blurRadius: 30,
                offset: const Offset(0, 16),
              ),
            ],
          ),
          child: Row(
            children: [
              Container(
                width: 58,
                height: 58,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.18),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: const Icon(
                  Icons.local_fire_department,
                  color: Colors.white,
                  size: 30,
                ),
              ),
              const SizedBox(width: 14),
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Tapşırıq ver',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 22,
                        fontWeight: FontWeight.w900,
                        letterSpacing: -0.6,
                      ),
                    ),
                    SizedBox(height: 4),
                    Text(
                      'Problem, ərazi, prioritet və icraçını bir axında seç.',
                      style: TextStyle(color: Color(0xFFDBEAFE), height: 1.3),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              const Icon(Icons.arrow_forward_rounded, color: Colors.white),
            ],
          ),
        ),
      ),
    );
  }

  Widget _wideShortcut({
    required String title,
    required String subtitle,
    required IconData icon,
    required Color color,
    required VoidCallback onTap,
  }) {
    return Material(
      color: AppTheme.panel,
      borderRadius: BorderRadius.circular(22),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(22),
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: AppTheme.border),
          ),
          child: Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Icon(icon, color: color),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        fontSize: 15,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      subtitle,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: AppTheme.mutedText,
                        height: 1.25,
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              const Icon(Icons.chevron_right_rounded,
                  color: AppTheme.mutedText),
            ],
          ),
        ),
      ),
    );
  }

  Widget _technicianTile(Map<String, dynamic> tech) {
    final onDuty = tech['is_on_duty'] == true;
    final color = onDuty ? AppTheme.success : AppTheme.mutedText;
    return Card(
      child: ListTile(
        minVerticalPadding: 14,
        leading: Container(
          width: 46,
          height: 46,
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Icon(onDuty ? Icons.gps_fixed : Icons.person_outline,
              color: color),
        ),
        title: Text(
          '${tech['full_name']}',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w900),
        ),
        subtitle: Text(
          '${tech['job_title']} · ${tech['area']} · ${tech['active_tasks']} aktiv iş',
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text(
            onDuty ? 'NÖVBƏDƏ' : 'OFF',
            style: TextStyle(
                color: color, fontWeight: FontWeight.w900, fontSize: 10),
          ),
        ),
      ),
    );
  }

  Widget _taskList() => RefreshIndicator(
        onRefresh: _load,
        child: _tickets.isEmpty
            ? ListView(children: const [
                SizedBox(height: 180),
                Center(child: Text('Aktiv tapşırıq yoxdur.')),
              ])
            : ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: _tickets.length,
                itemBuilder: (context, index) {
                  final ticket = _tickets[index] as Map<String, dynamic>;
                  return Card(
                      child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(children: [
                            Expanded(
                                child: Text(
                                    '${ticket['ticket_no']} · ${ticket['subject']}',
                                    style: const TextStyle(
                                        fontWeight: FontWeight.bold))),
                            Chip(
                                label: Text(
                                    _ticketStatusLabel('${ticket['status']}'),
                                    style: const TextStyle(fontSize: 10))),
                          ]),
                          Text('${ticket['customer_name']} · ${ticket['area']}',
                              style: const TextStyle(color: Colors.white70)),
                          Text('Açılıb: ${ticket['opened_at']}',
                              style: const TextStyle(
                                  fontSize: 12, color: Colors.orangeAccent)),
                          if ((ticket['assigned_technician_name'] ?? '')
                              .toString()
                              .isNotEmpty)
                            Text(
                                'İcraçı: ${ticket['assigned_technician_name']}',
                                style:
                                    const TextStyle(color: Color(0xFF00BCD4))),
                          const SizedBox(height: 10),
                          Align(
                            alignment: Alignment.centerRight,
                            child: OutlinedButton.icon(
                                onPressed: () => _assignTicket(ticket),
                                icon: const Icon(Icons.person_add_alt_1),
                                label: const Text('Təyin et / dəyiş')),
                          ),
                        ]),
                  ));
                },
              ),
      );

  Widget _proofList() => RefreshIndicator(
        onRefresh: _load,
        child: _proofs.isEmpty
            ? ListView(children: const [
                SizedBox(height: 180),
                Center(child: Text('Təsdiq gözləyən sübut yoxdur.')),
              ])
            : ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: _proofs.length,
                itemBuilder: (context, index) {
                  final proof = _proofs[index] as Map<String, dynamic>;
                  return Card(
                      child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${proof['ticket_no']} · ${proof['subject']}',
                              style:
                                  const TextStyle(fontWeight: FontWeight.bold)),
                          Text('Texnik: ${proof['technician_name']}'),
                          Text(
                              'OTP: ${proof['otp_verified'] == true ? 'təsdiqlənib' : 'təsdiqlənməyib'} · Optik güc: ${proof['optical_power_dbm'] ?? '-'} dBm'),
                          if ((proof['resolution_notes'] ?? '')
                              .toString()
                              .isNotEmpty)
                            Text('Qeyd: ${proof['resolution_notes']}'),
                          const SizedBox(height: 10),
                          Row(children: [
                            Expanded(
                                child: OutlinedButton(
                                    onPressed: () =>
                                        _reviewProof(proof, 'rejected'),
                                    child: const Text('Rədd et'))),
                            const SizedBox(width: 10),
                            Expanded(
                                child: FilledButton(
                                    onPressed: proof['otp_verified'] == true
                                        ? () => _reviewProof(proof, 'approved')
                                        : null,
                                    child: const Text('Təsdiqlə'))),
                          ]),
                        ]),
                  ));
                },
              ),
      );

  Widget _notificationList() {
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final raw in _notifications) {
      final item = raw as Map<String, dynamic>;
      final group = (item['group'] ?? 'Digər əməliyyat hadisələri').toString();
      groups.putIfAbsent(group, () => []).add(item);
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: groups.isEmpty
          ? ListView(children: const [
              SizedBox(height: 180),
              Center(child: Text('Aktiv və icra tələb edən hadisə yoxdur.')),
            ])
          : ListView(
              padding: const EdgeInsets.all(14),
              children: groups.entries.map((entry) {
                final first = entry.value.first;
                final color = _severityColor('${first['severity']}');
                return Card(
                  child: ExpansionTile(
                    initiallyExpanded: groups.length == 1,
                    leading: CircleAvatar(
                      backgroundColor: color.withValues(alpha: 0.16),
                      child: Icon(
                        _notificationIcon('${first['kind']}'),
                        color: color,
                      ),
                    ),
                    title: Text(entry.key,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${entry.value.length} aktiv qeyd'),
                    children: entry.value.map(_notificationTile).toList(),
                  ),
                );
              }).toList(),
            ),
    );
  }

  Widget _notificationTile(Map<String, dynamic> item) {
    final severity = '${item['severity']}';
    final color = _severityColor(severity);
    return Column(
      children: [
        const Divider(height: 1),
        ListTile(
          title: Text('${item['title']}'),
          subtitle:
              Text('${item['message']}\nSon yenilənmə: ${item['occurred_at']}'),
          trailing: Text(_statusLabel('${item['status']}'),
              style: TextStyle(color: color, fontSize: 10)),
        ),
      ],
    );
  }

  String _statusLabel(String status) => switch (status) {
        'open' => 'AÇIQ',
        'ack' => 'QƏBUL EDİLİB',
        'pending_admin_review' => 'QƏRAR GÖZLƏYİR',
        'failed' || 'final_failed' => 'UĞURSUZ',
        'queued' => 'NÖVBƏDƏ',
        _ => status.toUpperCase(),
      };

  String _ticketStatusLabel(String status) => switch (status) {
        'open' || 'Aciqdir' => 'AÇIQ',
        'assigned' || 'Gozlenilir' => 'TƏYİN EDİLİB',
        'in_progress' || 'Icrada' => 'İCRADA',
        'resolved' || 'Baglandi' => 'HƏLL EDİLİB',
        'closed' || 'Tesdiqlendi' => 'BAĞLANIB',
        _ => status.toUpperCase(),
      };

  Color _severityColor(String severity) => switch (severity) {
        'emergency' || 'critical' || 'high' => Colors.redAccent,
        'warning' || 'medium' => Colors.orangeAccent,
        _ => const Color(0xFF00BCD4),
      };

  IconData _notificationIcon(String kind) => switch (kind) {
        'alarm' => Icons.warning_amber_rounded,
        'ai_task' => Icons.smart_toy_outlined,
        'delivery' => Icons.send_outlined,
        'network_incident' => Icons.hub_outlined,
        'geofence' => Icons.wrong_location_outlined,
        _ => Icons.notifications_outlined,
      };
}

class _ErrorState extends StatelessWidget {
  final String message;
  final Future<void> Function() retry;
  const _ErrorState({required this.message, required this.retry});

  @override
  Widget build(BuildContext context) => Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Icon(Icons.cloud_off, size: 44, color: Colors.redAccent),
        const SizedBox(height: 12),
        Text(message),
        const SizedBox(height: 12),
        OutlinedButton(onPressed: retry, child: const Text('Yenidən yoxla')),
      ]));
}
