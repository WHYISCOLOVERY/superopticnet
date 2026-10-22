import 'dart:async';
import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:uuid/uuid.dart';
import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../../data/services/outbox_service.dart';
import '../auth/login_screen.dart';

class ManagerFleetMapScreen extends StatefulWidget {
  const ManagerFleetMapScreen({super.key});

  @override
  State<ManagerFleetMapScreen> createState() => _ManagerFleetMapScreenState();
}

class _ManagerFleetMapScreenState extends State<ManagerFleetMapScreen> {
  final MapController _mapCtrl = MapController();
  Timer? _refreshTimer;
  bool _isLoading = true;
  List<dynamic> _fleet = [];
  Map<String, dynamic>? _selectedTechnician;
  String? _fetchError;
  Map<String, dynamic>? _history;
  DateTime _historyDay = DateTime.now();
  bool _historyLoading = false;

  // Default centered in Baku (lat: 40.409264, lng: 49.867092)
  final LatLng _center = const LatLng(40.409264, 49.867092);

  @override
  void initState() {
    super.initState();
    _fetchFleet();
    _refreshTimer =
        Timer.periodic(const Duration(seconds: 30), (_) => _fetchFleet());
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  Future<void> _fetchFleet() async {
    try {
      final res = await ApiClient().dio.get(ApiEndpoints.managerFleetLive);
      if (res.statusCode == 200 && res.data['success'] == true) {
        final fleetList = res.data['data']['fleet'] as List<dynamic>;
        setState(() {
          _fleet = fleetList;
          _isLoading = false;
          _fetchError = null;
        });
      }
    } catch (error) {
      // Never fail silently: an empty map must be distinguishable from a
      // failed request (permission, server or network problem).
      var message = 'Xəritə məlumatı alınmadı.';
      if (error is DioException) {
        final status = error.response?.statusCode;
        final serverMessage = error.response?.data is Map
            ? (error.response?.data['error'] ?? '').toString()
            : '';
        message = serverMessage.isNotEmpty
            ? serverMessage
            : (status == null
                ? 'İnternet bağlantısı yoxdur.'
                : 'Server xətası ($status).');
      }
      if (mounted) {
        setState(() {
          _isLoading = false;
          _fetchError = message;
        });
      }
    }
  }

  Future<void> _loadHistory(Map<String, dynamic> member, DateTime day) async {
    final id = member['employee_id'];
    if (id == null) return;
    setState(() {
      _historyLoading = true;
      _historyDay = day;
    });
    final date =
        '${day.year.toString().padLeft(4, '0')}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}';
    try {
      final res = await ApiClient().dio.get(
        ApiEndpoints.managerRouteHistory,
        queryParameters: {'employee_id': id, 'date': date},
      );
      if (res.data['success'] == true && mounted) {
        setState(() => _history = Map<String, dynamic>.from(res.data['data']));
      }
    } on DioException catch (error) {
      final serverMessage = error.response?.data is Map
          ? (error.response?.data['error'] ?? '').toString()
          : '';
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(serverMessage.isNotEmpty
              ? serverMessage
              : 'Marşrut tarixçəsi alınmadı.'),
        ));
      }
    } finally {
      if (mounted) setState(() => _historyLoading = false);
    }
  }

  Future<void> _pickHistoryDay(Map<String, dynamic> member) async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _historyDay,
      firstDate: now.subtract(const Duration(days: 31)),
      lastDate: now,
    );
    if (picked != null) await _loadHistory(member, picked);
  }

  String _clock(dynamic value) {
    final text = (value ?? '').toString();
    return text.length >= 16 ? text.substring(11, 16) : text;
  }

  Future<void> _handleLogout() async {
    await SecureStorageService.clearAuth();
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  Future<void> _assignTicket(Map<String, dynamic> ticket) async {
    final technician = _selectedTechnician;
    if (technician == null) return;
    final clientUuid = const Uuid().v4();
    final payload = <String, dynamic>{
      'client_uuid': clientUuid,
      'ticket_id': ticket['ticket_id'],
      'technician_account_id': technician['employee_id'],
      'priority': 'normal',
      'instructions': 'Canlı xəritədə yaxınlığa görə operator yönləndirməsi',
    };
    try {
      final accountId = await SecureStorageService.getUserId();
      if (accountId == null) throw StateError('Aktiv sessiya tapılmadı.');
      await OutboxService.enqueue(
        clientUuid: clientUuid,
        accountId: accountId,
        endpoint: ApiEndpoints.managerAssignTask,
        payload: payload,
      );
      final response = await ApiClient().dio.post(
            ApiEndpoints.managerAssignTask,
            data: payload,
          );
      await OutboxService.markCompleted(clientUuid);
      if (!mounted) return;
      if (response.statusCode == 200 && response.data['success'] == true) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
                '${ticket['ticket_no']} ${technician['name']} üçün yönləndirildi.'),
          ),
        );
        await _fetchFleet();
      }
    } on DioException catch (error) {
      if (error.response == null) {
        await OutboxService.recordFailure(clientUuid, error);
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'İnternet yoxdur. Yönləndirmə telefonda saxlanıldı və bağlantıda göndəriləcək.'),
        ));
        return;
      }
      await OutboxService.markCompleted(clientUuid);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Bilet yönləndirilə bilmədi.')),
      );
    } catch (error) {
      await OutboxService.recordFailure(clientUuid, error);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Yönləndirmə sinxronlaşma növbəsinə yazıldı.'),
      ));
    }
  }

  void _callTech(String phone) async {
    final uri = Uri.parse('tel:$phone');
    if (await canLaunchUrl(uri)) await launchUrl(uri);
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'available':
        return const Color(0xFF00E676); // Green
      case 'en_route':
        return const Color(0xFF00BCD4); // Cyan
      case 'busy_working':
        return Colors.amber; // Amber
      default:
        return Colors.grey;
    }
  }

  String _getStatusText(String status) {
    switch (status) {
      case 'available':
        return 'SƏRBƏST';
      case 'en_route':
        return 'YOLDADIR';
      case 'busy_working':
        return 'İŞ İCRA EDİR';
      case 'stale_signal':
        return 'SİQNAL GECİKİR';
      default:
        return 'NÖVBƏDƏ DEYİL';
    }
  }

  @override
  Widget build(BuildContext context) {
    final markers = <Marker>[];
    final routePoints = <LatLng>[];

    for (final member in _fleet) {
      final loc = member['location'] as Map<String, dynamic>? ?? {};
      final lat = (loc['latitude'] as num?)?.toDouble();
      final lng = (loc['longitude'] as num?)?.toDouble();
      final status = member['status'] as String? ?? 'available';

      if (lat != null && lng != null) {
        final color = _getStatusColor(status);
        markers.add(
          Marker(
            point: LatLng(lat, lng),
            width: 48,
            height: 48,
            child: GestureDetector(
              onTap: () => setState(() {
                _selectedTechnician = member as Map<String, dynamic>;
                _history = null;
                _historyDay = DateTime.now();
              }),
              child: Stack(
                alignment: Alignment.center,
                children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color: color.withValues(alpha: 0.25),
                      shape: BoxShape.circle,
                      border: Border.all(color: color, width: 2),
                    ),
                  ),
                  CircleAvatar(
                    radius: 14,
                    backgroundColor: color,
                    child:
                        const Icon(Icons.person, color: Colors.black, size: 18),
                  ),
                ],
              ),
            ),
          ),
        );
      }
    }
    final nearbyTickets = _selectedTechnician?['nearby_tickets'] as List? ?? [];
    for (final raw
        in _selectedTechnician?['route_today'] as List? ?? const []) {
      final point = raw as Map<String, dynamic>;
      final latitude = (point['latitude'] as num?)?.toDouble();
      final longitude = (point['longitude'] as num?)?.toDouble();
      if (latitude != null && longitude != null) {
        routePoints.add(LatLng(latitude, longitude));
      }
    }
    for (final raw in nearbyTickets) {
      final ticket = raw as Map<String, dynamic>;
      final lat = (ticket['latitude'] as num?)?.toDouble();
      final lng = (ticket['longitude'] as num?)?.toDouble();
      if (lat == null || lng == null) continue;
      markers.add(
        Marker(
          point: LatLng(lat, lng),
          width: 38,
          height: 38,
          child: Tooltip(
            message: '${ticket['ticket_no']} · ${ticket['subject']}',
            child: Container(
              decoration: BoxDecoration(
                color: const Color(0xFFFF6A1A),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.white, width: 2),
              ),
              child: const Icon(Icons.confirmation_number_outlined,
                  size: 20, color: Colors.white),
            ),
          ),
        ),
      );
    }

    // Full-day history (loaded with "Günün marşrutu"): path, check-in/out and stays.
    final historyPoints = <LatLng>[];
    for (final raw in _history?['points'] as List? ?? const []) {
      final point = raw as Map<String, dynamic>;
      final latitude = (point['latitude'] as num?)?.toDouble();
      final longitude = (point['longitude'] as num?)?.toDouble();
      if (latitude == null || longitude == null) continue;
      historyPoints.add(LatLng(latitude, longitude));
      final event = point['event'];
      if (event == 'check_in' || event == 'check_out') {
        markers.add(
          Marker(
            point: LatLng(latitude, longitude),
            width: 34,
            height: 34,
            child: Tooltip(
              message:
                  '${event == 'check_in' ? 'İşə gəldi' : 'İşi bitirdi'} · ${_clock(point['recorded_at'])}',
              child: Icon(
                event == 'check_in' ? Icons.flag : Icons.stop_circle,
                color: event == 'check_in' ? Colors.green : Colors.red,
                size: 30,
              ),
            ),
          ),
        );
      }
    }
    for (final raw in _history?['stops'] as List? ?? const []) {
      final stop = raw as Map<String, dynamic>;
      final latitude = (stop['latitude'] as num?)?.toDouble();
      final longitude = (stop['longitude'] as num?)?.toDouble();
      if (latitude == null || longitude == null) continue;
      markers.add(
        Marker(
          point: LatLng(latitude, longitude),
          width: 34,
          height: 34,
          child: Tooltip(
            message:
                '${_clock(stop['arrived_at'])}–${_clock(stop['left_at'])} · ${stop['minutes']} dəq dayandı',
            child: Container(
              decoration: BoxDecoration(
                color: const Color(0xFF7C4DFF),
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white, width: 2),
              ),
              child: const Icon(Icons.pause, size: 18, color: Colors.white),
            ),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text('Canlı Əməliyyat Xəritəsi (${_fleet.length})'),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _fetchFleet),
          IconButton(icon: const Icon(Icons.logout), onPressed: _handleLogout),
        ],
      ),
      body: Stack(
        children: [
          // OpenStreetMap Vector/Raster Tile Layer
          FlutterMap(
            mapController: _mapCtrl,
            options: MapOptions(
              initialCenter: _center,
              initialZoom: 12.0,
            ),
            children: [
              TileLayer(
                urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                userAgentPackageName: 'net.superoptic.ops',
              ),
              if (_history == null && routePoints.length > 1)
                PolylineLayer(
                  polylines: [
                    Polyline(
                      points: routePoints,
                      color: const Color(0xFFFF6A1A),
                      strokeWidth: 5,
                    ),
                  ],
                ),
              if (historyPoints.length > 1)
                PolylineLayer(
                  polylines: [
                    Polyline(
                      points: historyPoints,
                      color: const Color(0xFF2979FF),
                      strokeWidth: 4,
                    ),
                  ],
                ),
              MarkerLayer(markers: markers),
            ],
          ),

          // Visible error / empty-state so a blank map is never a mystery.
          if (!_isLoading && (_fetchError != null || _fleet.isEmpty))
            Positioned(
              top: 12,
              left: 12,
              right: 12,
              child: Card(
                color: _fetchError != null
                    ? const Color(0xFF7F1D1D)
                    : const Color(0xFF1E293B),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    _fetchError != null
                        ? 'Xəritə yüklənmədi: $_fetchError'
                        : 'Hazırda xəritədə izlənilən əməkdaş yoxdur. Əməkdaş “İşdəyəm” düyməsini basıb GPS-i açdıqdan sonra burada görünəcək.',
                    style: const TextStyle(fontSize: 12, color: Colors.white),
                  ),
                ),
              ),
            ),

          // Loading Indicator
          if (_isLoading)
            const Positioned(
              top: 16,
              left: 16,
              child: Card(
                child: Padding(
                  padding: EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                  child: Row(
                    children: [
                      SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2)),
                      SizedBox(width: 10),
                      Text('Xəritə yenilənir...',
                          style: TextStyle(fontSize: 12)),
                    ],
                  ),
                ),
              ),
            ),

          // Selected Technician Details Card
          if (_selectedTechnician != null)
            Positioned(
              bottom: 24,
              left: 16,
              right: 16,
              child: SafeArea(
                top: false,
                child: Card(
                  elevation: 8,
                  child: ConstrainedBox(
                    constraints: BoxConstraints(
                      maxHeight: MediaQuery.sizeOf(context).height * 0.55,
                    ),
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                _selectedTechnician!['name'] ?? '',
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold, fontSize: 16),
                              ),
                              IconButton(
                                icon: const Icon(Icons.close, size: 20),
                                onPressed: () => setState(() {
                                  _selectedTechnician = null;
                                  _history = null;
                                }),
                              ),
                            ],
                          ),
                          Text(
                            'Vəzifə: ${_selectedTechnician!['title']} | Ərazi: ${_selectedTechnician!['area'] ?? 'Bakı'}',
                            style: const TextStyle(
                                fontSize: 12, color: Colors.grey),
                          ),
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 8, vertical: 4),
                                decoration: BoxDecoration(
                                  color: _getStatusColor(
                                          _selectedTechnician!['status'])
                                      .withValues(alpha: 0.2),
                                  borderRadius: BorderRadius.circular(6),
                                ),
                                child: Text(
                                  _getStatusText(
                                      _selectedTechnician!['status']),
                                  style: TextStyle(
                                    color: _getStatusColor(
                                        _selectedTechnician!['status']),
                                    fontWeight: FontWeight.bold,
                                    fontSize: 11,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 12),
                              const Icon(Icons.battery_std,
                                  size: 16, color: Colors.grey),
                              Text(
                                  '${_selectedTechnician!['device']?['battery_level'] ?? 100}%',
                                  style: const TextStyle(fontSize: 12)),
                              const SizedBox(width: 12),
                              const Icon(Icons.speed,
                                  size: 16, color: Colors.grey),
                              Text(
                                  '${_selectedTechnician!['location']?['speed_kmh'] ?? 0} km/s',
                                  style: const TextStyle(fontSize: 12)),
                            ],
                          ),
                          if (_selectedTechnician!['active_task'] != null) ...[
                            const Divider(height: 16),
                            Text(
                              'Cari İş: ${_selectedTechnician!['active_task']['ticket_no']} - ${_selectedTechnician!['active_task']['subject']}',
                              style: const TextStyle(
                                  fontSize: 12,
                                  color: Color(0xFF00BCD4),
                                  fontWeight: FontWeight.bold),
                            ),
                            Text(
                              'Müştəri: ${_selectedTechnician!['active_task']['customer_name']}',
                              style: const TextStyle(
                                  fontSize: 11, color: Colors.white70),
                            ),
                          ],
                          const Divider(height: 20),
                          Text(
                            'Bugünkü marşrut: ${routePoints.length} GPS nöqtəsi',
                            style: const TextStyle(
                                color: Colors.white70, fontSize: 12),
                          ),
                          const SizedBox(height: 8),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              FilledButton.icon(
                                onPressed: _historyLoading
                                    ? null
                                    : () => _loadHistory(
                                        _selectedTechnician!, _historyDay),
                                icon: const Icon(Icons.route, size: 18),
                                label: const Text('Günün marşrutu'),
                              ),
                              OutlinedButton.icon(
                                onPressed: _historyLoading
                                    ? null
                                    : () =>
                                        _pickHistoryDay(_selectedTechnician!),
                                icon: const Icon(Icons.calendar_month,
                                    size: 18),
                                label: const Text('Başqa gün'),
                              ),
                            ],
                          ),
                          if (_historyLoading)
                            const Padding(
                              padding: EdgeInsets.only(top: 8),
                              child: LinearProgressIndicator(),
                            ),
                          if (_history != null)
                            Padding(
                              padding: const EdgeInsets.only(top: 8),
                              child: Text(
                                '${_history!['date']} · ${_history!['summary']?['saved_points'] ?? 0} nöqtə · '
                                '${_history!['summary']?['distance_km'] ?? 0} km · '
                                '${_history!['summary']?['stop_count'] ?? 0} dayanacaq · '
                                '${_clock(_history!['summary']?['first_seen_at'])}–${_clock(_history!['summary']?['last_seen_at'])}',
                                style: const TextStyle(
                                    color: Color(0xFF2979FF),
                                    fontSize: 12,
                                    fontWeight: FontWeight.bold),
                              ),
                            ),
                          const Divider(height: 20),
                          Text(
                            'Aktiv yük: ${_selectedTechnician!['active_task_count'] ?? 0} bilet · Yaxın açıq biletlər',
                            style: const TextStyle(
                                fontWeight: FontWeight.w900, fontSize: 12),
                          ),
                          if (nearbyTickets.isEmpty)
                            const Padding(
                              padding: EdgeInsets.symmetric(vertical: 10),
                              child: Text(
                                  '30 km daxilində mövqeli açıq bilet yoxdur.'),
                            )
                          else
                            ...nearbyTickets.map((raw) {
                              final ticket = raw as Map<String, dynamic>;
                              final meters = (ticket['distance_meters'] as num?)
                                      ?.toInt() ??
                                  0;
                              final distance = meters < 1000
                                  ? '$meters m'
                                  : '${(meters / 1000).toStringAsFixed(1)} km';
                              return Container(
                                margin: const EdgeInsets.only(top: 8),
                                padding: const EdgeInsets.all(10),
                                decoration: BoxDecoration(
                                  color: const Color(0xFFFF6A1A)
                                      .withValues(alpha: 0.10),
                                  borderRadius: BorderRadius.circular(14),
                                  border: Border.all(
                                    color: const Color(0xFFFF6A1A)
                                        .withValues(alpha: 0.28),
                                  ),
                                ),
                                child: Row(
                                  children: [
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            '${ticket['ticket_no']} · ${ticket['subject']}',
                                            maxLines: 2,
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(
                                                fontWeight: FontWeight.w900,
                                                fontSize: 12),
                                          ),
                                          Text(
                                            '$distance · ${ticket['area'] ?? ''}${ticket['location_source'] == 'area_center' ? ' · ərazi mərkəzinə görə' : ''}',
                                            style: const TextStyle(
                                                color: Colors.white70,
                                                fontSize: 11),
                                          ),
                                        ],
                                      ),
                                    ),
                                    const SizedBox(width: 8),
                                    FilledButton(
                                      onPressed: () => _assignTicket(ticket),
                                      child: const Text('Yönləndir'),
                                    ),
                                  ],
                                ),
                              );
                            }),
                          const SizedBox(height: 12),
                          SizedBox(
                            width: double.infinity,
                            child: ElevatedButton.icon(
                              onPressed: () => _callTech(
                                  _selectedTechnician!['phone'] ?? ''),
                              icon: const Icon(Icons.phone),
                              label: const Text('Əməkdaşla Əlaqə Saxla'),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
