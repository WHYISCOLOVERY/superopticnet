import 'dart:async';
import 'dart:ui';
import 'package:battery_plus/battery_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:geolocator/geolocator.dart';
import 'package:uuid/uuid.dart';
import '../../core/constants/api_endpoints.dart';
import '../../core/security/secure_storage_service.dart';
import 'outbox_service.dart';

@pragma('vm:entry-point')
class BackgroundLocationService {
  static Future<void> initializeService() async {
    final service = FlutterBackgroundService();

    await service.configure(
      androidConfiguration: AndroidConfiguration(
        onStart: onStart,
        autoStart: true,
        autoStartOnBoot: true,
        isForegroundMode: true,
        initialNotificationTitle: 'SuperOptic Əməliyyat Növbəsi',
        initialNotificationContent: 'GPS xidməti aktivdir (Növbədəyəm)',
        foregroundServiceNotificationId: 888,
        foregroundServiceTypes: const [AndroidForegroundType.location],
      ),
      iosConfiguration: IosConfiguration(
        autoStart: false,
        onForeground: onStart,
        onBackground: onIosBackground,
      ),
    );
  }

  @pragma('vm:entry-point')
  static Future<bool> onIosBackground(ServiceInstance service) async {
    return true;
  }

  @pragma('vm:entry-point')
  static void onStart(ServiceInstance service) async {
    DartPluginRegistrant.ensureInitialized();
    if (!await SecureStorageService.isDutyTrackingEnabled()) {
      service.stopSelf();
      return;
    }
    final Battery battery = Battery();

    Timer.periodic(const Duration(seconds: 45), (timer) async {
      Map<String, dynamic>? pendingPayload;
      String? pendingUuid;
      int? accountId;
      Dio? statusDio;
      try {
        if (!await SecureStorageService.isDutyTrackingEnabled()) {
          timer.cancel();
          service.stopSelf();
          return;
        }
        final token = await SecureStorageService.getAccessToken();
        final deviceUuid = await SecureStorageService.getOrCreateDeviceUuid();
        accountId = await SecureStorageService.getUserId();

        if (token == null || token.isEmpty || accountId == null) {
          service.stopSelf();
          return;
        }

        statusDio = Dio(BaseOptions(
          baseUrl: ApiEndpoints.baseUrl,
          headers: {
            'Authorization': 'Bearer $token',
            'X-Device-UUID': deviceUuid,
          },
          connectTimeout: const Duration(seconds: 10),
        ));
        final serviceEnabled = await Geolocator.isLocationServiceEnabled();
        final permission = await Geolocator.checkPermission();
        if (!serviceEnabled ||
            permission == LocationPermission.denied ||
            permission == LocationPermission.deniedForever) {
          if (service is AndroidServiceInstance) {
            await service.setForegroundNotificationInfo(
              title: 'GPS söndürülüb',
              content:
                  'İş növbəsində məkan xidməti məcburidir. GPS-i aktiv edin.',
            );
          }
          await statusDio.post(ApiEndpoints.locationStatus, data: {
            'service_enabled': serviceEnabled,
            'permission_status': permission.name,
          });
          return;
        }

        final Position pos = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.high,
          timeLimit: const Duration(seconds: 10),
        );

        final int batteryLevel = await battery.batteryLevel;
        final BatteryState batteryState = await battery.batteryState;
        final bool isCharging = batteryState == BatteryState.charging;

        pendingUuid = const Uuid().v4();
        final Map<String, dynamic> payload = {
          'client_uuid': pendingUuid,
          'latitude': pos.latitude,
          'longitude': pos.longitude,
          'accuracy': pos.accuracy,
          'speed': pos.speed,
          'heading': pos.heading,
          'altitude': pos.altitude,
          'battery_level': batteryLevel,
          'is_charging': isCharging ? 1 : 0,
          'is_mock': pos.isMocked ? 1 : 0,
          'recorded_at': DateTime.now().toIso8601String(),
        };
        pendingPayload = payload;

        // Send to backend location ping endpoint
        await statusDio.post(ApiEndpoints.pingLocation, data: payload);
        await OutboxService.flushPending();
        if (service is AndroidServiceInstance) {
          await service.setForegroundNotificationInfo(
            title: 'SuperOptic Əməliyyat Növbəsi',
            content: 'GPS aktivdir · məkan təhlükəsiz ötürülür',
          );
        }
      } catch (error) {
        final status =
            error is DioException ? (error.response?.statusCode ?? 0) : 0;
        final permanentClientError =
            status >= 400 && status < 500 && status != 408 && status != 429;
        if (status == 409 && service is AndroidServiceInstance) {
          // A temporary server-side duty-session mismatch must not silently
          // disable tracking. Keep the foreground service alive and retry.
          await service.setForegroundNotificationInfo(
            title: 'Növbə yoxlanılır',
            content: 'GPS aktivdir; növbə sessiyası ilə yenidən əlaqə qurulur.',
          );
        }
        if (statusDio != null) {
          try {
            final serviceEnabled = await Geolocator.isLocationServiceEnabled();
            final permission = await Geolocator.checkPermission();
            await statusDio.post(ApiEndpoints.locationStatus, data: {
              'service_enabled': serviceEnabled,
              'permission_status': permission.name,
            });
          } catch (_) {}
        }
        if (!permanentClientError &&
            pendingPayload != null &&
            pendingUuid != null &&
            accountId != null) {
          await OutboxService.enqueue(
            clientUuid: pendingUuid,
            accountId: accountId,
            endpoint: ApiEndpoints.pingLocation,
            payload: pendingPayload,
          );
        }
      }
    });

    service.on('stop_duty').listen((event) {
      service.stopSelf();
    });
  }

  static Future<void> startDutyTracking() async {
    await SecureStorageService.setDutyTrackingEnabled(true);
    final service = FlutterBackgroundService();
    await service.startService();
  }

  static Future<void> stopDutyTracking() async {
    await SecureStorageService.setDutyTrackingEnabled(false);
    final service = FlutterBackgroundService();
    service.invoke('stop_duty');
  }
}
