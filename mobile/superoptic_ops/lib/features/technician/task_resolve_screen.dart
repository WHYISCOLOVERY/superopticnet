import 'dart:convert';
import 'dart:io';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:uuid/uuid.dart';
import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../../core/theme/app_theme.dart';
import '../../data/services/ocr_service.dart';
import '../../data/services/outbox_service.dart';
import '../../data/services/watermark_service.dart';

class TaskResolveScreen extends StatefulWidget {
  final Map<String, dynamic> task;
  const TaskResolveScreen({super.key, required this.task});

  @override
  State<TaskResolveScreen> createState() => _TaskResolveScreenState();
}

class _TaskResolveScreenState extends State<TaskResolveScreen> {
  File? _capturedImage;
  Position? _capturePosition;
  String? _opticalPowerDbm;
  String _opticalStatus = 'Ölçülməyib';
  Color _opticalColor = Colors.grey;
  String _problemType = 'signal';
  String _resolutionReason = 'Siqnal bərpa edildi';
  final Set<String> _completedActions = {};

  final _otpCtrl = TextEditingController();
  final _notesCtrl = TextEditingController();
  final _cableMetersCtrl = TextEditingController(text: '0');
  final _fastConnectorsCtrl = TextEditingController(text: '0');
  final _macCtrl = TextEditingController();
  final _serialCtrl = TextEditingController();
  final _meterCtrl = TextEditingController();

  bool _isSendingOtp = false;
  bool _otpSent = false;
  bool _isSubmitting = false;
  Map<String, dynamic> _inventory = {};
  bool _inventoryLoading = true;
  String? _inventoryError;
  int? _assetId;
  int? _drumId;
  int? _materialId;

  @override
  void initState() {
    super.initState();
    _loadInventory();
  }

  Future<void> _loadInventory() async {
    setState(() {
      _inventoryLoading = true;
      _inventoryError = null;
    });
    try {
      final response =
          await ApiClient().dio.get('/technician/inventory-options.php');
      if (mounted) {
        setState(() =>
            _inventory = Map<String, dynamic>.from(response.data['data']));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _inventoryError =
            'Adınızdakı qalıq yüklənmədi. İnternet bağlantısını yoxlayın.');
      }
    } finally {
      if (mounted) setState(() => _inventoryLoading = false);
    }
  }

  Widget _inventorySelect(
      String key, String label, int? value, ValueChanged<int?> onChanged) {
    final items = (_inventory[key] as List?) ?? [];
    return Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: DropdownButtonFormField<int>(
          initialValue: value,
          isExpanded: true,
          decoration: InputDecoration(labelText: label),
          items: [
            const DropdownMenuItem<int>(
                value: 0, child: Text('İstifadə olunmur')),
            ...items.map((row) => DropdownMenuItem<int>(
                value: int.parse('${row['id']}'),
                child: Text(
                    key == 'assets'
                        ? '${row['asset_code']} • ${row['serial_number'] ?? row['mac_address'] ?? ''}'
                        : key == 'drums'
                            ? '${row['drum_number']} • ${row['available_meters']} m'
                            : '${row['item_name']} • ${row['available_quantity']} ${row['unit']}',
                    overflow: TextOverflow.ellipsis))),
          ],
          onChanged: _isSubmitting ? null : onChanged,
        ));
  }

  @override
  void dispose() {
    for (final controller in [
      _otpCtrl,
      _notesCtrl,
      _cableMetersCtrl,
      _fastConnectorsCtrl,
      _macCtrl,
      _serialCtrl,
      _meterCtrl
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _takePhoto() async {
    final picker = ImagePicker();
    // Live camera ONLY - gallery choice is blocked
    final XFile? file = await picker.pickImage(
      source: ImageSource.camera,
      preferredCameraDevice: CameraDevice.rear,
      imageQuality: 85,
    );

    if (file == null) return;

    File imageFile = File(file.path);

    // 1. Run on-device OCR to read optical power from power meter
    final ocrResult = await OcrService.scanOpticalPower(imageFile);
    if (ocrResult.powerDbm != null) {
      setState(() {
        _opticalPowerDbm = ocrResult.powerDbm.toString();
        _opticalStatus = ocrResult.statusLabel;
        _opticalColor = ocrResult.statusColor == 'green'
            ? const Color(0xFF00E676)
            : (ocrResult.statusColor == 'red'
                ? Colors.redAccent
                : Colors.amber);
      });
    }

    // 2. Get current GPS for watermark
    Position? pos;
    try {
      pos = await Geolocator.getCurrentPosition(
          timeLimit: const Duration(seconds: 5));
    } catch (_) {}

    final techName = await SecureStorageService.getUserFullName() ?? 'Texnik';
    final customer = widget.task['customer'] as Map<String, dynamic>? ?? {};

    // 3. Stamp tamper-proof watermark directly onto JPEG bitmap
    imageFile = await WatermarkService.stampWatermark(
      originalImageFile: imageFile,
      latitude: pos?.latitude,
      longitude: pos?.longitude,
      technicianName: techName,
      ticketNo: widget.task['ticket_no'] ?? '',
      customerCode: customer['subscriber_code'] ?? '',
      opticalPowerDbm: _opticalPowerDbm,
    );

    if (!mounted) return;
    setState(() {
      _capturedImage = imageFile;
      _capturePosition = pos;
    });
  }

  Future<void> _requestCustomerOtp() async {
    setState(() => _isSendingOtp = true);
    try {
      final res = await ApiClient().dio.post(
        ApiEndpoints.requestOtp,
        data: {'ticket_id': widget.task['id']},
      );
      if (res.statusCode == 200 && res.data['success'] == true) {
        if (!mounted) return;
        setState(() => _otpSent = true);
        final masked = res.data['data']['destination_masked'] ?? 'nömrəyə';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(
                  'Təsdiq kodu müştərinin $masked nömrəsi üçün göndəriş növbəsinə yazıldı.')),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content:
                Text('OTP göndərilə bilmədi. İnternet əlaqəsini yoxlayın.')),
      );
    } finally {
      if (mounted) setState(() => _isSendingOtp = false);
    }
  }

  Future<void> _submitResolution() async {
    if (_capturedImage == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Zəhmət olmasa təhvil şəklini çəkin.')),
      );
      return;
    }
    if (_otpCtrl.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content:
                Text('Müştərinin 4 rəqəmli OTP təsdiq kodunu daxil edin.')),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    String? queuedClientUuid;
    try {
      final imageBytes = await _capturedImage!.readAsBytes();
      final base64Image = base64Encode(imageBytes);
      final clientUuid = const Uuid().v4();

      final materials = <Map<String, dynamic>>[];
      final cableMeters =
          double.tryParse(_cableMetersCtrl.text.replaceAll(',', '.'));
      final connectors = int.tryParse(_fastConnectorsCtrl.text);
      if (cableMeters == null || connectors == null) {
        throw StateError(
            'Kabel metrini rəqəmlə, material sayını tam ədədlə yazın.');
      }
      if (!cableMeters.isFinite || cableMeters < 0 || connectors < 0) {
        throw StateError('Material miqdarı düzgün deyil.');
      }
      if (cableMeters > 0 && (_drumId ?? 0) <= 0) {
        throw StateError('Kabel istifadə etdiyiniz barabanı seçin.');
      }
      if (connectors > 0 && (_materialId ?? 0) <= 0) {
        throw StateError('Konnektoru adınızdakı qalıqdan seçin.');
      }

      if (cableMeters > 0) {
        materials.add({
          'item_name': 'Optik Drop Kabel',
          'cable_drum_id': _drumId,
          'quantity': cableMeters,
          'unit': 'metr'
        });
      }
      if (connectors > 0) {
        materials.add({
          'item_name': 'Fast Connector',
          'item_id': _materialId,
          'quantity': connectors,
          'unit': 'ədəd'
        });
      }

      final payload = {
        'client_uuid': clientUuid,
        'ticket_id': widget.task['id'],
        'optical_power_dbm': _opticalPowerDbm != null
            ? double.tryParse(_opticalPowerDbm!)
            : null,
        'customer_otp_code': _otpCtrl.text.trim(),
        'problem_type': _problemType,
        'resolution_reason': _resolutionReason,
        'completed_actions': _completedActions.toList(),
        'equipment': {
          'asset_id': _assetId ?? 0,
          'mac': _macCtrl.text.trim(),
          'serial_number': _serialCtrl.text.trim(),
          'meter_or_device_no': _meterCtrl.text.trim(),
        },
        'resolution_notes': _notesCtrl.text.trim().isEmpty
            ? 'İş sahədə tamamlandı.'
            : _notesCtrl.text.trim(),
        'photo_base64': base64Image,
        'materials_used': materials,
        'watermark_data': {
          'captured_at': DateTime.now().toUtc().toIso8601String(),
          'latitude': _capturePosition?.latitude,
          'longitude': _capturePosition?.longitude,
          'gps_available': _capturePosition != null,
        },
      };

      final accountId = await SecureStorageService.getUserId();
      if (accountId == null) {
        throw StateError('Aktiv istifadəçi sessiyası tapılmadı.');
      }
      await OutboxService.enqueue(
        clientUuid: clientUuid,
        accountId: accountId,
        endpoint: ApiEndpoints.taskResolve,
        payload: payload,
      );
      queuedClientUuid = clientUuid;
      final res =
          await ApiClient().dio.post(ApiEndpoints.taskResolve, data: payload);

      if (res.statusCode == 200 && res.data['success'] == true) {
        await OutboxService.markCompleted(clientUuid);
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Bilet uğurla təhvil verildi və bağlandı!')),
        );
        if (mounted) Navigator.pop(context, true);
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res.data['error'] ?? 'Xəta baş verdi.')),
        );
      }
    } catch (e) {
      if (queuedClientUuid != null) {
        await OutboxService.recordFailure(queuedClientUuid, e);
      }
      if (!mounted) return;
      final serverMessage = e is DioException && e.response?.data is Map
          ? e.response?.data['error']?.toString()
          : null;
      final networkFailure = e is DioException && e.response == null;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(serverMessage ??
                (e is StateError
                    ? e.message.toString()
                    : queuedClientUuid != null
                        ? 'Şəbəkə xətası: əməliyyat cihazdakı Outbox növbəsində saxlanıldı.'
                        : 'Əməliyyat saxlanmadı. Yenidən cəhd edin.'))),
      );
      if (networkFailure && queuedClientUuid != null && mounted) {
        Navigator.pop(context, true);
      }
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final customer = widget.task['customer'] as Map<String, dynamic>? ?? {};

    return Scaffold(
      appBar: AppBar(title: Text('${widget.task['ticket_no']} - Təhvil Ver')),
      body: SingleChildScrollView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: EdgeInsets.fromLTRB(
            20, 20, 20, 32 + MediaQuery.viewInsetsOf(context).bottom),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Customer Header Card
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: const Color(0xFF112240),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(customer['name'] ?? '',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 16)),
                  const SizedBox(height: 4),
                  Text('Ünvan: ${customer['address'] ?? ''}',
                      style: const TextStyle(fontSize: 12, color: Colors.grey)),
                  Text('Paket: ${customer['plan'] ?? ''}',
                      style: const TextStyle(
                          fontSize: 12, color: Color(0xFF00BCD4))),
                ],
              ),
            ),
            const SizedBox(height: 20),

            const Text('0. İş tipi və bağlanma səbəbi',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              initialValue: _problemType,
              decoration: const InputDecoration(labelText: 'Problem tipi'),
              items: const [
                DropdownMenuItem(
                    value: 'installation',
                    child: Text('Qoşuntu / yeni abunəçi')),
                DropdownMenuItem(
                    value: 'signal', child: Text('Optik siqnal problemi')),
                DropdownMenuItem(
                    value: 'modem', child: Text('Modem / ONT problemi')),
                DropdownMenuItem(
                    value: 'cable', child: Text('Kabel / konnektor problemi')),
                DropdownMenuItem(value: 'pon', child: Text('PON / OLT xətti')),
              ],
              onChanged: (value) =>
                  setState(() => _problemType = value ?? 'signal'),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _resolutionReason,
              decoration: const InputDecoration(labelText: 'Bağlanma səbəbi'),
              items: const [
                DropdownMenuItem(
                    value: 'Siqnal bərpa edildi',
                    child: Text('Siqnal bərpa edildi')),
                DropdownMenuItem(
                    value: 'Kabel dəyişdirildi',
                    child: Text('Kabel dəyişdirildi')),
                DropdownMenuItem(
                    value: 'Konnektor dəyişdirildi',
                    child: Text('Konnektor dəyişdirildi')),
                DropdownMenuItem(
                    value: 'Modem/ONT dəyişdirildi',
                    child: Text('Modem/ONT dəyişdirildi')),
                DropdownMenuItem(
                    value: 'Müştəriyə təhvil verildi',
                    child: Text('Müştəriyə təhvil verildi')),
                DropdownMenuItem(
                    value: 'Xətt qaydaya salındı',
                    child: Text('Xətt qaydaya salındı')),
              ],
              onChanged: (value) => setState(
                  () => _resolutionReason = value ?? 'Siqnal bərpa edildi'),
            ),
            const SizedBox(height: 14),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _actionChip('Siqnal ölçüldü'),
                _actionChip('Xətt yoxlandı'),
                _actionChip('Konnektor təmizləndi'),
                _actionChip('Kabel çəkildi'),
                _actionChip('Modem aktiv edildi'),
                _actionChip('Müştəriyə izah edildi'),
              ],
            ),
            const SizedBox(height: 24),

            // Step 1: Live Photo & Watermark
            const Text('1. Canlı Foto Sübut (Watermark + OCR)',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            if (_capturedImage != null) ...[
              ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.file(_capturedImage!,
                    height: 220, width: double.infinity, fit: BoxFit.cover),
              ),
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: _opticalColor.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: _opticalColor),
                ),
                child: Row(
                  children: [
                    Icon(Icons.speed, color: _opticalColor, size: 20),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Optik Güc (OCR): $_opticalStatus',
                        style: TextStyle(
                            color: _opticalColor,
                            fontWeight: FontWeight.bold,
                            fontSize: 13),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 8),
            ],
            OutlinedButton.icon(
              onPressed: _takePhoto,
              icon: const Icon(Icons.camera_alt),
              label: Text(_capturedImage == null
                  ? 'Kameranı Aç və Şəkil Çək'
                  : 'Şəkli Yenidən Çək'),
            ),
            const SizedBox(height: 24),

            // Step 2: Customer SMS OTP
            const Text('2. Müştəri Təsdiq Kodu (SMS OTP)',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _otpCtrl,
                    keyboardType: TextInputType.number,
                    maxLength: 4,
                    decoration: InputDecoration(
                      labelText: '4 Rəqəmli OTP',
                      counterText: '',
                      border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                ElevatedButton.icon(
                  onPressed: _isSendingOtp ? null : _requestCustomerOtp,
                  icon: const Icon(Icons.send_outlined, size: 18),
                  label: _isSendingOtp
                      ? const SizedBox(
                          height: 16,
                          width: 16,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(_otpSent ? 'Yenidən Göndər' : 'OTP Göndər'),
                ),
              ],
            ),
            const SizedBox(height: 20),

            // Step 3: Material Usage
            const Text('3. Cihaz və istifadə olunan materiallar',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            if (_inventoryLoading) const LinearProgressIndicator(),
            if (_inventoryError != null) ...[
              Text(_inventoryError!),
              TextButton(
                  onPressed: _loadInventory,
                  child: const Text('Qalığı yenidən yüklə')),
            ],
            _inventorySelect('assets', 'Adımdakı ONT / router', _assetId,
                (value) {
              setState(() {
                _assetId = value;
                final rows = (_inventory['assets'] as List? ?? [])
                    .where((r) => '${r['id']}' == '$value');
                if (rows.isNotEmpty) {
                  _macCtrl.text = '${rows.first['mac_address'] ?? ''}';
                  _serialCtrl.text = '${rows.first['serial_number'] ?? ''}';
                }
              });
            }),
            _inventorySelect('drums', 'Kabel barabanı', _drumId,
                (value) => setState(() => _drumId = value)),
            _inventorySelect('stock', 'Adımdakı sərf materialı', _materialId,
                (value) => setState(() => _materialId = value)),
            TextField(
              controller: _macCtrl,
              textCapitalization: TextCapitalization.characters,
              decoration: InputDecoration(
                labelText: 'MAC ünvanı',
                hintText: 'AA:BB:CC:DD:EE:FF',
                border:
                    OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _serialCtrl,
              textCapitalization: TextCapitalization.characters,
              decoration: InputDecoration(
                labelText: 'SN / modem seriya nömrəsi',
                border:
                    OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _meterCtrl,
              decoration: InputDecoration(
                labelText: 'Ölçü cihazı / metr qeydiyyatı',
                hintText: 'Metr/sayğac/cihaz nömrəsi',
                border:
                    OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _cableMetersCtrl,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(
                      labelText: 'Kabel (metr)',
                      border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextField(
                    controller: _fastConnectorsCtrl,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(
                      labelText: 'Konnektor (ədəd)',
                      border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),

            // Step 4: Notes
            const Text('4. Texniki İzah / Qeyd',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            TextField(
              controller: _notesCtrl,
              maxLines: 2,
              decoration: InputDecoration(
                hintText: 'Məs: Siqnal bərpa olundu, xətt lehimləndi...',
                border:
                    OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
            const SizedBox(height: 28),

            // Submit Button
            ElevatedButton(
              onPressed: _isSubmitting ? null : _submitResolution,
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF00E676),
                foregroundColor: Colors.black,
                padding: const EdgeInsets.symmetric(vertical: 18),
              ),
              child: _isSubmitting
                  ? const CircularProgressIndicator()
                  : const Text('İşi Təsdiqlə və Təhvil Ver',
                      style:
                          TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _actionChip(String label) {
    final selected = _completedActions.contains(label);
    return FilterChip(
      selected: selected,
      label: Text(label),
      selectedColor: AppTheme.primary.withValues(alpha: 0.18),
      side: BorderSide(color: selected ? AppTheme.primary : AppTheme.border),
      onSelected: (value) {
        setState(() {
          if (value) {
            _completedActions.add(label);
          } else {
            _completedActions.remove(label);
          }
        });
      },
    );
  }
}
