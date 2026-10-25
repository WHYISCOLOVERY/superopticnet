import 'package:flutter/material.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';

class ManagerTaskBoardScreen extends StatefulWidget {
  const ManagerTaskBoardScreen({super.key});

  @override
  State<ManagerTaskBoardScreen> createState() => _ManagerTaskBoardScreenState();
}

class _ManagerTaskBoardScreenState extends State<ManagerTaskBoardScreen> {
  bool _loading = true;
  String? _error;
  String _period = 'month';
  Map<String, dynamic> _summary = {};
  List<dynamic> _employees = [];

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
        ApiEndpoints.managerTaskBoard,
        queryParameters: {'period': _period},
      );
      final data = response.data['data'] as Map<String, dynamic>;
      if (!mounted) return;
      setState(() {
        _summary = data['summary'] as Map<String, dynamic>? ?? {};
        _employees = data['employees'] as List<dynamic>? ?? [];
      });
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Tapşırıq board-u yüklənmədi.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  int _int(dynamic value) =>
      value is num ? value.toInt() : int.tryParse('$value') ?? 0;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Əməkdaş task board'),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh))
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _ErrorState(message: _error!, retry: _load)
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
                    children: [
                      _periodSelector(),
                      const SizedBox(height: 14),
                      _summaryHero(),
                      const SizedBox(height: 18),
                      const Text(
                        'Əməkdaşlara görə',
                        style: TextStyle(
                            fontSize: 18, fontWeight: FontWeight.w900),
                      ),
                      const SizedBox(height: 10),
                      ..._employees.map((raw) => Padding(
                            padding: const EdgeInsets.only(bottom: 12),
                            child: _employeeCard(raw as Map<String, dynamic>),
                          )),
                    ],
                  ),
                ),
    );
  }

  Widget _periodSelector() {
    const labels = {
      'day': 'Gün',
      'week': 'Həftə',
      'month': 'Ay',
      'year': 'İl',
      'all': 'Hamısı',
    };
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: labels.entries.map((entry) {
          final selected = _period == entry.key;
          return Padding(
            padding: const EdgeInsets.only(right: 8),
            child: ChoiceChip(
              selected: selected,
              label: Text(entry.value),
              selectedColor: AppTheme.primary.withValues(alpha: 0.18),
              side: BorderSide(
                  color: selected ? AppTheme.primary : AppTheme.border),
              onSelected: (_) {
                setState(() => _period = entry.key);
                _load();
              },
            ),
          );
        }).toList(),
      ),
    );
  }

  Widget _summaryHero() {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(28),
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF173B67), Color(0xFF111827)],
        ),
        border: Border.all(color: AppTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'İş bölgüsü və icra sürəti',
            style: TextStyle(
                color: AppTheme.mutedText, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                  child: _summaryNumber(
                      'Aktiv', _summary['active'], AppTheme.primary)),
              Expanded(
                  child: _summaryNumber(
                      'Bitmiş', _summary['completed'], AppTheme.success)),
              Expanded(
                  child: _summaryNumber(
                      'Gecikmiş', _summary['overdue'], AppTheme.danger)),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            'Orta icra vaxtı: ${_summary['avg_completion_hours'] ?? 0} saat · 24 saatdan çox saxlanan: ${_summary['stale_24h'] ?? 0}',
            style: const TextStyle(color: AppTheme.mutedText),
          ),
          const SizedBox(height: 6),
          Text(
            'Orta işlərarası yol/keçid: ${_summary['avg_travel_minutes'] ?? 0} dəq · Orta faktiki iş: ${_summary['avg_work_minutes'] ?? 0} dəq',
            style: const TextStyle(color: AppTheme.mutedText),
          ),
        ],
      ),
    );
  }

  Widget _summaryNumber(String label, dynamic value, Color color) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '${_int(value)}',
          style: TextStyle(
            color: color,
            fontSize: 30,
            fontWeight: FontWeight.w900,
            letterSpacing: -1,
          ),
        ),
        Text(label,
            style: const TextStyle(color: AppTheme.mutedText, fontSize: 12)),
      ],
    );
  }

  Widget _employeeCard(Map<String, dynamic> employee) {
    final stats = employee['stats'] as Map<String, dynamic>? ?? {};
    final tasks = employee['tasks'] as List<dynamic>? ?? [];
    final overdue = _int(stats['overdue']);
    final stale = _int(stats['stale_24h']);
    final color = overdue > 0
        ? AppTheme.danger
        : stale > 0
            ? AppTheme.warning
            : AppTheme.success;
    return Card(
      child: ExpansionTile(
        tilePadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        childrenPadding: const EdgeInsets.fromLTRB(14, 0, 14, 14),
        leading: Container(
          width: 48,
          height: 48,
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.14),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Icon(
            employee['role'] == 'unassigned'
                ? Icons.person_off_outlined
                : Icons.badge_outlined,
            color: color,
          ),
        ),
        title: Text(
          '${employee['name']}',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w900),
        ),
        subtitle: Text(
          '${employee['job_title'] ?? ''} · ${employee['area'] ?? ''}\nAktiv: ${stats['active'] ?? 0} · Bitmiş: ${stats['completed'] ?? 0} · Keçid: ${stats['avg_travel_minutes'] ?? 0} dəq · İş: ${stats['avg_work_minutes'] ?? 0} dəq',
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: _statusPill(overdue, stale),
        children: [
          if (tasks.isEmpty)
            const Padding(
              padding: EdgeInsets.all(12),
              child: Text('Bu period üçün tapşırıq yoxdur.'),
            )
          else
            ...tasks.map((raw) => _taskTile(raw as Map<String, dynamic>)),
        ],
      ),
    );
  }

  Widget _statusPill(int overdue, int stale) {
    final text = overdue > 0
        ? '$overdue gecikib'
        : stale > 0
            ? '$stale saxlanıb'
            : 'normal';
    final color = overdue > 0
        ? AppTheme.danger
        : stale > 0
            ? AppTheme.warning
            : AppTheme.success;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.13),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        text,
        style:
            TextStyle(color: color, fontWeight: FontWeight.w900, fontSize: 10),
      ),
    );
  }

  Widget _taskTile(Map<String, dynamic> task) {
    final color = _priorityColor('${task['priority']}');
    final timing = task['timing'] as Map<String, dynamic>? ?? {};
    return Container(
      margin: const EdgeInsets.only(top: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppTheme.panelRaised,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${task['ticket_no']} · ${task['subject']}',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
              ),
              const SizedBox(width: 8),
              Icon(Icons.local_fire_department, color: color, size: 18),
            ],
          ),
          const SizedBox(height: 5),
          Text(
            '${task['area'] ?? '-'} · ${_statusLabel('${task['status']}')} · Yaş: ${task['age_hours'] ?? 0} saat',
            style: const TextStyle(color: AppTheme.mutedText, fontSize: 12),
          ),
          if (task['due_date'] != null)
            Text(
              'Son tarix: ${task['due_date']}',
              style: TextStyle(
                color: task['is_overdue'] == true
                    ? AppTheme.danger
                    : AppTheme.mutedText,
                fontSize: 12,
                fontWeight: FontWeight.w800,
              ),
            ),
          if (timing['travel_minutes'] != null ||
              timing['work_minutes'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'Əvvəlki işdən keçid: ${timing['travel_minutes'] ?? '-'} dəq · Faktiki iş: ${timing['work_minutes'] ?? '-'} dəq',
                style: const TextStyle(
                    color: AppTheme.mutedText,
                    fontSize: 12,
                    fontWeight: FontWeight.w800),
              ),
            ),
          finalEvent(task),
        ],
      ),
    );
  }

  Widget finalEvent(Map<String, dynamic> task) {
    final event = task['last_event'] as Map<String, dynamic>? ?? {};
    final note = '${event['note'] ?? ''}'.trim();
    if (note.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Text(
        'Son hərəkət: $note',
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: const TextStyle(color: AppTheme.mutedText, fontSize: 12),
      ),
    );
  }

  Color _priorityColor(String priority) => switch (priority) {
        'critical' => AppTheme.danger,
        'high' => AppTheme.warning,
        'low' => AppTheme.mutedText,
        _ => AppTheme.primary,
      };

  String _statusLabel(String status) => switch (status) {
        'open' || 'Aciqdir' => 'açıq',
        'assigned' || 'Gozlenilir' => 'təyin edilib',
        'in_progress' || 'Icrada' => 'icrada',
        'resolved' || 'Baglandi' => 'həll edilib',
        'closed' || 'Tesdiqlendi' => 'bağlanıb',
        _ => status,
      };
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
              const Icon(Icons.cloud_off_outlined,
                  size: 48, color: AppTheme.danger),
              const SizedBox(height: 12),
              Text(message),
              const SizedBox(height: 12),
              OutlinedButton(
                  onPressed: retry, child: const Text('Yenidən yoxla')),
            ],
          ),
        ),
      );
}
