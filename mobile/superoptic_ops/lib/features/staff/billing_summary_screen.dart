import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_theme.dart';

class BillingSummaryScreen extends StatelessWidget {
  const BillingSummaryScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Billing icmalı')),
        body: const BillingSummaryView(),
      );
}

class BillingSummaryView extends StatefulWidget {
  const BillingSummaryView({super.key});

  @override
  State<BillingSummaryView> createState() => _BillingSummaryViewState();
}

class _BillingSummaryViewState extends State<BillingSummaryView>
    with AutomaticKeepAliveClientMixin {
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _metrics = {};
  Map<String, dynamic> _period = {};
  Map<String, dynamic> _definitions = {};
  String _generatedAt = '';
  Timer? _refreshTimer;

  @override
  bool get wantKeepAlive => true;

  @override
  void initState() {
    super.initState();
    _load();
    _refreshTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      _load(silent: true);
    });
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (mounted && !silent) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final response =
          await ApiClient().dio.get(ApiEndpoints.staffBillingSummary);
      final data = Map<String, dynamic>.from(response.data['data'] as Map);
      if (!mounted) return;
      setState(() {
        _metrics = Map<String, dynamic>.from(data['metrics'] as Map? ?? {});
        _period = Map<String, dynamic>.from(data['period'] as Map? ?? {});
        _definitions =
            Map<String, dynamic>.from(data['definitions'] as Map? ?? {});
        _generatedAt = '${data['generated_at'] ?? ''}';
      });
    } catch (_) {
      if (mounted && !silent) {
        setState(() => _error = 'Billing göstəriciləri yüklənmədi.');
      }
    } finally {
      if (mounted && !silent) setState(() => _loading = false);
    }
  }

  int _value(String key) => (_metrics[key] as num?)?.toInt() ?? 0;

  @override
  Widget build(BuildContext context) {
    super.build(context);
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.cloud_off_outlined,
                size: 44, color: AppTheme.danger),
            const SizedBox(height: 12),
            Text(_error!, textAlign: TextAlign.center),
            const SizedBox(height: 14),
            FilledButton.icon(
              onPressed: _load,
              icon: const Icon(Icons.refresh),
              label: const Text('Yenidən yoxla'),
            ),
          ]),
        ),
      );
    }

    final total = _value('total_customers');
    final paid = _value('paid_customers');
    final paidRatio = total == 0 ? 0.0 : (paid / total).clamp(0.0, 1.0);
    final startsAt = '${_period['starts_at'] ?? ''}'.split(' ').first;
    final endsAt = '${_period['ends_at'] ?? ''}'.split(' ').first;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: EdgeInsets.fromLTRB(
            18, 16, 18, 28 + MediaQuery.paddingOf(context).bottom),
        children: [
          Semantics(
            container: true,
            label:
                'Billing göstəriciləri, $startsAt tarixindən $endsAt tarixinə',
            child: Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(24),
                gradient: const LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [Color(0xFF064E3B), Color(0xFF10221D)],
                ),
                border:
                    Border.all(color: AppTheme.emerald.withValues(alpha: .45)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Row(children: [
                    Icon(Icons.account_balance_wallet_outlined,
                        color: AppTheme.emerald),
                    SizedBox(width: 10),
                    Text('Cari ayın ödəniş vəziyyəti',
                        style: TextStyle(fontWeight: FontWeight.w900)),
                  ]),
                  const SizedBox(height: 14),
                  Row(children: [
                    Text('$paid',
                        style: const TextStyle(
                            fontSize: 34,
                            fontWeight: FontWeight.w900,
                            letterSpacing: -1)),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text('müştəri ödəniş edib\n$startsAt – $endsAt',
                          style: const TextStyle(
                              color: Color(0xFFD1FAE5), height: 1.35)),
                    ),
                  ]),
                  const SizedBox(height: 14),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(99),
                    child: LinearProgressIndicator(
                      value: paidRatio,
                      minHeight: 8,
                      color: AppTheme.emerald,
                      backgroundColor: Colors.white.withValues(alpha: .12),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          GridView.count(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            crossAxisCount: 2,
            crossAxisSpacing: 12,
            mainAxisSpacing: 12,
            childAspectRatio: 1.18,
            children: [
              _metricCard('Ümumi müştəri', total, Icons.groups_2_outlined,
                  AppTheme.primary),
              _metricCard('Bu ay ödəmiş', paid, Icons.verified_outlined,
                  AppTheme.success),
              _metricCard('Deaktiv', _value('deactivated_customers'),
                  Icons.person_off_outlined, AppTheme.danger),
              _metricCard('İndi online', _value('online_customers'),
                  Icons.wifi_tethering, AppTheme.emerald),
            ],
          ),
          const SizedBox(height: 18),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Göstəricilər necə hesablanır?',
                      style: TextStyle(fontWeight: FontWeight.w900)),
                  const SizedBox(height: 10),
                  _definition(Icons.payments_outlined,
                      '${_definitions['paid_customers'] ?? ''}'),
                  _definition(Icons.person_off_outlined,
                      '${_definitions['deactivated_customers'] ?? ''}'),
                  _definition(Icons.router_outlined,
                      '${_definitions['online_customers'] ?? ''}'),
                ],
              ),
            ),
          ),
          if (_generatedAt.isNotEmpty) ...[
            const SizedBox(height: 12),
            Text('Son yenilənmə: $_generatedAt',
                textAlign: TextAlign.center,
                style:
                    const TextStyle(color: AppTheme.mutedText, fontSize: 12)),
          ],
        ],
      ),
    );
  }

  Widget _metricCard(String label, int value, IconData icon, Color color) =>
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppTheme.panel,
          borderRadius: BorderRadius.circular(22),
          border: Border.all(color: color.withValues(alpha: .42)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                  color: color.withValues(alpha: .14),
                  borderRadius: BorderRadius.circular(13)),
              child: Icon(icon, color: color),
            ),
            Text('$value',
                style: const TextStyle(
                    fontSize: 30,
                    fontWeight: FontWeight.w900,
                    letterSpacing: -1)),
            Text(label,
                maxLines: 2,
                style: const TextStyle(
                    color: AppTheme.mutedText, fontWeight: FontWeight.w700)),
          ],
        ),
      );

  Widget _definition(IconData icon, String text) {
    if (text.trim().isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, size: 19, color: AppTheme.accent),
        const SizedBox(width: 10),
        Expanded(
          child: Text(text,
              style: const TextStyle(color: AppTheme.mutedText, height: 1.35)),
        ),
      ]),
    );
  }
}
