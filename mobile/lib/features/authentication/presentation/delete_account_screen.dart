import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/sync/offline_queue.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_text_field.dart';
import 'auth_controller.dart';

/// Permanent deletion of the account, started from inside the app (both app stores require this). Nothing is deleted
/// until the server says so; a wrong password changes nothing.
class DeleteAccountScreen extends ConsumerStatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  ConsumerState<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends ConsumerState<DeleteAccountScreen> {
  final _field = TextEditingController();
  bool _busy = false;
  String? _banner;
  String? _fieldError;

  @override
  void dispose() {
    _field.dispose();
    super.dispose();
  }

  Future<void> _delete({required bool guest}) async {
    final value = _field.text;
    if (guest ? value.trim() != 'DELETE' : value.isEmpty) {
      setState(() => _fieldError = guest ? 'Type DELETE to confirm.' : 'Enter your password to confirm.');
      return;
    }
    setState(() {
      _busy = true;
      _banner = null;
      _fieldError = null;
    });
    try {
      await ref.read(authControllerProvider.notifier).deleteAccount(password: guest ? null : value);
      // The session ended: the router moves to the welcome screen, which says the account was deleted.
    } on ApiError catch (e) {
      if (!mounted) return;
      setState(() {
        _fieldError = e.fieldErrors['password'] ?? e.fieldErrors['confirm'];
        _banner = _fieldError == null ? (e.isOffline ? 'No connection. Your account was not deleted. Try again when you are online.' : e.message) : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    if (auth is! SignedIn) return const SizedBox.shrink();
    final guest = auth.user.isGuest;
    final unsent = ref.watch(offlineQueueProvider).ops.length;

    return Scaffold(
      appBar: AppBar(title: const Text('Delete account')),
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.fromLTRB(20, 12, 20, 24), children: [
          GlassCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('THIS CANNOT BE UNDONE', style: IpText.eyebrow(color: const Color(0xFFFF8A8A))),
              const SizedBox(height: 10),
              for (final line in const [
                'Your account is deleted from the server straight away.',
                'Your workouts, sets, personal records, nutrition log, measurements, progress photos, badges and XP are deleted with it.',
                'Every device signed in to this account is signed out.',
                'If you subscribed through the App Store or Google Play, deleting the account does not cancel the subscription. Cancel it in the store.',
              ])
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Icon(Icons.circle, size: 6, color: IpColors.muted),
                    const SizedBox(width: 10),
                    Expanded(child: Text(line, style: IpText.body(13.5, color: IpColors.ink, height: 1.4))),
                  ]),
                ),
              if (guest) ...[
                const SizedBox(height: 4),
                Text('This is a guest account: there is no way to get it back on another device.', style: IpText.body(13.5, weight: FontWeight.w600, color: IpColors.warn)),
              ],
              if (unsent > 0) ...[
                const SizedBox(height: 4),
                Text(
                  '$unsent ${unsent == 1 ? 'change that has' : 'changes that have'} not reached the server yet will be discarded.',
                  style: IpText.body(13.5, weight: FontWeight.w600, color: IpColors.warn),
                ),
              ],
            ]),
          ),
          const SizedBox(height: 16),
          if (_banner != null) ...[MessageBanner(_banner!), const SizedBox(height: 12)],
          NeonTextField(
            label: guest ? 'Type DELETE to confirm' : 'Your password',
            controller: _field,
            obscure: !guest,
            textInputAction: TextInputAction.done,
            errorText: _fieldError,
            onSubmitted: (_) => _delete(guest: guest),
          ),
          const SizedBox(height: 16),
          NeonButton(label: 'Delete my account', icon: Icons.delete_forever_outlined, variant: NeonButtonVariant.danger, size: NeonButtonSize.large, expand: true, loading: _busy, onPressed: () => _delete(guest: guest)),
        ]),
      ),
    );
  }
}
