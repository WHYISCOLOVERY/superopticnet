import 'package:flutter/material.dart';
import 'customer_actions_screen.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../data/services/offline_cache_service.dart';

class ModemSearchScreen extends StatefulWidget {
  const ModemSearchScreen({super.key});

  @override
  State<ModemSearchScreen> createState() => _ModemSearchScreenState();
}

class _ModemSearchScreenState extends State<ModemSearchScreen> {
  final _query = TextEditingController();
  bool _loading = false;
  String? _error;
  Map<String, dynamic>? _result;

  Future<void> _search() async {
    final query = _query.text.trim();
    if (query.isEmpty) return;
    setState(() {
      _loading = true;
      _error = null;
      _result = null;
    });
    try {
      final response = await ApiClient()
          .dio
          .post(ApiEndpoints.staffModemSearch, data: {'query': query});
      await OfflineCacheService.save(
        '${ApiEndpoints.staffModemSearch}:search',
        {'query': query.toLowerCase()},
        response.data,
      );
      if (!mounted) return;
      setState(() => _result = response.data['data'] as Map<String, dynamic>);
    } catch (_) {
      final cached = await OfflineCacheService.read(
        '${ApiEndpoints.staffModemSearch}:search',
        {'query': query.toLowerCase()},
      );
      if (mounted && cached != null && cached['data'] is Map) {
        setState(() => _result = Map<String, dynamic>.from(cached['data']));
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'İnternet yoxdur. Son saxlanmış müştəri məlumatı göstərilir.'),
        ));
      } else if (mounted) {
        setState(() => _error =
            'Müştəri/modem tapılmadı və ya canlı yoxlama mümkün olmadı.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _linkToCustomer(Map<String, dynamic> device) async {
    final customerCode = TextEditingController();
    final code = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('ONT-ni müştəriyə bağla'),
        content: TextField(
          controller: customerCode,
          autofocus: true,
          textCapitalization: TextCapitalization.characters,
          decoration: const InputDecoration(
            labelText: 'Müştəri kodu',
            hintText: 'Məsələn: NAR-00125',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Ləğv et'),
          ),
          FilledButton(
            onPressed: () {
              final value = customerCode.text.trim();
              if (value.isNotEmpty) Navigator.pop(dialogContext, value);
            },
            child: const Text('Bağla'),
          ),
        ],
      ),
    );
    customerCode.dispose();
    if (code == null || !mounted) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ApiClient().dio.post(ApiEndpoints.staffModemLink, data: {
        'customer_code': code,
        'device_key': device['device_key'],
        'pon': device['pon'],
        'ont_id': device['ont_id'],
        'serial': device['serial'],
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text('${device['serial']} $code müştərisinə bağlandı.')),
      );
      _query.text = code;
      await _search();
    } catch (_) {
      if (mounted) {
        setState(() => _error =
            'ONT bağlanmadı. Müştəri kodunu, canlı bağlantını və əvvəlki cihaz təyinatını yoxlayın.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final customer = _result?['customer'] as Map<String, dynamic>? ?? {};
    final device = _result?['device'] as Map<String, dynamic>? ?? {};
    final service = _result?['service'] as Map<String, dynamic>? ?? {};
    final equipment = _result?['equipment_lifecycle'] as List<dynamic>? ?? [];
    final history = _result?['ticket_history'] as List<dynamic>? ?? [];
    return Scaffold(
      appBar: AppBar(title: const Text('Müştəri və modem diaqnostikası')),
      body: ListView(
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
          padding: EdgeInsets.fromLTRB(
              18, 18, 18, 24 + MediaQuery.viewInsetsOf(context).bottom),
          children: [
            TextField(
              controller: _query,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _search(),
              decoration: InputDecoration(
                labelText: 'Kod, PPPoE, ad-soyad, MAC və ya SN',
                prefixIcon: const Icon(Icons.manage_search),
                suffixIcon: IconButton(
                    onPressed: _loading ? null : _search,
                    icon: const Icon(Icons.search)),
              ),
            ),
            if (_loading)
              const Padding(
                  padding: EdgeInsets.all(28),
                  child: Center(child: CircularProgressIndicator())),
            if (_error != null)
              Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: Text(_error!,
                      style: const TextStyle(color: Colors.redAccent))),
            if (_result != null) ...[
              if (_result?['can_link_ont'] == true &&
                  ((customer['id'] as num?)?.toInt() ?? 0) == 0 &&
                  '${device['serial'] ?? ''}'.isNotEmpty &&
                  device['serial'] != '-')
                Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: FilledButton.icon(
                    onPressed: _loading ? null : () => _linkToCustomer(device),
                    icon: const Icon(Icons.link),
                    label: const Text('Bu ONT-ni müştəri koduna bağla'),
                  ),
                ),
              if (_result?['can_manage_customer'] == true)
                Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: FilledButton(
                      onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (_) => CustomerActionsScreen(
                                  customerId:
                                      (customer['id'] as num).toInt()))),
                      child: const Text(
                          'Telefon, borc, ödəniş rejimi və panel şifrəsi'),
                    )),
              const SizedBox(height: 18),
              _section('Müştəri', [
                _line('Ad', customer['name']),
                _line('Kod', customer['subscriber_code']),
                _line('Telefon', customer['phone']),
                _line('Ünvan', '${customer['area']} · ${customer['address']}'),
                _line('Müştəri vəziyyəti', customer['status']),
              ]),
              _section('Canlı modem vəziyyəti', [
                _line('OLT / PON / ONT',
                    '${device['olt']} / ${device['pon']} / ${device['ont_id']}'),
                _line('SN / MAC', '${device['serial']} / ${device['mac']}'),
                _line('Vəziyyət', device['status']),
                _line('ONT RX / TX',
                    '${device['ont_rx']} / ${device['ont_tx']} dBm'),
                _line('OLT RX', '${device['olt_rx']} dBm'),
                _line('Temperatur', '${device['temperature']} °C'),
              ]),
              _section('Xidmət', [
                _line('RADIUS',
                    service['radius_online'] == true ? 'Onlayn' : 'Oflayn'),
                _line('PPPoE istifadəçi adı', service['pppoe_username']),
                if (service['can_manage_pppoe'] == true)
                  _line('PPPoE şifrəsi', service['pppoe_password'] ?? 'Məlumat yoxdur'),
                _line('Paket', service['plan']),
                _line('Sessiya IP', service['session_ip']),
                _line('Upload', _formatBytes(service['upload_bytes'])),
                _line('Download', _formatBytes(service['download_bytes'])),
                _line('Açıq biletlər', service['open_ticket_count']),
                _line('Xidmət aktivdir',
                    service['service_active'] == true ? 'Bəli' : 'Xeyr'),
                _line(
                    'Məlumat mənbəyi',
                    _result!['live'] == true
                        ? 'Canlı OLT sorğusu'
                        : 'Son saxlanmış ölçü'),
              ]),
              _section(
                  'Avadanlıq tarixçəsi',
                  equipment.isEmpty
                      ? [_line('Nəticə', 'Müştəriyə bağlı avadanlıq yoxdur')]
                      : equipment.map((raw) {
                          final row = raw as Map<String, dynamic>;
                          return _line(
                              '${row['vendor'] ?? ''} ${row['model'] ?? row['asset_code'] ?? 'Cihaz'}',
                              '${row['serial_number'] ?? '-'} · ${row['mac_address'] ?? '-'} · ${row['assignment_status'] ?? row['lifecycle_status'] ?? '-'}');
                        }).toList()),
              _section(
                'Əvvəlki problemlər və həllər',
                history.isEmpty
                    ? [_line('Nəticə', 'Əvvəlki bilet tapılmadı')]
                    : history.map((raw) {
                        final row = Map<String, dynamic>.from(raw as Map);
                        final solution = '${row['resolution_reason'] ?? ''}'
                                .trim()
                                .isNotEmpty
                            ? row['resolution_reason']
                            : row['resolution_note'];
                        return _line(
                          '${row['ticket_no']} · ${row['opened_at']}',
                          '${row['subject']}\nVəziyyət: ${row['status']} · Həll: ${solution ?? 'qeyd edilməyib'} · ${row['elapsed_minutes'] ?? 0} dəq.',
                        );
                      }).toList(),
              ),
            ],
          ]),
    );
  }

  Widget _section(String title, List<Widget> children) => Card(
        child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title,
                    style: const TextStyle(
                        fontSize: 16, fontWeight: FontWeight.bold)),
                const Divider(),
                ...children
              ],
            )),
      );

  Widget _line(String label, Object? value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(
              width: 112,
              child:
                  Text(label, style: const TextStyle(color: Colors.white60))),
          Expanded(child: Text('${value ?? '-'}')),
        ]),
      );

  String _formatBytes(dynamic value) {
    final bytes =
        value is num ? value.toDouble() : double.tryParse('$value') ?? 0;
    if (bytes >= 1073741824) {
      return '${(bytes / 1073741824).toStringAsFixed(2)} GB';
    }
    if (bytes >= 1048576) return '${(bytes / 1048576).toStringAsFixed(1)} MB';
    if (bytes >= 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
    return '${bytes.toInt()} B';
  }
}
