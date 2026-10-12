import 'dart:math' as math;

import 'package:flutter/rendering.dart';
import 'package:flutter/widgets.dart';

/// Makes a small control at least [size] to hit without making it look bigger: the touch area (and the node a screen
/// reader sees) grows around it, and a touch anywhere in that area reaches the control. Android asks for 48 dp, iOS for 44 pt.
/// The same idea as Material's own tap-target padding, for the app's custom controls.
class MinTapTarget extends SingleChildRenderObjectWidget {
  const MinTapTarget({super.key, this.size = const Size(48, 48), super.child});

  final Size size;

  @override
  RenderObject createRenderObject(BuildContext context) => _RenderMinTapTarget(size);

  @override
  void updateRenderObject(BuildContext context, covariant RenderObject renderObject) => (renderObject as _RenderMinTapTarget).minSize = size;
}

class _RenderMinTapTarget extends RenderShiftedBox {
  _RenderMinTapTarget(this._minSize) : super(null);

  Size _minSize;

  set minSize(Size value) {
    if (_minSize == value) return;
    _minSize = value;
    markNeedsLayout();
  }

  @override
  double computeMinIntrinsicWidth(double height) => math.max(child?.getMinIntrinsicWidth(height) ?? 0, _minSize.width);

  @override
  double computeMaxIntrinsicWidth(double height) => math.max(child?.getMaxIntrinsicWidth(height) ?? 0, _minSize.width);

  @override
  double computeMinIntrinsicHeight(double width) => math.max(child?.getMinIntrinsicHeight(width) ?? 0, _minSize.height);

  @override
  double computeMaxIntrinsicHeight(double width) => math.max(child?.getMaxIntrinsicHeight(width) ?? 0, _minSize.height);

  @override
  Size computeDryLayout(BoxConstraints constraints) {
    final childSize = child?.getDryLayout(constraints) ?? Size.zero;
    return constraints.constrain(Size(math.max(childSize.width, _minSize.width), math.max(childSize.height, _minSize.height)));
  }

  @override
  void performLayout() {
    final c = child;
    if (c == null) {
      size = constraints.constrain(_minSize);
      return;
    }
    c.layout(constraints, parentUsesSize: true);
    size = constraints.constrain(Size(math.max(c.size.width, _minSize.width), math.max(c.size.height, _minSize.height)));
    (c.parentData! as BoxParentData).offset = Alignment.center.alongOffset(size - c.size as Offset);
  }

  @override
  bool hitTest(BoxHitTestResult result, {required Offset position}) {
    if (super.hitTest(result, position: position)) return true;
    final c = child;
    if (c == null) return false;
    // A touch in the padding around the control counts as a touch on its centre.
    final center = c.size.center(Offset.zero);
    return result.addWithRawTransform(
      transform: MatrixUtils.forceToPoint(center),
      position: center,
      hitTest: (result, position) => c.hitTest(result, position: position),
    );
  }
}
