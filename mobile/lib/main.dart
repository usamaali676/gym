import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app/app.dart';
import 'app/config/environment.dart';
import 'core/bootstrap.dart';
import 'core/providers.dart';
import 'core/storage/secure_store.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp, DeviceOrientation.portraitDown]);
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.light,
    statusBarBrightness: Brightness.dark,
    systemNavigationBarColor: Color(0xFF0D1210),
    systemNavigationBarIconBrightness: Brightness.light,
  ));

  final env = await AppEnvironment.load();
  final prefs = await SharedPreferences.getInstance();
  await wipeStaleSecretsOnFreshInstall(prefs, FlutterSecureStore());

  runApp(ProviderScope(
    overrides: [
      appEnvironmentProvider.overrideWithValue(env),
      sharedPreferencesProvider.overrideWithValue(prefs),
    ],
    child: const IronPulseApp(),
  ));
}
