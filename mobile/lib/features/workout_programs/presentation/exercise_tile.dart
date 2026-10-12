import 'package:flutter/material.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/widgets/glass_card.dart';
import '../domain/plan.dart';

/// One planned exercise: name, sets x reps, rest, the coach's note and the server's last / next suggestion.
class ExerciseTile extends StatelessWidget {
  const ExerciseTile({super.key, required this.exercise});
  final PlanExercise exercise;

  String get _prescription => exercise.isTimed ? '${exercise.sets} × hold' : '${exercise.sets} × ${exercise.repsLabel}';

  @override
  Widget build(BuildContext context) {
    final e = exercise;
    final t = e.target;
    return GlassCard(
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            width: 28,
            height: 28,
            alignment: Alignment.center,
            decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: IpColors.lineStrong)),
            child: Text('${e.position}', style: IpText.number(15, color: IpColors.neon)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(e.name, style: IpText.body(15.5, weight: FontWeight.w600, color: IpColors.ink)),
              const SizedBox(height: 2),
              Text(
                [_prescription, if (e.restSeconds > 0) 'rest ${e.restSeconds}s', if (e.swapped) 'swapped in'].join(' · '),
                style: IpText.body(13, color: IpColors.muted),
              ),
            ]),
          ),
        ]),
        if (e.focus != null && e.focus!.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(e.focus!, style: IpText.body(12.5, color: IpColors.muted))),
        if (e.notes != null && e.notes!.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(e.notes!, style: IpText.body(12.5, color: IpColors.ink))),
        if (t != null) ...[
          const SizedBox(height: 10),
          DecoratedBox(
            decoration: BoxDecoration(color: const Color.fromRGBO(57, 255, 20, 0.06), border: Border.all(color: IpColors.line), borderRadius: BorderRadius.circular(10)),
            child: Padding(
              padding: const EdgeInsets.all(10),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('Last: ${t.last}', style: IpText.body(12.5, color: IpColors.muted)),
                const SizedBox(height: 2),
                Text('Next: ${t.next}', style: IpText.body(13, weight: FontWeight.w600, color: IpColors.neon)),
                if (t.reason.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(t.reason, style: IpText.body(12, color: IpColors.muted))),
              ]),
            ),
          ),
        ],
      ]),
    );
  }
}
