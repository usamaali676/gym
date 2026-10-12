import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../providers.dart';

import 'pending_op.dart';

final pendingStoreProvider = Provider<PendingStore>((ref) => PrefsPendingStore(ref.watch(sharedPreferencesProvider)));

/// Where queued changes live between app launches. One list per account.
///
/// Deliberately **not** cleared on sign-out: these are the member's own changes that have not reached the server yet,
/// and silently deleting them would lose their workout. They stay scoped to their account (another account never sees
/// or sends them) and the sign-out screen warns when any are left.
abstract interface class PendingStore {
  List<PendingOp> read(String scope);

  Future<void> write(String scope, List<PendingOp> ops);

  /// Accounts (other than [scope]) that still have unsent changes on this phone.
  List<String> scopesWithChanges();
}

class PrefsPendingStore implements PendingStore {
  PrefsPendingStore(this._prefs);

  static const _prefix = 'ip.queue.';
  final SharedPreferences _prefs;

  @override
  List<PendingOp> read(String scope) {
    final raw = _prefs.getString('$_prefix$scope');
    if (raw == null) return const [];
    try {
      return [for (final j in jsonDecode(raw) as List) PendingOp.fromJson(Map<String, dynamic>.from(j as Map))];
    } catch (_) {
      // A list written by a different app version that no longer parses must not take the app down.
      return const [];
    }
  }

  @override
  Future<void> write(String scope, List<PendingOp> ops) async {
    if (ops.isEmpty) {
      await _prefs.remove('$_prefix$scope');
    } else {
      await _prefs.setString('$_prefix$scope', jsonEncode([for (final o in ops) o.toJson()]));
    }
  }

  @override
  List<String> scopesWithChanges() => [for (final k in _prefs.getKeys()) if (k.startsWith(_prefix)) k.substring(_prefix.length)];
}

/// Tests and previews.
class MemoryPendingStore implements PendingStore {
  final Map<String, List<PendingOp>> _lists = {};

  @override
  List<PendingOp> read(String scope) => List.of(_lists[scope] ?? const []);

  @override
  Future<void> write(String scope, List<PendingOp> ops) async {
    if (ops.isEmpty) {
      _lists.remove(scope);
    } else {
      _lists[scope] = List.of(ops);
    }
  }

  @override
  List<String> scopesWithChanges() => _lists.keys.toList();
}
