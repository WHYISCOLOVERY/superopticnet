import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../../core/theme/app_theme.dart';
import '../../data/services/outbox_service.dart';

class FinanceScreen extends StatefulWidget {
  const FinanceScreen({super.key});
  @override
  State<FinanceScreen> createState() => _FinanceScreenState();
}

class _FinanceScreenState extends State<FinanceScreen> {
  String _range = 'today';
  DateTimeRange? _custom;
  Map<String, dynamic>? _data;
  String? _error;
  bool _loading = true;
  int _request = 0;
  String _date(DateTime d) => d.toIso8601String().substring(0, 10);
  double _number(dynamic n) => double.tryParse('$n') ?? 0;
  String _money(dynamic n) => '${_number(n).toStringAsFixed(2)} AZN';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r =
          await ApiClient().dio.get('/finance/ledger.php', queryParameters: {
        'range': _range,
        if (_custom != null) 'start_date': _date(_custom!.start),
        if (_custom != null) 'end_date': _date(_custom!.end),
      });
      if (mounted && request == _request) {
        setState(() => _data = Map<String, dynamic>.from(r.data['data']));
      }
    } on DioException catch (e) {
      if (mounted && request == _request) {
        setState(() => _error = e.response?.statusCode == 403
            ? 'Maliyyə bölməsinə giriş səlahiyyətiniz yoxdur.'
            : 'Hesabat yüklənmədi. Bağlantını yoxlayıb yenidən sınayın.');
      }
    } finally {
      if (mounted && request == _request) setState(() => _loading = false);
    }
  }

  Future<void> _pickRange() async {
    final selected = await showDateRangePicker(
        context: context,
        firstDate: DateTime(2020),
        lastDate: DateTime.now(),
        initialDateRange: _custom);
    if (selected == null || !mounted) return;
    setState(() {
      _custom = selected;
      _range = 'custom';
    });
    await _load();
  }

  Future<void> _create() async {
    final saved = await Navigator.push<bool>(context,
        MaterialPageRoute(builder: (_) => const _FinanceEntryScreen()));
    if (saved == true && mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final daily = (_data?['daily'] as List?) ?? [];
    final entries = (_data?['entries'] as List?) ?? [];
    final maxAmount = daily.fold<double>(1, (value, row) {
      final income = _number(row['income']);
      final expense = _number(row['expense']);
      return [value, income, expense].reduce((a, b) => a > b ? a : b);
    });
    return Scaffold(
      appBar: AppBar(title: const Text('Maliyyə və hesabatlar')),
      body: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: EdgeInsets.fromLTRB(
                  20, 20, 20, 24 + MediaQuery.paddingOf(context).bottom),
              children: [
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final item in {
                    'today': 'Bu gün',
                    'week': '7 gün',
                    'month': '30 gün',
                    'year': '12 ay'
                  }.entries)
                    ChoiceChip(
                        label: Text(item.value),
                        selected: _range == item.key,
                        onSelected: (_) {
                          setState(() => _range = item.key);
                          _load();
                        }),
                  ActionChip(
                      avatar: const Icon(Icons.date_range, size: 18),
                      label: const Text('Tarix seç'),
                      onPressed: _pickRange),
                ]),
                const SizedBox(height: 20),
                if (_loading)
                  const LinearProgressIndicator()
                else if (_error != null) ...[
                  Text(_error!),
                  const SizedBox(height: 12),
                  OutlinedButton(
                      onPressed: _load, child: const Text('Yenidən yoxla')),
                ] else ...[
                  Text(
                      '${_data?['period']['start_date']} — ${_data?['period']['end_date']}',
                      style: const TextStyle(color: AppTheme.mutedText)),
                  const SizedBox(height: 16),
                  for (final kpi in (_data?['kpis'] as List? ?? []))
                    Card(
                        margin: const EdgeInsets.only(bottom: 12),
                        child: Padding(
                            padding: const EdgeInsets.all(18),
                            child: Wrap(
                                alignment: WrapAlignment.spaceBetween,
                                spacing: 20,
                                runSpacing: 8,
                                children: [
                                  Text(const {
                                        'Gelir': 'Gəlir',
                                        'Xerc': 'Xərc',
                                        'Net pul axini': 'Xalis pul axını',
                                        'Qaime': 'Qaimə'
                                      }[kpi['label']] ??
                                      '${kpi['label']}'),
                                  Text(
                                      kpi['kind'] == 'money'
                                          ? _money(kpi['value'])
                                          : '${kpi['value']}',
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w800,
                                          fontSize: 20))
                                ]))),
                  const SizedBox(height: 20),
                  FilledButton.icon(
                      onPressed: _create,
                      icon: const Icon(Icons.add),
                      label: const Text('Gəlir / xərc qeyd et')),
                  const SizedBox(height: 28),
                  const Text('Qeyd edilmiş gəlir və xərclər',
                      style:
                          TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 8),
                  const Text(
                      'Qrafik: təsdiqlənmiş AZN qeydləri. Yaşıl — gəlir, narıncı — xərc. '
                      'Yuxarıdakı ümumi gəlirə billing ödənişləri də daxildir.',
                      style: TextStyle(color: AppTheme.mutedText)),
                  const SizedBox(height: 16),
                  if (daily.isEmpty)
                    const Text(
                        'Bu aralıqda təsdiqlənmiş maliyyə qeydi yoxdur.'),
                  for (final row in daily)
                    Padding(
                        padding: const EdgeInsets.only(bottom: 18),
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('${row['day']}'),
                              const SizedBox(height: 8),
                              _bar('Gəlir', _number(row['income']), maxAmount,
                                  AppTheme.success),
                              const SizedBox(height: 8),
                              _bar('Xərc', _number(row['expense']), maxAmount,
                                  AppTheme.primary),
                            ])),
                  const SizedBox(height: 24),
                  const Text('Əməliyyat tarixçəsi',
                      style:
                          TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                  const Text('Seçilmiş aralıqda son 200 qeyd'),
                  const SizedBox(height: 12),
                  if (entries.isEmpty) const Text('Qeyd yoxdur.'),
                  for (final row in entries)
                    Card(
                        child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text('${row['title']}',
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w800)),
                                  const SizedBox(height: 8),
                                  Text(
                                      '${row['entry_type'] == 'income' ? 'Gəlir' : 'Xərc'} • ${row['amount']} ${row['currency']}'),
                                  Text(
                                      '${row['transaction_date']} • ${row['created_by_name']}'),
                                  if (row['payment_source'] != null)
                                    Text(
                                        'Mənbə / təyinat: ${row['payment_source']}'),
                                  if (row['note'] != null)
                                    Text('Səbəb: ${row['note']}'),
                                  const SizedBox(height: 8),
                                  Text(
                                      {
                                            'posted': 'Qeydə alınıb',
                                            'pending_approval':
                                                'Təsdiq gözləyir',
                                            'void': 'Ləğv edilib'
                                          }[row['status']] ??
                                          '${row['status']}',
                                      style: const TextStyle(
                                          color: AppTheme.accent)),
                                ]))),
                ],
              ])),
    );
  }

  Widget _bar(String label, double value, double max, Color color) => Semantics(
      label: '$label ${_money(value)}',
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('$label: ${_money(value)}'),
        const SizedBox(height: 4),
        LinearProgressIndicator(
            value: (value / max).clamp(0, 1),
            minHeight: 10,
            color: color,
            backgroundColor: AppTheme.border,
            borderRadius: BorderRadius.circular(6)),
      ]));
}

class _FinanceEntryScreen extends StatefulWidget {
  const _FinanceEntryScreen();
  @override
  State<_FinanceEntryScreen> createState() => _FinanceEntryScreenState();
}

class _FinanceEntryScreenState extends State<_FinanceEntryScreen> {
  final _form = GlobalKey<FormState>();
  final _title = TextEditingController();
  final _amount = TextEditingController();
  final _source = TextEditingController();
  final _reason = TextEditingController();
  String _key = const Uuid().v4();
  String _type = 'income';
  DateTime _date = DateTime.now();
  bool _saving = false;
  String? _error;
  @override
  void dispose() {
    for (final c in [_title, _amount, _source, _reason]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate() || _saving) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    final payload = <String, dynamic>{
      'entry_type': _type,
      'title': _title.text.trim(),
      'amount': _amount.text.replaceAll(',', '.'),
      'transaction_date': _date.toIso8601String().substring(0, 10),
      'payment_source': _source.text.trim(),
      'reason': _reason.text.trim(),
      'idempotency_key': 'mobile-finance-$_key',
      'client_uuid': _key,
    };
    try {
      final accountId = await SecureStorageService.getUserId();
      if (accountId == null) throw StateError('Aktiv sessiya tapılmadı.');
      await OutboxService.enqueue(
        clientUuid: _key,
        accountId: accountId,
        endpoint: '/finance/ledger.php',
        payload: payload,
      );
      final result =
          await ApiClient().dio.post('/finance/ledger.php', data: payload);
      await OutboxService.markCompleted(_key);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(result.data['data']['status'] == 'pending_approval'
              ? 'Xərc qeydə alındı və təsdiq gözləyir.'
              : 'Maliyyə qeydi saxlanıldı.')));
      Navigator.pop(context, true);
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      if (status != null && status >= 400 && status < 500) {
        await OutboxService.markCompleted(_key);
        if (!mounted) return;
        if (status == 422) _key = const Uuid().v4();
        setState(() => _error = e.response?.data is Map
            ? '${e.response?.data['error'] ?? 'Qeyd saxlanmadı.'}'
            : 'Qeyd server tərəfindən qəbul edilmədi.');
      } else {
        await OutboxService.recordFailure(_key, e);
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'İnternet yoxdur. Maliyyə qeydi telefonda saxlanıldı və bağlantı gələndə göndəriləcək.'),
        ));
        Navigator.pop(context, true);
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Yeni maliyyə qeydi')),
        body: Form(
            key: _form,
            child: ListView(
                keyboardDismissBehavior:
                    ScrollViewKeyboardDismissBehavior.onDrag,
                padding: EdgeInsets.fromLTRB(
                    20, 20, 20, 32 + MediaQuery.viewInsetsOf(context).bottom),
                children: [
                  SegmentedButton<String>(
                      segments: const [
                        ButtonSegment(
                            value: 'income',
                            label: Text('Gəlir'),
                            icon: Icon(Icons.south_west)),
                        ButtonSegment(
                            value: 'expense',
                            label: Text('Xərc'),
                            icon: Icon(Icons.north_east)),
                      ],
                      selected: {
                        _type
                      },
                      onSelectionChanged: _saving
                          ? null
                          : (s) => setState(() => _type = s.first)),
                  const SizedBox(height: 24),
                  _field(_title, 'Başlıq'),
                  _field(_amount, 'Məbləğ (AZN)', amount: true),
                  _field(
                      _source,
                      _type == 'income'
                          ? 'Haradan gəlib?'
                          : 'Hara / kimə xərclənib?'),
                  _field(_reason, 'Səbəb və açıqlama', lines: 3),
                  OutlinedButton.icon(
                      icon: const Icon(Icons.calendar_today),
                      label: Text(_date.toIso8601String().substring(0, 10)),
                      onPressed: _saving
                          ? null
                          : () async {
                              final date = await showDatePicker(
                                  context: context,
                                  initialDate: _date,
                                  firstDate: DateTime(2020),
                                  lastDate: DateTime.now());
                              if (date != null && mounted) {
                                setState(() => _date = date);
                              }
                            }),
                  const SizedBox(height: 20),
                  if (_error != null)
                    Padding(
                        padding: const EdgeInsets.only(bottom: 16),
                        child: Text(_error!)),
                  FilledButton(
                      onPressed: _saving ? null : _save,
                      child: Text(_saving ? 'Saxlanılır…' : 'Qeydi saxla')),
                ])),
      );

  Widget _field(TextEditingController c, String label,
          {bool amount = false, int lines = 1}) =>
      Padding(
          padding: const EdgeInsets.only(bottom: 20),
          child: TextFormField(
            controller: c,
            enabled: !_saving,
            maxLines: lines,
            keyboardType: amount
                ? const TextInputType.numberWithOptions(decimal: true)
                : TextInputType.text,
            decoration:
                InputDecoration(labelText: label, alignLabelWithHint: true),
            validator: (v) {
              if (v == null || v.trim().isEmpty) return 'Bu sahəni doldurun.';
              if (amount) {
                final n = double.tryParse(v.replaceAll(',', '.'));
                if (n == null || !n.isFinite || n <= 0) {
                  return 'Sıfırdan böyük məbləğ yazın.';
                }
              }
              return null;
            },
          ));
}
