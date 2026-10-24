import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqlite3/sqlite3.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';

class OutboxService {
  static Database? _database;

  static Future<Database> _db() async {
    final existing = _database;
    if (existing != null) return existing;

    final directory = await getApplicationSupportDirectory();
    final database =
        sqlite3.open(p.join(directory.path, 'superoptic_outbox.sqlite'));
    database.execute('''
      CREATE TABLE IF NOT EXISTS mobile_outbox (
        client_uuid TEXT PRIMARY KEY,
        account_id INTEGER NOT NULL,
        endpoint TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        state TEXT NOT NULL DEFAULT 'queued',
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
      )
    ''');
    database.execute(
      'CREATE INDEX IF NOT EXISTS idx_mobile_outbox_account_state '
      'ON mobile_outbox(account_id, state, created_at)',
    );
    _database = database;
    return database;
  }

  static Future<void> enqueue({
    required String clientUuid,
    required int accountId,
    required String endpoint,
    required Map<String, dynamic> payload,
  }) async {
    final db = await _db();
    final now = DateTime.now().toUtc().toIso8601String();
    db.execute(
      '''INSERT OR IGNORE INTO mobile_outbox
         (client_uuid, account_id, endpoint, payload_json, state, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'queued', ?, ?)''',
      [clientUuid, accountId, endpoint, jsonEncode(payload), now, now],
    );
  }

  static Future<void> markCompleted(String clientUuid) async {
    final db = await _db();
    db.execute('DELETE FROM mobile_outbox WHERE client_uuid = ?', [clientUuid]);
  }

  static Future<void> recordFailure(String clientUuid, Object error) async {
    final db = await _db();
    final message = error.toString();
    db.execute(
      '''UPDATE mobile_outbox
         SET attempts = attempts + 1, last_error = ?, updated_at = ?
         WHERE client_uuid = ?''',
      [
        message.substring(0, message.length > 500 ? 500 : message.length),
        DateTime.now().toUtc().toIso8601String(),
        clientUuid,
      ],
    );
  }

  static Future<int> flushPending() async {
    final accountId = await SecureStorageService.getUserId();
    if (accountId == null) return 0;

    final db = await _db();
    final rows = db.select(
      '''SELECT client_uuid, endpoint, payload_json
         FROM mobile_outbox
         WHERE account_id = ? AND state = 'queued'
         ORDER BY created_at LIMIT 100''',
      [accountId],
    );
    var completed = 0;
    for (final row in rows) {
      final clientUuid = row['client_uuid'] as String;
      try {
        final payload = jsonDecode(row['payload_json'] as String);
        if (payload is! Map<String, dynamic>) {
          throw const FormatException('Outbox payload etibarsızdır.');
        }
        final response = await ApiClient()
            .dio
            .post(row['endpoint'] as String, data: payload);
        if (response.statusCode != null &&
            response.statusCode! >= 200 &&
            response.statusCode! < 300 &&
            response.data is Map &&
            response.data['success'] == true) {
          await markCompleted(clientUuid);
          completed++;
        }
      } on DioException catch (error) {
        final status = error.response?.statusCode ?? 0;
        if (status >= 400 && status < 500 && status != 408 && status != 429) {
          if (row['endpoint'] == '/location/ping.php') {
            await markCompleted(clientUuid);
          } else {
            db.execute(
              "UPDATE mobile_outbox SET state = 'rejected', last_error = ?, updated_at = ? WHERE client_uuid = ?",
              [
                error.message,
                DateTime.now().toUtc().toIso8601String(),
                clientUuid
              ],
            );
          }
        } else {
          await recordFailure(clientUuid, error);
        }
      } catch (error) {
        await recordFailure(clientUuid, error);
      }
    }
    db.execute(
      "DELETE FROM mobile_outbox WHERE endpoint='/location/ping.php' AND state='rejected'",
    );
    return completed;
  }

  static Future<int> queuedCount() async {
    final accountId = await SecureStorageService.getUserId();
    if (accountId == null) return 0;
    final db = await _db();
    final result = db.select(
      "SELECT COUNT(*) AS total FROM mobile_outbox WHERE account_id=? AND state='queued'",
      [accountId],
    );
    return result.isEmpty ? 0 : result.first['total'] as int;
  }
}
