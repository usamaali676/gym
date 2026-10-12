import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/theme/typography.dart';
import 'status_chip.dart';

/// An inline message above a form: a server error that is not tied to one field, or a notice.
class MessageBanner extends StatelessWidget {
  const MessageBanner(this.message, {super.key, this.tone = Tone.bad});
  final String message;
  final Tone tone;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: tone.fill, border: Border.all(color: tone.border), borderRadius: BorderRadius.circular(IpRadius.control)),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(tone == Tone.bad ? Icons.error_outline : Icons.info_outline, size: 18, color: tone.color),
          const SizedBox(width: 10),
          Expanded(child: Text(message, style: IpText.body(13.5, color: tone.color))),
        ]),
      ),
    );
  }
}
