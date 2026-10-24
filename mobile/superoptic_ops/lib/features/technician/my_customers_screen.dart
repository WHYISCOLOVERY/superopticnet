import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';

class MyCustomersScreen extends StatefulWidget {
  const MyCustomersScreen({super.key});

  @override
  State<MyCustomersScreen> createState() => _MyCustomersScreenState();
}

class _MyCustomersScreenState extends State<MyCustomersScreen> {
  final _searchCtrl = TextEditingController();
  bool _isLoading = true;
  bool _debtOnly = false;
  List<dynamic> _customers = [];
  int _totalCount = 0;

  @override
  void initState() {
    super.initState();
    _fetchCustomers();
  }

  Future<void> _fetchCustomers() async {
    setState(() => _isLoading = true);
    try {
      final res = await ApiClient().dio.get(
        ApiEndpoints.myCustomers,
        queryParameters: {
          'debt_only': _debtOnly ? 1 : 0,
          'q': _searchCtrl.text.trim(),
        },
      );
      if (res.statusCode == 200 && res.data['success'] == true) {
        setState(() {
          _customers = res.data['data']['customers'] as List<dynamic>;
          _totalCount = res.data['data']['total'] as int;
        });
      }
    } catch (_) {
      // offline fallback
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _callCustomer(String phone) async {
    final uri = Uri.parse('tel:$phone');
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Mənim Müştərilərim ($_totalCount)'),
        actions: [
          IconButton(
              icon: const Icon(Icons.refresh), onPressed: _fetchCustomers),
        ],
      ),
      body: Column(
        children: [
          // Search & Filter
          Padding(
            padding: const EdgeInsets.all(16.0),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _searchCtrl,
                    onSubmitted: (_) => _fetchCustomers(),
                    decoration: InputDecoration(
                      hintText: 'Ad, telefon və ya abunəçi kodu...',
                      prefixIcon: const Icon(Icons.search),
                      suffixIcon: _searchCtrl.text.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear),
                              onPressed: () {
                                _searchCtrl.clear();
                                _fetchCustomers();
                              },
                            )
                          : null,
                      border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12)),
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 12),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                FilterChip(
                  label: const Text('Yalnız Borclular'),
                  selected: _debtOnly,
                  selectedColor: Colors.redAccent.withValues(alpha: 0.3),
                  onSelected: (val) {
                    setState(() => _debtOnly = val);
                    _fetchCustomers();
                  },
                ),
              ],
            ),
          ),

          // Customer List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _customers.isEmpty
                    ? const Center(
                        child: Text('Abunəçi tapılmadı.',
                            style: TextStyle(color: Colors.grey)))
                    : RefreshIndicator(
                        onRefresh: _fetchCustomers,
                        child: ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 16),
                          itemCount: _customers.length,
                          itemBuilder: (context, index) {
                            final c = _customers[index] as Map<String, dynamic>;
                            final bool hasDebt = c['has_debt'] == true;
                            final double debtAzn =
                                (c['debt_azn'] as num? ?? 0).toDouble();

                            return Card(
                              margin: const EdgeInsets.only(bottom: 12),
                              child: ListTile(
                                contentPadding: const EdgeInsets.symmetric(
                                    horizontal: 16, vertical: 8),
                                leading: CircleAvatar(
                                  backgroundColor: hasDebt
                                      ? Colors.redAccent.withValues(alpha: 0.2)
                                      : const Color(0xFF00E676)
                                          .withValues(alpha: 0.2),
                                  child: Icon(
                                    hasDebt ? Icons.money_off : Icons.check,
                                    color: hasDebt
                                        ? Colors.redAccent
                                        : const Color(0xFF00E676),
                                  ),
                                ),
                                title: Text(
                                  c['name'] ?? '',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 15),
                                ),
                                subtitle: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const SizedBox(height: 4),
                                    Text(
                                        'Kod: ${c['subscriber_code']} | ${c['plan']}',
                                        style: const TextStyle(
                                            fontSize: 12, color: Colors.grey)),
                                    Text(c['address'] ?? '',
                                        style: const TextStyle(
                                            fontSize: 11,
                                            color: Colors.white70)),
                                    const SizedBox(height: 4),
                                    Text(
                                      hasDebt
                                          ? 'Borc: ${debtAzn.toStringAsFixed(2)} ₼'
                                          : 'Ödəniş qaydasındadır (Borcsuz)',
                                      style: TextStyle(
                                        fontSize: 12,
                                        fontWeight: FontWeight.bold,
                                        color: hasDebt
                                            ? Colors.redAccent
                                            : const Color(0xFF00E676),
                                      ),
                                    ),
                                  ],
                                ),
                                trailing: IconButton(
                                  icon: const Icon(Icons.phone,
                                      color: Color(0xFF00BCD4)),
                                  onPressed: () =>
                                      _callCustomer(c['phone'] ?? ''),
                                ),
                              ),
                            );
                          },
                        ),
                      ),
          ),
        ],
      ),
    );
  }
}
