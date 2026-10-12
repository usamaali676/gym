import '../../../core/policy/app_config.dart';

/// Client-side checks that only save a round trip. The server validates everything again and its messages win.
abstract final class AuthValidators {
  static final _email = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  static String? email(String? v) {
    final value = (v ?? '').trim();
    if (value.isEmpty) return 'Enter your email address.';
    if (!_email.hasMatch(value)) return 'That does not look like an email address.';
    return null;
  }

  static String? name(String? v) {
    final value = (v ?? '').trim();
    if (value.isEmpty) return 'Enter your name.';
    if (value.length > 120) return 'Keep your name under 120 characters.';
    return null;
  }

  /// The password rule comes from the server (`/app/config` -> `auth.password`); the server checks it again.
  static String? newPassword(String? v, [PasswordPolicy policy = PasswordPolicy.fallback]) => policy.check(v ?? '');
}
