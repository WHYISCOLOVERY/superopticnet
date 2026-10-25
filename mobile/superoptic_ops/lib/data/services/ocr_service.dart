import 'dart:io';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';

class OcrOpticalPowerResult {
  final double? powerDbm;
  final String rawText;
  final bool isValid;
  final String statusLabel;
  final String statusColor;

  OcrOpticalPowerResult({
    required this.powerDbm,
    required this.rawText,
    required this.isValid,
    required this.statusLabel,
    required this.statusColor,
  });
}

class OcrService {
  static final TextRecognizer _recognizer =
      TextRecognizer(script: TextRecognitionScript.latin);

  /// Analyzes an image of the optical power meter screen on-device
  static Future<OcrOpticalPowerResult> scanOpticalPower(File imageFile) async {
    final inputImage = InputImage.fromFile(imageFile);
    final RecognizedText recognizedText =
        await _recognizer.processImage(inputImage);

    final String fullText = recognizedText.text;
    double? detectedPower;

    // Regex to match optical dBm levels like: -19.4 dBm, -21.05dBm, -18.2, -28.4
    final RegExp dbmRegex =
        RegExp(r'(-?\d{1,2}(?:\.\d{1,2})?)\s*(?:dBm|dbm|dB|db)?');
    final Iterable<RegExpMatch> matches = dbmRegex.allMatches(fullText);

    for (final match in matches) {
      final String? numStr = match.group(1);
      if (numStr != null) {
        final double? val = double.tryParse(numStr);
        // Optical PON RX typically falls between -8.0 dBm and -35.0 dBm
        if (val != null && val <= -5.0 && val >= -40.0) {
          detectedPower = val;
          break;
        }
      }
    }

    if (detectedPower != null) {
      if (detectedPower < -27.0) {
        return OcrOpticalPowerResult(
          powerDbm: detectedPower,
          rawText: fullText,
          isValid: true,
          statusLabel: 'Kritik Zəif ($detectedPower dBm)',
          statusColor: 'red',
        );
      } else if (detectedPower < -24.0) {
        return OcrOpticalPowerResult(
          powerDbm: detectedPower,
          rawText: fullText,
          isValid: true,
          statusLabel: 'Qənaətbəxş ($detectedPower dBm)',
          statusColor: 'yellow',
        );
      } else {
        return OcrOpticalPowerResult(
          powerDbm: detectedPower,
          rawText: fullText,
          isValid: true,
          statusLabel: 'Əla Siqnal ($detectedPower dBm)',
          statusColor: 'green',
        );
      }
    }

    return OcrOpticalPowerResult(
      powerDbm: null,
      rawText: fullText,
      isValid: false,
      statusLabel: 'Oxunmadı (əllə daxil edin)',
      statusColor: 'grey',
    );
  }

  static void dispose() {
    _recognizer.close();
  }
}
