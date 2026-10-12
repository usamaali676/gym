import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';

/// A neon line chart of (date, value) points. Values are drawn exactly as the server sent them; the Semantics label
/// states the range and the latest value, since a chart alone says nothing to a screen reader.
class TrendChart extends StatelessWidget {
  const TrendChart({super.key, required this.points, required this.unit, required this.semanticLabel, this.height = 180, this.decimals = 1});

  final List<(DateTime, double)> points;
  final String unit;
  final String semanticLabel;
  final double height;
  final int decimals;

  @override
  Widget build(BuildContext context) {
    final spots = [for (final (i, p) in points.indexed) FlSpot(i.toDouble(), p.$2)];
    final values = points.map((p) => p.$2);
    final min = values.reduce((a, b) => a < b ? a : b);
    final max = values.reduce((a, b) => a > b ? a : b);
    final pad = (max - min) < 0.5 ? 1.0 : (max - min) * 0.2;
    final dateFormat = DateFormat.MMMd();

    return Semantics(
      label: semanticLabel,
      image: true,
      child: SizedBox(
        height: height,
        child: ExcludeSemantics(
          child: LineChart(
            LineChartData(
              minY: min - pad,
              maxY: max + pad,
              minX: 0,
              maxX: (points.length - 1).toDouble().clamp(1, double.infinity),
              gridData: FlGridData(
                show: true,
                drawVerticalLine: false,
                getDrawingHorizontalLine: (_) => const FlLine(color: Color.fromRGBO(57, 255, 20, 0.08), strokeWidth: 1),
              ),
              borderData: FlBorderData(show: false),
              titlesData: FlTitlesData(
                topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                leftTitles: AxisTitles(
                  sideTitles: SideTitles(
                    showTitles: true,
                    reservedSize: 40,
                    getTitlesWidget: (v, meta) => v == meta.min || v == meta.max ? const SizedBox.shrink() : Text(v.toStringAsFixed(decimals == 0 ? 0 : 0), style: IpText.body(10.5, color: IpColors.muted)),
                  ),
                ),
                bottomTitles: AxisTitles(
                  sideTitles: SideTitles(
                    showTitles: true,
                    reservedSize: 26,
                    interval: points.length <= 4 ? 1 : (points.length / 4).ceilToDouble(),
                    getTitlesWidget: (v, meta) {
                      final i = v.round();
                      if (i < 0 || i >= points.length || v != i.toDouble()) return const SizedBox.shrink();
                      return Padding(padding: const EdgeInsets.only(top: 6), child: Text(dateFormat.format(points[i].$1), style: IpText.body(10.5, color: IpColors.muted)));
                    },
                  ),
                ),
              ),
              lineTouchData: LineTouchData(
                touchTooltipData: LineTouchTooltipData(
                  getTooltipColor: (_) => IpColors.surface2,
                  getTooltipItems: (spots) => [
                    for (final s in spots) LineTooltipItem('${s.y.toStringAsFixed(decimals)} $unit\n${dateFormat.format(points[s.x.round()].$1)}', IpText.body(12, weight: FontWeight.w600, color: IpColors.neon)),
                  ],
                ),
              ),
              lineBarsData: [
                LineChartBarData(
                  spots: spots,
                  isCurved: points.length > 2,
                  curveSmoothness: 0.25,
                  color: IpColors.neon,
                  barWidth: 3,
                  dotData: FlDotData(show: points.length <= 12, getDotPainter: (_, _, _, _) => FlDotCirclePainter(radius: 3.5, color: IpColors.neon, strokeWidth: 0)),
                  belowBarData: BarAreaData(show: true, gradient: const LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [Color.fromRGBO(57, 255, 20, 0.25), Color.fromRGBO(57, 255, 20, 0)])),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
