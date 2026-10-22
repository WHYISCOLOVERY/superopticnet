import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:superoptic_ops/core/network/api_client.dart';
import 'package:superoptic_ops/features/finance/finance_screen.dart';

class _ReportAdapter implements HttpClientAdapter {
  @override
  void close({bool force = false}) {}
  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    return ResponseBody.fromString(
        jsonEncode({
          'data': {
            'period': {'start_date': '2026-10-05', 'end_date': '2026-10-05'},
            'kpis': [
              {'label': 'Gəlir', 'kind': 'money', 'value': 1234567.89}
            ],
            'daily': [],
            'entries': [],
          }
        }),
        200,
        headers: {
          Headers.contentTypeHeader: ['application/json']
        });
  }
}

void main() {
  testWidgets('finance remains usable at narrow width with large text',
      (tester) async {
    FlutterSecureStorage.setMockInitialValues({});
    final dio = ApiClient().dio;
    final original = dio.httpClientAdapter;
    dio.httpClientAdapter = _ReportAdapter();
    addTearDown(() => dio.httpClientAdapter = original);
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
        builder: (context, child) => MediaQuery(
            data: MediaQuery.of(context)
                .copyWith(textScaler: const TextScaler.linear(1.5)),
            child: child!),
        home: const FinanceScreen()));
    await tester.pumpAndSettle();
    expect(find.text('1234567.89 AZN'), findsOneWidget);
    expect(tester.takeException(), isNull);
    final create = find.text('Gəlir / xərc qeyd et');
    await tester.ensureVisible(create);
    await tester.tap(create);
    await tester.pumpAndSettle();
    final save = find.text('Qeydi saxla');
    await tester.ensureVisible(save);
    await tester.tap(save);
    await tester.pumpAndSettle();
    expect(find.text('Bu sahəni doldurun.'), findsWidgets);
    expect(tester.takeException(), isNull);
  });
}
