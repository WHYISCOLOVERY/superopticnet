import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../../core/theme/app_theme.dart';
import '../../data/services/outbox_service.dart';

class OperatorTicketsScreen extends StatefulWidget {
  const OperatorTicketsScreen({super.key});

  @override
  State<OperatorTicketsScreen> createState() => _OperatorTicketsScreenState();
}

class _OperatorTicketsScreenState extends State<OperatorTicketsScreen> {
  final _search = TextEditingController();
  bool _loading = true;
  String _status = 'active';
  List<dynamic> _tickets = [];
  List<dynamic> _employees = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final response = await ApiClient().dio.get(
        ApiEndpoints.staffTickets,
        queryParameters: {'status': _status, 'query': _search.text.trim()},
      );
      final data = response.data['data'] as Map<String, dynamic>;
      if (mounted) {
        setState(() {
          _tickets = data['tickets'] as List<dynamic>? ?? [];
          _employees = data['employees'] as List<dynamic>? ?? [];
        });
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _assign(Map<String, dynamic> ticket) async {
    int? selected;
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: Text('${ticket['ticket_no']} yönləndir'),
          content: DropdownButtonFormField<int>(
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'İcraçı'),
            items: _employees.map((raw) {
              final employee = raw as Map<String, dynamic>;
              return DropdownMenuItem<int>(
                value: employee['id'] as int,
                child: Text('${employee['full_name']} · ${employee['area']}',
                    overflow: TextOverflow.ellipsis),
              );
            }).toList(),
            onChanged: (value) => update(() => selected = value),
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Ləğv et')),
            FilledButton(
                onPressed: selected == null
                    ? null
                    : () => Navigator.pop(context, true),
                child: const Text('Yönləndir')),
          ],
        ),
      ),
    );
    if (ok != true || selected == null) return;
    final payload = <String, dynamic>{
      'client_uuid': const Uuid().v4(),
      'ticket_id': ticket['id'],
      'technician_account_id': selected,
      'priority': '${ticket['metadata']?['priority'] ?? 'normal'}',
    };
    final queued = await _postOrQueue(
      ApiEndpoints.managerAssignTask,
      payload,
      'Bilet əməkdaşa yönləndirildi.',
      'İnternet yoxdur. Yönləndirmə telefonda saxlanıldı.',
    );
    if (!queued) await _load();
  }

  Future<void> _create() async {
    final subject = TextEditingController();
    final description = TextEditingController();
    final area = TextEditingController();
    final customer = TextEditingController();
    int? selected;
    final key = GlobalKey<FormState>();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => Padding(
          padding: EdgeInsets.fromLTRB(
              18, 18, 18, MediaQuery.viewInsetsOf(context).bottom + 18),
          child: SingleChildScrollView(
            child: Form(
              key: key,
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                const Text('Yeni müraciət / bilet',
                    style:
                        TextStyle(fontSize: 21, fontWeight: FontWeight.w900)),
                const SizedBox(height: 16),
                TextFormField(
                  controller: customer,
                  decoration: const InputDecoration(
                      labelText: 'Müştəri adı və ya kodu'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: subject,
                  validator: (value) => (value ?? '').trim().isEmpty
                      ? 'Başlıq tələb olunur'
                      : null,
                  decoration: const InputDecoration(labelText: 'Problem tipi'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: description,
                  minLines: 3,
                  maxLines: 5,
                  validator: (value) =>
                      (value ?? '').trim().isEmpty ? 'İzah tələb olunur' : null,
                  decoration: const InputDecoration(labelText: 'Müraciət'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: area,
                  validator: (value) => (value ?? '').trim().isEmpty
                      ? 'Ərazi tələb olunur'
                      : null,
                  decoration: const InputDecoration(labelText: 'Ərazi'),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<int>(
                  isExpanded: true,
                  decoration:
                      const InputDecoration(labelText: 'İcraçı · istəyə bağlı'),
                  items: _employees.map((raw) {
                    final employee = raw as Map<String, dynamic>;
                    return DropdownMenuItem<int>(
                      value: employee['id'] as int,
                      child: Text(
                          '${employee['full_name']} · ${employee['area']}',
                          overflow: TextOverflow.ellipsis),
                    );
                  }).toList(),
                  onChanged: (value) => update(() => selected = value),
                ),
                const SizedBox(height: 18),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: () {
                      if (key.currentState?.validate() == true) {
                        Navigator.pop(context, true);
                      }
                    },
                    icon: const Icon(Icons.add_task),
                    label: const Text('Bileti yarat'),
                  ),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
    if (ok != true) return;
    final payload = <String, dynamic>{
      'client_uuid': const Uuid().v4(),
      'customer_name': customer.text.trim().isEmpty
          ? 'Operator müraciəti'
          : customer.text.trim(),
      'subject': subject.text.trim(),
      'message': description.text.trim(),
      'area': area.text.trim(),
      'priority': 'normal',
      'technician_account_id': selected ?? 0,
    };
    final queued = await _postOrQueue(
      ApiEndpoints.managerCreateTask,
      payload,
      'Bilet yaradıldı.',
      'İnternet yoxdur. Bilet telefonda saxlanıldı və bağlantıda yaradılacaq.',
    );
    if (!queued) await _load();
  }

  Future<bool> _postOrQueue(
    String endpoint,
    Map<String, dynamic> payload,
    String successMessage,
    String queuedMessage,
  ) async {
    final accountId = await SecureStorageService.getUserId();
    if (accountId == null) throw StateError('Aktiv sessiya tapılmadı.');
    final clientUuid = '${payload['client_uuid']}';
    await OutboxService.enqueue(
      clientUuid: clientUuid,
      accountId: accountId,
      endpoint: endpoint,
      payload: payload,
    );
    try {
      await ApiClient().dio.post(endpoint, data: payload);
      await OutboxService.markCompleted(clientUuid);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(successMessage)));
      }
      return false;
    } on DioException catch (error) {
      if (error.response != null) {
        await OutboxService.markCompleted(clientUuid);
        if (mounted) {
          final data = error.response?.data;
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(data is Map
                ? '${data['error'] ?? 'Əməliyyat qəbul edilmədi.'}'
                : 'Əməliyyat qəbul edilmədi.'),
          ));
        }
        return false;
      }
      await OutboxService.recordFailure(clientUuid, error);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(queuedMessage)));
      }
      return true;
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('Biletlər və yönləndirmə'),
          actions: [
            IconButton(onPressed: _create, icon: const Icon(Icons.add))
          ],
        ),
        body: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(
                  controller: _search,
                  onSubmitted: (_) => _load(),
                  decoration: const InputDecoration(
                    hintText: 'Bilet, müştəri, telefon, problem',
                    prefixIcon: Icon(Icons.search),
                  ),
                ),
                const SizedBox(height: 8),
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(value: 'active', label: Text('Aktiv')),
                    ButtonSegment(value: 'completed', label: Text('Bitmiş')),
                  ],
                  selected: {_status},
                  onSelectionChanged: (value) {
                    setState(() => _status = value.first);
                    _load();
                  },
                ),
              ],
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView.builder(
                      padding: const EdgeInsets.fromLTRB(16, 4, 16, 28),
                      itemCount: _tickets.length,
                      itemBuilder: (context, index) {
                        final ticket = _tickets[index] as Map<String, dynamic>;
                        return Card(
                          child: ListTile(
                            leading: const Icon(
                                Icons.confirmation_number_outlined,
                                color: AppTheme.primary),
                            title: Text(
                              '${ticket['customer_name']} · ${ticket['subject']}',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style:
                                  const TextStyle(fontWeight: FontWeight.w900),
                            ),
                            subtitle: Text(
                                '${ticket['ticket_no']} · ${ticket['area']} · ${ticket['status']}\nİcraçı: ${ticket['assigned_technician_name']?.toString().isNotEmpty == true ? ticket['assigned_technician_name'] : 'təyin edilməyib'}'),
                            isThreeLine: true,
                            trailing: _status == 'active'
                                ? IconButton(
                                    onPressed: () => _assign(ticket),
                                    icon: const Icon(Icons.person_add_alt_1))
                                : null,
                          ),
                        );
                      },
                    ),
                  ),
          ),
        ]),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: _create,
          icon: const Icon(Icons.add_task),
          label: const Text('Yeni bilet'),
        ),
      );
}
