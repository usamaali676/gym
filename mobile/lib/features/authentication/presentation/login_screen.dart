import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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
import 'auth_controller.dart';
import 'auth_validators.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _show = false;
  String? _banner;
  Map<String, String> _server = const {};

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _banner = null;
      _server = const {};
    });
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await ref.read(authControllerProvider.notifier).login(_email.text, _password.text);
      TextInput.finishAutofillContext();
    } on ApiError catch (e) {
      if (!mounted) return;
      setState(() {
        _server = e.fieldErrors;
        _banner = e.fieldErrors.isEmpty ? e.message : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(leading: BackButton(onPressed: () => context.go(Routes.welcome))),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
          child: AutofillGroup(
            child: Form(
              key: _form,
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Align(alignment: Alignment.centerLeft, child: BrandMark(size: 24)),
                const SizedBox(height: 22),
                Text('Welcome back', style: IpText.display(24, color: IpColors.ink)),
                const SizedBox(height: 22),
                if (_banner != null) ...[MessageBanner(_banner!), const SizedBox(height: 16)],
                NeonTextField(
                  label: 'Email',
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  textInputAction: TextInputAction.next,
                  autofillHints: const [AutofillHints.username, AutofillHints.email],
                  validator: AuthValidators.email,
                  errorText: _server['email'],
                ),
                const SizedBox(height: 16),
                NeonTextField(
                  label: 'Password',
                  controller: _password,
                  obscure: !_show,
                  textInputAction: TextInputAction.done,
                  autofillHints: const [AutofillHints.password],
                  onSubmitted: (_) => _submit(),
                  validator: (v) => (v == null || v.isEmpty) ? 'Enter your password.' : null,
                  errorText: _server['password'],
                  suffix: IconButton(
                    tooltip: _show ? 'Hide password' : 'Show password',
                    icon: Icon(_show ? Icons.visibility_off_outlined : Icons.visibility_outlined, color: IpColors.muted),
                    onPressed: () => setState(() => _show = !_show),
                  ),
                ),
                Align(
                  alignment: Alignment.centerRight,
                  child: TextButton(
                    onPressed: () => context.go(Routes.forgotPassword),
                    child: Text('Forgot password?', style: IpText.body(13, weight: FontWeight.w600, color: IpColors.neon)),
                  ),
                ),
                const SizedBox(height: 8),
                NeonButton(label: 'Sign in', size: NeonButtonSize.large, expand: true, loading: _busy, onPressed: _submit),
                const SizedBox(height: 16),
                Center(
                  child: TextButton(
                    onPressed: () => context.go(Routes.register),
                    child: Text('New here? Create an account', style: IpText.body(13.5, weight: FontWeight.w600, color: IpColors.muted)),
                  ),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
