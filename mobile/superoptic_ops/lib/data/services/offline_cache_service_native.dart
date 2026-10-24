import 'dart:convert';

import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqlite3/sqlite3.dart';

import '../../core/security/secure_storage_service.dart';

class OfflineCacheService {
  static Database? _database;

  static Future<Database> _db() async {
    final open = _database;
    if (open != null) return open;
    final directory = await getApplicationSupportDirectory();
    final database =
        sqlite3.open(p.join(directory.path, 'superoptic_offline.sqlite'));
    database.execute('''
      CREATE TABLE IF NOT EXISTS response_cache (
        account_id INTEGER NOT NULL,
        cache_key TEXT NOT NULL,
        response_json TEXT NOT NULL,
        saved_at TEXT NOT NULL,
        PRIMARY KEY (account_id, cache_key)
      )
    ''');
    _database = database;
    return database;
  }

  static String requestKey(String path, Map<String, dynamic> query) {
    final keys = query.keys.toList()..sort();
    final normalized = <String, dynamic>{
      for (final key in keys) key: query[key]
    };
    return '$path?${jsonEncode(normalized)}';
  }

  static Future<void> save(
      String path, Map<String, dynamic> query, dynamic response) async {
    final accountId = await SecureStorageService.getUserId();
    if (accountId == null || response == null) return;
    final encoded = jsonEncode(response);
    final db = await _db();
    db.execute(
      '''INSERT INTO response_cache(account_id,cache_key,response_json,saved_at)
         VALUES(?,?,?,?)
         ON CONFLICT(account_id,cache_key) DO UPDATE SET
           response_json=excluded.response_json,saved_at=excluded.saved_at''',
      [
        accountId,
        requestKey(path, query),
        encoded,
        DateTime.now().toUtc().toIso8601String(),
      ],
    );
  }

  static Future<Map<String, dynamic>?> read(
      String path, Map<String, dynamic> query) async {
    final accountId = await SecureStorageService.getUserId();
    if (accountId == null) return null;
    final db = await _db();
    final rows = db.select(
      'SELECT response_json,saved_at FROM response_cache WHERE account_id=? AND cache_key=? LIMIT 1',
      [accountId, requestKey(path, query)],
    );
    if (rows.isEmpty) return null;
    final decoded = jsonDecode(rows.first['response_json'] as String);
    if (decoded is! Map) return null;
    final result = Map<String, dynamic>.from(decoded);
    result['offline_cache'] = true;
    result['offline_saved_at'] = rows.first['saved_at'];
    return result;
  }
}
