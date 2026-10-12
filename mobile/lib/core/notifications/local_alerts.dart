import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:timezone/timezone.dart' as tz;

/// Notifications the phone shows by itself at a time the app chose in advance (no server, no push service). Behind an
/// interface so tests do not need platform channels.
abstract interface class LocalAlerts {
  /// Whether the system lets this app show notifications right now.
  Future<bool> isAllowed();

  /// Shows the system's permission prompt if it has not been answered; returns whether notifications are allowed after it.
  Future<bool> requestPermission();

  /// Whether an alert can be delivered at its exact time. Always true where the platform has no such restriction.
  Future<bool> hasExactTiming();

  /// Opens the system screen where the member can allow exact timing (Android 12+).
  Future<void> requestExactTiming();

  Future<void> schedule({required int id, required DateTime at, required String title, required String body});

  Future<void> cancel(int id);
}

/// NOT run on a device in this repository's CI: the thin layer over `flutter_local_notifications`. It compiles for both
/// platforms; the scheduling, the permission prompts and the delivery time are unconfirmed on real phones.
class PlatformLocalAlerts implements LocalAlerts {
  PlatformLocalAlerts({FlutterLocalNotificationsPlugin? plugin}) : _plugin = plugin ?? FlutterLocalNotificationsPlugin();

  static const channelId = 'rest_timer';
  final FlutterLocalNotificationsPlugin _plugin;
  Future<void>? _ready;

  bool get _android => !kIsWeb && Platform.isAndroid;
  bool get _ios => !kIsWeb && Platform.isIOS;

  Future<void> _init() => _ready ??= _plugin.initialize(
        settings: const InitializationSettings(
          android: AndroidInitializationSettings('@mipmap/ic_launcher'),
          // Nothing is asked at start-up: the permission prompt appears when the member turns the alert on.
          iOS: DarwinInitializationSettings(requestAlertPermission: false, requestBadgePermission: false, requestSoundPermission: false),
        ),
      );

  AndroidFlutterLocalNotificationsPlugin? get _androidPlugin => _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
  IOSFlutterLocalNotificationsPlugin? get _iosPlugin => _plugin.resolvePlatformSpecificImplementation<IOSFlutterLocalNotificationsPlugin>();

  @override
  Future<bool> isAllowed() async {
    await _init();
    if (_android) return await _androidPlugin?.areNotificationsEnabled() ?? false;
    if (_ios) return (await _iosPlugin?.checkPermissions())?.isEnabled ?? false;
    return false;
  }

  @override
  Future<bool> requestPermission() async {
    await _init();
    if (_android) return await _androidPlugin?.requestNotificationsPermission() ?? false;
    if (_ios) return await _iosPlugin?.requestPermissions(alert: true, sound: true) ?? false;
    return false;
  }

  @override
  Future<bool> hasExactTiming() async {
    if (!_android) return true;
    await _init();
    return await _androidPlugin?.canScheduleExactNotifications() ?? false;
  }

  @override
  Future<void> requestExactTiming() async {
    if (!_android) return;
    await _init();
    await _androidPlugin?.requestExactAlarmsPermission();
  }

  @override
  Future<void> schedule({required int id, required DateTime at, required String title, required String body}) async {
    await _init();
    // A rest of a minute or two is useless if it arrives five minutes late, so exact timing is used whenever the member has
    // allowed it; otherwise the system may delay the alert (and the Account screen says so).
    final exact = await hasExactTiming();
    await _plugin.zonedSchedule(
      id: id,
      title: title,
      body: body,
      scheduledDate: tz.TZDateTime.from(at, tz.UTC), // an instant, so no time-zone database is needed
      notificationDetails: const NotificationDetails(
        android: AndroidNotificationDetails(
          channelId,
          'Rest timer',
          channelDescription: 'Tells you when your rest between sets is over while the app is in the background',
          importance: Importance.high,
          priority: Priority.high,
          category: AndroidNotificationCategory.alarm,
          autoCancel: true,
        ),
        iOS: DarwinNotificationDetails(presentAlert: true, presentSound: true),
      ),
      androidScheduleMode: exact ? AndroidScheduleMode.exactAllowWhileIdle : AndroidScheduleMode.inexactAllowWhileIdle,
    );
  }

  @override
  Future<void> cancel(int id) async {
    await _init();
    await _plugin.cancel(id: id);
  }
}

final localAlertsProvider = Provider<LocalAlerts>((ref) => PlatformLocalAlerts());
