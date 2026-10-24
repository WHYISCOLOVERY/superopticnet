import 'dart:convert';
import 'dart:html' as html;

import '../../core/security/secure_storage_service.dart';

/// Browser-safe read cache for the iPhone/web client.
/// Android and iOS native builds use SQLite in offline_cache_service_native.dart.
class OfflineCacheService {
  static const _prefix = 'superoptic.offline_cache.v1.';

  static String requestKey(String path, Map<String, dynamic> query) {
    final keys = query.keys.toList()..sort();
    final normalized = <String, dynamic>{
      for (final key in keys) key: query[key],
    };
    return '$path?${jsonEncode(normalized)}';
  }

  static String _storageKey(int accountId, String requestKey) {
    return '$_prefix$accountId.${base64UrlEncode(utf8.encode(requestKey))}';
  }

  static Future<void> save(
      String path, Map<String, dynamic> query, dynamic response) async {
    final accountId = await SecureStorageService.getUserId();
    if (accountId == null || response == null) return;
    final payload = <String, dynamic>{
      'response': response,
      'saved_at': DateTime.now().toUtc().toIso8601String(),
    };
    try {
      html.window.localStorage[
          _storageKey(accountId, requestKey(path, query))] = jsonEncode(payload);
    } catch (_) {
      // Private browsing or a full local store must not break online requests.
    }
  }

  static Future<Map<String, dynamic>?> read(
      String path, Map<String, dynamic> query) async {
    final accountId = await SecureStorageService.getUserId();
    if (accountId == null) return null;
    try {
      final encoded = html.window.localStorage[
          _storageKey(accountId, requestKey(path, query))];
      if (encoded == null || encoded.isEmpty) return null;
      final stored = jsonDecode(encoded);
      if (stored is! Map || stored['response'] is! Map) return null;
      final result = Map<String, dynamic>.from(stored['response'] as Map);
      result['offline_cache'] = true;
      result['offline_saved_at'] = stored['saved_at'];
      return result;
    } catch (_) {
      return null;
    }
  }
}
