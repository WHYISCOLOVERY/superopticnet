import 'dart:convert';
import 'dart:html' as html;

import 'package:dio/dio.dart';

import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';

/// LocalStorage outbox for the browser/PWA build.
/// Native Android/iOS builds retain the SQLite outbox implementation.
class OutboxService {
  static const _prefix = 'superoptic.mobile_outbox.v1.';

  static Future<int?> _accountId() => SecureStorageService.getUserId();

  static Future<List<Map<String, dynamic>>> _read(int accountId) async {
    try {
      final raw = html.window.localStorage['$_prefix$accountId'];
      if (raw == null || raw.isEmpty) return <Map<String, dynamic>>[];
      final decoded = jsonDecode(raw);
      if (decoded is! List) return <Map<String, dynamic>>[];
      return decoded
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList();
    } catch (_) {
      return <Map<String, dynamic>>[];
    }
  }

  static Future<void> _write(int accountId, List<Map<String, dynamic>> rows) async {
    try {
      html.window.localStorage['$_prefix$accountId'] = jsonEncode(rows);
    } catch (_) {
      // Browser private mode/storage limits must not crash the online action.
    }
  }

  static Future<void> enqueue({
    required String clientUuid,
    required int accountId,
    required String endpoint,
    required Map<String, dynamic> payload,
  }) async {
    final rows = await _read(accountId);
    if (rows.any((row) => row['client_uuid'] == clientUuid)) return;
    final now = DateTime.now().toUtc().toIso8601String();
    rows.add(<String, dynamic>{
      'client_uuid': clientUuid,
      'account_id': accountId,
      'endpoint': endpoint,
      'payload': payload,
      'state': 'queued',
      'attempts': 0,
      'created_at': now,
      'updated_at': now,
    });
    await _write(accountId, rows);
  }

  static Future<void> markCompleted(String clientUuid) async {
    final accountId = await _accountId();
    if (accountId == null) return;
    final rows = await _read(accountId);
    rows.removeWhere((row) => row['client_uuid'] == clientUuid);
    await _write(accountId, rows);
  }

  static Future<void> recordFailure(String clientUuid, Object error) async {
    final accountId = await _accountId();
    if (accountId == null) return;
    final rows = await _read(accountId);
    final now = DateTime.now().toUtc().toIso8601String();
    for (final row in rows) {
      if (row['client_uuid'] != clientUuid) continue;
      row['attempts'] = ((row['attempts'] as num?)?.toInt() ?? 0) + 1;
      row['last_error'] = error.toString().substring(
          0, error.toString().length > 500 ? 500 : error.toString().length);
      row['updated_at'] = now;
      break;
    }
    await _write(accountId, rows);
  }

  static Future<int> flushPending() async {
    final accountId = await _accountId();
    if (accountId == null) return 0;
    final rows = await _read(accountId);
    var completed = 0;
    for (final row in List<Map<String, dynamic>>.from(rows)) {
      if (row['state'] != 'queued') continue;
      final clientUuid = '${row['client_uuid']}';
      try {
        final response = await ApiClient().dio.post(
          '${row['endpoint']}',
          data: Map<String, dynamic>.from(row['payload'] as Map),
        );
        if (response.statusCode != null &&
            response.statusCode! >= 200 &&
            response.statusCode! < 300 &&
            response.data is Map &&
            response.data['success'] == true) {
          rows.removeWhere((item) => item['client_uuid'] == clientUuid);
          completed++;
        }
      } on DioException catch (error) {
        final status = error.response?.statusCode ?? 0;
        if (status >= 400 && status < 500 && status != 408 && status != 429) {
          if (row['endpoint'] == '/location/ping.php') {
            rows.removeWhere((item) => item['client_uuid'] == clientUuid);
          } else {
            row['state'] = 'rejected';
            row['last_error'] = error.message;
            row['updated_at'] = DateTime.now().toUtc().toIso8601String();
          }
        } else {
          row['attempts'] = ((row['attempts'] as num?)?.toInt() ?? 0) + 1;
          row['last_error'] = error.toString().substring(
              0, error.toString().length > 500 ? 500 : error.toString().length);
          row['updated_at'] = DateTime.now().toUtc().toIso8601String();
        }
      } catch (error) {
        row['attempts'] = ((row['attempts'] as num?)?.toInt() ?? 0) + 1;
        row['last_error'] = error.toString().substring(
            0, error.toString().length > 500 ? 500 : error.toString().length);
        row['updated_at'] = DateTime.now().toUtc().toIso8601String();
      }
    }
    rows.removeWhere((row) =>
        row['endpoint'] == '/location/ping.php' && row['state'] == 'rejected');
    await _write(accountId, rows);
    return completed;
  }

  static Future<int> queuedCount() async {
    final accountId = await _accountId();
    if (accountId == null) return 0;
    final rows = await _read(accountId);
    return rows.where((row) => row['state'] == 'queued').length;
  }
}
