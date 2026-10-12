import 'package:flutter/material.dart';

/// Two items at opposite ends of a row (a title and a status, a label and a figure). At large text sizes there is no
/// room for both side by side, so the second one drops to its own line instead of overflowing the screen.
class SpreadRow extends StatelessWidget {
  const SpreadRow({super.key, required this.start, required this.end});

  final Widget start;
  final Widget end;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: double.infinity,
        child: Wrap(
          alignment: WrapAlignment.spaceBetween,
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 8,
          runSpacing: 6,
          children: [start, end],
        ),
      );
}
