import 'package:flutter/material.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';

class ManagerOperatorPresenceScreen extends StatefulWidget {
  const ManagerOperatorPresenceScreen({super.key});

  @override
  State<ManagerOperatorPresenceScreen> createState() =>
      _ManagerOperatorPresenceScreenState();
}

class _ManagerOperatorPresenceScreenState
    extends State<ManagerOperatorPresenceScreen> {
  bool _loading = true;
  List<dynamic> _operators = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final response =
          await ApiClient().dio.get(ApiEndpoints.managerOperatorPresence);
      if (mounted) {
        setState(() => _operators =
            response.data['data']['operators'] as List<dynamic>? ?? []);
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _hhmm(Object? value, String fallback) {
    final text = '${value ?? ''}';
    return text.length >= 5 ? text.substring(0, 5) : fallback;
  }

  Future<void> _edit(Map<String, dynamic> operator) async {
    final start =
        TextEditingController(text: _hhmm(operator['shift_start'], '20:00'));
    final end =
        TextEditingController(text: _hhmm(operator['shift_end'], '08:00'));
    final existingBreaks = operator['breaks'] as List<dynamic>? ?? [];
    final firstBreak = existingBreaks.isNotEmpty
        ? existingBreaks.first as Map<String, dynamic>
        : <String, dynamic>{};
    final breakStart =
        TextEditingController(text: '${firstBreak['start'] ?? ''}');
    final breakEnd = TextEditingController(text: '${firstBreak['end'] ?? ''}');
    var enabled = operator['is_enabled'] == true;
    var weekdays =
        (operator['weekdays'] as List<dynamic>? ?? [1, 2, 3, 4, 5, 6, 7])
            .map((value) => int.tryParse('$value') ?? 0)
            .where((value) => value > 0)
            .toSet();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => Padding(
          padding: EdgeInsets.fromLTRB(
              18, 18, 18, MediaQuery.viewInsetsOf(context).bottom + 18),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text('${operator['full_name']} · növbə nəzarəti',
                  style: const TextStyle(
                      fontSize: 19, fontWeight: FontWeight.w900)),
              const SizedBox(height: 14),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Saatlıq aktivlik nəzarəti'),
                value: enabled,
                onChanged: (value) => update(() => enabled = value),
              ),
              Row(children: [
                Expanded(
                  child: TextField(
                    controller: start,
                    decoration:
                        const InputDecoration(labelText: 'Başlama HH:mm'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: TextField(
                    controller: end,
                    decoration: const InputDecoration(labelText: 'Bitmə HH:mm'),
                  ),
                ),
              ]),
              const SizedBox(height: 12),
              const Align(
                alignment: Alignment.centerLeft,
                child: Text('İstirahət intervalı',
                    style: TextStyle(fontWeight: FontWeight.w900)),
              ),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(
                    child: TextField(
                        controller: breakStart,
                        decoration:
                            const InputDecoration(labelText: 'Başlama'))),
                const SizedBox(width: 10),
                Expanded(
                    child: TextField(
                        controller: breakEnd,
                        decoration: const InputDecoration(labelText: 'Bitmə'))),
              ]),
              const SizedBox(height: 12),
              Wrap(
                spacing: 6,
                children: const ['B.e', 'Ç.a', 'Ç', 'C.a', 'C', 'Ş', 'B']
                    .asMap()
                    .entries
                    .map((entry) => FilterChip(
                          label: Text(entry.value),
                          selected: weekdays.contains(entry.key + 1),
                          onSelected: (selected) => update(() {
                            if (selected) {
                              weekdays.add(entry.key + 1);
                            } else {
                              weekdays.remove(entry.key + 1);
                            }
                          }),
                        ))
                    .toList(),
              ),
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: weekdays.isEmpty
                      ? null
                      : () => Navigator.pop(context, true),
                  child: const Text('Növbə qaydasını saxla'),
                ),
              ),
            ]),
          ),
        ),
      ),
    );
    if (ok != true) return;
    final breaks =
        breakStart.text.trim().isNotEmpty && breakEnd.text.trim().isNotEmpty
            ? [
                {'start': breakStart.text.trim(), 'end': breakEnd.text.trim()}
              ]
            : <Map<String, String>>[];
    await ApiClient().dio.post(ApiEndpoints.managerOperatorPresence, data: {
      'account_id': operator['account_id'],
      'shift_start': start.text.trim(),
      'shift_end': end.text.trim(),
      'weekdays': weekdays.toList()..sort(),
      'breaks': breaks,
      'required_interval_minutes': 60,
      'grace_minutes': 10,
      'is_enabled': enabled,
    });
    await _load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('Operator növbəsi'),
          actions: [
            IconButton(onPressed: _load, icon: const Icon(Icons.refresh))
          ],
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: _operators.length,
                itemBuilder: (context, index) {
                  final operator = _operators[index] as Map<String, dynamic>;
                  final missed = operator['open_missed_alerts'] as int? ?? 0;
                  return Card(
                    child: ListTile(
                      leading: Icon(Icons.nightlight_round,
                          color:
                              missed > 0 ? AppTheme.danger : AppTheme.primary),
                      title: Text('${operator['full_name']}',
                          style: const TextStyle(fontWeight: FontWeight.w900)),
                      subtitle: Text(operator['schedule_id'] == null
                          ? 'Növbə qaydası qurulmayıb'
                          : '${_hhmm(operator['shift_start'], '--:--')}–${_hhmm(operator['shift_end'], '--:--')} · son aktivlik: ${operator['last_activity_at'] ?? '-'}'),
                      trailing: IconButton(
                          onPressed: () => _edit(operator),
                          icon: const Icon(Icons.tune)),
                    ),
                  );
                },
              ),
      );
}
