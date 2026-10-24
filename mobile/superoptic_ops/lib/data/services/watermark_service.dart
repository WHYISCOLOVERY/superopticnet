import 'dart:io';
import 'dart:typed_data';
import 'package:image/image.dart' as img;
import 'package:intl/intl.dart';

class WatermarkService {
  /// Stamp tamper-proof metadata directly onto the bitmap on-device (offline)
  static Future<File> stampWatermark({
    required File originalImageFile,
    required double? latitude,
    required double? longitude,
    required String technicianName,
    required String ticketNo,
    required String customerCode,
    String? opticalPowerDbm,
  }) async {
    final Uint8List bytes = await originalImageFile.readAsBytes();
    final img.Image? photo = img.decodeImage(bytes);

    if (photo == null) {
      return originalImageFile;
    }

    final String timestamp =
        DateFormat('yyyy-MM-dd HH:mm:ss').format(DateTime.now());
    final String line1 = 'SUPEROPTIC OPS | $timestamp';
    final String gpsLabel = latitude != null && longitude != null
        ? '${latitude.toStringAsFixed(6)}, ${longitude.toStringAsFixed(6)}'
        : 'mövcud deyil';
    final String line2 = 'GPS: $gpsLabel | Texnik: $technicianName';
    final String line3 =
        'Bilet: $ticketNo | Abunəçi: $customerCode${opticalPowerDbm != null ? ' | Güc: $opticalPowerDbm dBm' : ''}';

    // Draw dark semi-transparent banner at the bottom of the photo
    final int bannerHeight = (photo.height * 0.08).clamp(80, 160).toInt();
    final int bannerY = photo.height - bannerHeight;

    img.fillRect(
      photo,
      x1: 0,
      y1: bannerY,
      x2: photo.width,
      y2: photo.height,
      color: img.ColorRgba8(0, 0, 0, 190), // 75% dark overlay
    );

    // Render 3 lines of high-contrast crisp text onto the banner
    final font = img.arial24;
    img.drawString(photo, line1,
        font: font,
        x: 20,
        y: bannerY + 10,
        color: img.ColorRgba8(0, 230, 118, 255)); // Neon Green
    img.drawString(photo, line2,
        font: font,
        x: 20,
        y: bannerY + 38,
        color: img.ColorRgba8(255, 255, 255, 255)); // White
    img.drawString(photo, line3,
        font: font,
        x: 20,
        y: bannerY + 66,
        color: img.ColorRgba8(255, 215, 0, 255)); // Gold

    // Re-encode to optimized JPEG (quality 82% to save mobile data)
    final Uint8List watermarkedBytes =
        Uint8List.fromList(img.encodeJpg(photo, quality: 82));
    await originalImageFile.writeAsBytes(watermarkedBytes);

    return originalImageFile;
  }
}
