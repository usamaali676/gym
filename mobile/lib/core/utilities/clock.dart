import 'package:flutter_riverpod/flutter_riverpod.dart';

typedef Clock = DateTime Function();

/// The wall clock, in UTC. Timers are derived from timestamps taken with this, so tests can drive time by overriding
/// the provider instead of waiting.
final clockProvider = Provider<Clock>((ref) => () => DateTime.now().toUtc());
