import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';

/// A labelled input in the web's `.label` + `.input` style. Server validation messages go in [errorText].
class NeonTextField extends StatelessWidget {
  const NeonTextField({
    super.key,
    required this.label,
    this.controller,
    this.hint,
    this.helper,
    this.errorText,
    this.obscure = false,
    this.keyboardType,
    this.textInputAction,
    this.autofillHints,
    this.onChanged,
    this.onSubmitted,
    this.validator,
    this.inputFormatters,
    this.suffix,
    this.enabled = true,
    this.maxLength,
    this.autofocus = false,
  });

  final String label;
  final TextEditingController? controller;
  final String? hint;
  final String? helper;
  final String? errorText;
  final bool obscure;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final Iterable<String>? autofillHints;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final FormFieldValidator<String>? validator;
  final List<TextInputFormatter>? inputFormatters;
  final Widget? suffix;
  final bool enabled;
  final int? maxLength;
  final bool autofocus;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 6.4),
          child: Text(label.toUpperCase(), style: IpText.body(12.8, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 12.8 * 0.04)),
        ),
        TextFormField(
          controller: controller,
          obscureText: obscure,
          enabled: enabled,
          autofocus: autofocus,
          maxLength: maxLength,
          keyboardType: keyboardType,
          textInputAction: textInputAction,
          autofillHints: autofillHints,
          onChanged: onChanged,
          onFieldSubmitted: onSubmitted,
          validator: validator,
          inputFormatters: inputFormatters,
          style: IpText.body(15.5, color: IpColors.ink),
          decoration: InputDecoration(hintText: hint, errorText: errorText, errorMaxLines: 3, suffixIcon: suffix, counterText: ''),
        ),
        if (helper != null && errorText == null)
          Padding(padding: const EdgeInsets.only(top: 5.6), child: Text(helper!, style: IpText.body(12.8, color: IpColors.muted))),
      ],
    );
  }
}
