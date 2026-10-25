import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';

class ManagerAccountSecurityScreen extends StatefulWidget {
  const ManagerAccountSecurityScreen({super.key});

  @override
  State<ManagerAccountSecurityScreen> createState() =>
      _ManagerAccountSecurityScreenState();
}

class _ManagerAccountSecurityScreenState
    extends State<ManagerAccountSecurityScreen> {
  bool _loading = true;
  String? _error;
  String _search = '';
  List<dynamic> _accounts = [];
  List<dynamic> _audit = [];

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
      final response =
          await ApiClient().dio.get(ApiEndpoints.managerAccountSecurity);
      final data = Map<String, dynamic>.from(response.data['data']);
      if (!mounted) return;
      setState(() {
        _accounts = data['accounts'] as List<dynamic>? ?? [];
        _audit = data['audit'] as List<dynamic>? ?? [];
      });
    } catch (_) {
      if (mounted) setState(() => _error = 'Hesab təhlükəsizliyi yüklənmədi.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _resetPassword(Map<String, dynamic> account) async {
    final controller = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('${account['full_name']} · parolu yenilə'),
        content: TextField(
          controller: controller,
          obscureText: true,
          decoration: const InputDecoration(
            labelText: 'Yeni parol',
            helperText:
                'Ən azı 10 simvol və böyük/kiçik hərf, rəqəm, xüsusi işarə.',
          ),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Ləğv et')),
          FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Parolu yenilə')),
        ],
      ),
    );
    if (confirmed != true) return;
    try {
      final response = await ApiClient().dio.post(
        ApiEndpoints.managerAccountSecurity,
        data: {
          'action': 'reset_password',
          'account_id': account['id'],
          'new_password': controller.text,
        },
      );
      final data = Map<String, dynamic>.from(response.data['data']);
      final temporaryPassword = '${data['temporary_password']}';
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Yeni parol yaradıldı'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('${data['login']}'),
              const SizedBox(height: 10),
              SelectableText(temporaryPassword,
                  style: const TextStyle(
                      fontSize: 18, fontWeight: FontWeight.w900)),
              const SizedBox(height: 8),
              const Text('Əvvəlki cihaz sessiyaları bağlandı.'),
            ],
          ),
          actions: [
            TextButton.icon(
              onPressed: () async {
                await Clipboard.setData(ClipboardData(text: temporaryPassword));
                if (context.mounted) Navigator.pop(context);
              },
              icon: const Icon(Icons.copy_outlined),
              label: const Text('Kopyala'),
            ),
            FilledButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Bağla')),
          ],
        ),
      );
      await _load();
    } on DioException catch (error) {
      if (!mounted) return;
      final message = error.response?.data is Map
          ? error.response?.data['error']
          : 'Parol yenilənmədi.';
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('$message')));
    }
  }

  Future<void> _revokeDevice(
      Map<String, dynamic> account, Map<String, dynamic> device) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Aktiv cihazı ləğv et?'),
        content: Text(
            '${account['full_name']} · ${device['device_model'] ?? device['platform']}\nBu cihaz dərhal sistemdən çıxacaq və hesab başqa telefonda aktivləşdirilə biləcək.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Ləğv et')),
          FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Cihazı çıxart')),
        ],
      ),
    );
    if (confirmed != true) return;
    await ApiClient().dio.post(ApiEndpoints.managerAccountSecurity, data: {
      'action': 'revoke_device',
      'account_id': account['id'],
      'device_uuid': device['device_uuid'],
    });
    await _load();
  }

  Future<void> _activateDevice(
      Map<String, dynamic> account, Map<String, dynamic> device) async {
    try {
      await ApiClient().dio.post(ApiEndpoints.managerAccountSecurity, data: {
        'action': 'activate_device',
        'account_id': account['id'],
        'device_uuid': device['device_uuid'],
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Cihaz yenidən aktivləşdirildi.')),
      );
      await _load();
    } on DioException catch (error) {
      if (!mounted) return;
      final message = error.response?.data is Map
          ? error.response?.data['error']
          : 'Cihaz aktivləşdirilmədi.';
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('$message')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final query = _search.toLowerCase();
    final visible = _accounts.where((raw) {
      final account = raw as Map<String, dynamic>;
      return '${account['full_name']} ${account['login']} ${account['job_title']}'
          .toLowerCase()
          .contains(query);
    }).toList();
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Hesablar və giriş nəzarəti'),
          actions: [
            IconButton(onPressed: _load, icon: const Icon(Icons.refresh))
          ],
          bottom: const TabBar(tabs: [
            Tab(text: 'Hesablar', icon: Icon(Icons.manage_accounts_outlined)),
            Tab(text: 'Giriş jurnalı', icon: Icon(Icons.security_outlined)),
          ]),
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? Center(child: Text(_error!))
                : TabBarView(children: [
                    ListView(
                      padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
                      children: [
                        TextField(
                          onChanged: (value) => setState(() => _search = value),
                          decoration: const InputDecoration(
                              prefixIcon: Icon(Icons.search),
                              labelText: 'Ad və ya giriş adı ilə axtar'),
                        ),
                        const SizedBox(height: 12),
                        ...visible.map((raw) => _accountCard(
                            Map<String, dynamic>.from(raw as Map))),
                      ],
                    ),
                    ListView.builder(
                      padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
                      itemCount: _audit.length,
                      itemBuilder: (context, index) => _auditTile(
                          Map<String, dynamic>.from(_audit[index] as Map)),
                    ),
                  ]),
      ),
    );
  }

  Widget _accountCard(Map<String, dynamic> account) {
    final devices = account['devices'] as List<dynamic>? ?? [];
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${account['full_name']}',
              style: const TextStyle(fontWeight: FontWeight.w900)),
          Text('${account['login']} · ${account['job_title'] ?? ''}',
              style: const TextStyle(color: AppTheme.mutedText)),
          const SizedBox(height: 8),
          Text(
              'Son giriş: ${account['last_login_at'] ?? 'yoxdur'} · Aktiv cihaz: ${account['active_devices']} · 7 günlük uğursuz giriş: ${account['failed_logins_7d']}',
              style: const TextStyle(fontSize: 12)),
          const SizedBox(height: 8),
          ...devices.map((raw) {
            final device = Map<String, dynamic>.from(raw as Map);
            final isActive = device['is_active'] == true ||
                device['is_active'] == 1 ||
                device['is_active'] == '1';
            return Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AppTheme.panelRaised,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: AppTheme.border),
              ),
              child: Row(children: [
                const Icon(Icons.smartphone, size: 20),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    '${device['device_model'] ?? '-'} · ${device['platform'] ?? '-'} · ${isActive ? 'Aktiv' : 'Ləğv edilib'}\nSon aktivlik: ${device['last_seen_at'] ?? '-'}',
                    style: const TextStyle(fontSize: 12),
                  ),
                ),
                IconButton(
                  tooltip: isActive ? 'Cihazı ləğv et' : 'Cihazı aktivləşdir',
                  onPressed: () => isActive
                      ? _revokeDevice(account, device)
                      : _activateDevice(account, device),
                  icon: Icon(
                    isActive ? Icons.phonelink_erase : Icons.phonelink_lock,
                    color: isActive ? AppTheme.danger : AppTheme.success,
                  ),
                ),
              ]),
            );
          }),
          Align(
            alignment: Alignment.centerRight,
            child: OutlinedButton.icon(
              onPressed: () => _resetPassword(account),
              icon: const Icon(Icons.password_outlined),
              label: const Text('Parolu sıfırla'),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _auditTile(Map<String, dynamic> row) {
    final success = row['event_type'] == 'login_success';
    return Card(
      child: ListTile(
        leading: Icon(success ? Icons.login : Icons.gpp_bad_outlined,
            color: success ? AppTheme.success : AppTheme.danger),
        title: Text('${row['full_name'] ?? row['login_entered']}'),
        subtitle: Text(
            '${success ? 'Uğurlu giriş' : row['event_type']} · ${row['device_model'] ?? '-'}\n${row['ip_address']} · ${row['occurred_at']}'),
        isThreeLine: true,
      ),
    );
  }
}
