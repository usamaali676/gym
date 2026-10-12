import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/theme/app_theme.dart';
import 'package:ironpulse/core/api/api_error.dart';
import 'package:ironpulse/core/widgets/app_background.dart';
import 'package:ironpulse/core/widgets/brand.dart';
import 'package:ironpulse/core/widgets/choice_tiles.dart';
import 'package:ironpulse/core/widgets/error_banner.dart';
import 'package:ironpulse/core/widgets/glass_card.dart';
import 'package:ironpulse/core/widgets/neon_button.dart';
import 'package:ironpulse/core/widgets/neon_text_field.dart';
import 'package:ironpulse/core/widgets/state_views.dart';
import 'package:ironpulse/core/widgets/status_chip.dart';
import 'package:ironpulse/features/authentication/presentation/login_screen.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

/// Design QA: pixel snapshots of the shared widgets and key screens in the real theme and fonts. Regenerate with
///   flutter test --update-goldens test/goldens
/// and review the PNGs next to the website before committing.
Widget frame(Widget child) => ProviderScope(
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: buildAppTheme(),
        home: RepaintBoundary(key: const Key('shot'), child: AppBackground(child: child)),
      ),
    );

void main() {
  testWidgets('buttons: primary, ghost, danger, sizes, loading, disabled', (tester) async {
    usePhoneScreen(tester);
    await tester.pumpWidget(frame(Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, spacing: 12, children: [
            const BrandMark(size: 30),
            NeonButton(label: 'Start workout', size: NeonButtonSize.large, expand: true, icon: Icons.play_arrow, onPressed: () {}),
            NeonButton(label: 'Sign in', expand: true, onPressed: () {}),
            NeonButton(label: 'Create account', expand: true, variant: NeonButtonVariant.ghost, onPressed: () {}),
            NeonButton(label: 'Delete account', expand: true, variant: NeonButtonVariant.danger, onPressed: () {}),
            Row(spacing: 10, children: [
              NeonButton(label: 'Small', size: NeonButtonSize.small, onPressed: () {}),
              NeonButton(label: 'Ghost', size: NeonButtonSize.small, variant: NeonButtonVariant.ghost, onPressed: () {}),
              const NeonButton(label: 'Disabled', size: NeonButtonSize.small, onPressed: null),
            ]),
            NeonButton(label: 'Saving', expand: true, loading: true, onPressed: () {}),
          ]),
        ),
      ),
    )));
    await tester.pump();
    await expectLater(find.byKey(const Key('shot')), matchesGoldenFile('buttons.png'));
  });

  testWidgets('cards, chips (green / red / amber / neutral), inputs and tiles', (tester) async {
    tester.view.physicalSize = const Size(360, 1100); // tall: the whole gallery in one image
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final controller = TextEditingController(text: 'sam@example.com');
    await tester.pumpWidget(frame(Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, spacing: 14, children: [
            const GlassCard(child: Text('Glass card')),
            const GlassCard(neon: true, child: Text('Neon glass card')),
            const Wrap(spacing: 8, runSpacing: 8, children: [
              StatusChip('Correct form', tone: Tone.good, icon: Icons.check),
              StatusChip('Knees caving', tone: Tone.bad, icon: Icons.close),
              StatusChip('Low confidence', tone: Tone.warn, icon: Icons.visibility_off_outlined),
              StatusChip('Not tracking'),
            ]),
            NeonTextField(label: 'Email', controller: controller),
            const NeonTextField(label: 'Password', hint: 'At least 8 characters', helper: 'Use a long phrase.'),
            const NeonTextField(label: 'Age', errorText: 'Age must be between 14 and 90.'),
            const MessageBanner('The server could not be reached.'),
            const MessageBanner('Your session ended. Please sign in again.', tone: Tone.warn),
            ChoiceTiles<String>(options: const [('a', 'Cutting (fat loss)'), ('b', 'Bulking (muscle gain)')], selected: const {'b'}, onChanged: (_) {}),
            ChoiceTiles<int>(compact: true, multi: true, options: const [(1, 'Mon'), (2, 'Tue'), (3, 'Wed'), (4, 'Thu')], selected: const {1, 3}, onChanged: (_) {}),
          ]),
        ),
      ),
    )));
    await tester.pump();
    await expectLater(find.byKey(const Key('shot')), matchesGoldenFile('cards_chips_inputs.png'));
  });

  testWidgets('empty, error and offline states', (tester) async {
    usePhoneScreen(tester);
    await tester.pumpWidget(frame(Scaffold(
      body: SafeArea(
        child: Column(children: [
          const OfflineBanner(),
          Expanded(child: EmptyState(title: 'No workouts yet', message: 'Your first session will show up here.', actionLabel: 'Browse programmes', onAction: () {})),
          const Expanded(child: ErrorState(error: ApiError(kind: ApiErrorKind.server, code: 'server_error', message: 'The server had a problem. Try again in a moment.', requestId: 'req-8f2a'))),
        ]),
      ),
    )));
    await tester.pump();
    await expectLater(find.byKey(const Key('shot')), matchesGoldenFile('states.png'));
  });

  testWidgets('login screen', (tester) async {
    final w = await TestWorld.create();
    usePhoneScreen(tester);
    await tester.pumpWidget(ProviderScope(
      overrides: w.overrides,
      child: MaterialApp(debugShowCheckedModeBanner: false, theme: buildAppTheme(), home: const RepaintBoundary(key: Key('shot'), child: AppBackground(child: LoginScreen()))),
    ));
    await tester.pump();
    await expectLater(find.byKey(const Key('shot')), matchesGoldenFile('login.png'));
  });

  testWidgets('home screen', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.pump(const Duration(milliseconds: 300));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('home.png'));
  });

  testWidgets('update required screen', (tester) async {
    final w = await TestWorld.create();
    w.server.reply('GET', '/app/config', 200, {'data': configJson(status: 'update_required', storeUrl: 'https://example.com/store')});
    await pumpApp(tester, w);
    await pumpUntil(tester, find.text('Update required'));
    await tester.pump(const Duration(milliseconds: 300));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('update_required.png'));
  });

  testWidgets('dashboard with a locked workout card and no nutrition', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember();
    w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(allow: ['library.premium'], nutrition: false)});
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.pump(const Duration(milliseconds: 300));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('dashboard_locked.png'));
  });

  testWidgets('membership screen', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember();
    w.server.reply('GET', '/membership', 200, {
      'data': membershipJson(
        plan: planJson(id: 2, slug: 'pro', name: 'Pro', free: false, allow: ['workouts', 'library.premium', 'nutrition']),
        allow: ['workouts', 'library.premium', 'nutrition'],
        subscription: {'id': 1, 'status': 'active', 'source': 'admin', 'plan_slug': 'pro', 'ends_at': '2030-06-30T00:00:00+00:00', 'current_period_ends_at': '2030-06-30T00:00:00+00:00', 'auto_renew': false},
      ),
    });
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.tap(find.text('ACCOUNT'));
    await pumpUntil(tester, find.text('Membership'));
    await tester.tap(find.text('Membership'));
    await pumpUntil(tester, find.text('YOUR PLAN'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('membership.png'));
  });

  testWidgets('train tab (week) and library', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember();
    w.server.reply('GET', '/exercises/exercise-1', 200, {'data': exerciseJson(1, name: 'Bench press', full: true, posture: true)});
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.tap(find.text('TRAIN'));
    await pumpUntil(tester, find.text('Strength Block'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('train_week.png'));

    await tester.tap(find.text('Exercises'));
    await pumpUntil(tester, find.text('Exercise 1'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('library.png'));

    await tester.tap(find.text('Exercise 1'));
    await pumpUntil(tester, find.text('FORM CUES'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('exercise_detail.png'));
  });

  testWidgets('day detail and programme detail', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.tap(find.text('TRAIN'));
    await pumpUntil(tester, find.text('Strength Block'));
    await tester.tap(find.text('Legs'));
    await pumpUntil(tester, find.text('Bench press'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('day_detail.png'));
  });
}
