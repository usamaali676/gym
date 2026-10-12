import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/policy/app_config.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/brand.dart';
import '../../../core/widgets/choice_tiles.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_text_field.dart';
import '../../../core/widgets/state_views.dart';
import '../../authentication/presentation/auth_controller.dart';

/// First-run profile. The choices come from the server (`/app/config` → `profile_options`), the rules are the
/// server's too (`/me/onboarding` re-validates everything); this screen only saves a round trip.
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name;
  final _age = TextEditingController();
  final _height = TextEditingController();
  final _weight = TextEditingController();

  final Map<String, Object?> _single = {};
  Set<String> _equipment = {};
  Set<int> _days = {};

  bool _busy = false;
  String? _banner;
  Map<String, String> _server = const {};
  final Map<String, String> _local = {};

  @override
  void initState() {
    super.initState();
    final auth = ref.read(authControllerProvider);
    _name = TextEditingController(text: auth is SignedIn && !auth.user.isGuest ? auth.user.name : '');
  }

  @override
  void dispose() {
    for (final c in [_name, _age, _height, _weight]) {
      c.dispose();
    }
    super.dispose();
  }

  String? _err(String field) => _local[field] ?? _server[field];

  bool _validateChoices(AppConfig config) {
    _local.clear();
    for (final key in ['sex', 'activity_level', 'fitness_level', 'goal', 'diet_preference']) {
      if (config.options(key).isNotEmpty && _single[key] == null) _local[key] = 'Choose one.';
    }
    if (_equipment.isEmpty) _local['equipment'] = 'Pick at least one.';
    if (_days.isEmpty) _local['training_days'] = 'Pick at least one day.';
    return _local.isEmpty;
  }

  Future<void> _submit(AppConfig config) async {
    setState(() {
      _banner = null;
      _server = const {};
    });
    final fieldsOk = _form.currentState!.validate();
    final choicesOk = _validateChoices(config);
    setState(() {});
    if (!fieldsOk || !choicesOk) return;

    setState(() => _busy = true);
    try {
      await ref.read(authControllerProvider.notifier).completeOnboarding({
        'name': _name.text.trim(),
        'age': int.parse(_age.text.trim()),
        'sex': _single['sex'],
        'height_cm': double.parse(_height.text.trim().replaceAll(',', '.')),
        'weight_kg': double.parse(_weight.text.trim().replaceAll(',', '.')),
        'activity_level': _single['activity_level'],
        'fitness_level': _single['fitness_level'],
        'goal': _single['goal'],
        'diet_preference': _single['diet_preference'],
        'equipment': _equipment.toList(),
        'training_days': (_days.toList()..sort()),
      });
    } on ApiError catch (e) {
      if (!mounted) return;
      setState(() {
        _server = {for (final entry in e.fieldErrors.entries) entry.key.split('.').first: entry.value};
        _banner = e.fieldErrors.isEmpty ? e.message : 'Please check the highlighted fields.';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _number(String? v, {required num min, required num max, required String what, bool whole = false}) {
    final n = num.tryParse((v ?? '').trim().replaceAll(',', '.'));
    if (n == null) return 'Enter your $what.';
    if (whole && n != n.roundToDouble()) return 'Use a whole number.';
    if (n < min || n > max) return '$what must be between $min and $max.'.replaceFirstMapped(RegExp('^.'), (m) => m[0]!.toUpperCase());
    return null;
  }

  Widget _section(String title, Widget child) => Padding(
        padding: const EdgeInsets.only(bottom: 22),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(title.toUpperCase(), style: IpText.body(12.8, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 0.5))),
          child,
        ]),
      );

  Widget _single_(AppConfig config, String key, String title) {
    final opts = [for (final o in config.options(key)) (o.value, o.label)];
    return _section(
      title,
      ChoiceTiles<Object?>(
        options: opts,
        selected: {if (_single[key] != null) _single[key]},
        errorText: _err(key),
        onChanged: (s) => setState(() => _single[key] = s.first),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final policy = ref.watch(policyControllerProvider);
    final config = policy.config;

    if (config == null || config.options('goal').isEmpty) {
      return Scaffold(
        body: SafeArea(
          child: policy.loading
              ? const Center(child: CircularProgressIndicator())
              : ErrorState(
                  error: const ApiError(kind: ApiErrorKind.network, code: 'offline', message: 'The profile choices could not be loaded. Connect to the internet and try again.'),
                  onRetry: () => ref.read(policyControllerProvider.notifier).refresh(),
                ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: const BrandMark(size: 20),
        actions: [
          TextButton(
            onPressed: _busy ? null : () => ref.read(authControllerProvider.notifier).signOut(),
            child: Text('Sign out', style: IpText.body(13, weight: FontWeight.w600, color: IpColors.muted)),
          ),
        ],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 32),
          child: Form(
            key: _form,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text('Set up your profile', style: IpText.display(24, color: IpColors.ink)),
              const SizedBox(height: 8),
              Text('This sets your calories, macros and training plan. You can change it later.', style: IpText.body(14, color: IpColors.muted)),
              const SizedBox(height: 22),
              if (_banner != null) ...[MessageBanner(_banner!), const SizedBox(height: 16)],
              NeonTextField(label: 'Name', controller: _name, textInputAction: TextInputAction.next, validator: (v) => (v ?? '').trim().isEmpty ? 'Enter your name.' : null, errorText: _err('name')),
              const SizedBox(height: 16),
              NeonTextField(
                label: 'Age',
                controller: _age,
                keyboardType: TextInputType.number,
                textInputAction: TextInputAction.next,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                validator: (v) => _number(v, min: 14, max: 90, what: 'age', whole: true),
                errorText: _err('age'),
              ),
              const SizedBox(height: 16),
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(
                  child: NeonTextField(
                    label: 'Height (cm)',
                    controller: _height,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    textInputAction: TextInputAction.next,
                    validator: (v) => _number(v, min: 100, max: 250, what: 'height'),
                    errorText: _err('height_cm'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: NeonTextField(
                    label: 'Weight (kg)',
                    controller: _weight,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    textInputAction: TextInputAction.done,
                    validator: (v) => _number(v, min: 30, max: 300, what: 'weight'),
                    errorText: _err('weight_kg'),
                  ),
                ),
              ]),
              const SizedBox(height: 22),
              _single_(config, 'sex', 'Sex (used for the calorie formula)'),
              _single_(config, 'activity_level', 'Daily activity'),
              _single_(config, 'fitness_level', 'Training experience'),
              _single_(config, 'goal', 'Goal'),
              _single_(config, 'diet_preference', 'Diet'),
              _section(
                'Equipment you can use',
                ChoiceTiles<String>(
                  multi: true,
                  options: [for (final o in config.options('equipment')) (o.value as String, o.label)],
                  selected: _equipment,
                  errorText: _err('equipment'),
                  onChanged: (s) => setState(() => _equipment = s),
                ),
              ),
              _section(
                'Training days',
                ChoiceTiles<int>(
                  multi: true,
                  compact: true,
                  options: [for (final o in config.options('training_days')) (o.value as int, o.label.substring(0, 3))],
                  selected: _days,
                  errorText: _err('training_days'),
                  onChanged: (s) => setState(() => _days = s),
                ),
              ),
              const SizedBox(height: 6),
              NeonButton(label: 'Finish', size: NeonButtonSize.large, expand: true, loading: _busy, onPressed: () => _submit(config)),
            ]),
          ),
        ),
      ),
    );
  }
}
