import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

/// The store address comes from the server. The app is an untrusted-input boundary too: whatever the server (or someone who
/// got into its admin) puts there, only a plain `https://host/...` address is ever opened. `tel:`, `sms:`, `intent:`,
/// `javascript:`, `file:`, custom app schemes and cleartext `http:` are refused, and a malformed string never throws.
Uri? safeStoreUri(String? raw) {
  if (raw == null) return null;
  final text = raw.trim();
  if (text.isEmpty || text.length > 2048) return null;
  final uri = Uri.tryParse(text);
  if (uri == null || uri.scheme.toLowerCase() != 'https' || uri.host.isEmpty || uri.userInfo.isNotEmpty) return null;
  return uri;
}

/// Opening an address outside the app; replaced in tests.
typedef UrlOpener = Future<bool> Function(Uri uri);

final urlOpenerProvider = Provider<UrlOpener>((ref) => (uri) => launchUrl(uri, mode: LaunchMode.externalApplication));

/// Opens the store page if the address is acceptable; otherwise says so instead of doing anything.
Future<void> openStoreLink(BuildContext context, WidgetRef ref, String? raw) async {
  final uri = safeStoreUri(raw);
  var opened = false;
  if (uri != null) {
    try {
      opened = await ref.read(urlOpenerProvider)(uri);
    } catch (_) {
      opened = false;
    }
  }
  if (!opened && context.mounted) {
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('The store page could not be opened. Look for IronPulse in your app store.')));
  }
}
