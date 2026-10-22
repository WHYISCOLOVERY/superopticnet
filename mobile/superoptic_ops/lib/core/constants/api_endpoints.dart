class ApiEndpoints {
  // Base server URL (configured for production gateway or staging)
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://mgt.superoptic.net/api/mobile/v1',
  );
  static const String localDevUrl = 'http://10.0.2.2/api/mobile/v1';

  // Auth & System
  static const String login = '/auth/login.php';
  static const String refreshToken = '/auth/refresh-token.php';
  static const String profile = '/auth/profile.php';
  static const String registerDeviceToken = '/auth/device-token.php';
  static const String legalConsent = '/auth/legal-consent.php';
  static const String revokeDevice = '/auth/revoke-device.php';
  static const String versionCheck = '/app/version-check.php';

  // Location / Fleet
  static const String pingLocation = '/location/ping.php';
  static const String locationStatus = '/location/status.php';
  static const String dutyStatus = '/location/duty.php';
  static const String presenceHeartbeat = '/presence/heartbeat.php';
  static const String attendanceStatus = '/attendance/status.php';
  static const String attendanceEvent = '/attendance/event.php';

  // Technician & Field Dispatch
  static const String technicianDashboard = '/technician/dashboard.php';
  static const String technicianTasks = '/technician/tasks.php';
  static const String taskStart = '/technician/task-start.php';
  static const String taskProgress = '/technician/task-progress.php';
  static const String requestOtp = '/technician/request-otp.php';
  static const String taskResolve = '/technician/task-resolve.php';
  static const String myCustomers = '/technician/my-customers.php';
  static const String technicianPortfolioBonus =
      '/technician/portfolio-bonus.php';

  // Manager Operations
  static const String managerDashboard = '/manager/dashboard.php';
  static const String managerFleetLive = '/manager/fleet-live.php';
  static const String managerAssignTask = '/manager/assign-task.php';
  static const String managerCreateTask = '/manager/create-task.php';
  static const String managerTaskBoard = '/manager/task-board.php';
  static const String managerReviewProof = '/manager/review-proof.php';
  static const String managerGeofences = '/manager/geofences.php';
  static const String managerAttendanceReport =
      '/manager/attendance-report.php';
  static const String managerSupervisionReport =
      '/manager/supervision-report.php';
  static const String managerAccountSecurity = '/manager/account-security.php';
  static const String managerOperatorPresence =
      '/manager/operator-presence.php';

  // Operator / Engineer Operations
  static const String staffDashboard = '/staff/dashboard.php';
  static const String staffModemSearch = '/staff/modem-search.php';
  static const String staffModemLink = '/staff/modem-link.php';
  static const String staffTickets = '/staff/tickets.php';
  static const String staffNocMonitor = '/staff/noc-monitor.php';
  static const String staffBillingSummary = '/staff/billing-summary.php';

  // Alerts & preferences
  static const String notificationFeed = '/notifications/feed.php';
  static const String notificationPreferences =
      '/notifications/preferences.php';

  // Customer Portal
  static const String customerOverview = '/customer/overview.php';
  static const String customerLineDiagnostics =
      '/customer/line-diagnostics.php';
}
