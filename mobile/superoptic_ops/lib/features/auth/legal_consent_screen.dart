import 'package:flutter/material.dart';
import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/security/secure_storage_service.dart';
import '../home/role_home.dart';

class LegalConsentScreen extends StatefulWidget {
  const LegalConsentScreen({super.key});

  @override
  State<LegalConsentScreen> createState() => _LegalConsentScreenState();
}

class _LegalConsentScreenState extends State<LegalConsentScreen> {
  bool _agreedToTerms = false;
  bool _isLoading = false;

  Future<void> _submitConsent() async {
    if (!_agreedToTerms) return;

    setState(() => _isLoading = true);

    try {
      final response = await ApiClient().dio.post(
        ApiEndpoints.legalConsent,
        data: {
          'agreed': true,
        },
      );

      if (response.statusCode == 200 && response.data['success'] == true) {
        final role = await SecureStorageService.getUserRole();
        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => homeForEmployeeRole(role)),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text(
                'Razılıq qeydə alına bilmədi. İnternet əlaqənizi yoxlayın.')),
      );
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Hüquqi Razılaşma')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Row(
                children: [
                  Icon(Icons.gavel_rounded, color: Color(0xFF00BCD4), size: 32),
                  SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      'İş Vaxtı GPS İzləmə və Əməliyyat Protokolu',
                      style:
                          TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.04),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Colors.white12),
                  ),
                  child: const SingleChildScrollView(
                    child: Text(
                      'Azərbaycan Respublikasının Əmək Məcəlləsinə və "Fərdi Məlumatlar Haqqında" AR Qanununa uyğun olaraq:\n\n'
                      '1. Bu tətbiq telekom şəbəkəsində sahə tapşırıqlarının təhlükəsiz paylanması, müştəri xidmətlərinin operativliyi və fövqəladə qəzaların aradan qaldırılması üçün geolokasiya (GPS) məlumatlarını toplayır.\n\n'
                      '2. İzləmə YALNIZ əməkdaş tətbiqdə "Növbədəyəm" rejimini aktiv etdikdə həyata keçirilir. İş saatlarından kənar və ya "Növbəni Bitir" edildikdə yer məlumatlarının toplanması tamamilə dayandırılır.\n\n'
                      '3. Təyin edilmiş iş ərazisindən çıxış qeydə alınır. Ev geofence-i qurulubsa, iş vaxtı və nahar fasiləsi xaricində həmin zonada 30 dəqiqədən uzun qalma rəhbərə hadisə kimi bildirilir. Qısa keçid xəbərdarlıq yaratmır.\n\n'
                      '4. Toplanan koordinatlar üçüncü tərəflərə ötürülmür, yalnız "SuperOptic" əməliyyat idarəetmə mərkəzində dispetçerlik və təhlükəsizlik məqsədilə arxivləşdirilir. Rəhbər hadisə siyahısında dəqiq ev ünvanını deyil, yalnız zona hadisəsini və müddəti görür.\n\n'
                      '5. İş təhvili zamanı çəkilən şəkillər və optik güc göstəriciləri birbaşa xidmət keyfiyyətinin sübutu kimi qəbul olunur.',
                      style: TextStyle(
                          fontSize: 13, height: 1.6, color: Colors.white70),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              CheckboxListTile(
                value: _agreedToTerms,
                onChanged: (val) =>
                    setState(() => _agreedToTerms = val ?? false),
                title: const Text(
                  'Yuxarıdakı şərtlərlə tanış oldum və razıyam.',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                ),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
              ),
              const SizedBox(height: 16),
              ElevatedButton(
                onPressed:
                    _agreedToTerms && !_isLoading ? _submitConsent : null,
                child: _isLoading
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Qəbul Edirəm və Davam Et'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
