import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';

class InventoryScreen extends StatefulWidget {
  const InventoryScreen({super.key});
  @override
  State<InventoryScreen> createState() => _InventoryScreenState();
}

class _InventoryScreenState extends State<InventoryScreen> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _data = {};
  bool get _canManage => _data['can_manage'] == true;
  List<dynamic> _list(String key) => _data[key] as List<dynamic>? ?? [];

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
      final response = await ApiClient().dio.get('/inventory/overview.php');
      if (mounted) {
        setState(
            () => _data = Map<String, dynamic>.from(response.data['data']));
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() => _error = e.response?.data is Map
            ? '${e.response?.data['error'] ?? 'Anbar məlumatı yüklənmədi.'}'
            : 'Anbar məlumatı yüklənmədi.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _post(String endpoint, int id, String success) async {
    try {
      await ApiClient().dio.post(endpoint, data: {'document_id': id});
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(success)));
      await _load();
    } on DioException catch (e) {
      if (!mounted) return;
      final message =
          e.response?.data is Map ? e.response?.data['error'] : null;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('${message ?? 'Əməliyyat tamamlanmadı.'}')));
    }
  }

  Future<void> _returnStock() async {
    final balances = _list('balances');
    if (balances.isEmpty) return;
    int itemId = int.parse('${balances.first['id']}');
    final quantity = TextEditingController();
    final note = TextEditingController();
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _InventoryFormShell(
          title: 'Qalığı anbara qaytar',
          child: StatefulBuilder(
              builder: (context, setSheetState) =>
                  Column(mainAxisSize: MainAxisSize.min, children: [
                    DropdownButtonFormField<int>(
                        initialValue: itemId,
                        isExpanded: true,
                        decoration:
                            const InputDecoration(labelText: 'Adınızdakı mal'),
                        items: balances
                            .map((row) => DropdownMenuItem(
                                value: int.parse('${row['id']}'),
                                child: Text(
                                    '${row['item_name']} • ${row['available_quantity']} ${row['unit']}',
                                    overflow: TextOverflow.ellipsis)))
                            .toList(),
                        onChanged: (value) =>
                            setSheetState(() => itemId = value ?? itemId)),
                    const SizedBox(height: 16),
                    TextField(
                        controller: quantity,
                        keyboardType: const TextInputType.numberWithOptions(
                            decimal: true),
                        decoration: const InputDecoration(
                            labelText: 'Qaytarılan miqdar')),
                    const SizedBox(height: 16),
                    TextField(
                        controller: note,
                        decoration: const InputDecoration(labelText: 'Qeyd')),
                    const SizedBox(height: 24),
                    FilledButton(
                        onPressed: () async {
                          final amount = double.tryParse(
                              quantity.text.replaceAll(',', '.'));
                          if (amount == null || amount <= 0) return;
                          try {
                            await ApiClient()
                                .dio
                                .post('/inventory/request-return.php', data: {
                              'item_id': itemId,
                              'quantity': amount,
                              'note': note.text.trim()
                            });
                            if (context.mounted) Navigator.pop(context, true);
                          } on DioException catch (e) {
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                                  content: Text(
                                      '${e.response?.data?['error'] ?? 'Sorğu yaradılmadı.'}')));
                            }
                          }
                        },
                        child: const Text('Anbara qaytarma sorğusu yarat')),
                  ]))),
    );
    quantity.dispose();
    note.dispose();
    if (saved == true) await _load();
  }

  Future<void> _createHandover() async {
    final products = _list('products');
    final employees = _list('employees');
    if (products.isEmpty || employees.isEmpty) return;
    int itemId = int.parse('${products.first['id']}');
    int employeeId = int.parse('${employees.first['id']}');
    final quantity = TextEditingController();
    final serial = TextEditingController();
    final mac = TextEditingController();
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _InventoryFormShell(
          title: 'Əməkdaşa mal təhvil ver',
          child: StatefulBuilder(
              builder: (context, setSheetState) =>
                  Column(mainAxisSize: MainAxisSize.min, children: [
                    DropdownButtonFormField<int>(
                        initialValue: itemId,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Mal'),
                        items: products
                            .map((row) => DropdownMenuItem(
                                value: int.parse('${row['id']}'),
                                child: Text(
                                    '${row['item_name']} • ${row['quantity_on_hand']} ${row['unit']}',
                                    overflow: TextOverflow.ellipsis)))
                            .toList(),
                        onChanged: (value) =>
                            setSheetState(() => itemId = value ?? itemId)),
                    const SizedBox(height: 16),
                    DropdownButtonFormField<int>(
                        initialValue: employeeId,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Əməkdaş'),
                        items: employees
                            .map((row) => DropdownMenuItem(
                                value: int.parse('${row['id']}'),
                                child: Text(
                                    '${row['full_name']} • ${row['area']}',
                                    overflow: TextOverflow.ellipsis)))
                            .toList(),
                        onChanged: (value) => setSheetState(
                            () => employeeId = value ?? employeeId)),
                    const SizedBox(height: 16),
                    TextField(
                        controller: quantity,
                        keyboardType: const TextInputType.numberWithOptions(
                            decimal: true),
                        decoration: const InputDecoration(labelText: 'Miqdar')),
                    const SizedBox(height: 16),
                    TextField(
                        controller: serial,
                        decoration: const InputDecoration(
                            labelText: 'SN (seriyalı cihazdırsa)')),
                    const SizedBox(height: 16),
                    TextField(
                        controller: mac,
                        decoration: const InputDecoration(
                            labelText: 'MAC (cihazdırsa)')),
                    const SizedBox(height: 24),
                    FilledButton(
                        onPressed: () async {
                          final amount = double.tryParse(
                              quantity.text.replaceAll(',', '.'));
                          if (amount == null || amount <= 0) return;
                          try {
                            await ApiClient()
                                .dio
                                .post('/inventory/handover.php', data: {
                              'item_id': itemId,
                              'employee_id': employeeId,
                              'quantity': amount,
                              'source_location_type': 'warehouse',
                              'source_location_id': 'MAIN',
                              'serial_number': serial.text.trim(),
                              'mac_address': mac.text.trim()
                            });
                            if (context.mounted) Navigator.pop(context, true);
                          } on DioException catch (e) {
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                                  content: Text(
                                      '${e.response?.data?['error'] ?? 'Təhvil yaradılmadı.'}')));
                            }
                          }
                        },
                        child:
                            const Text('Təhvili əməkdaşın təsdiqinə göndər')),
                  ]))),
    );
    quantity.dispose();
    serial.dispose();
    mac.dispose();
    if (saved == true) await _load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Anbar və qalıqlar'), actions: [
          IconButton(
              onPressed: _load,
              tooltip: 'Yenilə',
              icon: const Icon(Icons.refresh))
        ]),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? Center(
                    child: Padding(
                        padding: const EdgeInsets.all(24),
                        child:
                            Column(mainAxisSize: MainAxisSize.min, children: [
                          Text(_error!),
                          const SizedBox(height: 16),
                          FilledButton(
                              onPressed: _load,
                              child: const Text('Yenidən yoxla'))
                        ])))
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView(
                        padding: EdgeInsets.fromLTRB(18, 12, 18,
                            28 + MediaQuery.paddingOf(context).bottom),
                        children: [
                          Container(
                              padding: const EdgeInsets.all(20),
                              decoration: BoxDecoration(
                                  gradient: const LinearGradient(colors: [
                                    Color(0xFF6B2B16),
                                    Color(0xFF17233B)
                                  ]),
                                  borderRadius: BorderRadius.circular(24),
                                  border: Border.all(color: AppTheme.border)),
                              child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                        _canManage
                                            ? 'ANBAR NƏZARƏTİ'
                                            : 'MƏNİM ANBARIM',
                                        style: const TextStyle(
                                            color: AppTheme.accent,
                                            fontWeight: FontWeight.w800)),
                                    const SizedBox(height: 8),
                                    Text(
                                        _canManage
                                            ? 'Malı əməkdaşa ver, təsdiqləri və qaytarmaları izləyin.'
                                            : 'Adınızdakı qalıqları, təhvilləri və qaytarmaları idarə edin.',
                                        style: const TextStyle(
                                            fontSize: 18,
                                            fontWeight: FontWeight.w800)),
                                  ])),
                          const SizedBox(height: 16),
                          if (_canManage)
                            FilledButton.icon(
                                onPressed: _createHandover,
                                icon: const Icon(Icons.assignment_ind_outlined),
                                label: const Text('Əməkdaşa mal təhvil ver')),
                          if (!_canManage && _list('balances').isNotEmpty)
                            FilledButton.icon(
                                onPressed: _returnStock,
                                icon: const Icon(Icons.keyboard_return),
                                label: const Text('Qalığı anbara qaytar')),
                          const SizedBox(height: 24),
                          _title('Adımdakı qalıqlar', _list('balances').length),
                          if (_list('balances').isEmpty)
                            const _EmptyCard(
                                'Adınıza aktiv mal qalığı yoxdur.'),
                          ..._list('balances').map((row) => Card(
                              margin: const EdgeInsets.only(bottom: 10),
                              child: ListTile(
                                  leading: const Icon(
                                      Icons.inventory_2_outlined,
                                      color: AppTheme.primary),
                                  title: Text('${row['item_name']}'),
                                  subtitle: Text('${row['sku']}'),
                                  trailing: Text(
                                      '${row['available_quantity']} ${row['unit']}',
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w800))))),
                          const SizedBox(height: 20),
                          _title('Gözləyən təhvillər',
                              _list('pending_handovers').length),
                          if (_list('pending_handovers').isEmpty)
                            const _EmptyCard('Gözləyən təhvil yoxdur.'),
                          ..._list('pending_handovers').map((row) =>
                              _DocumentCard(
                                  row: row,
                                  actionLabel: _canManage ? null : 'Qəbul et',
                                  onAction: () => _post(
                                      '/inventory/confirm-handover.php',
                                      int.parse('${row['id']}'),
                                      'Təhvil qəbul edildi.'))),
                          const SizedBox(height: 20),
                          _title('Qaytarma sorğuları',
                              _list('pending_returns').length),
                          if (_list('pending_returns').isEmpty)
                            const _EmptyCard('Gözləyən qaytarma yoxdur.'),
                          ..._list('pending_returns').map((row) =>
                              _DocumentCard(
                                  row: row,
                                  actionLabel:
                                      _canManage ? 'Anbara qəbul et' : null,
                                  onAction: () => _post(
                                      '/inventory/confirm-return.php',
                                      int.parse('${row['id']}'),
                                      'Qalıq anbara qəbul edildi.'))),
                        ])),
      );

  Widget _title(String text, int count) => Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(children: [
        Expanded(
            child: Text(text,
                style: const TextStyle(
                    fontSize: 18, fontWeight: FontWeight.w800))),
        Text('$count',
            style: const TextStyle(
                color: AppTheme.accent, fontWeight: FontWeight.w800))
      ]));
}

class _DocumentCard extends StatelessWidget {
  final Map<String, dynamic> row;
  final String? actionLabel;
  final VoidCallback onAction;
  const _DocumentCard(
      {required this.row, required this.actionLabel, required this.onAction});
  @override
  Widget build(BuildContext context) => Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
          padding: const EdgeInsets.all(16),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('${row['document_no']}',
                style: const TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text('${row['employee_name'] ?? row['created_by_name'] ?? ''}'),
            Text('${row['item_summary'] ?? ''}',
                style: const TextStyle(color: AppTheme.mutedText)),
            Text('${row['created_at'] ?? ''}',
                style: const TextStyle(color: AppTheme.mutedText)),
            if (actionLabel != null) ...[
              const SizedBox(height: 12),
              FilledButton(onPressed: onAction, child: Text(actionLabel!))
            ],
          ])));
}

class _InventoryFormShell extends StatelessWidget {
  final String title;
  final Widget child;
  const _InventoryFormShell({required this.title, required this.child});
  @override
  Widget build(BuildContext context) => SafeArea(
          child: Padding(
        padding: EdgeInsets.fromLTRB(
            20, 20, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
        child: SingleChildScrollView(
            keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
            child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(title,
                      style: const TextStyle(
                          fontSize: 22, fontWeight: FontWeight.w900)),
                  const SizedBox(height: 20),
                  child
                ])),
      ));
}

class _EmptyCard extends StatelessWidget {
  final String text;
  const _EmptyCard(this.text);
  @override
  Widget build(BuildContext context) => Card(
      child: Padding(
          padding: const EdgeInsets.all(20),
          child: Text(text,
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppTheme.mutedText))));
}
