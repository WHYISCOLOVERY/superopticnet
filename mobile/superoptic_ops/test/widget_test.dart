import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:superoptic_ops/main.dart';

void main() {
  testWidgets('SuperOptic app shell renders its initial screen',
      (tester) async {
    await tester.pumpWidget(
      const SuperOpticOpsApp(
        initialScreen: Scaffold(body: Text('SuperOptic test')),
      ),
    );

    expect(find.text('SuperOptic test'), findsOneWidget);
    expect(find.byType(MaterialApp), findsOneWidget);
  });

  testWidgets('app content stays above the Android bottom system area',
      (tester) async {
    tester.view.physicalSize = const Size(1080, 2340);
    tester.view.devicePixelRatio = 3;
    tester.view.padding = const FakeViewPadding(bottom: 96);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    addTearDown(tester.view.resetPadding);

    await tester.pumpWidget(
      const SuperOpticOpsApp(
        initialScreen: Scaffold(
          body: Align(
            alignment: Alignment.bottomCenter,
            child: SizedBox(
              key: ValueKey('bottom-action'),
              height: 52,
              child: Text('Alt əməliyyat'),
            ),
          ),
        ),
      ),
    );

    final actionBottom = tester
        .getBottomLeft(
          find.byKey(const ValueKey('bottom-action')),
        )
        .dy;
    expect(actionBottom, lessThanOrEqualTo(2340 / 3 - 96 / 3));
  });
}
