<?php

use App\Services\PostureRuleValidator;

/** Apply the fixture's dot-path edits (numbers index arrays) to a copy of a config. */
function mutateRule(array $config, array $case): array
{
    foreach ($case['set'] ?? [] as $path => $value) {
        $keys = explode('.', $path);
        $ref = &$config;
        foreach (array_slice($keys, 0, -1) as $k) {
            $ref = &$ref[$k];
        }
        $ref[end($keys)] = $value;
        unset($ref);
    }
    foreach ($case['unset'] ?? [] as $path) {
        $keys = explode('.', $path);
        $ref = &$config;
        foreach (array_slice($keys, 0, -1) as $k) {
            $ref = &$ref[$k];
        }
        unset($ref[end($keys)]);
        unset($ref);
    }

    return $config;
}

function ruleFile(string $slug): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 2)."/database/seeders/data/posture-rules/{$slug}.json"), true, 512, JSON_THROW_ON_ERROR);
}

$fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/posture-rule-invalid.json'), true, 512, JSON_THROW_ON_ERROR);

test('every shipped rule passes the PHP validator', function () {
    $validator = new PostureRuleValidator;
    $files = glob(dirname(__DIR__, 2).'/database/seeders/data/posture-rules/*.json');

    expect($files)->toHaveCount(35);
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        expect($validator->validate($data['config']))->toBe([], basename($file));
    }
});

test('the PHP validator rejects the same mistakes with the same messages as the JS engine', function (array $case) use ($fixture) {
    $base = ruleFile($case['base'] ?? $fixture['valid_base'])['config'];
    $errors = (new PostureRuleValidator)->validate(mutateRule($base, $case));

    expect($errors)->toContain($case['expect']);
})->with(array_combine(array_column($fixture['cases'], 'name'), array_map(fn ($c) => [$c], $fixture['cases'])));

test('the vocabulary matches the one the JS engine uses', function () {
    $expected = json_decode(file_get_contents(__DIR__.'/../fixtures/posture-vocabulary.json'), true, 512, JSON_THROW_ON_ERROR);
    unset($expected['_comment']);
    $actual = (new PostureRuleValidator)->vocabulary();

    foreach ($expected as $key => $list) {
        expect($actual[$key])->toEqual($list, "vocabulary.{$key}");
    }
    expect(array_keys($actual))->toEqualCanonicalizing(array_keys($expected));
});

test('non-objects and empty configs are rejected', function () {
    $v = new PostureRuleValidator;

    expect($v->validate(null))->toBe(['config must be an object'])
        ->and($v->validate('x'))->toBe(['config must be an object'])
        ->and($v->validate([1, 2]))->toBe(['config must be an object'])
        ->and($v->validate([]))->not->toBeEmpty();
});

test('a minimal valid rule passes', function () {
    $config = [
        'schema' => 1,
        'required' => ['shoulder', 'hip'],
        'measures' => ['elbow' => ['type' => 'angle', 'points' => ['shoulder', 'elbow', 'wrist']]],
        'rep' => ['signal' => 'elbow', 'start' => 160, 'peak' => 50, 'tolerance' => 14],
    ];

    expect((new PostureRuleValidator)->validate($config))->toBe([]);
});
