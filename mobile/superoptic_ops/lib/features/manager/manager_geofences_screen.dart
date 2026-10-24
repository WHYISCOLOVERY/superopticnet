import 'package:flutter/material.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';

class ManagerGeofencesScreen extends StatefulWidget {
  const ManagerGeofencesScreen({super.key});

  @override
  State<ManagerGeofencesScreen> createState() => _ManagerGeofencesScreenState();
}

class _ManagerGeofencesScreenState extends State<ManagerGeofencesScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _areas = [];
  List<Map<String, dynamic>> _employees = [];
  List<Map<String, dynamic>> _alerts = [];

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
      final response = await ApiClient().dio.get(ApiEndpoints.managerGeofences);
      final raw = response.data['data']['areas'] as List<dynamic>? ?? [];
      final employeeRaw =
          response.data['data']['employees'] as List<dynamic>? ?? [];
      final alertRaw = response.data['data']['recent_supervision_alerts']
              as List<dynamic>? ??
          [];
      if (!mounted) return;
      setState(() {
        _areas = raw
            .whereType<Map>()
            .map((item) => Map<String, dynamic>.from(item))
            .toList();
        _employees = employeeRaw
            .whereType<Map>()
            .map((item) => Map<String, dynamic>.from(item))
            .toList();
        _alerts = alertRaw
            .whereType<Map>()
            .map((item) => Map<String, dynamic>.from(item))
            .toList();
      });
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Ərazi sərhədləri yüklənmədi.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _editHome(Map<String, dynamic> employee) async {
    final isNightOperator = employee['employee_role'] == 'night_operator';
    final latitude = TextEditingController(
        text: employee['center_latitude']?.toString() ?? '');
    final longitude = TextEditingController(
        text: employee['center_longitude']?.toString() ?? '');
    final radius = TextEditingController(
        text: employee['radius_meters']?.toString() ?? '150');
    final dwell = TextEditingController(
        text: employee['dwell_threshold_minutes']?.toString() ?? '30');
    final workStart = TextEditingController(
        text:
            '${employee['work_start'] ?? (isNightOperator ? '20:00' : '09:00')}'
                .substring(0, 5));
    final workEnd = TextEditingController(
        text: '${employee['work_end'] ?? (isNightOperator ? '08:00' : '18:00')}'
            .substring(0, 5));
    final lunchStart = TextEditingController(
        text: '${employee['lunch_start'] ?? '13:00'}'.substring(0, 5));
    final lunchEnd = TextEditingController(
        text: '${employee['lunch_end'] ?? '14:00'}'.substring(0, 5));
    var active = '${employee['is_active'] ?? '1'}' != '0';
    final save = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: Text('${employee['full_name']} · ev geofence'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(
                isNightOperator
                    ? 'Evdən iş rejimi: növbə vaxtında bu ev zonasından 30 dəqiqədən çox kənarda qalarsa rəhbərə xəbərdarlıq gedir.'
                    : 'İş vaxtında bu ev zonasından işə başlamaq olmaz; 30 dəqiqədən çox evdə qalarsa rəhbərə xəbərdarlıq gedir.',
                style: const TextStyle(color: Colors.white70, fontSize: 12),
              ),
              const SizedBox(height: 12),
              TextField(
                  controller: latitude,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(labelText: 'Enlik')),
              TextField(
                  controller: longitude,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(labelText: 'Uzunluq')),
              TextField(
                  controller: radius,
                  keyboardType: TextInputType.number,
                  decoration:
                      const InputDecoration(labelText: 'Radius (50–1000 m)')),
              TextField(
                  controller: dwell,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                      labelText: 'Xəbərdarlıq müddəti (minimum 30 dəq)')),
              Row(children: [
                Expanded(
                    child: TextField(
                        controller: workStart,
                        decoration:
                            const InputDecoration(labelText: 'İş başlanğıcı'))),
                const SizedBox(width: 10),
                Expanded(
                    child: TextField(
                        controller: workEnd,
                        decoration:
                            const InputDecoration(labelText: 'İş sonu'))),
              ]),
              Row(children: [
                Expanded(
                    child: TextField(
                        controller: lunchStart,
                        decoration: const InputDecoration(
                            labelText: 'Nahar başlanğıcı'))),
                const SizedBox(width: 10),
                Expanded(
                    child: TextField(
                        controller: lunchEnd,
                        decoration:
                            const InputDecoration(labelText: 'Nahar sonu'))),
              ]),
              SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Nəzarət aktivdir'),
                  value: active,
                  onChanged: (value) => setDialogState(() => active = value)),
            ]),
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Ləğv et')),
            FilledButton(
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Yadda saxla')),
          ],
        ),
      ),
    );
    if (save != true) return;
    try {
      await ApiClient().dio.post(ApiEndpoints.managerGeofences, data: {
        'type': 'home',
        'account_id': employee['id'],
        'latitude': double.parse(latitude.text.trim()),
        'longitude': double.parse(longitude.text.trim()),
        'radius_meters': int.parse(radius.text.trim()),
        'dwell_threshold_minutes': int.parse(dwell.text.trim()),
        'work_start': workStart.text.trim(),
        'work_end': workEnd.text.trim(),
        'lunch_start': lunchStart.text.trim(),
        'lunch_end': lunchEnd.text.trim(),
        'is_active': active,
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Ev geofence qaydası saxlanıldı.')));
      await _load();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Koordinat, vaxt və radius dəyərlərini yoxlayın.')));
      }
    } finally {
      for (final controller in [
        latitude,
        longitude,
        radius,
        dwell,
        workStart,
        workEnd,
        lunchStart,
        lunchEnd
      ]) {
        controller.dispose();
      }
    }
  }

  Future<void> _edit(Map<String, dynamic> area) async {
    final geofence = area['geofence'] is Map
        ? Map<String, dynamic>.from(area['geofence'] as Map)
        : <String, dynamic>{};
    final latitude =
        TextEditingController(text: geofence['latitude']?.toString() ?? '');
    final longitude =
        TextEditingController(text: geofence['longitude']?.toString() ?? '');
    final radius = TextEditingController(
        text: geofence['radius_meters']?.toString() ?? '2000');
    var isActive = geofence['is_active'] as bool? ?? true;

    final save = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: Text('${area['area']} sərhədi'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text(
                  'Ərazinin mərkəz koordinatını və icazə verilən radiusu yazın.',
                  style: TextStyle(color: Colors.white70),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: latitude,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(
                    labelText: 'Enlik (latitude)',
                    hintText: '40.4093',
                  ),
                ),
                TextField(
                  controller: longitude,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(
                    labelText: 'Uzunluq (longitude)',
                    hintText: '49.8671',
                  ),
                ),
                TextField(
                  controller: radius,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Radius (metr)',
                    helperText: '100–100000 metr',
                  ),
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Sərhəd aktivdir'),
                  value: isActive,
                  onChanged: (value) => setDialogState(() => isActive = value),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Ləğv et'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Yadda saxla'),
            ),
          ],
        ),
      ),
    );
    if (save != true) return;

    final parsedLatitude = double.tryParse(latitude.text.trim());
    final parsedLongitude = double.tryParse(longitude.text.trim());
    final parsedRadius = int.tryParse(radius.text.trim());
    if (parsedLatitude == null ||
        parsedLongitude == null ||
        parsedRadius == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Koordinat və radius rəqəmlə yazılmalıdır.')));
      }
      return;
    }

    try {
      await ApiClient().dio.post(ApiEndpoints.managerGeofences, data: {
        'area': area['area'],
        'latitude': parsedLatitude,
        'longitude': parsedLongitude,
        'radius_meters': parsedRadius,
        'is_active': isActive,
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('${area['area']} sərhədi saxlanıldı.')));
      await _load();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Ərazi sərhədi saxlanıla bilmədi.')));
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('İş əraziləri'),
          actions: [
            IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
          ],
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(_error!),
                        const SizedBox(height: 10),
                        OutlinedButton(
                            onPressed: _load,
                            child: const Text('Yenidən yoxla')),
                      ],
                    ),
                  )
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView(
                      padding: EdgeInsets.fromLTRB(16, 16, 16,
                          28 + MediaQuery.paddingOf(context).bottom),
                      children: [
                        const Text('İş ərazisi sərhədləri',
                            style: TextStyle(
                                fontSize: 18, fontWeight: FontWeight.w900)),
                        const SizedBox(height: 10),
                        ..._areas.map((area) {
                          final geofence = area['geofence'] is Map
                              ? Map<String, dynamic>.from(
                                  area['geofence'] as Map)
                              : null;
                          return Card(
                            child: ListTile(
                              leading: CircleAvatar(
                                child: Icon(geofence == null
                                    ? Icons.location_off_outlined
                                    : Icons.location_on_outlined),
                              ),
                              title: Text('${area['area']}'),
                              subtitle: Text(geofence == null
                                  ? '${area['employee_count']} əməkdaş · sərhəd qurulmayıb'
                                  : '${area['employee_count']} əməkdaş · ${geofence['radius_meters']} m radius · ${geofence['is_active'] == true ? 'aktiv' : 'söndürülüb'}'),
                              trailing:
                                  const Icon(Icons.edit_location_alt_outlined),
                              onTap: () => _edit(area),
                            ),
                          );
                        }),
                        const SizedBox(height: 22),
                        const Text('Əməkdaş ev geofence qaydaları',
                            style: TextStyle(
                                fontSize: 18, fontWeight: FontWeight.w900)),
                        const SizedBox(height: 6),
                        const Text(
                            'Yalnız növbə vaxtında işləyir; nahar və qısa keçidlər xəbərdarlıq yaratmır.',
                            style: TextStyle(color: Colors.white70)),
                        const SizedBox(height: 10),
                        ..._employees.map((employee) => Card(
                              child: ListTile(
                                leading: Icon(
                                    employee['center_latitude'] == null
                                        ? Icons.home_outlined
                                        : Icons.home_work_outlined),
                                title: Text('${employee['full_name']}'),
                                subtitle: Text(employee['center_latitude'] ==
                                        null
                                    ? '${employee['area'] ?? ''} · ${employee['employee_role'] == 'night_operator' ? 'evdən gecə növbəsi' : 'sahə əməkdaşı'} · qurulmayıb'
                                    : '${employee['area'] ?? ''} · ${employee['employee_role'] == 'night_operator' ? 'evdən gecə növbəsi' : 'evdə iş qadağası'} · ${employee['radius_meters']} m · ${employee['dwell_threshold_minutes']} dəq'),
                                trailing: const Icon(Icons.tune),
                                onTap: () => _editHome(employee),
                              ),
                            )),
                        if (_alerts.isNotEmpty) ...[
                          const SizedBox(height: 22),
                          const Text('Son 7 günün nəzarət hadisələri',
                              style: TextStyle(
                                  fontSize: 18, fontWeight: FontWeight.w900)),
                          const SizedBox(height: 10),
                          ..._alerts.take(20).map((alert) => Card(
                                child: ListTile(
                                  leading: Icon(
                                      alert['resolved_at'] == null
                                          ? Icons.warning_amber_rounded
                                          : Icons.check_circle_outline,
                                      color: alert['resolved_at'] == null
                                          ? Colors.orange
                                          : Colors.green),
                                  title: Text('${alert['title']}'),
                                  subtitle: Text(
                                      '${alert['message']}\n${alert['event_at']}'),
                                  isThreeLine: true,
                                ),
                              )),
                        ],
                      ],
                    ),
                  ),
      );
}
