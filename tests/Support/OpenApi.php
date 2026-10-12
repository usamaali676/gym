<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/** Loads docs/mobile/openapi.yaml and checks JSON values against its schemas (the subset of JSON Schema it uses). */
final class OpenApi
{
    private static ?array $spec = null;

    public static function spec(): array
    {
        return self::$spec ??= Yaml::parseFile(base_path('docs/mobile/openapi.yaml'));
    }

    /**
     * @return list<string> human-readable problems; empty when the value fits
     */
    public static function check(string $schemaName, mixed $value): array
    {
        return self::validate(['$ref' => "#/components/schemas/{$schemaName}"], $value, $schemaName);
    }

    /** @return list<string> */
    private static function validate(array $schema, mixed $value, string $path): array
    {
        if (isset($schema['$ref'])) {
            return self::validate(self::resolve($schema['$ref']), $value, $path);
        }
        if (isset($schema['allOf'])) {
            return array_merge(...array_map(fn ($s) => self::validate($s, $value, $path), $schema['allOf']));
        }

        // Optional fields may be null or absent; "required" is enforced by the parent object.
        if ($value === null) {
            return [];
        }

        $problems = [];
        $type = $schema['type'] ?? null;

        if ($type === 'object' || isset($schema['properties'])) {
            if (! is_array($value) || (array_is_list($value) && $value !== [])) {
                return ["{$path}: expected an object"];
            }
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $problems[] = "{$path}.{$key}: required but missing";
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (array_key_exists($key, $value)) {
                    $problems = array_merge($problems, self::validate($sub, $value[$key], "{$path}.{$key}"));
                }
            }
            if (is_array($schema['additionalProperties'] ?? null)) {
                foreach ($value as $key => $item) {
                    if (! isset($schema['properties'][$key])) {
                        $problems = array_merge($problems, self::validate($schema['additionalProperties'], $item, "{$path}.{$key}"));
                    }
                }
            }

            return $problems;
        }

        if ($type === 'array') {
            if (! is_array($value) || ! array_is_list($value)) {
                return ["{$path}: expected a list"];
            }
            foreach ($value as $i => $item) {
                $problems = array_merge($problems, self::validate($schema['items'] ?? [], $item, "{$path}[{$i}]"));
            }

            return $problems;
        }

        $ok = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => true,
        };
        if (! $ok) {
            return ["{$path}: expected {$type}, got ".get_debug_type($value)];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $problems[] = "{$path}: '{$value}' is not one of [".implode(', ', $schema['enum']).']';
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private static function resolve(string $ref): array
    {
        $node = self::spec();
        foreach (explode('/', ltrim($ref, '#/')) as $part) {
            $node = $node[$part] ?? throw new \RuntimeException("Unresolvable reference {$ref}");
        }

        return $node;
    }
}
