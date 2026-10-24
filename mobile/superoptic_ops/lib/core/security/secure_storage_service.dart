import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';
import 'dart:convert';

class SecureStorageService {
  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  static const String _keyAccessToken = 'sec_access_token';
  static const String _keyRefreshToken = 'sec_refresh_token';
  static const String _keyDeviceUuid = 'sec_device_uuid';
  static const String _keyUserId = 'sec_user_id';
  static const String _keyUserRole = 'sec_user_role';
  static const String _keyUserFullName = 'sec_user_name';
  static const String _keyCapabilities = 'sec_user_capabilities';
  static const String _keyDutyTrackingEnabled = 'sec_duty_tracking_enabled';

  static Future<String> getOrCreateDeviceUuid() async {
    String? uuid = await _storage.read(key: _keyDeviceUuid);
    if (uuid == null || uuid.isEmpty) {
      uuid = const Uuid().v4();
      await _storage.write(key: _keyDeviceUuid, value: uuid);
    }
    return uuid;
  }

  static Future<void> saveAuthTokens({
    required String accessToken,
    required String refreshToken,
    required int userId,
    required String role,
    required String fullName,
    List<String> capabilities = const [],
  }) async {
    await _storage.write(key: _keyAccessToken, value: accessToken);
    await _storage.write(key: _keyRefreshToken, value: refreshToken);
    await _storage.write(key: _keyUserId, value: userId.toString());
    await _storage.write(key: _keyUserRole, value: role);
    await _storage.write(key: _keyUserFullName, value: fullName);
    await _storage.write(
        key: _keyCapabilities, value: jsonEncode(capabilities));
  }

  static Future<String?> getAccessToken() async =>
      _storage.read(key: _keyAccessToken);
  static Future<String?> getRefreshToken() async =>
      _storage.read(key: _keyRefreshToken);
  static Future<String?> getUserRole() async =>
      _storage.read(key: _keyUserRole);
  static Future<String?> getUserFullName() async =>
      _storage.read(key: _keyUserFullName);
  static Future<List<String>> getCapabilities() async {
    final raw = await _storage.read(key: _keyCapabilities);
    final decoded = raw == null ? null : jsonDecode(raw);
    return decoded is List ? decoded.map((value) => '$value').toList() : [];
  }

  static Future<void> updateIdentity({
    required String role,
    required String fullName,
    required List<String> capabilities,
  }) async {
    await _storage.write(key: _keyUserRole, value: role);
    await _storage.write(key: _keyUserFullName, value: fullName);
    await _storage.write(
        key: _keyCapabilities, value: jsonEncode(capabilities));
  }

  static Future<int?> getUserId() async {
    final str = await _storage.read(key: _keyUserId);
    return str != null ? int.tryParse(str) : null;
  }

  static Future<void> setDutyTrackingEnabled(bool enabled) async {
    await _storage.write(
      key: _keyDutyTrackingEnabled,
      value: enabled ? '1' : '0',
    );
  }

  static Future<bool> isDutyTrackingEnabled() async =>
      await _storage.read(key: _keyDutyTrackingEnabled) == '1';

  static Future<void> clearAuth() async {
    await setDutyTrackingEnabled(false);
    await _storage.delete(key: _keyAccessToken);
    await _storage.delete(key: _keyRefreshToken);
    await _storage.delete(key: _keyUserId);
    await _storage.delete(key: _keyUserRole);
    await _storage.delete(key: _keyUserFullName);
    await _storage.delete(key: _keyCapabilities);
  }
}
