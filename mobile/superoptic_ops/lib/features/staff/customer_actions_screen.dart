import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import '../../core/network/api_client.dart';

class CustomerActionsScreen extends StatefulWidget {
  final int customerId;
  const CustomerActionsScreen({super.key, required this.customerId});
  @override
  State<CustomerActionsScreen> createState() => _CustomerActionsScreenState();
}

class _CustomerActionsScreenState extends State<CustomerActionsScreen> {
  final _reason = TextEditingController();
  final _value = TextEditingController();
  final _document = TextEditingController();
  String _action = 'phone';
  String _mode = '30_days';
  String _kind = 'service';
  bool _busy = false;
  String _message = '';
  String _login = '';
  String _pppoeUsername = '';
  String _pppoePassword = '';
  String _requestId = 'customer_${DateTime.now().microsecondsSinceEpoch}';
  List<Map<String, dynamic>> _tariffs = [];
  int? _tariffId;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await ApiClient().dio.get('/staff/customer-actions.php',
          queryParameters: {'customer_id': widget.customerId});
      final data = r.data['data'] as Map<String, dynamic>;
      final tariffs = (data['tariffs'] as List? ?? const [])
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList();
      if (mounted) {
        setState(() {
          _login = '${data['login']}';
          _pppoeUsername = '${data['pppoe_username'] ?? ''}';
          _pppoePassword = '${data['pppoe_password'] ?? ''}';
          _tariffs = tariffs;
          _tariffId =
              tariffs.isEmpty ? null : (tariffs.first['id'] as num).toInt();
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() => _message = 'Məlumat yüklənmədi və ya icazəniz yoxdur.');
      }
    }
  }

  Future<void> _save() async {
    if (_busy || _reason.text.trim().isEmpty) {
      setState(() => _message = 'Əməliyyatın səbəbini yazın.');
      return;
    }
    setState(() {
      _busy = true;
      _message = '';
    });
    try {
      final r =
          await ApiClient().dio.post('/staff/customer-actions.php', data: {
        'customer_id': widget.customerId,
        'action': _action,
        'reason': _reason.text.trim(),
        'request_id': _requestId,
        'phone': _value.text.trim(),
        'pppoe_password': _action == 'reset_pppoe_password' ? _value.text.trim() : '',
        'days': int.tryParse(_value.text),
        'amount': _value.text.replaceAll(',', '.'),
        'mode': _mode,
        'day': int.tryParse(_value.text),
        'kind': _kind,
        'document_no': _document.text.trim(),
        'tariff_id': _tariffId,
      });
      final data = r.data['data'];
      _requestId = 'customer_${DateTime.now().microsecondsSinceEpoch}';
      if (mounted) {
        setState(() => _message = data['pppoe_password'] != null
            ? 'PPPoE şifrəsi: ${data['pppoe_password']}\nRADIUS-a yazıldı.'
            : data['password'] != null
                ? 'Müvəqqəti şifrə: ${data['password']}\nMüştəri ilk girişdə şifrəni dəyişməlidir.'
                : 'Əməliyyat saxlanıldı.');
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() => _message =
            '${e.response?.data is Map ? (e.response?.data['message'] ?? e.response?.data['error'] ?? 'Əməliyyat alınmadı.') : 'Şəbəkə xətası. Nəticəni yoxlamadan təkrar göndərməyin.'}');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void dispose() {
    _reason.dispose();
    _value.dispose();
    _document.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Müştəri əməliyyatları')),
        body: SafeArea(
            child: ListView(padding: const EdgeInsets.all(20), children: [
          SelectableText('Panel girişi: $_login'),
          const SizedBox(height: 20),
          DropdownButtonFormField<String>(
              initialValue: _action,
              isExpanded: true,
              items: const [
                DropdownMenuItem(
                    value: 'phone', child: Text('Telefonu yenilə')),
                DropdownMenuItem(value: 'package', child: Text('Paketi dəyiş')),
                DropdownMenuItem(value: 'cycle', child: Text('Ödəniş rejimi')),
                DropdownMenuItem(
                    value: 'debt', child: Text('Xidmət / avadanlıq borcu')),
                DropdownMenuItem(
                    value: 'gift_days',
                    child: Text('Hədiyyə günü · ayda ən çox 3 gün')),
                DropdownMenuItem(
                    value: 'borrow_days',
                    child: Text('Borc günü · ayda bir dəfə, 1–3 gün')),
                DropdownMenuItem(
                    value: 'reset_password',
                    child: Text('Panel şifrəsini yenilə')),
                DropdownMenuItem(
                    value: 'reset_pppoe_password',
                    child: Text('PPPoE şifrəsini yenilə'))
              ],
              onChanged: _busy
                  ? null
                  : (v) => setState(() {
                        _action = v!;
                        _value.clear();
                        _message = '';
                      })),
          const SizedBox(height: 16),
          if (_action == 'package')
            DropdownButtonFormField<int>(
                initialValue: _tariffId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Yeni paket'),
                items: _tariffs
                    .map((tariff) => DropdownMenuItem<int>(
                        value: (tariff['id'] as num).toInt(),
                        child: Text(
                            '${tariff['name']} · ${tariff['monthly_fee']} AZN')))
                    .toList(),
                onChanged: (value) => setState(() => _tariffId = value)),
          if (_action == 'cycle')
            DropdownButtonFormField<String>(
                initialValue: _mode,
                items: const [
                  DropdownMenuItem(value: '30_days', child: Text('30 günlük')),
                  DropdownMenuItem(
                      value: 'stable', child: Text('Ayın stabil günü'))
                ],
                onChanged: (v) => setState(() => _mode = v!)),
          if (_action == 'debt') ...[
            DropdownButtonFormField<String>(
                initialValue: _kind,
                items: const [
                  DropdownMenuItem(
                      value: 'service', child: Text('Əlavə xidmət')),
                  DropdownMenuItem(value: 'equipment', child: Text('Avadanlıq'))
                ],
                onChanged: (v) => setState(() => _kind = v!)),
            TextField(
                controller: _document,
                decoration: const InputDecoration(labelText: 'Sənəd nömrəsi')),
          ],
          if (_action == 'reset_pppoe_password') ...[
            Text('PPPoE: $_pppoeUsername'),
            SelectableText('Cari şifrə: ${_pppoePassword.isEmpty ? 'Məlumat yoxdur' : _pppoePassword}'),
            TextField(controller: _value, decoration: const InputDecoration(labelText: 'Yeni PPPoE şifrəsi · boş = avtomatik')),
          ],
          if (!['reset_password', 'reset_pppoe_password', 'package'].contains(_action) &&
              !(_action == 'cycle' && _mode == '30_days'))
            TextField(
                controller: _value,
                decoration: InputDecoration(
                    labelText: switch (_action) {
                  'phone' => 'Telefon',
                  'cycle' => 'Ayın günü · 1–31',
                  'debt' => 'Məbləğ · AZN',
                  _ => 'Gün sayı · 1–3'
                })),
          if (_action == 'gift_days')
            const Padding(
                padding: EdgeInsets.only(top: 12),
                child: Text('Hədiyyə günləri tarixçəyə yazılır və RADIUS-a dərhal tətbiq edilir.')),
          if (_action == 'borrow_days')
            const Padding(
                padding: EdgeInsets.only(top: 12),
                child: Text(
                    'Verilən günlər növbəti tam ödənişin müddətindən çıxılacaq.')),
          const SizedBox(height: 16),
          TextField(
              controller: _reason,
              maxLines: 3,
              decoration: const InputDecoration(
                  labelText: 'Səbəb / müştəri müraciəti')),
          const SizedBox(height: 20),
          FilledButton(
              onPressed: _busy ? null : _save,
              child: Text(_busy ? 'Saxlanılır…' : 'Təsdiqlə')),
          const SizedBox(height: 16),
          SelectableText(_message),
        ])),
      );
}
