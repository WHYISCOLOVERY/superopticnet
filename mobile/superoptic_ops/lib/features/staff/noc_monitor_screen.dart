import 'package:flutter/material.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';

class NocMonitorScreen extends StatefulWidget {
  const NocMonitorScreen({super.key});

  @override
  State<NocMonitorScreen> createState() => _NocMonitorScreenState();
}

class _NocMonitorScreenState extends State<NocMonitorScreen> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _summary = {};
  List<Map<String, dynamic>> _devices = [];
  List<Map<String, dynamic>> _alarms = [];
  List<Map<String, dynamic>> _history = [];
  List<Map<String, dynamic>> _syslog = [];
  String _filter = 'all';
  String _query = '';

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
      final response = await ApiClient().dio.get(ApiEndpoints.staffNocMonitor);
      final data = Map<String, dynamic>.from(response.data['data'] as Map);
      if (!mounted) return;
      setState(() {
        _summary = Map<String, dynamic>.from(data['summary'] as Map? ?? {});
        _devices = (data['devices'] as List<dynamic>? ?? [])
            .whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .toList();
        _alarms = (data['alarms'] as List<dynamic>? ?? [])
            .whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .toList();
        _history = (data['history'] as List<dynamic>? ?? [])
            .whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .toList();
        _syslog = (data['syslog'] as List<dynamic>? ?? [])
            .whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .toList();
      });
    } catch (_) {
      if (mounted) setState(() => _error = 'NOC məlumatları yüklənmədi.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final visible = _devices.where((device) {
      final state = '${device['state']}';
      final freshness = '${device['freshness']}';
      final text =
          '${device['name']} ${device['ip_address']} ${device['vendor']} ${device['model']}'
              .toLowerCase();
      final matchesFilter = _filter == 'all' ||
          (_filter == 'stale' ? freshness == 'stale' : state == _filter);
      return matchesFilter && text.contains(_query.toLowerCase());
    }).toList();
    return Scaffold(
      appBar: AppBar(
        title: const Text('NOC canlı monitor'),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh))
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: EdgeInsets.fromLTRB(
                    16, 12, 16, 24 + MediaQuery.paddingOf(context).bottom),
                children: [
                  if (_error != null) _errorCard(),
                  _summaryGrid(),
                  const SizedBox(height: 14),
                  TextField(
                    onChanged: (value) => setState(() => _query = value),
                    decoration: const InputDecoration(
                      prefixIcon: Icon(Icons.search),
                      labelText: 'Cihaz adı, IP, vendor və model',
                    ),
                  ),
                  const SizedBox(height: 10),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(children: [
                      _filterChip('all', 'Hamısı'),
                      _filterChip('offline', 'Sönən'),
                      _filterChip('online', 'Aktiv'),
                      _filterChip('stale', 'Köhnə ölçü'),
                      _filterChip('unknown', 'Naməlum'),
                      _filterChip('disabled', 'Monitorinqsiz'),
                    ]),
                  ),
                  const SizedBox(height: 14),
                  Text('Avadanlıqlar · ${visible.length}',
                      style: const TextStyle(
                          fontSize: 18, fontWeight: FontWeight.w900)),
                  const SizedBox(height: 8),
                  if (visible.isEmpty)
                    const Card(
                        child: Padding(
                            padding: EdgeInsets.all(22),
                            child: Text('Bu filtrə uyğun cihaz yoxdur.'))),
                  ...visible.map(_deviceCard),
                  const SizedBox(height: 18),
                  Text('Anlamlandırılmış aktiv hadisələr · ${_alarms.length}',
                      style: const TextStyle(
                          fontSize: 18, fontWeight: FontWeight.w900)),
                  const SizedBox(height: 8),
                  if (_alarms.isEmpty)
                    const Card(
                        child: Padding(
                            padding: EdgeInsets.all(22),
                            child: Text('Aktiv şəbəkə hadisəsi yoxdur.'))),
                  ..._alarms.take(30).map(_alarmCard),
                  const SizedBox(height: 18),
                  Text('Xəta və vəziyyət tarixçəsi · ${_history.length}',
                      style: const TextStyle(
                          fontSize: 18, fontWeight: FontWeight.w900)),
                  const SizedBox(height: 8),
                  if (_history.isEmpty)
                    const Card(
                        child: Padding(
                            padding: EdgeInsets.all(22),
                            child:
                                Text('Cihaz hadisə tarixçəsi hələ yoxdur.'))),
                  ..._history.take(50).map(_historyCard),
                  const SizedBox(height: 18),
                  Text('Router və cihaz jurnalı · ${_syslog.length}',
                      style: const TextStyle(
                          fontSize: 18, fontWeight: FontWeight.w900)),
                  const SizedBox(height: 8),
                  if (_syslog.isEmpty)
                    const Card(
                        child: Padding(
                            padding: EdgeInsets.all(22),
                            child: Text(
                                'Syslog qəbuledicisi hazırdır. Routerdən ilk hadisə gözlənilir.'))),
                  ..._syslog.take(50).map(_syslogCard),
                ],
              ),
            ),
    );
  }

  Widget _summaryGrid() {
    final rows = [
      ('online', 'Aktiv', Icons.check_circle_outline, AppTheme.success),
      ('offline', 'Sönən', Icons.cloud_off_outlined, AppTheme.danger),
      ('stale', 'Köhnə ölçü', Icons.history_toggle_off, AppTheme.warning),
      ('unknown', 'Naməlum', Icons.help_outline, AppTheme.mutedText),
    ];
    return GridView.count(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisCount: 2,
      childAspectRatio: 1.8,
      crossAxisSpacing: 10,
      mainAxisSpacing: 10,
      children: rows
          .map((row) => Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                    color: AppTheme.panel,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: AppTheme.border)),
                child: Row(children: [
                  Icon(row.$3, color: row.$4),
                  const SizedBox(width: 10),
                  Expanded(
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                        Text('${_summary[row.$1] ?? 0}',
                            style: const TextStyle(
                                fontSize: 23, fontWeight: FontWeight.w900)),
                        Text(row.$2,
                            style: const TextStyle(
                                color: AppTheme.mutedText, fontSize: 12)),
                      ])),
                ]),
              ))
          .toList(),
    );
  }

  Widget _filterChip(String key, String label) => Padding(
        padding: const EdgeInsets.only(right: 8),
        child: ChoiceChip(
            label: Text(label),
            selected: _filter == key,
            onSelected: (_) => setState(() => _filter = key)),
      );

  Widget _deviceCard(Map<String, dynamic> device) {
    final state = '${device['state']}';
    final freshness = '${device['freshness']}';
    final stateColor = switch (state) {
      'online' => AppTheme.emerald,
      'offline' => AppTheme.danger,
      'disabled' => AppTheme.mutedText,
      _ => AppTheme.warning,
    };
    final stateLabel = switch (state) {
      'online' => 'AKTİV',
      'offline' => 'SÖNÜB',
      'disabled' => 'MONİTORİNQ SÖNÜB',
      _ => 'NAMƏLUM',
    };
    final isStale = freshness == 'stale';
    final traffic = device['traffic'] is Map
        ? Map<String, dynamic>.from(device['traffic'] as Map)
        : <String, dynamic>{};
    final loadText = traffic.isEmpty
        ? 'Yük: ölçü yoxdur'
        : 'Yük ↓ ${_formatBps(traffic['in_bps'])} · ↑ ${_formatBps(traffic['out_bps'])}';
    final lastError = '${device['last_probe_error'] ?? ''}'.trim();
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: AppTheme.panel,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: stateColor.withValues(alpha: .52)),
      ),
      child: IntrinsicHeight(
        child: Row(children: [
          Container(
            width: 5,
            decoration: BoxDecoration(
              color: stateColor,
              borderRadius:
                  const BorderRadius.horizontal(left: Radius.circular(22)),
            ),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.all(15),
              child:
                  Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Container(
                  width: 46,
                  height: 46,
                  decoration: BoxDecoration(
                    color: stateColor.withValues(alpha: .14),
                    borderRadius: BorderRadius.circular(15),
                  ),
                  child: Icon(Icons.router_outlined, color: stateColor),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${device['name']}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w900)),
                      const SizedBox(height: 4),
                      Text(
                        '${device['ip_address']} · ${device['vendor']} ${device['model']}',
                        style: const TextStyle(
                            color: AppTheme.mutedText, fontSize: 12),
                      ),
                      const SizedBox(height: 8),
                      Text(loadText,
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      const SizedBox(height: 3),
                      Text('Son ölçü: ${device['last_probe_at'] ?? 'yoxdur'}',
                          style: const TextStyle(
                              color: AppTheme.mutedText, fontSize: 12)),
                      if (lastError.isNotEmpty) ...[
                        const SizedBox(height: 3),
                        Text('Xəta: $lastError',
                            style: const TextStyle(
                                color: AppTheme.danger, fontSize: 12)),
                      ],
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                  _statusBadge(stateLabel, stateColor),
                  if (isStale) ...[
                    const SizedBox(height: 7),
                    _statusBadge('ÖLÇÜ KÖHNƏDİR', AppTheme.warning),
                  ],
                ]),
              ]),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _statusBadge(String label, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        decoration: BoxDecoration(
          color: color.withValues(alpha: .14),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: color.withValues(alpha: .45)),
        ),
        child: Text(label,
            textAlign: TextAlign.center,
            style: TextStyle(
                color: color, fontSize: 9, fontWeight: FontWeight.w900)),
      );

  Widget _alarmCard(Map<String, dynamic> alarm) => Card(
          child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${alarm['group'] ?? 'Şəbəkə hadisəsi'}',
              style: const TextStyle(
                  color: AppTheme.warning,
                  fontSize: 12,
                  fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text('${alarm['title']}',
              style: const TextStyle(fontWeight: FontWeight.w900)),
          const SizedBox(height: 6),
          Text('${alarm['message']}',
              style: const TextStyle(color: AppTheme.mutedText, height: 1.35)),
        ]),
      ));

  Widget _historyCard(Map<String, dynamic> row) => Card(
        child: ListTile(
          leading: const Icon(Icons.history_outlined, color: AppTheme.warning),
          title:
              Text('${row['title'] ?? row['dedup_key'] ?? 'Şəbəkə hadisəsi'}'),
          subtitle: Text(
            '${row['from_status'] ?? '-'} → ${row['to_status'] ?? '-'} · ${row['to_severity'] ?? '-'}\n${row['created_at'] ?? ''}',
          ),
          isThreeLine: true,
        ),
      );

  String _formatBps(dynamic raw) {
    final value = raw is num ? raw.toDouble() : double.tryParse('$raw') ?? 0;
    if (value >= 1000000000) {
      return '${(value / 1000000000).toStringAsFixed(1)} Gbit/s';
    }
    if (value >= 1000000) {
      return '${(value / 1000000).toStringAsFixed(1)} Mbit/s';
    }
    if (value >= 1000) return '${(value / 1000).toStringAsFixed(1)} Kbit/s';
    return '${value.toStringAsFixed(0)} bit/s';
  }

  Widget _syslogCard(Map<String, dynamic> row) => Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                const Icon(Icons.receipt_long_outlined,
                    size: 18, color: AppTheme.primary),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    '${row['hostname'] ?? 'Cihaz'} · ${row['source_ip'] ?? '-'}',
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                ),
                Text('${row['occurred_at'] ?? ''}',
                    style: const TextStyle(
                        color: AppTheme.mutedText, fontSize: 10)),
              ]),
              const SizedBox(height: 7),
              SelectableText('${row['message'] ?? ''}',
                  style:
                      const TextStyle(color: AppTheme.mutedText, height: 1.35)),
            ],
          ),
        ),
      );

  Widget _errorCard() => Card(
          child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(children: [
          const Expanded(
              child: Text(
                  'NOC serveri cavab vermədi. Aşağı çəkərək yenidən yoxlayın.')),
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
        ]),
      ));
}
