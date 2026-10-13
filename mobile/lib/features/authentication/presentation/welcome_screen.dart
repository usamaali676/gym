import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/brand.dart';
import '../../../core/widgets/credit_badge.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/status_chip.dart';
import 'auth_controller.dart';

class WelcomeScreen extends ConsumerStatefulWidget {
  const WelcomeScreen({super.key});

  @override
  ConsumerState<WelcomeScreen> createState() => _WelcomeScreenState();
}

class _WelcomeScreenState extends ConsumerState<WelcomeScreen> {
  bool _busy = false;
  String? _error;

  Future<void> _guest() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(authControllerProvider.notifier).continueAsGuest();
    } on ApiError catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final policy = ref.watch(policyControllerProvider);
    final notice = auth is SignedOut ? auth.notice : null;
    final guestAllowed = policy.config?.guestMode ?? false;

    return Scaffold(
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, c) => SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: c.maxHeight - 48),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Align(child: BrandMark(size: 38)),
                  const SizedBox(height: 14),
                  Text('TRAIN. TRACK. LEVEL UP.', textAlign: TextAlign.center, style: IpText.eyebrow(color: IpColors.neon)),
                  const SizedBox(height: 22),
                  Text(
                    'Your plan, your sets, your form and your food in one place.',
                    textAlign: TextAlign.center,
                    style: IpText.body(16, color: IpColors.muted),
                  ),
                  const SizedBox(height: 32),
                  if (notice != null) ...[MessageBanner(notice, tone: Tone.warn), const SizedBox(height: 16)],
                  if (_error != null) ...[MessageBanner(_error!), const SizedBox(height: 16)],
                  NeonButton(label: 'Sign in', size: NeonButtonSize.large, expand: true, onPressed: _busy ? null : () => context.go(Routes.login)),
                  const SizedBox(height: 12),
                  NeonButton(
                    label: 'Create account',
                    size: NeonButtonSize.large,
                    expand: true,
                    variant: NeonButtonVariant.ghost,
                    onPressed: _busy ? null : () => context.go(Routes.register),
                  ),
                  if (guestAllowed) ...[
                    const SizedBox(height: 12),
                    NeonButton(
                      label: 'Try as a guest',
                      expand: true,
                      variant: NeonButtonVariant.ghost,
                      loading: _busy,
                      onPressed: _guest,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'A guest account lives on this phone only. Create an account to keep your data if you switch phones.',
                      textAlign: TextAlign.center,
                      style: IpText.body(12.5, color: IpColors.muted),
                    ),
                  ],
                  if (policy.offline) ...[
                    const SizedBox(height: 20),
                    const MessageBanner('You appear to be offline. Sign-in needs a connection.', tone: Tone.warn),
                  ],
                  const SizedBox(height: 28),
                  const CreditBadge(),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
