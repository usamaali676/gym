import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';

/// The launcher icons and the launch screen are generated from the website's icon (scripts/export-mobile-icons.mjs).
/// These checks fail if a template reset or a wrong export puts the Flutter defaults, a white launch flash or a
/// transparent App Store icon back.
({int width, int height, int colorType}) pngInfo(File f) {
  final b = f.readAsBytesSync();
  expect(b.sublist(1, 4), 'PNG'.codeUnits, reason: '${f.path} is a PNG');
  final d = ByteData.sublistView(b);
  return (width: d.getUint32(16), height: d.getUint32(20), colorType: b[25]);
}

void main() {
  test('every iOS icon exists at the size its slot asks for, and none has transparency (the App Store rejects that)', () {
    const dir = 'ios/Runner/Assets.xcassets/AppIcon.appiconset';
    final contents = jsonDecode(File('$dir/Contents.json').readAsStringSync()) as Map<String, dynamic>;
    final images = (contents['images'] as List).cast<Map<String, dynamic>>();
    expect(images.length, greaterThanOrEqualTo(15));
    for (final i in images) {
      final side = double.parse((i['size'] as String).split('x').first);
      final scale = double.parse((i['scale'] as String).replaceAll('x', ''));
      final info = pngInfo(File('$dir/${i['filename']}'));
      expect((info.width, info.height), ((side * scale).round(), (side * scale).round()), reason: i['filename'] as String);
      expect(info.colorType, 2, reason: '${i['filename']} must be RGB without an alpha channel');
    }
  });

  test('every Android density has a launcher icon and an adaptive-icon layer of the right size', () {
    const sizes = {'mdpi': 48, 'hdpi': 72, 'xhdpi': 96, 'xxhdpi': 144, 'xxxhdpi': 192};
    for (final e in sizes.entries) {
      final icon = pngInfo(File('android/app/src/main/res/mipmap-${e.key}/ic_launcher.png'));
      expect((icon.width, icon.height), (e.value, e.value), reason: e.key);
      final layer = pngInfo(File('android/app/src/main/res/mipmap-${e.key}/ic_launcher_foreground.png'));
      expect((layer.width, layer.height), (e.value * 108 ~/ 48, e.value * 108 ~/ 48), reason: '${e.key} adaptive layer is 108 dp');
    }
    final adaptive = File('android/app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml').readAsStringSync();
    expect(adaptive, allOf(contains('<adaptive-icon'), contains('@color/ic_launcher_background'), contains('@mipmap/ic_launcher_foreground'), contains('<monochrome')));
    expect(File('android/app/src/main/res/values/ic_launcher_background.xml').readAsStringSync(), contains('#07090A'));
  });

  test('the app does not open with a white flash: the launch screens use the brand background on both platforms', () {
    final android = File('android/app/src/main/res/drawable/launch_background.xml').readAsStringSync();
    expect(android, isNot(contains('@android:color/white')));
    expect(android, contains('@color/ic_launcher_background'));
    final storyboard = File('ios/Runner/Base.lproj/LaunchScreen.storyboard').readAsStringSync();
    expect(storyboard, isNot(contains('red="1" green="1" blue="1"')));
    expect(storyboard, contains('red="0.027'));
  });

  test('the Flutter placeholder name and logo are gone', () {
    expect(File('android/app/src/main/AndroidManifest.xml').readAsStringSync(), contains('android:label="IronPulse"'));
    expect(File('ios/Runner/Info.plist').readAsStringSync(), contains('IronPulse'));
  });
}
