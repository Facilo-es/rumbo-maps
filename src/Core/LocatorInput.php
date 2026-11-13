<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

/**
 * Validates the public store-locator query (a request anyone can send).
 * Returns cleaned values and per-field error codes: required, invalid, out-of-range,
 * too-short, too-long, invalid-handle, invalid-value, too-many.
 */
final class LocatorInput
{
    public const RADIUS_MAX = 500;
    public const LIMIT_MAX = 100;
    public const QUERY_MIN = 2;
    public const QUERY_MAX = 120;
    public const MAX_FILTERS = 10;
    public const MAX_FILTER_VALUES = 20;
    public const HANDLE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';
    public const DEFAULTS = ['query' => null, 'lat' => null, 'lng' => null, 'radius' => 25.0, 'unit' => 'km', 'limit' => 20, 'filters' => []];

    /**
     * Validates a raw query array.
     *
     * @return array{valid:bool, values:array{query:?string,lat:?float,lng:?float,radius:float,unit:string,limit:int,filters:array<string,string|list<string>>}, errors:array<string,string>}
     */
    public static function validate(array $q): array
    {
        $v = self::DEFAULTS;
        $e = [];

        if (self::present($q, 'query')) {
            if (!is_scalar($q['query'])) {
                $e['query'] = 'invalid';
            } else {
                $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', (string) $q['query']);
                $text = $text === null ? null : trim(preg_replace('/\s+/u', ' ', $text) ?? '');
                $len = $text === null ? -1 : mb_strlen($text, 'UTF-8');
                if ($text === null) {
                    $e['query'] = 'invalid';
                } elseif ($len === 0) {
                    $v['query'] = null;
                } elseif ($len < self::QUERY_MIN) {
                    $e['query'] = 'too-short';
                } elseif ($len > self::QUERY_MAX) {
                    $e['query'] = 'too-long';
                } else {
                    $v['query'] = $text;
                }
            }
        }

        foreach (['lat' => 90, 'lng' => 180] as $field => $max) {
            if (!self::present($q, $field)) {
                continue;
            }
            $n = self::number($q[$field]);
            if ($n === null) {
                $e[$field] = 'invalid';
            } elseif (abs($n) > $max) {
                $e[$field] = 'out-of-range';
            } else {
                $v[$field] = $n;
            }
        }
        foreach (['lat' => 'lng', 'lng' => 'lat'] as $have => $need) {
            if (self::present($q, $have) && !self::present($q, $need) && !isset($e[$need])) {
                $e[$need] = 'required';
            }
        }

        if (self::present($q, 'radius')) {
            $n = self::number($q['radius']);
            if ($n === null) {
                $e['radius'] = 'invalid';
            } elseif ($n < 0 || $n > self::RADIUS_MAX) {
                $e['radius'] = 'out-of-range';
            } else {
                $v['radius'] = $n;
            }
        }

        if (self::present($q, 'unit')) {
            $unit = is_string($q['unit']) ? strtolower(trim($q['unit'])) : '';
            if ($unit === 'km' || $unit === 'mi') {
                $v['unit'] = $unit;
            } else {
                $e['unit'] = 'invalid';
            }
        }

        if (self::present($q, 'limit')) {
            $raw = $q['limit'];
            $ok = is_int($raw) || (is_string($raw) && preg_match('/^\s*\d+\s*$/', $raw));
            $n = $ok ? (int) $raw : null;
            if ($n === null) {
                $e['limit'] = 'invalid';
            } elseif ($n < 1 || $n > self::LIMIT_MAX) {
                $e['limit'] = 'out-of-range';
            } else {
                $v['limit'] = $n;
            }
        }

        if (self::present($q, 'filters')) {
            [$filters, $error] = self::filters($q['filters']);
            if ($error !== null) {
                $e['filters'] = $error;
            } else {
                $v['filters'] = $filters;
            }
        }

        return ['valid' => $e === [], 'values' => $v, 'errors' => $e];
    }

    /** @return array{0:array<string,string|list<string>>,1:?string} */
    private static function filters(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [[], 'invalid'];
        }
        if (count($raw) > self::MAX_FILTERS) {
            return [[], 'too-many'];
        }
        $out = [];
        foreach ($raw as $handle => $value) {
            if (!is_string($handle) || !preg_match(self::HANDLE_PATTERN, $handle)) {
                return [[], 'invalid-handle'];
            }
            if (is_scalar($value)) {
                $out[$handle] = self::filterValue($value);
                continue;
            }
            if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_FILTER_VALUES) {
                return [[], 'invalid-value'];
            }
            $list = [];
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    return [[], 'invalid-value'];
                }
                $list[] = self::filterValue($item);
            }
            $out[$handle] = $list;
        }

        return [$out, null];
    }

    private static function filterValue(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return mb_substr(trim(preg_replace('/\p{Cc}+/u', '', (string) $value) ?? ''), 0, 200, 'UTF-8');
    }

    private static function present(array $q, string $key): bool
    {
        return array_key_exists($key, $q) && $q[$key] !== null && $q[$key] !== '';
    }

    private static function number(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            $n = (float) $raw;
        } elseif (is_string($raw) && is_numeric(trim($raw))) {
            $n = (float) trim($raw);
        } else {
            return null;
        }

        return is_finite($n) ? $n : null;
    }
}
