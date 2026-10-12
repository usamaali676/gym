/** Prints the posture-rule vocabulary as JSON (see tests/fixtures/posture-vocabulary.json). */
import * as G from '../resources/js/modules/geometry.js';
import * as R from '../resources/js/modules/rule-engine.js';
const vocab = {
  _comment: 'The posture-rule vocabulary. tests/js/rule-engine.test.mjs asserts the JS engine matches it and tests/Unit/PostureRuleValidatorTest.php asserts the PHP validator matches it, so the two cannot drift apart. Regenerate with `node scripts/dump-posture-vocabulary.mjs > tests/fixtures/posture-vocabulary.json`.',
  points: [...G.POINT_NAMES].sort(),
  segments: Object.keys(G.SEGMENT_GROUPS).sort(),
  sides: [...R.SIDES],
  measure_types: [...R.MEASURE_TYPES],
  norms: [...R.NORMS],
  axes: [...R.AXES],
  ops: [...R.OPS],
  phases: [...R.PHASES],
  stats: [...R.STATS],
  severities: [...R.SEVERITIES],
  combine: [...R.COMBINE],
  relative: [...R.RELATIVE],
};
process.stdout.write(JSON.stringify(vocab, null, 4) + '\n');
