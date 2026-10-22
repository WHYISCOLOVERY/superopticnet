import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'core/security/secure_storage_service.dart';
import 'core/network/api_client.dart';
import 'core/theme/app_theme.dart';
import 'data/services/background_location_service.dart';
import 'data/services/app_presence_observer.dart';
import 'features/auth/login_screen.dart';
import 'features/home/role_home.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Lock to portrait orientation for field technicians
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  // Initialize native background location service
  try {
    await BackgroundLocationService.initializeService();
  } catch (_) {}

  // Determine initial route based on secure storage
  final token = await SecureStorageService.getAccessToken();
  var role = await SecureStorageService.getUserRole();

  Widget initialScreen = const LoginScreen();
  if (token != null && token.isNotEmpty) {
    // Do not block startup on a network request: saved role and cached GET
    // responses make the app useful after a tunnel/network outage.
    initialScreen = homeForEmployeeRole(role);
    ApiClient().syncCurrentIdentity();
  }

  runApp(SuperOpticOpsApp(initialScreen: initialScreen));
}

class SuperOpticOpsApp extends StatelessWidget {
  final Widget initialScreen;
  const SuperOpticOpsApp({super.key, required this.initialScreen});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'SuperOptic Ops',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      darkTheme: AppTheme.darkTheme,
      themeMode: ThemeMode.dark, // Default to OLED Dark Mode
      builder: (context, child) => AppPresenceObserver(
        child: SafeArea(
          top: false,
          left: false,
          right: false,
          bottom: true,
          child: child ?? const SizedBox.shrink(),
        ),
      ),
      home: initialScreen,
    );
  }
}
