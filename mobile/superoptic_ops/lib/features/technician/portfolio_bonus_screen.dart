import 'package:flutter/material.dart';

import '../../core/constants/api_endpoints.dart';
import '../../core/network/api_client.dart';

class PortfolioBonusScreen extends StatefulWidget {
  const PortfolioBonusScreen({super.key});

  @override
  State<PortfolioBonusScreen> createState() => _PortfolioBonusScreenState();
}

class _PortfolioBonusScreenState extends State<PortfolioBonusScreen> {
  bool _isLoading = true;
  String _period = 'month';
  Map<String, dynamic> _data = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _isLoading = true);
    try {
      final response = await ApiClient().dio.get(
        ApiEndpoints.technicianPortfolioBonus,
        queryParameters: {'period': _period},
      );
      if (response.statusCode == 200 && response.data['success'] == true) {
        setState(
            () => _data = Map<String, dynamic>.from(response.data['data']));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  String _money(dynamic value) {
    final amount =
        value is num ? value.toDouble() : double.tryParse('$value') ?? 0;
    return '${amount.toStringAsFixed(2)} ₼';
  }

  int _int(dynamic value) {
    return value is num ? value.toInt() : int.tryParse('$value') ?? 0;
  }

  @override
  Widget build(BuildContext context) {
    final period = Map<String, dynamic>.from(_data['period'] ?? {});
    final portfolio = Map<String, dynamic>.from(_data['portfolio'] ?? {});
    final bonus = Map<String, dynamic>.from(_data['bonus'] ?? {});
    final score = Map<String, dynamic>.from(_data['score'] ?? {});
    final riskCustomers = List<dynamic>.from(_data['risk_customers'] ?? []);
    final bonusItems = List<dynamic>.from(bonus['items'] ?? []);
    final notifications = List<dynamic>.from(bonus['notifications'] ?? []);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Portfel və bonus'),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  SegmentedButton<String>(
                    segments: const [
                      ButtonSegment(
                        value: 'month',
                        label: Text('Ay'),
                        icon: Icon(Icons.calendar_month),
                      ),
                      ButtonSegment(
                        value: 'year',
                        label: Text('İl'),
                        icon: Icon(Icons.date_range),
                      ),
                    ],
                    selected: {_period},
                    onSelectionChanged: (selection) {
                      setState(() => _period = selection.first);
                      _load();
                    },
                  ),
                  const SizedBox(height: 12),
                  Text(
                    '${period['label'] ?? ''} · ${period['start'] ?? ''} — ${period['end'] ?? ''}',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white70, fontSize: 12),
                  ),
                  const SizedBox(height: 16),
                  _sectionTitle('Mənim abunəçi portfelim'),
                  GridView.count(
                    crossAxisCount: 2,
                    childAspectRatio: 1.35,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    crossAxisSpacing: 12,
                    mainAxisSpacing: 12,
                    children: [
                      _metricCard(
                        'Cəmi müştəri',
                        '${portfolio['total_customers'] ?? 0}',
                        Icons.people_alt_outlined,
                        const Color(0xFF00BCD4),
                      ),
                      _metricCard(
                        'Aktiv müştəri',
                        '${portfolio['active_customers'] ?? 0}',
                        Icons.verified_user_outlined,
                        const Color(0xFF00E676),
                      ),
                      _metricCard(
                        'Problemli',
                        '${portfolio['problem_customers'] ?? 0}',
                        Icons.report_problem_outlined,
                        Colors.orangeAccent,
                      ),
                      _metricCard(
                        'Borc riski',
                        _money(portfolio['total_debt_azn']),
                        Icons.money_off_outlined,
                        Colors.redAccent,
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),
                  _sectionTitle('Bonus və maaş xülasəsi'),
                  _bonusCard(bonus),
                  const SizedBox(height: 12),
                  _scoreCard(score),
                  if (notifications.isNotEmpty) ...[
                    const SizedBox(height: 20),
                    _sectionTitle('Bonus xəbərdarlıqları'),
                    ...notifications.map((item) => _notificationTile(
                          Map<String, dynamic>.from(item as Map),
                        )),
                  ],
                  if (bonusItems.isNotEmpty) ...[
                    const SizedBox(height: 20),
                    _sectionTitle('Son bonus sətrləri'),
                    ...bonusItems.map((item) => _bonusItemTile(
                          Map<String, dynamic>.from(item as Map),
                        )),
                  ],
                  const SizedBox(height: 20),
                  _sectionTitle('Nəzarətə götürülməli müştərilər'),
                  if (riskCustomers.isEmpty)
                    const Card(
                      child: Padding(
                        padding: EdgeInsets.all(16),
                        child: Text(
                            'Hazırda borc, açıq problem və ya deaktiv status riski görünmür.'),
                      ),
                    )
                  else
                    ...riskCustomers.map((item) => _riskCustomerTile(
                          Map<String, dynamic>.from(item as Map),
                        )),
                ],
              ),
            ),
    );
  }

  Widget _sectionTitle(String title) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Text(
        title,
        style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
      ),
    );
  }

  Widget _metricCard(String title, String value, IconData icon, Color color) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFF112240),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withValues(alpha: 0.25)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(icon, color: color),
          const SizedBox(height: 10),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
                fontSize: 21, fontWeight: FontWeight.w900, color: color),
          ),
          const SizedBox(height: 4),
          Text(title,
              style: const TextStyle(fontSize: 12, color: Colors.white70)),
        ],
      ),
    );
  }

  Widget _bonusCard(Map<String, dynamic> bonus) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            _amountRow('Sabit maaş', _money(bonus['base_salary'])),
            _amountRow(
                'Yeni qoşulma bonusu', _money(bonus['new_connection_bonus'])),
            _amountRow('Aktiv ödəniş bonusu', _money(bonus['recurring_bonus'])),
            _amountRow('Əl ilə düzəliş', _money(bonus['manual_adjustment'])),
            const Divider(height: 24),
            _amountRow(
              'Cəmi hesablanan',
              _money(bonus['total_payable']),
              strong: true,
              color: const Color(0xFF00E676),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: Text(
                'Bonusda sayılan aktiv müştəri: ${bonus['active_customer_count'] ?? 0}',
                style: const TextStyle(color: Colors.white70, fontSize: 12),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _scoreCard(Map<String, dynamic> score) {
    final scoreValue = _int(score['score']);
    final scoreColor = scoreValue >= 90
        ? const Color(0xFF00E676)
        : scoreValue >= 70
            ? Colors.orangeAccent
            : Colors.redAccent;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: scoreColor.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: scoreColor.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          Icon(Icons.workspace_premium_outlined, color: scoreColor, size: 32),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('İllik performans skoru: $scoreValue/100',
                    style: TextStyle(
                        color: scoreColor, fontWeight: FontWeight.bold)),
                const SizedBox(height: 4),
                Text(
                  'Mükafat: ${score['reward_points'] ?? 0} · Cərimə: ${score['penalty_points'] ?? 0} · Hadisə: ${score['event_count'] ?? 0}',
                  style: const TextStyle(fontSize: 12, color: Colors.white70),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _amountRow(String label, String value,
      {bool strong = false, Color? color}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          Expanded(
              child: Text(label,
                  style: TextStyle(
                      fontWeight:
                          strong ? FontWeight.bold : FontWeight.normal))),
          Text(
            value,
            style: TextStyle(
              color: color,
              fontWeight: strong ? FontWeight.w900 : FontWeight.w600,
              fontSize: strong ? 18 : 14,
            ),
          ),
        ],
      ),
    );
  }

  Widget _notificationTile(Map<String, dynamic> item) {
    return Card(
      child: ListTile(
        leading: const Icon(Icons.notifications_active_outlined,
            color: Colors.amber),
        title: Text('${item['message'] ?? ''}'),
        subtitle: Text(
            'Status: ${item['status'] ?? '-'} · Vaxt: ${item['due_at'] ?? '-'}'),
      ),
    );
  }

  Widget _bonusItemTile(Map<String, dynamic> item) {
    return Card(
      child: ListTile(
        leading: const Icon(Icons.payments_outlined, color: Color(0xFF00BCD4)),
        title: Text(
            '${item['customer_name'] ?? item['subscriber_code'] ?? 'Bonus sətri'}'),
        subtitle: Text('${item['reason'] ?? ''} · ${item['status'] ?? ''}'),
        trailing: Text(
          _money(item['amount']),
          style: const TextStyle(
              fontWeight: FontWeight.bold, color: Color(0xFF00E676)),
        ),
      ),
    );
  }

  Widget _riskCustomerTile(Map<String, dynamic> customer) {
    final reasons = List<dynamic>.from(customer['risk_reasons'] ?? []);
    final activeTickets = _int(customer['active_ticket_count']);
    final debt = _money(customer['debt_azn']);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.person_search_outlined,
                    color: Colors.orangeAccent),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    '${customer['name'] ?? ''}',
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
                Text(debt,
                    style: const TextStyle(
                        color: Colors.redAccent, fontWeight: FontWeight.bold)),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              'Kod: ${customer['subscriber_code'] ?? '-'} · ${customer['area'] ?? '-'} · Problem: $activeTickets',
              style: const TextStyle(fontSize: 12, color: Colors.white70),
            ),
            if ('${customer['latest_ticket_subject'] ?? ''}'.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(
                'Son problem: ${customer['latest_ticket_subject']}',
                style: const TextStyle(fontSize: 12),
              ),
            ],
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: reasons
                  .map((reason) => Chip(
                        label: Text('$reason'),
                        visualDensity: VisualDensity.compact,
                        backgroundColor:
                            Colors.orangeAccent.withValues(alpha: 0.16),
                      ))
                  .toList(),
            ),
          ],
        ),
      ),
    );
  }
}
