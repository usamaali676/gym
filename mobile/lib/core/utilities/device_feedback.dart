import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

/// Things the phone does for the member during a workout: a tap on set completion, a buzz when the rest is over,
/// and keeping the screen on. Behind an interface so tests do not need platform channels.
abstract interface class DeviceFeedback {
  void setLogged();
  void restOver({required bool sound});
  Future<void> keepScreenOn(bool on);
}

class PlatformDeviceFeedback implements DeviceFeedback {
  @override
  void setLogged() => HapticFeedback.mediumImpact();

  @override
  void restOver({required bool sound}) {
    HapticFeedback.heavyImpact();
    if (sound) SystemSound.play(SystemSoundType.alert);
  }

  @override
  Future<void> keepScreenOn(bool on) async {
    try {
      await (on ? WakelockPlus.enable() : WakelockPlus.disable());
    } catch (_) {
      // Not available on this device: the screen may dim, nothing else.
    }
  }
}

class SilentDeviceFeedback implements DeviceFeedback {
  final List<String> events = [];

  @override
  void setLogged() => events.add('setLogged');

  @override
  void restOver({required bool sound}) => events.add(sound ? 'restOver+sound' : 'restOver');

  @override
  Future<void> keepScreenOn(bool on) async => events.add(on ? 'awake' : 'sleep');
}

final deviceFeedbackProvider = Provider<DeviceFeedback>((ref) => PlatformDeviceFeedback());
