import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/router.dart';
import 'package:ironpulse/core/policy/app_config.dart';
import 'package:ironpulse/core/policy/policy_controller.dart';
import 'package:ironpulse/features/authentication/domain/auth_user.dart';
import 'package:ironpulse/features/authentication/presentation/auth_controller.dart';

import '../support/fake_adapter.dart';

AuthUser user({bool onboarded = true}) => AuthUser.fromJson(userJson(onboarded: onboarded));

AppConfig config({String status = 'ok', bool maintenance = false}) => AppConfig.fromJson(configJson(status: status, maintenance: maintenance));

String? go(AuthState auth, String location, {PolicyState policy = const PolicyState()}) => appRedirect(auth: auth, policy: policy, location: location);

void main() {
  group('signed out', () {
    const out = SignedOut();

    test('may see welcome, login, register and forgot-password', () {
      for (final p in Routes.signedOutPages) {
        expect(go(out, p), isNull, reason: p);
      }
    });

    test('is sent to welcome from anything that needs an account', () {
      for (final p in [Routes.home, Routes.account, Routes.onboarding, Routes.splash, '/workouts/1']) {
        expect(go(out, p), Routes.welcome, reason: p);
      }
    });
  });

  group('while the session is being checked', () {
    test('everything waits on the splash screen', () {
      expect(go(const AuthInitializing(), Routes.home), Routes.splash);
      expect(go(const AuthInitializing(), Routes.login), Routes.splash);
      expect(go(const AuthInitializing(), Routes.splash), isNull);
    });
  });

  group('signed in', () {
    test('a member who has not finished onboarding can only see onboarding', () {
      final auth = SignedIn(user(onboarded: false));
      expect(go(auth, Routes.home), Routes.onboarding);
      expect(go(auth, Routes.account), Routes.onboarding);
      expect(go(auth, Routes.onboarding), isNull);
    });

    test('an onboarded member is moved off the auth pages and onto home', () {
      final auth = SignedIn(user());
      for (final p in [Routes.welcome, Routes.login, Routes.register, Routes.splash, Routes.onboarding]) {
        expect(go(auth, p), Routes.home, reason: p);
      }
      expect(go(auth, Routes.home), isNull);
      expect(go(auth, Routes.account), isNull);
    });
  });

  group('server policy overrides everything', () {
    test('maintenance wins over a signed-in member', () {
      final policy = PolicyState(config: config(maintenance: true));
      expect(go(SignedIn(user()), Routes.home, policy: policy), Routes.maintenance);
      expect(go(const SignedOut(), Routes.login, policy: policy), Routes.maintenance);
      expect(go(SignedIn(user()), Routes.maintenance, policy: policy), isNull);
    });

    test('a 503 maintenance seen by any call also forces the maintenance screen', () {
      const policy = PolicyState(forcedMaintenance: Maintenance(message: 'Back soon'));
      expect(go(SignedIn(user()), Routes.home, policy: policy), Routes.maintenance);
    });

    test('an unsupported version forces the update screen, from any state', () {
      final policy = PolicyState(config: config(status: 'update_required'));
      expect(go(SignedIn(user()), Routes.home, policy: policy), Routes.updateRequired);
      expect(go(const SignedOut(), Routes.welcome, policy: policy), Routes.updateRequired);
      expect(go(const AuthInitializing(), Routes.splash, policy: policy), Routes.updateRequired);
      expect(go(SignedIn(user()), Routes.updateRequired, policy: policy), isNull);
    });

    test('a 426 seen by any call forces the update screen', () {
      expect(go(SignedIn(user()), Routes.home, policy: const PolicyState(forcedUpdate: true)), Routes.updateRequired);
    });

    test('maintenance is shown before the update screen', () {
      final policy = PolicyState(config: config(status: 'update_required', maintenance: true));
      expect(go(SignedIn(user()), Routes.home, policy: policy), Routes.maintenance);
    });

    test('a recommended (optional) update never blocks anyone', () {
      final policy = PolicyState(config: config(status: 'update_recommended'));
      expect(go(SignedIn(user()), Routes.home, policy: policy), isNull);
    });

    test('once the policy clears, the gate screens redirect back into the app', () {
      expect(go(SignedIn(user()), Routes.maintenance), Routes.home);
      expect(go(SignedIn(user()), Routes.updateRequired), Routes.home);
      expect(go(const SignedOut(), Routes.maintenance), Routes.welcome);
    });
  });
}
