import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:uuid/uuid.dart';
import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../../core/theme/app_theme.dart';
import '../../data/services/outbox_service.dart';
import 'task_resolve_screen.dart';

class TaskListScreen extends StatefulWidget {
  const TaskListScreen({super.key});

  @override
  State<TaskListScreen> createState() => _TaskListScreenState();
}

class _TaskListScreenState extends State<TaskListScreen> {
  String _selectedFilter = '';
  String _problemFilter = '';
  String _datePeriod = '';
  bool _isLoading = true;
  bool _isStarting = false;
  List<dynamic> _tasks = [];

  @override
  void initState() {
    super.initState();
    _fetchTasks();
  }

  Future<void> _fetchTasks() async {
    setState(() => _isLoading = true);
    try {
      final res = await ApiClient().dio.get(
        ApiEndpoints.technicianTasks,
        queryParameters: {
          if (_selectedFilter.isNotEmpty) 'status': _selectedFilter,
          if (_problemFilter.isNotEmpty) 'problem_type': _problemFilter,
          if (_datePeriod.isNotEmpty) 'date_period': _datePeriod,
        },
      );
      if (res.statusCode == 200 && res.data['success'] == true) {
        setState(() {
          _tasks = res.data['data']['tasks'] as List<dynamic>;
        });
      }
    } catch (_) {
      // offline fallback
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _callCustomer(String phone) async {
    final uri = Uri.parse('tel:$phone');
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri);
    }
  }

  void _openMap(String address) async {
    final encoded = Uri.encodeComponent(address);
    final uri =
        Uri.parse('https://www.google.com/maps/search/?api=1&query=$encoded');
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  Future<void> _advanceTask(Map<String, dynamic> task, String eventType,
      String successMessage) async {
    if (_isStarting) return;
    setState(() => _isStarting = true);
    final clientUuid = const Uuid().v4();
    final payload = {
      'client_uuid': clientUuid,
      'ticket_id': task['id'],
      'event_type': eventType,
    };
    try {
      final accountId = await SecureStorageService.getUserId();
      if (accountId == null) throw StateError('Aktiv sessiya tapılmadı.');
      await OutboxService.enqueue(
        clientUuid: clientUuid,
        accountId: accountId,
        endpoint: ApiEndpoints.taskProgress,
        payload: payload,
      );
      final res = await ApiClient().dio.post(
            ApiEndpoints.taskProgress,
            data: payload,
          );
      if (res.statusCode == 200 && res.data['success'] == true) {
        await OutboxService.markCompleted(clientUuid);
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(successMessage)),
        );
        await _fetchTasks();
      }
    } on DioException catch (error) {
      final status = error.response?.statusCode ?? 0;
      if (status >= 400 && status < 500 && status != 408 && status != 429) {
        await OutboxService.markCompleted(clientUuid);
      } else {
        await OutboxService.recordFailure(clientUuid, error);
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(status >= 400 && status < 500
              ? '${error.response?.data?['error'] ?? 'İş başlatma qəbul edilmədi.'}'
              : 'İnternet yoxdur. “İcraya başladım” telefonda saxlanıldı və bağlantıda göndəriləcək.'),
        ),
      );
    } catch (error) {
      await OutboxService.recordFailure(clientUuid, error);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
              'Əməliyyat telefonda saxlanıldı və bağlantıda göndəriləcək.'),
        ),
      );
    } finally {
      if (mounted) setState(() => _isStarting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Tapşırıqlar və Qəzalar'),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _fetchTasks),
        ],
      ),
      body: Column(
        children: [
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            child: Row(
              children: [
                _buildFilterChip('Aktiv İşlər', ''),
                const SizedBox(width: 8),
                _buildFilterChip('İcrada Olan', 'in_progress'),
                const SizedBox(width: 8),
                _buildFilterChip('Gözləyən', 'assigned'),
                const SizedBox(width: 8),
                _buildFilterChip('Tamamlanan', 'resolved'),
              ],
            ),
          ),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              children: [
                _buildProblemChip('Hamısı', ''),
                const SizedBox(width: 8),
                _buildProblemChip('Qoşuntu', 'installation'),
                const SizedBox(width: 8),
                _buildProblemChip('Siqnal', 'signal'),
                const SizedBox(width: 8),
                _buildProblemChip('Modem', 'modem'),
                const SizedBox(width: 8),
                _buildProblemChip('Kabel', 'cable'),
                const SizedBox(width: 8),
                _buildProblemChip('PON/OLT', 'pon'),
              ],
            ),
          ),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 8),
            child: Row(
              children: [
                _buildDateChip('Bütün tarixlər', ''),
                const SizedBox(width: 8),
                _buildDateChip('Bu gün', 'today'),
                const SizedBox(width: 8),
                _buildDateChip('7 gün', 'week'),
                const SizedBox(width: 8),
                _buildDateChip('30 gün', 'month'),
              ],
            ),
          ),

          // Task List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _tasks.isEmpty
                    ? const Center(
                        child: Text('Göstəriləcək tapşırıq tapılmadı.',
                            style: TextStyle(color: Colors.grey)),
                      )
                    : RefreshIndicator(
                        onRefresh: _fetchTasks,
                        child: ListView.builder(
                          padding: const EdgeInsets.all(16),
                          itemCount: _tasks.length,
                          itemBuilder: (context, index) {
                            final task = _tasks[index] as Map<String, dynamic>;
                            final customer =
                                task['customer'] as Map<String, dynamic>? ?? {};
                            final status = task['status'] as String? ?? 'open';
                            final isResolved =
                                status == 'resolved' || status == 'closed';
                            final isInProgress =
                                status == 'in_progress' || status == 'Icrada';
                            final progressEvent =
                                '${task['progress_event'] ?? ''}';
                            final nextProgress = _nextProgress(progressEvent);
                            final priority = task['priority'] is Map
                                ? Map<String, dynamic>.from(task['priority'])
                                : <String, dynamic>{};

                            return Card(
                              margin: const EdgeInsets.only(bottom: 16),
                              child: Padding(
                                padding: const EdgeInsets.all(16),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    // Header
                                    Row(
                                      mainAxisAlignment:
                                          MainAxisAlignment.spaceBetween,
                                      children: [
                                        Text(
                                          task['ticket_no'] ?? '#Bilet',
                                          style: const TextStyle(
                                              fontWeight: FontWeight.bold,
                                              color: Color(0xFF00BCD4)),
                                        ),
                                        _buildStatusBadge(status),
                                      ],
                                    ),
                                    if (priority.isNotEmpty) ...[
                                      const SizedBox(height: 10),
                                      Container(
                                        padding: const EdgeInsets.all(12),
                                        decoration: BoxDecoration(
                                          color: _priorityColor(
                                                  '${priority['label']}')
                                              .withValues(alpha: 0.12),
                                          borderRadius:
                                              BorderRadius.circular(14),
                                          border: Border.all(
                                              color: _priorityColor(
                                                  '${priority['label']}')),
                                        ),
                                        child: Row(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              '#${priority['queue_position'] ?? index + 1}',
                                              style: TextStyle(
                                                color: _priorityColor(
                                                    '${priority['label']}'),
                                                fontWeight: FontWeight.w900,
                                              ),
                                            ),
                                            const SizedBox(width: 10),
                                            Expanded(
                                              child: Text(
                                                (priority['reasons'] as List?)
                                                        ?.join(' · ') ??
                                                    'Prioritet növbəsi',
                                                style: const TextStyle(
                                                    fontSize: 12,
                                                    fontWeight:
                                                        FontWeight.w700),
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ],
                                    const SizedBox(height: 8),
                                    Text(
                                      customer['name'] ?? 'Naməlum Müştəri',
                                      style: const TextStyle(
                                          fontSize: 16,
                                          fontWeight: FontWeight.bold),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      'Mövzu: ${task['subject'] ?? ''}',
                                      style: const TextStyle(
                                          color: Colors.white70, fontSize: 13),
                                    ),
                                    if (task['message'] != null &&
                                        (task['message'] as String)
                                            .isNotEmpty) ...[
                                      const SizedBox(height: 4),
                                      Text(
                                        'Qeyd: ${task['message']}',
                                        style: const TextStyle(
                                            color: Colors.grey, fontSize: 12),
                                      ),
                                    ],
                                    const Divider(height: 20),

                                    // Address & Contact
                                    Row(
                                      children: [
                                        const Icon(Icons.location_on_outlined,
                                            size: 16, color: Colors.grey),
                                        const SizedBox(width: 6),
                                        Expanded(
                                          child: Text(
                                            customer['address'] ??
                                                'Ünvan qeyd olunmayıb',
                                            style:
                                                const TextStyle(fontSize: 12),
                                            maxLines: 2,
                                            overflow: TextOverflow.ellipsis,
                                          ),
                                        ),
                                        IconButton(
                                          icon: const Icon(Icons.map_outlined,
                                              color: Color(0xFF00BCD4)),
                                          onPressed: () => _openMap(
                                              customer['address'] ?? ''),
                                          tooltip: 'Xəritədə Aç',
                                        ),
                                        IconButton(
                                          icon: const Icon(Icons.phone_outlined,
                                              color: Color(0xFF00E676)),
                                          onPressed: () => _callCustomer(
                                              customer['phone'] ?? ''),
                                          tooltip: 'Zəng Et',
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 12),

                                    // Action
                                    if (!isResolved)
                                      Row(
                                        children: [
                                          if (!isInProgress &&
                                              nextProgress != null) ...[
                                            Expanded(
                                              child: OutlinedButton.icon(
                                                onPressed: _isStarting
                                                    ? null
                                                    : () => _advanceTask(
                                                        task,
                                                        nextProgress.$1,
                                                        nextProgress.$3),
                                                icon: Icon(nextProgress.$4),
                                                label: Text(nextProgress.$2),
                                              ),
                                            ),
                                            const SizedBox(width: 10),
                                          ],
                                          if (isInProgress &&
                                              progressEvent == 'started')
                                            Expanded(
                                              child: FilledButton.icon(
                                                onPressed: () async {
                                                  final updated =
                                                      await Navigator.push(
                                                    context,
                                                    MaterialPageRoute(
                                                      builder: (_) =>
                                                          TaskResolveScreen(
                                                              task: task),
                                                    ),
                                                  );
                                                  if (updated == true) {
                                                    _fetchTasks();
                                                  }
                                                },
                                                icon: const Icon(Icons
                                                    .assignment_turned_in_outlined),
                                                label: const Text('Təhvil ver'),
                                              ),
                                            ),
                                        ],
                                      )
                                    else
                                      Container(
                                        padding: const EdgeInsets.symmetric(
                                            vertical: 8),
                                        alignment: Alignment.center,
                                        decoration: BoxDecoration(
                                          color: const Color(0xFF00E676)
                                              .withValues(alpha: 0.1),
                                          borderRadius:
                                              BorderRadius.circular(8),
                                        ),
                                        child: const Text(
                                          'İş tamamlanıb və təhvil verilib',
                                          style: TextStyle(
                                              color: Color(0xFF00E676),
                                              fontSize: 13,
                                              fontWeight: FontWeight.bold),
                                        ),
                                      ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
                      ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterChip(String label, String value) {
    final isSelected = _selectedFilter == value;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      onSelected: (_) {
        setState(() => _selectedFilter = value);
        _fetchTasks();
      },
    );
  }

  (String, String, String, IconData)? _nextProgress(String current) =>
      switch (current) {
        '' => (
            'started',
            'İcraya başladım',
            'Faktiki iş vaxtı başladı.',
            Icons.play_arrow_rounded
          ),
        _ => null,
      };

  Color _priorityColor(String priority) => switch (priority) {
        'kritik' => AppTheme.danger,
        'yüksək' => AppTheme.warning,
        'orta' => AppTheme.primary,
        _ => AppTheme.mutedText,
      };

  Widget _buildProblemChip(String label, String value) {
    final isSelected = _problemFilter == value;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      selectedColor: AppTheme.primary.withValues(alpha: 0.18),
      side: BorderSide(color: isSelected ? AppTheme.primary : AppTheme.border),
      onSelected: (_) {
        setState(() => _problemFilter = value);
        _fetchTasks();
      },
    );
  }

  Widget _buildDateChip(String label, String value) {
    final isSelected = _datePeriod == value;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      selectedColor: AppTheme.warning.withValues(alpha: 0.18),
      side: BorderSide(color: isSelected ? AppTheme.warning : AppTheme.border),
      onSelected: (_) {
        setState(() => _datePeriod = value);
        _fetchTasks();
      },
    );
  }

  Widget _buildStatusBadge(String status) {
    Color color = Colors.grey;
    String label = status;

    switch (status) {
      case 'in_progress':
        color = AppTheme.primary;
        label = 'İCRADA';
        break;
      case 'assigned':
        color = Colors.amber;
        label = 'TƏYİN EDİLİB';
        break;
      case 'resolved':
      case 'closed':
        color = AppTheme.success;
        label = 'TAMAMLANDI';
        break;
      case 'open':
        color = Colors.redAccent;
        label = 'AÇIQ';
        break;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.2),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color, width: 0.8),
      ),
      child: Text(label,
          style: TextStyle(
              color: color, fontSize: 10, fontWeight: FontWeight.bold)),
    );
  }
}
