import 'dart:async';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// Widget tests normally draw every glyph as a square (the "Ahem" test font). Load the real brand fonts and the
/// Material icon font so layout, overflow checks and golden images match what a phone shows.
Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  TestWidgetsFlutterBinding.ensureInitialized();

  const families = {
    'Orbitron': ['orbitron-500', 'orbitron-700', 'orbitron-900'],
    'Rajdhani': ['rajdhani-600', 'rajdhani-700'],
    'Inter': ['inter-400', 'inter-500', 'inter-600', 'inter-700'],
  };
  for (final entry in families.entries) {
    final loader = FontLoader(entry.key);
    for (final file in entry.value) {
      loader.addFont(_bytes('assets/fonts/$file.ttf'));
    }
    await loader.load();
  }

  final flutterRoot = Platform.environment['FLUTTER_ROOT'];
  if (flutterRoot != null) {
    final icons = File('$flutterRoot/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf');
    if (icons.existsSync()) {
      final loader = FontLoader('MaterialIcons')..addFont(_bytes(icons.path));
      await loader.load();
    }
  }

  await testMain();
}

Future<ByteData> _bytes(String path) async {
  final data = await File(path).readAsBytes();
  return ByteData.sublistView(data);
}
