<?php

namespace facilo\rumbo\Craft;

use Craft;
use craft\elements\Entry;
use facilo\rumbo\Craft\fields\Location;

/** CSV import (F13): read a file, preview it, and turn each row into an entry. Never looks anything up itself. */
final class CsvImport
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const PREVIEW = 10;

    /** @return array{headers: string[], rows: string[][]} */
    public static function read(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException(Craft::t('rumbo-maps', 'The file could not be read.'));
        }
        $first = (string)fgets($fh);
        rewind($fh);
        // Spanish spreadsheets often save with semicolons.
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $headers = null;
        $rows = [];
        while (($cells = fgetcsv($fh, null, $sep, '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $cells = array_map(fn($c) => trim((string)$c), $cells);
            if ($headers === null) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
                $headers = $cells;
                continue;
            }
            $rows[] = array_pad(array_slice($cells, 0, count($headers)), count($headers), '');
        }
        fclose($fh);
        if (!$headers) {
            throw new \RuntimeException(Craft::t('rumbo-maps', 'The file is empty.'));
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Guess a target for each column from its name. */
    public static function guess(array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $h) {
            $k = mb_strtolower(trim($h));
            $map[$i] = match (true) {
                (bool)preg_match('/^(title|t[ií]tulo|nombre|name)$/u', $k) => 'title',
                (bool)preg_match('/^(lat|latitude|latitud)$/u', $k) => 'lat',
                (bool)preg_match('/^(lng|lon|long|longitude|longitud)$/u', $k) => 'lng',
                (bool)preg_match('/(address|direcci[oó]n|domicilio)/u', $k) => 'address',
                default => '',
            };
        }
        return $map;
    }

    /** One row and the column map → the values that go on the entry. */
    public static function values(array $row, array $map): array
    {
        $out = ['title' => '', 'location' => [], 'fields' => []];
        foreach ($map as $i => $target) {
            $v = $row[$i] ?? '';
            if ($target === '' || $v === '') {
                continue;
            }
            match (true) {
                $target === 'title' => $out['title'] = $v,
                in_array($target, ['address', 'lat', 'lng'], true) => $out['location'][$target] = $v,
                str_starts_with($target, 'field:') => $out['fields'][substr($target, 6)] = $v,
                default => null,
            };
        }
        $loc = $out['location'];
        $lat = str_replace(',', '.', $loc['lat'] ?? '');
        $lng = str_replace(',', '.', $loc['lng'] ?? '');
        if (is_numeric($lat) && is_numeric($lng)) {
            $loc['lat'] = (float)$lat;
            $loc['lng'] = (float)$lng;
            $loc['source'] = 'import';
        } else {
            unset($loc['lat'], $loc['lng']);
            $loc['source'] = 'pending';
        }
        $out['location'] = isset($loc['lat']) || ($loc['address'] ?? '') !== '' ? $loc : null;
        return $out;
    }

    /** Save one row as an entry. Returns the error text, or null. */
    public static function save(array $row, array $map, int $sectionId, int $typeId, string $fieldHandle, int $siteId): ?string
    {
        $v = self::values($row, $map);
        $entry = new Entry(['sectionId' => $sectionId, 'typeId' => $typeId, 'siteId' => $siteId]);
        $entry->title = $v['title'] !== '' ? $v['title'] : ($v['location']['address'] ?? Craft::t('rumbo-maps', 'Imported place'));
        if ($v['location'] !== null) {
            $entry->setFieldValue($fieldHandle, $v['location']);
        }
        foreach ($v['fields'] as $h => $val) {
            if ($entry->getFieldLayout()?->getFieldByHandle($h)) {
                $entry->setFieldValue($h, $val);
            }
        }
        if (!Craft::$app->getElements()->saveElement($entry)) {
            return implode(' ', $entry->getFirstErrors());
        }
        return null;
    }

    /** The sections and entry types that have a Rumbo field, for the form. */
    public static function targets(): array
    {
        $out = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $type) {
                foreach ($type->getFieldLayout()->getCustomFields() as $field) {
                    if ($field instanceof Location) {
                        $other = [];
                        foreach ($type->getFieldLayout()->getCustomFields() as $f) {
                            if ($f instanceof \craft\fields\PlainText || $f instanceof \craft\fields\Url || $f instanceof \craft\fields\Email || $f instanceof \craft\fields\Number) {
                                $other[$f->handle] = $f->name;
                            }
                        }
                        $out[] = ['value' => "$section->id:$type->id:$field->handle", 'label' => "$section->name › $type->name › $field->name", 'fields' => $other];
                    }
                }
            }
        }
        return $out;
    }
}
