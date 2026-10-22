import 'dart:async';

import 'package:geolocator/geolocator.dart';

class AttendanceLocationException implements Exception {
  final String message;
  const AttendanceLocationException(this.message);

  @override
  String toString() => message;
}

class AttendanceLocationService {
  static Future<Position> capture() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const AttendanceLocationException(
          'Telefonun məkan xidməti söndürülüb. GPS-i aktiv edin.');
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.deniedForever) {
      throw const AttendanceLocationException(
          'Məkan icazəsi daimi bloklanıb. Telefon ayarlarında “Həmişə icazə ver” seçin.');
    }
    if (permission == LocationPermission.denied) {
      throw const AttendanceLocationException(
          'Davamiyyət üçün məkan icazəsi tələb olunur.');
    }

    try {
      final position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
        timeLimit: const Duration(seconds: 25),
      );
      return _validate(position);
    } on TimeoutException {
      final last = await Geolocator.getLastKnownPosition();
      if (last != null &&
          DateTime.now().difference(last.timestamp).abs() <=
              const Duration(minutes: 5)) {
        return _validate(last);
      }
      throw const AttendanceLocationException(
          'GPS 25 saniyədə mövqe tapa bilmədi. Pəncərə və ya açıq sahəyə yaxınlaşıb yenidən yoxlayın.');
    }
  }

  static Position _validate(Position position) {
    if (position.isMocked) {
      throw const AttendanceLocationException(
          'Saxta məkan tətbiqi aktivdir. Real GPS mövqeyi tələb olunur.');
    }
    if (position.accuracy <= 0 || position.accuracy > 250) {
      throw AttendanceLocationException(
          'GPS dəqiqliyi zəifdir (${position.accuracy.round()} m). Siqnal yaxşılaşdıqdan sonra yenidən yoxlayın.');
    }
    return position;
  }
}
