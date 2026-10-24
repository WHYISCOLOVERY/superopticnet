import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import 'outbox_service.dart';
import 'offline_sync_service.dart';

class AppPresenceObserver extends StatefulWidget {
  final Widget child;

  const AppPresenceObserver({super.key, required this.child});

  @override
  State<AppPresenceObserver> createState() => _AppPresenceObserverState();
}

class _AppPresenceObserverState extends State<AppPresenceObserver>
    with WidgetsBindingObserver {
  Timer? _foregroundTimer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _record('app_open');
    _startTimer();
  }

  void _startTimer() {
    _foregroundTimer?.cancel();
    _foregroundTimer = Timer.periodic(
      const Duration(minutes: 5),
      (_) => _record('heartbeat'),
    );
  }

  Future<void> _record(String type) async {
    final token = await SecureStorageService.getAccessToken();
    if (token == null || token.isEmpty) return;
    try {
      await OutboxService.flushPending();
      await OfflineSyncService.syncNow();
      await ApiClient().dio.post(ApiEndpoints.presenceHeartbeat, data: {
        'activity_type': type,
        'app_version': '1.0.0',
      });
    } catch (_) {}
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _record('foreground');
      _startTimer();
    } else if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.detached ||
        state == AppLifecycleState.hidden) {
      _foregroundTimer?.cancel();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _foregroundTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
