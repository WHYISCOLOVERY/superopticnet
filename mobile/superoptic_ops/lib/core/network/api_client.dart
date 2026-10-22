import 'package:dio/dio.dart';
import '../constants/api_endpoints.dart';
import '../security/secure_storage_service.dart';
import '../../data/services/offline_cache_service.dart';

class ApiClient {
  static final ApiClient _instance = ApiClient._internal();
  factory ApiClient() => _instance;

  late final Dio dio;
  Future<bool>? _refreshInFlight;

  ApiClient._internal() {
    dio = Dio(
      BaseOptions(
        baseUrl: ApiEndpoints.baseUrl,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 20),
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'User-Agent': 'SuperOptic-Mobile-Ops/1.0.0',
        },
      ),
    );

    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await SecureStorageService.getAccessToken();
          final deviceUuid = await SecureStorageService.getOrCreateDeviceUuid();

          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          options.headers['X-Device-UUID'] = deviceUuid;

          return handler.next(options);
        },
        onResponse: (response, handler) async {
          if (response.requestOptions.method.toUpperCase() == 'GET' &&
              response.statusCode != null &&
              response.statusCode! >= 200 &&
              response.statusCode! < 300) {
            await OfflineCacheService.save(
              response.requestOptions.path,
              response.requestOptions.queryParameters,
              response.data,
            );
          }
          return handler.next(response);
        },
        onError: (DioException error, handler) async {
          // Check for Remote Wipe signal
          if (error.response?.statusCode == 403) {
            final data = error.response?.data;
            if (data is Map &&
                data['data'] != null &&
                data['data']['remote_wipe'] == true) {
              await SecureStorageService.clearAuth();
              // Trigger app lockdown / restart
              return handler.next(error);
            }
          }

          // Handle 401 Unauthorized by attempting token rotation
          final alreadyRetried =
              error.requestOptions.extra['auth_retry'] == true;
          if (error.response?.statusCode == 401 &&
              !error.requestOptions.path.contains('/auth/') &&
              !alreadyRetried) {
            final refreshed = await _attemptTokenRefresh();
            if (refreshed) {
              // Retry original request with new token
              final retryOptions = error.requestOptions;
              retryOptions.extra['auth_retry'] = true;
              final newToken = await SecureStorageService.getAccessToken();
              retryOptions.headers['Authorization'] = 'Bearer $newToken';

              try {
                final response = await dio.fetch(retryOptions);
                return handler.resolve(response);
              } catch (e) {
                return handler.next(error);
              }
            } else {
              await SecureStorageService.clearAuth();
            }
          }

          final status = error.response?.statusCode;
          final isConnectionFailure = status == null ||
              error.type == DioExceptionType.connectionError ||
              error.type == DioExceptionType.connectionTimeout ||
              error.type == DioExceptionType.receiveTimeout ||
              error.type == DioExceptionType.sendTimeout;
          if (isConnectionFailure &&
              error.requestOptions.method.toUpperCase() == 'GET') {
            final cached = await OfflineCacheService.read(
              error.requestOptions.path,
              error.requestOptions.queryParameters,
            );
            if (cached != null) {
              return handler.resolve(Response<dynamic>(
                requestOptions: error.requestOptions,
                statusCode: 200,
                data: cached,
                extra: const {'offline_cache': true},
              ));
            }
          }

          return handler.next(error);
        },
      ),
    );
  }

  Future<bool> _attemptTokenRefresh() {
    final activeRefresh = _refreshInFlight;
    if (activeRefresh != null) return activeRefresh;

    final refresh = _performTokenRefresh();
    _refreshInFlight = refresh;
    refresh.whenComplete(() {
      if (identical(_refreshInFlight, refresh)) {
        _refreshInFlight = null;
      }
    });
    return refresh;
  }

  Future<bool> _performTokenRefresh() async {
    try {
      final refreshToken = await SecureStorageService.getRefreshToken();
      final deviceUuid = await SecureStorageService.getOrCreateDeviceUuid();

      if (refreshToken == null || refreshToken.isEmpty) return false;

      final refreshDio = Dio(BaseOptions(baseUrl: ApiEndpoints.baseUrl));
      final response = await refreshDio.post(
        ApiEndpoints.refreshToken,
        data: {
          'refresh_token': refreshToken,
          'device_uuid': deviceUuid,
        },
      );

      if (response.statusCode == 200 && response.data['success'] == true) {
        final data = response.data['data'];
        final newAccess = data['access_token'] as String;
        final newRefresh = data['refresh_token'] as String;
        final user = data['account'] as Map<String, dynamic>;

        await SecureStorageService.saveAuthTokens(
          accessToken: newAccess,
          refreshToken: newRefresh,
          userId: user['id'] as int,
          role: (user['employee_role'] ?? user['role']) as String,
          fullName: user['full_name'] as String,
          capabilities: (data['capabilities'] as List<dynamic>? ?? const [])
              .map((value) => '$value')
              .toList(),
        );
        return true;
      }
    } catch (_) {
      return false;
    }
    return false;
  }

  Future<String?> syncCurrentIdentity() async {
    try {
      final response = await dio.get(ApiEndpoints.profile);
      final data = response.data['data'] as Map<String, dynamic>;
      final role = '${data['employee_role'] ?? data['role']}';
      await SecureStorageService.updateIdentity(
        role: role,
        fullName: '${data['full_name'] ?? 'Əməkdaş'}',
        capabilities: (data['capabilities'] as List<dynamic>? ?? const [])
            .map((value) => '$value')
            .toList(),
      );
      return role;
    } catch (_) {
      return null;
    }
  }
}
