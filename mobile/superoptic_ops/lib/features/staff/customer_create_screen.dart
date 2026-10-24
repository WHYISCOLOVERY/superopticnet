import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../../core/network/api_client.dart';

class CustomerCreateScreen extends StatefulWidget {
  const CustomerCreateScreen({super.key});
  @override
  State<CustomerCreateScreen> createState() => _CustomerCreateScreenState();
}

class _CustomerCreateScreenState extends State<CustomerCreateScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _fin = TextEditingController();
  final _document = TextEditingController();
  final _address = TextEditingController();
  final _radius = TextEditingController();
  final _olt = TextEditingController();
  final _pon = TextEditingController();
  final _ontSn = TextEditingController();
  final _mac = TextEditingController();
  bool _loading = true;
  bool _saving = false;
  String _service = 'pppoe';
  String? _area;
  int? _tariffId;
  int? _poolId;
  int? _routerId;
  List<String> _areas = [];
  List<Map<String, dynamic>> _tariffs = [];
  List<Map<String, dynamic>> _pools = [];
  List<Map<String, dynamic>> _routers = [];
  String _message = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  List<Map<String, dynamic>> get _serviceTariffs =>
      _tariffs.where((e) => e['service_type'] == _service).toList();
  List<Map<String, dynamic>> get _servicePools =>
      _pools.where((e) => e['service_type'] == _service).toList();
  Future<void> _load() async {
    try {
      final response = await ApiClient().dio.get('/staff/customer-create.php');
      final data = response.data['data'] as Map<String, dynamic>;
      _areas = (data['areas'] as List? ?? []).map((e) => '$e').toList();
      _tariffs = (data['tariffs'] as List? ?? [])
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .toList();
      _pools = (data['pools'] as List? ?? [])
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .toList();
      _routers = (data['routers'] as List? ?? [])
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .toList();
      _selectDefaults();
    } catch (_) {
      _message = 'Yeni müştəri forması yüklənmədi.';
    }
    if (mounted) setState(() => _loading = false);
  }

  void _selectDefaults() {
    _area ??= _areas.isEmpty ? null : _areas.first;
    _tariffId = _serviceTariffs.isEmpty
        ? null
        : (_serviceTariffs.first['id'] as num).toInt();
    _poolId = _servicePools.isEmpty
        ? null
        : (_servicePools.first['id'] as num).toInt();
    _routerId ??=
        _routers.isEmpty ? null : (_routers.first['id'] as num).toInt();
  }

  Future<void> _save() async {
    if (_saving ||
        !_form.currentState!.validate() ||
        _tariffId == null ||
        _poolId == null ||
        _routerId == null) {
      return;
    }
    setState(() {
      _saving = true;
      _message = '';
    });
    try {
      final response =
          await ApiClient().dio.post('/staff/customer-create.php', data: {
        'request_id': 'create_${DateTime.now().microsecondsSinceEpoch}',
        'full_name': _name.text.trim(),
        'phone': _phone.text.trim(),
        'fin_code': _fin.text.trim(),
        'document_no': _document.text.trim(),
        'address': _address.text.trim(),
        'area': _area,
        'service_type': _service,
        'tariff_id': _tariffId,
        'pool_id': _poolId,
        'router_id': _routerId,
        'radius_username': _radius.text.trim(),
        'olt': _olt.text.trim(),
        'pon': _pon.text.trim(),
        'ont_serial': _ontSn.text.trim(),
        'mac': _mac.text.trim(),
      });
      final data = response.data['data'];
      if (mounted) {
        setState(() => _message =
            'Müştəri yaradıldı\nKod: ${data['subscriber_code']}\nGiriş: ${data['portal_login']}\nMüvəqqəti şifrə: ${data['temporary_password']}\nIP: ${data['assigned_ip']}');
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() => _message =
            '${e.response?.data['error'] ?? 'Müştəri yaradılmadı.'}');
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Widget _field(TextEditingController controller, String label,
          {TextInputType? keyboard}) =>
      Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: TextFormField(
              controller: controller,
              keyboardType: keyboard,
              decoration: InputDecoration(labelText: label),
              validator: (v) =>
                  (v ?? '').trim().isEmpty ? 'Tələb olunur' : null));
  @override
  void dispose() {
    for (final c in [
      _name,
      _phone,
      _fin,
      _document,
      _address,
      _radius,
      _olt,
      _pon,
      _ontSn,
      _mac
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('Yeni müştəri')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SafeArea(
              child: Form(
                  key: _form,
                  child: ListView(padding: const EdgeInsets.all(20), children: [
                    _field(_name, 'Ad soyad'),
                    _field(_phone, 'Telefon', keyboard: TextInputType.phone),
                    _field(_fin, 'FIN'),
                    _field(_document, 'Şəxsiyyət vəsiqəsi'),
                    _field(_address, 'Ünvan'),
                    DropdownButtonFormField<String>(
                        initialValue: _area,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Ərazi'),
                        items: _areas
                            .map((v) =>
                                DropdownMenuItem(value: v, child: Text(v)))
                            .toList(),
                        onChanged: (v) => setState(() => _area = v)),
                    const SizedBox(height: 12),
                    SegmentedButton<String>(
                        segments: const [
                          ButtonSegment(value: 'pppoe', label: Text('PPPoE')),
                          ButtonSegment(value: 'ipoe', label: Text('IPoE'))
                        ],
                        selected: {
                          _service
                        },
                        onSelectionChanged: (v) => setState(() {
                              _service = v.first;
                              _selectDefaults();
                            })),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<int>(
                        key: ValueKey('tariff-$_service'),
                        initialValue: _tariffId,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Paket'),
                        items: _serviceTariffs
                            .map((t) => DropdownMenuItem(
                                value: (t['id'] as num).toInt(),
                                child: Text(
                                    '${t['name']} · ${t['monthly_fee']} AZN')))
                            .toList(),
                        onChanged: (v) => setState(() => _tariffId = v)),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<int>(
                        key: ValueKey('pool-$_service'),
                        initialValue: _poolId,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'IP pool'),
                        items: _servicePools
                            .map((p) => DropdownMenuItem(
                                value: (p['id'] as num).toInt(),
                                child: Text('${p['name']}')))
                            .toList(),
                        onChanged: (v) => setState(() => _poolId = v)),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<int>(
                        initialValue: _routerId,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Router'),
                        items: _routers
                            .map((r) => DropdownMenuItem(
                                value: (r['id'] as num).toInt(),
                                child: Text(
                                    '${r['router_name']} · ${r['management_ip']}')))
                            .toList(),
                        onChanged: (v) => setState(() => _routerId = v)),
                    const SizedBox(height: 12),
                    TextField(
                        controller: _radius,
                        decoration: const InputDecoration(
                            labelText:
                                'RADIUS username · boş qalsa müştəri kodu')),
                    const SizedBox(height: 12),
                    ExpansionTile(
                        title: const Text('OLT / ONT məlumatı'),
                        children: [
                          TextField(
                              controller: _olt,
                              decoration:
                                  const InputDecoration(labelText: 'OLT')),
                          TextField(
                              controller: _pon,
                              decoration:
                                  const InputDecoration(labelText: 'PON')),
                          TextField(
                              controller: _ontSn,
                              decoration:
                                  const InputDecoration(labelText: 'ONT SN')),
                          TextField(
                              controller: _mac,
                              decoration:
                                  const InputDecoration(labelText: 'MAC'))
                        ]),
                    const SizedBox(height: 18),
                    FilledButton(
                        onPressed: _saving ? null : _save,
                        child:
                            Text(_saving ? 'Yaradılır…' : 'Müştərini yarat')),
                    const SizedBox(height: 16),
                    SelectableText(_message),
                  ]))));
}
