import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';

class NotificationCenterScreen extends StatefulWidget {
  const NotificationCenterScreen({super.key});

  @override
  State<NotificationCenterScreen> createState() =>
      _NotificationCenterScreenState();
}

class _NotificationCenterScreenState extends State<NotificationCenterScreen> {
  bool _loading = true;
  bool _sound = true;
  bool _vibration = true;
  String _soundKey = 'superoptic_alert';
  List<dynamic> _items = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final response = await ApiClient().dio.get(ApiEndpoints.notificationFeed);
      final data = response.data['data'] as Map<String, dynamic>;
      final preferences =
          data['preferences'] as Map<String, dynamic>? ?? const {};
      if (!mounted) return;
      setState(() {
        _items = data['items'] as List<dynamic>? ?? [];
        _sound = preferences['sound_enabled'] != false;
        _vibration = preferences['vibration_enabled'] != false;
        _soundKey = '${preferences['sound_key'] ?? 'superoptic_alert'}';
      });
      if (_sound &&
          _items.any((raw) =>
              raw is Map &&
              raw['is_open'] == true &&
              {'high', 'critical'}.contains('${raw['severity']}'))) {
        await SystemSound.play(SystemSoundType.alert);
        if (_vibration) await HapticFeedback.heavyImpact();
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Bildirişlər yüklənmədi.')),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() async {
    await ApiClient().dio.post(ApiEndpoints.notificationPreferences, data: {
      'sound_enabled': _sound,
      'vibration_enabled': _vibration,
      'sound_key': _soundKey,
    });
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Bildiriş ayarları saxlanıldı.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Bildiriş mərkəzi'),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh))
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
              children: [
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(children: [
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('Bildiriş səsi'),
                        subtitle: const Text('Kritik hadisədə səs çalınsın'),
                        value: _sound,
                        onChanged: (value) => setState(() => _sound = value),
                      ),
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('Vibrasiya'),
                        value: _vibration,
                        onChanged: (value) =>
                            setState(() => _vibration = value),
                      ),
                      DropdownButtonFormField<String>(
                        initialValue: _soundKey,
                        decoration: const InputDecoration(
                          labelText: 'Səs profili',
                          prefixIcon: Icon(Icons.volume_up_outlined),
                        ),
                        items: const [
                          DropdownMenuItem(
                              value: 'superoptic_alert',
                              child: Text('SuperOptic xəbərdarlığı')),
                          DropdownMenuItem(
                              value: 'system_default',
                              child: Text('Telefonun standart səsi')),
                          DropdownMenuItem(
                              value: 'silent', child: Text('Səssiz')),
                        ],
                        onChanged: (value) =>
                            setState(() => _soundKey = value ?? _soundKey),
                      ),
                      const SizedBox(height: 12),
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton.icon(
                          onPressed: _save,
                          icon: const Icon(Icons.save_outlined),
                          label: const Text('Ayarları saxla'),
                        ),
                      ),
                    ]),
                  ),
                ),
                const SizedBox(height: 14),
                if (_items.isEmpty)
                  const Card(
                    child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Center(child: Text('Yeni bildiriş yoxdur.')),
                    ),
                  ),
                ..._items.map((raw) {
                  final item = raw as Map<String, dynamic>;
                  final severe =
                      {'high', 'critical'}.contains('${item['severity']}');
                  return Card(
                    child: ListTile(
                      leading: Icon(
                        severe
                            ? Icons.notification_important_outlined
                            : Icons.notifications_outlined,
                        color: severe ? AppTheme.danger : AppTheme.primary,
                      ),
                      title: Text('${item['title']}',
                          style: const TextStyle(fontWeight: FontWeight.w900)),
                      subtitle:
                          Text('${item['message']}\n${item['event_at'] ?? ''}'),
                      isThreeLine: true,
                    ),
                  );
                }),
              ],
            ),
    );
  }
}
