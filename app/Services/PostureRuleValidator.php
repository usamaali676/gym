<?php

namespace App\Services;

/**
 * Validates a posture rule's `config` JSON (schema 1).
 *
 * This mirrors validateRule() in resources/js/modules/rule-engine.js, message for message, so a rule that
 * saves here always compiles in the browser. tests/fixtures/posture-vocabulary.json and
 * tests/fixtures/posture-rule-invalid.json are asserted by both the PHP and the JS test suites to keep the
 * two implementations from drifting apart.
 */
class PostureRuleValidator
{
    public const SCHEMA = 1;

    private const SIDES = ['auto', 'left', 'right', 'both'];

    private const MEASURE_TYPES = ['angle', 'inclination', 'offset', 'ratio', 'line_offset', 'diff'];

    private const NORMS = ['torso', 'shoulder_width', 'thigh', 'shank', 'upper_arm', 'forearm', 'none'];

    private const AXES = ['x', 'forward', 'up'];

    private const OPS = ['<', '>', 'outside', 'inside'];

    private const PHASES = ['rest', 'out', 'peak', 'back', 'hold'];

    private const STATS = ['min', 'max', 'peak', 'range'];

    private const SEVERITIES = ['major', 'minor'];

    private const COMBINE = ['mean', 'min', 'max'];

    private const RELATIVE = ['delta', 'ratio'];

    private const NAME_RE = '/^[a-z][a-z0-9_]*$/';

    private const SIDED = [
        'eye_inner', 'eye', 'eye_outer', 'ear', 'mouth', 'shoulder', 'elbow', 'wrist',
        'pinky', 'index', 'thumb', 'hip', 'knee', 'ankle', 'heel', 'foot_index',
    ];

    private const MID = ['mid_shoulder', 'mid_hip', 'mid_ankle', 'mid_wrist', 'mid_ear'];

    /** Segment groups that also exist per side (`upper_arm_l`). The others (shoulders, hips, neck) are single bones. */
    private const SEGMENT_BASE = ['torso', 'shoulders', 'hips', 'neck', 'upper_arm', 'forearm', 'hand', 'thigh', 'shin', 'foot', 'arm', 'leg', 'body'];

    private const SEGMENT_SIDED = ['torso', 'upper_arm', 'forearm', 'hand', 'thigh', 'shin', 'foot', 'arm', 'leg', 'body'];

    /** @return list<string> */
    public function validate(mixed $config): array
    {
        $errors = [];
        if (! is_array($config) || ($config !== [] && array_is_list($config))) {
            return ['config must be an object'];
        }

        if (($config['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = 'schema must be '.self::SCHEMA;
        }
        if (array_key_exists('side', $config) && ! in_array($config['side'], self::SIDES, true)) {
            $errors[] = 'side must be one of '.implode(', ', self::SIDES);
        }
        if (array_key_exists('min_visibility', $config) && ! ($this->isNum($config['min_visibility']) && $config['min_visibility'] >= 0.1 && $config['min_visibility'] <= 0.95)) {
            $errors[] = 'min_visibility must be a number between 0.1 and 0.95';
        }
        if (array_key_exists('good_score', $config) && ! ($this->isNum($config['good_score']) && $config['good_score'] >= 0 && $config['good_score'] <= 100)) {
            $errors[] = 'good_score must be a number between 0 and 100';
        }
        if (array_key_exists('smoothing', $config)) {
            $s = $config['smoothing'];
            if (! $this->isObj($s)
                || (array_key_exists('min_cutoff', $s) && ! ($this->isNum($s['min_cutoff']) && $s['min_cutoff'] > 0))
                || (array_key_exists('beta', $s) && ! ($this->isNum($s['beta']) && $s['beta'] >= 0))) {
                $errors[] = 'smoothing needs min_cutoff > 0 and beta >= 0';
            }
        }

        $required = $config['required'] ?? null;
        if (! is_array($required) || $required === [] || ! array_is_list($required)) {
            $errors[] = 'required must list at least one point';
        } else {
            foreach ($required as $p) {
                if (! $this->isPoint($p)) {
                    $errors[] = 'unknown point "'.$this->str($p).'" in required';
                }
            }
        }

        $measures = $config['measures'] ?? null;
        if (! $this->isObj($measures) || $measures === []) {
            $errors[] = 'measures must define at least one measure';
            $measures = [];
        } else {
            foreach ($measures as $name => $def) {
                $this->validateMeasure((string) $name, $def, $measures, $errors);
            }
        }

        $names = array_map('strval', array_keys($measures));
        $rep = $config['rep'] ?? null;
        $this->validateRep($rep, $names, $errors);
        $this->validateChecks($config['checks'] ?? null, array_key_exists('checks', $config), $names, $rep, $errors);

        return $errors;
    }

    /**
     * The words a rule may use. Compared against tests/fixtures/posture-vocabulary.json.
     *
     * @return array<string, list<string>>
     */
    public function vocabulary(): array
    {
        $points = ['nose', ...self::SIDED, ...self::MID];
        foreach (self::SIDED as $p) {
            $points[] = "l_{$p}";
            $points[] = "r_{$p}";
        }
        $segments = self::SEGMENT_BASE;
        foreach (self::SEGMENT_SIDED as $s) {
            $segments[] = "{$s}_l";
            $segments[] = "{$s}_r";
        }
        sort($points);
        sort($segments);

        return [
            'points' => $points,
            'segments' => $segments,
            'sides' => self::SIDES,
            'measure_types' => self::MEASURE_TYPES,
            'norms' => self::NORMS,
            'axes' => self::AXES,
            'ops' => self::OPS,
            'phases' => self::PHASES,
            'stats' => self::STATS,
            'severities' => self::SEVERITIES,
            'combine' => self::COMBINE,
            'relative' => self::RELATIVE,
        ];
    }

    // ---------------------------------------------------------------------------------------

    private function validateMeasure(string $name, mixed $def, array $measures, array &$errors): void
    {
        if (! preg_match(self::NAME_RE, $name)) {
            $errors[] = "measure name \"{$name}\" is invalid";

            return;
        }
        if (! $this->isObj($def)) {
            $errors[] = "measure \"{$name}\" must be an object";

            return;
        }
        $type = $def['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::MEASURE_TYPES, true)) {
            $errors[] = "measure \"{$name}\" has unknown type \"".$this->str($type).'"';

            return;
        }
        if (array_key_exists('combine', $def) && ! in_array($def['combine'], self::COMBINE, true)) {
            $errors[] = "measure \"{$name}\": combine must be one of ".implode(', ', self::COMBINE);
        }
        if (array_key_exists('relative', $def) && $def['relative'] !== null && ! in_array($def['relative'], self::RELATIVE, true)) {
            $errors[] = "measure \"{$name}\": relative must be one of ".implode(', ', self::RELATIVE);
        }
        if (array_key_exists('norm', $def) && ! in_array($def['norm'], self::NORMS, true)) {
            $errors[] = "measure \"{$name}\": norm must be one of ".implode(', ', self::NORMS);
        }

        $point = function (mixed $p) use ($name, &$errors): void {
            if (! $this->isPoint($p)) {
                $errors[] = 'unknown point "'.$this->str($p)."\" in measure \"{$name}\"";
            }
        };

        switch ($type) {
            case 'angle':
                $points = $def['points'] ?? null;
                if (! is_array($points) || ! array_is_list($points) || count($points) !== 3) {
                    $errors[] = "measure \"{$name}\": points must list exactly 3 points";
                } else {
                    array_map($point, $points);
                }
                break;
            case 'inclination':
                $point($def['from'] ?? null);
                $point($def['to'] ?? null);
                if (! in_array($def['ref'] ?? null, ['vertical', 'horizontal'], true)) {
                    $errors[] = "measure \"{$name}\": ref must be vertical or horizontal";
                }
                break;
            case 'offset':
                $point($def['from'] ?? null);
                $point($def['to'] ?? null);
                if (! in_array($def['axis'] ?? null, self::AXES, true)) {
                    $errors[] = "measure \"{$name}\": axis must be one of ".implode(', ', self::AXES);
                }
                break;
            case 'ratio':
                foreach (['num', 'den'] as $key) {
                    $list = $def[$key] ?? null;
                    if (! is_array($list) || ! array_is_list($list) || count($list) !== 2) {
                        $errors[] = "measure \"{$name}\": {$key} must list exactly 2 points";
                    } else {
                        array_map($point, $list);
                    }
                }
                break;
            case 'line_offset':
                $point($def['from'] ?? null);
                $point($def['to'] ?? null);
                $point($def['point'] ?? null);
                break;
            case 'diff':
                foreach (['a', 'b'] as $key) {
                    $ref = $def[$key] ?? null;
                    $target = is_string($ref) ? ($measures[$ref] ?? null) : null;
                    if (! is_string($ref) || $ref === $name || ! $this->isObj($target)) {
                        $errors[] = "measure \"{$name}\": {$key} must reference another measure";
                    } elseif (($target['type'] ?? null) === 'diff') {
                        $errors[] = "measure \"{$name}\": {$key} cannot reference a diff measure";
                    }
                }
                break;
        }
    }

    private function validateRep(mixed $rep, array $measureNames, array &$errors): void
    {
        if (! $this->isObj($rep)) {
            $errors[] = 'rep must be an object';

            return;
        }

        if (($rep['hold'] ?? null) === true) {
            $conditions = $rep['in_position'] ?? null;
            if (! is_array($conditions) || $conditions === [] || ! array_is_list($conditions)) {
                $errors[] = 'rep.in_position must list at least one condition for a hold';
            } else {
                foreach ($conditions as $i => $c) {
                    if (! $this->isObj($c) || ! is_string($c['measure'] ?? null) || ! in_array($c['measure'], $measureNames, true)) {
                        $errors[] = "rep.in_position[{$i}] must reference a measure";
                    } else {
                        $this->validateComparison("rep.in_position[{$i}]", $c, $errors);
                    }
                }
            }
            foreach (['settle_ms', 'grace_ms', 'min_hold_ms'] as $key) {
                if (array_key_exists($key, $rep) && ! ($this->isNum($rep[$key]) && $rep[$key] >= 0)) {
                    $errors[] = "rep.{$key} must be a number >= 0";
                }
            }

            return;
        }

        $signal = $rep['signal'] ?? null;
        if (! is_string($signal) || ! in_array($signal, $measureNames, true)) {
            $errors[] = 'rep.signal "'.$this->str($signal).'" is not a measure';
        }
        $start = $rep['start'] ?? null;
        $peak = $rep['peak'] ?? null;
        if (! $this->isNum($start) || ! $this->isNum($peak)) {
            $errors[] = 'rep.start and rep.peak must be numbers';

            return;
        }
        if ($start == $peak) {
            $errors[] = 'rep.start and rep.peak must differ';
        }
        $range = abs($peak - $start);
        $tolerance = $rep['tolerance'] ?? null;
        if (! $this->isNum($tolerance) || $tolerance <= 0) {
            $errors[] = 'rep.tolerance must be a number greater than 0';
        } elseif ($tolerance >= $range / 2) {
            $errors[] = 'rep.tolerance must be smaller than half the range between start and peak';
        }
        $min = $rep['min_ms'] ?? 700;
        $max = $rep['max_ms'] ?? 15000;
        if (! $this->isNum($min) || $min < 100) {
            $errors[] = 'rep.min_ms must be a number >= 100';
        }
        if (! $this->isNum($max) || ! $this->isNum($min) || $max <= $min) {
            $errors[] = 'rep.max_ms must be greater than rep.min_ms';
        }
        if (array_key_exists('min_rom', $rep) && ! ($this->isNum($rep['min_rom']) && $rep['min_rom'] > 0 && $rep['min_rom'] <= 1)) {
            $errors[] = 'rep.min_rom must be a number between 0 and 1';
        }
        if (array_key_exists('settle_ms', $rep) && ! ($this->isNum($rep['settle_ms']) && $rep['settle_ms'] >= 0)) {
            $errors[] = 'rep.settle_ms must be a number >= 0';
        }
        if (array_key_exists('fast_ms', $rep) && ! ($this->isNum($rep['fast_ms']) && $this->isNum($min) && $rep['fast_ms'] > $min)) {
            $errors[] = 'rep.fast_ms must be greater than rep.min_ms';
        }
        if (array_key_exists('partial_message', $rep)) {
            $m = $rep['partial_message'];
            if (! (is_string($m) && trim($m) !== '' && mb_strlen($m) <= 110)) {
                $errors[] = 'rep.partial_message must be a short text (110 characters at most)';
            }
        }
    }

    private function validateComparison(string $where, array $c, array &$errors): void
    {
        $op = $c['op'] ?? null;
        if (! in_array($op, self::OPS, true)) {
            $errors[] = "{$where}: op must be one of ".implode(', ', self::OPS);

            return;
        }
        $value = $c['value'] ?? null;
        if ($op === 'outside' || $op === 'inside') {
            if (! is_array($value) || ! array_is_list($value) || count($value) !== 2 || ! $this->isNum($value[0]) || ! $this->isNum($value[1]) || $value[0] >= $value[1]) {
                $errors[] = "{$where}: value must be [low, high] for {$op}";
            }
        } elseif (! $this->isNum($value)) {
            $errors[] = "{$where}: value must be a number";
        }
    }

    private function validateChecks(mixed $checks, bool $present, array $measureNames, mixed $rep, array &$errors): void
    {
        if (! $present) {
            return;
        }
        if (! is_array($checks) || ($checks !== [] && ! array_is_list($checks))) {
            $errors[] = 'checks must be a list';

            return;
        }

        $seen = [];
        $isHold = $this->isObj($rep) && ($rep['hold'] ?? null) === true;
        foreach ($checks as $i => $c) {
            $where = 'check #'.($i + 1);
            if (! $this->isObj($c)) {
                $errors[] = "{$where} must be an object";

                continue;
            }
            $id = $c['id'] ?? null;
            if (! is_string($id) || ! preg_match(self::NAME_RE, $id)) {
                $errors[] = "{$where}: id is invalid";
            } elseif (isset($seen[$id])) {
                $errors[] = "duplicate check id \"{$id}\"";
            } else {
                $seen[$id] = true;
            }
            $label = is_string($id) ? "check \"{$id}\"" : $where;

            if (! is_string($c['label'] ?? null) || trim($c['label']) === '') {
                $errors[] = "{$label}: label is required";
            }
            if (! is_string($c['message'] ?? null) || trim($c['message']) === '') {
                $errors[] = "{$label}: message is required";
            }
            $measure = $c['measure'] ?? null;
            if (! is_string($measure) || ! in_array($measure, $measureNames, true)) {
                $errors[] = "{$label}: measure \"".$this->str($measure).'" does not exist';
            }
            if (! in_array($c['severity'] ?? 'major', self::SEVERITIES, true)) {
                $errors[] = "{$label}: severity must be major or minor";
            }
            $this->validateComparison($label, $c, $errors);

            $scope = $c['scope'] ?? 'frame';
            if (! in_array($scope, ['frame', 'rep'], true)) {
                $errors[] = "{$label}: scope must be frame or rep";
            }
            if ($scope === 'rep') {
                if (! in_array($c['stat'] ?? null, self::STATS, true)) {
                    $errors[] = "{$label}: stat must be one of ".implode(', ', self::STATS);
                }
                if ($isHold) {
                    $errors[] = "{$label}: rep-scope checks need a movement rep, not a hold";
                }
            }
            if (array_key_exists('phases', $c)) {
                $phases = $c['phases'];
                if (! is_array($phases) || $phases === [] || ! array_is_list($phases) || array_diff($phases, self::PHASES) !== []) {
                    $errors[] = "{$label}: phases must be a list of ".implode(', ', self::PHASES);
                }
            }
            if (array_key_exists('frames', $c) && ! ($this->isWhole($c['frames']) && $c['frames'] >= 1 && $c['frames'] <= 30)) {
                $errors[] = "{$label}: frames must be a whole number between 1 and 30";
            }
            if (array_key_exists('segments', $c)) {
                $segments = $c['segments'];
                if (! is_array($segments) || ($segments !== [] && ! array_is_list($segments))) {
                    $errors[] = "{$label}: segments must be a list";
                } else {
                    $known = $this->vocabulary()['segments'];
                    foreach ($segments as $s) {
                        if (! is_string($s) || ! in_array($s, $known, true)) {
                            $errors[] = "{$label}: unknown segment \"".$this->str($s).'"';
                        }
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------------------------

    private function isNum(mixed $v): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v);
    }

    private function isWhole(mixed $v): bool
    {
        return is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v);
    }

    /** A JSON object (decoded as an associative array; an empty object decodes to []). */
    private function isObj(mixed $v): bool
    {
        return is_array($v) && ($v === [] || ! array_is_list($v));
    }

    private function isPoint(mixed $p): bool
    {
        if (! is_string($p)) {
            return false;
        }
        if ($p === 'nose' || in_array($p, self::SIDED, true) || in_array($p, self::MID, true)) {
            return true;
        }

        return (bool) preg_match('/^[lr]_(.+)$/', $p, $m) && in_array($m[1], self::SIDED, true);
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) || $v === null ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? 'undefined')) : 'object';
    }
}
