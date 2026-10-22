import 'package:flutter/material.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';

class ManagerAttendanceReportScreen extends StatefulWidget {
  const ManagerAttendanceReportScreen({super.key});

  @override
  State<ManagerAttendanceReportScreen> createState() =>
      _ManagerAttendanceReportScreenState();
}

class _ManagerAttendanceReportScreenState
    extends State<ManagerAttendanceReportScreen> {
  static const _periods = {
    'day': 'Gün',
    'week': 'Həftə',
    'month': 'Ay',
    'year': 'İl',
  };

  bool _loading = true;
  String? _error;
  String _period = 'day';
  Map<String, dynamic> _periodInfo = {};
  Map<String, dynamic> _summary = {};
  List<Map<String, dynamic>> _employees = [];
  List<Map<String, dynamic>> _recent = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final response = await ApiClient().dio.get(
        ApiEndpoints.managerAttendanceReport,
        queryParameters: {'period': _period},
      );
      final data = response.data['data'] as Map<String, dynamic>;
      if (!mounted) return;
      setState(() {
        _periodInfo = Map<String, dynamic>.from(data['period'] as Map);
        _summary = Map<String, dynamic>.from(data['summary'] as Map);
        _employees = (data['employees'] as List<dynamic>? ?? [])
            .whereType<Map>()
            .map((item) => Map<String, dynamic>.from(item))
            .toList();
        _recent = (data['recent'] as List<dynamic>? ?? [])
            .whereType<Map>()
            .map((item) => Map<String, dynamic>.from(item))
            .toList();
      });
    } catch (_) {
      if (mounted) setState(() => _error = 'Davamiyyət hesabatı yüklənmədi.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _changePeriod(String period) {
    if (_period == period) return;
    setState(() => _period = period);
    _load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('Davamiyyət hesabatı'),
          actions: [
            IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
          ],
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? _ErrorState(message: _error!, retry: _load)
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView(
                      padding: const EdgeInsets.all(16),
                      children: [
                        _periodSelector(),
                        const SizedBox(height: 14),
                        _summaryGrid(),
                        const SizedBox(height: 18),
                        _sectionTitle(
                          'Əməkdaşlar',
                          '${_employees.length} nəfər',
                        ),
                        const SizedBox(height: 8),
                        ..._employees.map(_employeeCard),
                        const SizedBox(height: 18),
                        _sectionTitle('Son qeydlər', '${_recent.length} qeyd'),
                        const SizedBox(height: 8),
                        if (_recent.isEmpty)
                          const Card(
                            child: Padding(
                              padding: EdgeInsets.all(18),
                              child: Text('Bu period üçün qeyd yoxdur.'),
                            ),
                          )
                        else
                          ..._recent.take(20).map(_recentTile),
                      ],
                    ),
                  ),
      );

  Widget _periodSelector() => Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${_periodInfo['label'] ?? ''} · ${_periodInfo['start_date'] ?? ''} - ${_periodInfo['end_date'] ?? ''}',
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 10),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: _periods.entries.map((entry) {
                  final selected = entry.key == _period;
                  return ChoiceChip(
                    label: Text(entry.value),
                    selected: selected,
                    onSelected: (_) => _changePeriod(entry.key),
                  );
                }).toList(),
              ),
            ],
          ),
        ),
      );

  Widget _summaryGrid() {
    final cards = [
      (
        'Qeydli gün',
        _summary['recorded_days'] ?? 0,
        Icons.event_available_outlined,
        const Color(0xFF00E676)
      ),
      (
        'Gecikmə',
        _summary['late_days'] ?? 0,
        Icons.schedule_outlined,
        Colors.orangeAccent
      ),
      (
        'Tez çıxış',
        _summary['early_leave_days'] ?? 0,
        Icons.logout_outlined,
        Colors.deepOrangeAccent
      ),
      (
        'Çıxışsız gün',
        _summary['missing_checkout_days'] ?? 0,
        Icons.warning_amber_outlined,
        Colors.redAccent
      ),
      (
        'İş saatı',
        _hours(_summary['worked_minutes']),
        Icons.timer_outlined,
        const Color(0xFF00BCD4)
      ),
      (
        'Eksik saat',
        _hours(_summary['missing_minutes']),
        Icons.hourglass_bottom_outlined,
        Colors.amber
      ),
    ];

    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 10,
        mainAxisSpacing: 10,
        childAspectRatio: 1.34,
      ),
      itemCount: cards.length,
      itemBuilder: (context, index) {
        final card = cards[index];
        return Card(
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Icon(card.$3, color: card.$4),
                Text(
                  '${card.$2}',
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                Text(
                  card.$1,
                  style: const TextStyle(fontSize: 12, color: Colors.white70),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _employeeCard(Map<String, dynamic> employee) {
    final risk = (employee['late_days'] ?? 0) as int;
    final missing = (employee['missing_checkout_days'] ?? 0) as int;
    final early = (employee['early_leave_days'] ?? 0) as int;
    final color = risk + missing + early > 0
        ? Colors.orangeAccent
        : const Color(0xFF00E676);

    return Card(
      child: ExpansionTile(
        leading: CircleAvatar(
          backgroundColor: color.withValues(alpha: 0.16),
          child: Icon(Icons.badge_outlined, color: color),
        ),
        title: Text('${employee['full_name']}'),
        subtitle: Text(
          '${employee['job_title'] ?? ''} · ${employee['area'] ?? ''}',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: Text(
          '${employee['recorded_days'] ?? 0} gün',
          style: const TextStyle(fontSize: 11, color: Colors.white70),
        ),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
        children: [
          _detailLine(
            'Qrafik',
            '${employee['work_start_time']} - ${employee['work_end_time']} · ${employee['expected_work_minutes']} dəq',
          ),
          _detailLine('Gecikmə', '${employee['late_days'] ?? 0} gün'),
          _detailLine('Tez çıxış', '${employee['early_leave_days'] ?? 0} gün'),
          _detailLine(
            'Çıxışsız gün',
            '${employee['missing_checkout_days'] ?? 0} gün',
          ),
          _detailLine('İşlənib', _hours(employee['worked_minutes'])),
          _detailLine('Eksik', _hours(employee['missing_minutes'])),
          if ('${employee['last_check_in_at'] ?? ''}'.isNotEmpty)
            _detailLine('Son giriş', '${employee['last_check_in_at']}'),
          if ('${employee['last_check_out_at'] ?? ''}'.isNotEmpty)
            _detailLine('Son çıxış', '${employee['last_check_out_at']}'),
        ],
      ),
    );
  }

  Widget _recentTile(Map<String, dynamic> row) {
    final late = row['attendance_status'] == 'late';
    final early = row['early_leave'] == true;
    final color = late || early ? Colors.orangeAccent : const Color(0xFF00E676);
    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: color.withValues(alpha: 0.16),
          child: Icon(
            late ? Icons.schedule_outlined : Icons.check_circle_outline,
            color: color,
          ),
        ),
        title: Text('${row['employee_name']}'),
        subtitle: Text(
          '${row['work_date']} · giriş ${_time(row['check_in_at'])} · çıxış ${_time(row['check_out_at'])}',
        ),
        trailing: Text(
          _hours(row['worked_minutes']),
          style: const TextStyle(fontSize: 11, color: Colors.white70),
        ),
      ),
    );
  }

  Widget _sectionTitle(String title, String count) => Row(
        children: [
          Expanded(
            child: Text(
              title,
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
            ),
          ),
          Text(count, style: const TextStyle(color: Colors.white70)),
        ],
      );

  Widget _detailLine(String label, String value) => Padding(
        padding: const EdgeInsets.only(top: 6),
        child: Row(
          children: [
            Expanded(
              child: Text(label, style: const TextStyle(color: Colors.white70)),
            ),
            Flexible(
              child: Text(
                value,
                textAlign: TextAlign.right,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      );

  String _hours(dynamic rawMinutes) {
    final minutes = rawMinutes is int
        ? rawMinutes
        : int.tryParse('${rawMinutes ?? 0}') ?? 0;
    final hours = minutes ~/ 60;
    final rest = minutes % 60;
    return '${hours}s ${rest}d';
  }

  String _time(dynamic raw) {
    final value = '${raw ?? ''}';
    if (value.length >= 16) return value.substring(11, 16);
    return '-';
  }
}

class _ErrorState extends StatelessWidget {
  final String message;
  final Future<void> Function() retry;
  const _ErrorState({required this.message, required this.retry});

  @override
  Widget build(BuildContext context) => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.cloud_off, size: 44, color: Colors.redAccent),
            const SizedBox(height: 12),
            Text(message),
            const SizedBox(height: 12),
            OutlinedButton(
                onPressed: retry, child: const Text('Yenidən yoxla')),
          ],
        ),
      );
}
