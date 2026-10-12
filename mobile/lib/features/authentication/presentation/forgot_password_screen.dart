import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/widgets/brand.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_text_field.dart';
import '../../../core/widgets/status_chip.dart';
import 'auth_controller.dart';
import 'auth_validators.dart';

/// Asks the server to e-mail a reset link. The link opens the website's reset page; the app never sees the token.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  bool _busy = false;
  bool _sent = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _error = null);
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await ref.read(authRepositoryProvider).forgotPassword(_email.text);
      if (mounted) setState(() => _sent = true);
    } on ApiError catch (e) {
      if (mounted) setState(() => _error = e.fieldErrors['email'] ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(leading: BackButton(onPressed: () => context.go(Routes.login))),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
          child: Form(
            key: _form,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Align(alignment: Alignment.centerLeft, child: BrandMark(size: 24)),
              const SizedBox(height: 22),
              Text('Reset your password', style: IpText.display(24, color: IpColors.ink)),
              const SizedBox(height: 10),
              Text('Enter the email you signed up with. We will send a link to choose a new password.', style: IpText.body(14, color: IpColors.muted)),
              const SizedBox(height: 22),
              if (_sent)
                const MessageBanner('If that email has an account, a reset link is on its way. Open it on this phone or any device.', tone: Tone.good)
              else ...[
                if (_error != null) ...[MessageBanner(_error!), const SizedBox(height: 16)],
                NeonTextField(
                  label: 'Email',
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  textInputAction: TextInputAction.done,
                  autofillHints: const [AutofillHints.email],
                  onSubmitted: (_) => _submit(),
                  validator: AuthValidators.email,
                ),
                const SizedBox(height: 24),
                NeonButton(label: 'Send reset link', size: NeonButtonSize.large, expand: true, loading: _busy, onPressed: _submit),
              ],
            ]),
          ),
        ),
      ),
    );
  }
}
