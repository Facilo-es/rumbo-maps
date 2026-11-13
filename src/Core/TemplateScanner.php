<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * "Check my templates": finds the old Maps plugin's Twig calls (spec F4). It only reads files,
 * never renders them, and only looks inside {{ }} and {% %} tags ({# #} comments are skipped).
 *
 * Rules, in the order they're applied to each dotted chain such as `entry.myMap.parts.city`:
 *  1. `craft.maps.X` and `craft.simpleMap.X`: embed, img, imgSrcSet, getLatLngFromAddress and
 *     getUserLocation on craft.maps are "pro"; anything else, and everything on craft.simpleMap, is
 *     "change" with a replacement hint.
 *  2. `.parts.X` and `.streetAddress` on any value: "lite".
 *  3. `.embed(`, `.img(`, `.imgSrcSet(` and `.distance`: "pro", when the value is at least two
 *     names deep (`entry.myMap.img(`) or is a known Maps value (rule 5).
 *  4. A method call whose first argument is a hash with a `location`, `radius` or `coordinate` key,
 *     and `orderBy('distance')` (optionally with asc/desc): "pro".
 *  5. A value is "known" when the same file uses it with rule 2 or 3 anywhere. Only on known
 *     values are the generic names lat, lng, zoom, address and the old top-level part names
 *     (number, city, postcode, county, state, country) reported, as "lite".
 * One finding per chain. Findings are ordered by position in the file.
 */
final class TemplateScanner
{
    public const EXTENSIONS = ['twig', 'html', 'htm'];
    private const MAX_FILE_BYTES = 2_000_000;

    private const PRO_VARIABLE_METHODS = ['embed', 'img', 'imgSrcSet', 'getLatLngFromAddress', 'getUserLocation'];
    private const RENDER_METHODS = ['embed', 'img', 'imgSrcSet'];
    private const GENERIC_VALUES = ['lat', 'lng', 'zoom', 'address', 'number', 'city', 'postcode', 'county', 'state', 'country'];
    private const QUERY_KEYS = ['location', 'radius', 'coordinate'];

    private const HINTS = [
        'getMapToken' => 'Not needed: Rumbo maps need no token',
        'mapToken' => 'Not needed: Rumbo maps need no token',
    ];
    private const GENERIC_HINT = 'Not in Rumbo: use rumbo.map(), rumbo.img() or the field\'s values (see "Moving from Maps")';

    /**
     * Scans one template's source.
     *
     * @return list<array{file:string,line:int,call:string,badge:'lite'|'pro'|'change',replacement:?string}>
     */
    public function scan(string $contents, string $relativePath): array
    {
        $regions = $this->regions($contents);

        $known = [];
        foreach ($regions as [$text, $masked]) {
            foreach ($this->chains($masked) as [$segments, , $isCall]) {
                $receiver = $this->definiteReceiver($segments, $isCall);
                if ($receiver !== null) {
                    $known[$receiver] = true;
                }
            }
        }

        $found = [];
        foreach ($regions as [$text, $masked, $offset]) {
            foreach ($this->chains($masked) as [$segments, $pos, $isCall]) {
                $f = $this->chainFinding($segments, $isCall, $known);
                if ($f !== null) {
                    $found[] = [$offset + $pos, $f[0], $f[1], $f[2]];
                }
            }
            foreach ($this->queries($text, $masked) as [$pos, $call]) {
                $found[] = [$offset + $pos, $call, 'pro', null];
            }
            if (preg_match_all('/\borderBy\(\s*([\'"])distance(?:\s+(?:asc|desc))?\1\s*\)/i', $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$call, $pos]) {
                    $found[] = [$offset + $pos, $call, 'pro', null];
                }
            }
        }

        usort($found, static fn($a, $b) => $a[0] <=> $b[0]);

        return array_map(static fn($f) => [
            'file' => $relativePath,
            'line' => substr_count($contents, "\n", 0, $f[0]) + 1,
            'call' => $f[1],
            'badge' => $f[2],
            'replacement' => $f[3],
        ], $found);
    }

    /**
     * Scans every .twig, .html and .htm file under the roots. Files reached through a symlink
     * that leaves its root are skipped. Files under extra roots are prefixed with the root's folder name.
     *
     * @return array{findings:list<array>,summary:array{total:int,lite:int,pro:int,change:int},files:int}
     */
    public function scanTree(string $root, array $extraRoots = []): array
    {
        $findings = [];
        $files = 0;

        foreach (array_merge([$root], $extraRoots) as $i => $dir) {
            $real = is_string($dir) ? realpath($dir) : false;
            if ($real === false || !is_dir($real)) {
                continue;
            }
            $prefix = $i === 0 ? '' : basename($real) . '/';

            foreach ($this->files($real) as $relative => $path) {
                $contents = @file_get_contents($path);
                if ($contents === false) {
                    continue;
                }
                $files++;
                array_push($findings, ...$this->scan($contents, $prefix . $relative));
            }
        }

        return ['findings' => $findings, 'summary' => self::summary($findings), 'files' => $files];
    }

    /** Counts findings per badge. */
    public static function summary(array $findings): array
    {
        $summary = ['total' => count($findings), 'lite' => 0, 'pro' => 0, 'change' => 0];
        foreach ($findings as $f) {
            $summary[$f['badge']]++;
        }

        return $summary;
    }

    /** @return array<string,string> relative path => absolute path, sorted */
    private function files(string $root): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }
            $real = $file->getRealPath();
            if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
                continue;
            }
            if (filesize($real) > self::MAX_FILE_BYTES) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            $out[$relative] = $real;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @return list<array{0:string,1:string,2:int}> [text, text with string contents blanked, offset] */
    private function regions(string $contents): array
    {
        preg_match_all('/\{#.*?#\}|\{\{.*?\}\}|\{%.*?%\}/s', $contents, $m, PREG_OFFSET_CAPTURE);
        $regions = [];
        foreach ($m[0] as [$text, $offset]) {
            if (!str_starts_with($text, '{#')) {
                $regions[] = [$text, $this->maskStrings($text), $offset];
            }
        }

        return $regions;
    }

    private function maskStrings(string $text): string
    {
        return preg_replace_callback(
            '/(["\'])((?:\\\\.|(?!\1).)*)\1/s',
            static fn($m) => $m[1] . str_repeat(' ', strlen($m[2])) . $m[1],
            $text,
        ) ?? $text;
    }

    /** @return list<array{0:list<string>,1:int,2:bool}> [segments, offset, followed by "("] */
    private function chains(string $masked): array
    {
        preg_match_all('/(?<![\w.$])[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)+/', $masked, $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[0] as [$chain, $pos]) {
            $after = ltrim(substr($masked, $pos + strlen($chain), 8));
            $out[] = [explode('.', $chain), $pos, str_starts_with($after, '(')];
        }

        return $out;
    }

    private function definiteReceiver(array $s, bool $isCall): ?string
    {
        if ($s[0] === 'craft') {
            return null;
        }
        $n = count($s);
        for ($i = 1; $i < $n; $i++) {
            $last = $i === $n - 1;
            if (($s[$i] === 'parts' && !$last) || $s[$i] === 'streetAddress') {
                return implode('.', array_slice($s, 0, $i));
            }
            if ($last && $i >= 2 && (($isCall && in_array($s[$i], self::RENDER_METHODS, true)) || (!$isCall && $s[$i] === 'distance'))) {
                return implode('.', array_slice($s, 0, $i));
            }
        }

        return null;
    }

    /** @return array{0:string,1:string,2:?string}|null [call, badge, replacement] */
    private function chainFinding(array $s, bool $isCall, array $known): ?array
    {
        $n = count($s);
        $paren = $isCall ? '()' : '';

        if ($s[0] === 'craft') {
            if ($n < 3 || !in_array($s[1], ['maps', 'simpleMap'], true)) {
                return null;
            }
            $call = 'craft.' . $s[1] . '.' . $s[2] . $paren;
            $method = $s[2];
            if ($s[1] === 'maps' && in_array($method, self::PRO_VARIABLE_METHODS, true)) {
                return [$call, 'pro', null];
            }
            if ($s[1] === 'simpleMap' && in_array($method, self::PRO_VARIABLE_METHODS, true)) {
                return [$call, 'change', "Rename to craft.maps.$method() (Pro), or use Rumbo's own rumbo.map() and rumbo.img()"];
            }

            return [$call, 'change', self::HINTS[$method] ?? self::GENERIC_HINT];
        }

        for ($i = 1; $i < $n; $i++) {
            $name = $s[$i];
            $last = $i === $n - 1;
            $receiver = implode('.', array_slice($s, 0, $i));
            $isKnown = isset($known[$receiver]);

            if ($name === 'parts' && !$last) {
                return [implode('.', array_slice($s, 0, $i + 2)), 'lite', null];
            }
            if ($name === 'streetAddress') {
                return [$receiver . '.streetAddress' . $paren, 'lite', null];
            }
            if ($last && ($i >= 2 || $isKnown)) {
                if ($isCall && in_array($name, self::RENDER_METHODS, true)) {
                    return [$receiver . '.' . $name . '()', 'pro', null];
                }
                if (!$isCall && $name === 'distance') {
                    return [$receiver . '.distance', 'pro', null];
                }
            }
            if ($isKnown && in_array($name, self::GENERIC_VALUES, true)) {
                return [$receiver . '.' . $name, 'lite', null];
            }
        }

        return null;
    }

    /** Method calls whose first argument is a hash with an old proximity key. */
    private function queries(string $text, string $masked): array
    {
        preg_match_all('/(?:\.|(?<![\w.$]))[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*\s*\(\s*\{/', $masked, $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[0] as [$match, $pos]) {
            $keys = $this->hashKeys($text, $pos + strlen($match) - 1);
            if (array_intersect($keys, self::QUERY_KEYS) === []) {
                continue;
            }
            $chain = rtrim(preg_replace('/\s*\(\s*\{$/', '', $match) ?? $match);
            if ($chain[0] === '.') {
                $chain = '…' . substr($chain, 1);
            }
            $out[] = [$pos, $chain . '({ ' . implode(', ', $keys) . ' })'];
        }

        return $out;
    }

    /** Top-level keys of the hash literal opening at $open (quoted, bare and shorthand keys). */
    private function hashKeys(string $text, int $open): array
    {
        $keys = [];
        $depth = 0;
        $expectKey = false;
        $len = strlen($text);

        for ($i = $open; $i < $len; $i++) {
            $ch = $text[$i];

            if ($ch === '"' || $ch === "'") {
                $end = $i + 1;
                while ($end < $len && $text[$end] !== $ch) {
                    $end += $text[$end] === '\\' ? 2 : 1;
                }
                if ($depth === 1 && $expectKey && preg_match('/\G\s*:/', $text, $_, 0, $end + 1)) {
                    $keys[] = substr($text, $i + 1, $end - $i - 1);
                }
                $expectKey = false;
                $i = $end;
                continue;
            }
            if (str_contains('{[(', $ch)) {
                $depth++;
                $expectKey = $depth === 1;
                continue;
            }
            if (str_contains('}])', $ch)) {
                if (--$depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            if ($ch === ',') {
                $expectKey = true;
                continue;
            }
            if ($expectKey && preg_match('/\G([A-Za-z_]\w*)\s*([:,}])/', $text, $km, 0, $i)) {
                $keys[] = $km[1];
                $i += strlen($km[0]) - 2;
                $expectKey = false;
                continue;
            }
            if (!ctype_space($ch)) {
                $expectKey = false;
            }
        }

        return $keys;
    }
}
