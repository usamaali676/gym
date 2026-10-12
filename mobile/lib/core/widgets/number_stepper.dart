import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/theme/app_theme.dart';
import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';

/// A big "- value +" control for entering weight or reps with a gloved or sweaty thumb. The value can also be typed.
class NumberStepper extends StatefulWidget {
  const NumberStepper({super.key, required this.label, required this.value, required this.onChanged, required this.step, this.min = 0, this.max = 1000, this.decimals = 0, this.unit});

  final String label;
  final double value;
  final ValueChanged<double> onChanged;
  final double step;
  final double min;
  final double max;
  final int decimals;
  final String? unit;

  @override
  State<NumberStepper> createState() => _NumberStepperState();
}

class _NumberStepperState extends State<NumberStepper> {
  late final TextEditingController _controller = TextEditingController(text: _format(widget.value));
  final _focus = FocusNode();

  String _format(double v) => widget.decimals == 0 ? v.round().toString() : (v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(widget.decimals));

  @override
  void didUpdateWidget(NumberStepper old) {
    super.didUpdateWidget(old);
    // Keep the field in step with the value unless the member is typing in it.
    if (!_focus.hasFocus && old.value != widget.value) _controller.text = _format(widget.value);
  }

  @override
  void dispose() {
    _controller.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _set(double v) {
    final clamped = v.clamp(widget.min, widget.max).toDouble();
    widget.onChanged(clamped);
    _controller.text = _format(clamped);
  }

  @override
  Widget build(BuildContext context) {
    Widget button(IconData icon, String tooltip, VoidCallback onTap) => Tooltip(
          message: tooltip,
          child: SizedBox(
            width: 56,
            height: 56,
            child: Material(
              color: const Color.fromRGBO(57, 255, 20, 0.08),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(IpRadius.control), side: const BorderSide(color: IpColors.lineStrong)),
              child: InkWell(borderRadius: BorderRadius.circular(IpRadius.control), onTap: onTap, child: Icon(icon, color: IpColors.neon)),
            ),
          ),
        );

    return Column(children: [
      Text(widget.label.toUpperCase(), style: IpText.body(11.5, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 1)),
      const SizedBox(height: 8),
      Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        button(Icons.remove, 'Decrease ${widget.label}', () => _set(widget.value - widget.step)),
        SizedBox(
          width: 96,
          child: TextField(
            controller: _controller,
            focusNode: _focus,
            textAlign: TextAlign.center,
            keyboardType: TextInputType.numberWithOptions(decimal: widget.decimals > 0),
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(widget.decimals > 0 ? r'[0-9.,]' : r'[0-9]'))],
            style: IpText.number(34, color: IpColors.ink),
            decoration: const InputDecoration(border: InputBorder.none, enabledBorder: InputBorder.none, focusedBorder: InputBorder.none, filled: false, contentPadding: EdgeInsets.zero),
            onChanged: (text) {
              final v = double.tryParse(text.replaceAll(',', '.'));
              if (v != null) widget.onChanged(v.clamp(widget.min, widget.max).toDouble());
            },
            onSubmitted: (_) => _set(widget.value),
          ),
        ),
        button(Icons.add, 'Increase ${widget.label}', () => _set(widget.value + widget.step)),
      ]),
      if (widget.unit != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(widget.unit!, style: IpText.body(12, color: IpColors.muted))),
    ]);
  }
}
