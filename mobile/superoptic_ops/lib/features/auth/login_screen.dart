import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import 'legal_consent_screen.dart';
import '../home/role_home.dart';
import '../../data/services/offline_sync_service.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _loginCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _isLoading = false;
  bool _obscurePass = true;
  String? _errorMessage;

  Future<void> _handleLogin() async {
    final login = _loginCtrl.text.trim();
    final password = _passCtrl.text;

    if (login.isEmpty || password.isEmpty) {
      setState(
          () => _errorMessage = 'İstifadəçi adı və parol daxil edilməlidir.');
      return;
    }

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final deviceUuid = await SecureStorageService.getOrCreateDeviceUuid();
      final response = await ApiClient().dio.post(
        ApiEndpoints.login,
        data: {
          'login': login,
          'password': password,
          'device_uuid': deviceUuid,
          'platform': 'android',
          'device_model': 'Mobile Device',
          'app_version': '1.0.2',
        },
      );

      if (response.statusCode == 200 && response.data['success'] == true) {
        final data = response.data['data'];
        final accessToken = data['access_token'] as String;
        final refreshToken = data['refresh_token'] as String;
        final user = data['user'] as Map<String, dynamic>;
        final bool consentNeeded = data['legal_consent_needed'] == true;

        final effectiveRole = (user['employee_role'] ?? user['role']) as String;
        await SecureStorageService.saveAuthTokens(
          accessToken: accessToken,
          refreshToken: refreshToken,
          userId: user['id'] as int,
          role: effectiveRole,
          fullName: user['full_name'] as String,
          capabilities: (user['capabilities'] as List<dynamic>? ?? [])
              .map((value) => '$value')
              .toList(),
        );
        try {
          await ApiClient().dio.post(ApiEndpoints.presenceHeartbeat,
              data: {'activity_type': 'app_open', 'app_version': '1.0.2'});
        } catch (_) {}
        await OfflineSyncService.syncNow();

        if (!mounted) return;

        if (consentNeeded) {
          Navigator.pushReplacement(
            context,
            MaterialPageRoute(builder: (_) => const LegalConsentScreen()),
          );
        } else {
          Navigator.pushReplacement(
            context,
            MaterialPageRoute(
                builder: (_) => homeForEmployeeRole(effectiveRole)),
          );
        }
      } else {
        setState(() =>
            _errorMessage = response.data['error'] ?? 'Giriş uğursuz oldu.');
      }
    } on DioException catch (error) {
      final data = error.response?.data;
      setState(() => _errorMessage = data is Map && data['error'] != null
          ? '${data['error']}'
          : 'Şəbəkə xətası və ya serverə qoşulmaq mümkün olmadı.');
    } catch (_) {
      setState(() => _errorMessage = 'Giriş tamamlanmadı. Yenidən sınayın.');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // Logo & Header
                const Icon(Icons.hub_outlined,
                    size: 72, color: Color(0xFF00BCD4)),
                const SizedBox(height: 16),
                const Text(
                  'SUPEROPTIC OPS',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      fontSize: 26,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 1.5),
                ),
                const SizedBox(height: 8),
                const Text(
                  'Carrier-Grade ISP Əməliyyat & Sahə İdarəetmə Sistemi',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 13, color: Colors.grey),
                ),
                const SizedBox(height: 36),

                if (_errorMessage != null) ...[
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.red.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: Colors.redAccent),
                    ),
                    child: Text(_errorMessage!,
                        style: const TextStyle(
                            color: Colors.redAccent, fontSize: 13)),
                  ),
                  const SizedBox(height: 16),
                ],

                // Input fields
                TextField(
                  controller: _loginCtrl,
                  decoration: InputDecoration(
                    labelText: 'İstifadəçi adı / Telefon / Abunəçi kodu',
                    prefixIcon: const Icon(Icons.person_outline),
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(12)),
                  ),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _passCtrl,
                  obscureText: _obscurePass,
                  decoration: InputDecoration(
                    labelText: 'Şifrə',
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(_obscurePass
                          ? Icons.visibility_off
                          : Icons.visibility),
                      onPressed: () =>
                          setState(() => _obscurePass = !_obscurePass),
                    ),
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(12)),
                  ),
                ),
                const SizedBox(height: 28),

                ElevatedButton(
                  onPressed: _isLoading ? null : _handleLogin,
                  child: _isLoading
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Text('Daxil Ol'),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Sistem qorunur: 256-bit SSL Pinning & Cihaz Başına Təhlükəsizlik',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 11, color: Colors.grey),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
