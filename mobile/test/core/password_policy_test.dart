import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/policy/app_config.dart';

void main() {
  test('reads the server policy and explains it', () {
    final p = PasswordPolicy.fromJson({'min': 10, 'letters': true, 'numbers': true});
    expect(p.hint, 'At least 10 characters, with letters and numbers.');
    expect(PasswordPolicy.fromJson({'min': 8, 'letters': false, 'numbers': false}).hint, 'At least 8 characters.');
    expect(PasswordPolicy.fromJson({'min': 8, 'letters': true, 'numbers': false}).hint, 'At least 8 characters, with letters.');
  });

  test('checks length, letters and numbers', () {
    const p = PasswordPolicy(min: 8, letters: true, numbers: true);
    expect(p.check('short1'), 'Use at least 8 characters.');
    expect(p.check('12345678'), 'Add at least one letter.');
    expect(p.check('abcdefgh'), 'Add at least one number.');
    expect(p.check('abcdefg1'), isNull);
    expect(p.check('żółćżółć9'), isNull, reason: 'non-ASCII letters count');
  });

  test('before the policy is known only the length is checked', () {
    expect(PasswordPolicy.fallback.check('abcdefgh'), isNull);
    expect(PasswordPolicy.fromJson(null).check('abc'), 'Use at least 8 characters.');
  });
}
