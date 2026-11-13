<?php

namespace facilo\rumbo\Craft;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\events\PopulateElementEvent;
use facilo\rumbo\Core\Geo;
use facilo\rumbo\Craft\fields\Location;
use facilo\rumbo\migrations\Install;
use facilo\rumbo\Plugin;
use yii\db\Expression;

/**
 * Distance search on element queries (F4, F5):
 * `craft.entries.location({ near: 'Valencia', within: 25, unit: 'km' }).orderBy('distance')`,
 * plus the old plugin's `{ location, radius, unit, country }` and `{ coordinate }` keys.
 *
 * Runs after Craft has prepared its query: joins {{%rumbo_locations}} in the sub-query,
 * filters by an indexed box first and the exact great-circle distance second, and swaps
 * a `distance` key in the order for the computed distance. Plain SQL that runs on MySQL,
 * MariaDB and PostgreSQL alike.
 */
class Proximity extends Component
{
    private const EARTH_KM = 6371.0088;

    /** The last reason a search point couldn't be found, for the locator and the log. */
    public ?string $lastError = null;

    /** @var array<int, array<string, string>> query object id => [handle => unit] */
    private array $active = [];

    public function afterPrepare(ElementQuery $query): void
    {
        if ($query->subQuery === null || empty($query->customFields)) {
            return;
        }
        $byHandle = [];
        foreach ($query->customFields as $field) {
            if ($field instanceof Location) {
                $byHandle[$field->handle][$field->id] = $field;
            }
        }
        $distanceAlias = null;
        foreach ($byHandle as $handle => $fields) {
            $params = $query->$handle ?? null;
            if (!is_array($params) || array_is_list($params) || !self::isProximity($params)) {
                continue;
            }
            if (!Plugin::getInstance()->isPro()) {
                Plugin::getInstance()->locations->warnOnce('location query');
                continue;
            }
            $alias = $this->apply($query, $handle, array_keys($fields), $params);
            $distanceAlias ??= $alias;
        }
        $this->rewriteOrder($query, $distanceAlias);
    }

    public static function isProximity(array $params): bool
    {
        return (bool)array_intersect(array_keys($params), ['near', 'location', 'coordinate', 'covers', 'lat']);
    }

    /** @param int[] $fieldIds */
    private function apply(ElementQuery $query, string $handle, array $fieldIds, array $params): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $unit = in_array($params['unit'] ?? null, ['km', 'mi', 'miles', 'kilometers'], true) ? (str_starts_with($params['unit'], 'm') ? 'mi' : 'km') : $settings->unit;
        $covers = $params['covers'] ?? null;
        $near = $covers ?? $params['near'] ?? $params['location'] ?? (isset($params['coordinate']) ? ['coordinate' => $params['coordinate']] : null) ?? (isset($params['lat']) ? $params : null);
        $country = isset($params['country']) && is_string($params['country']) ? strtoupper(substr($params['country'], 0, 2)) : null;
        $within = $params['within'] ?? $params['radius'] ?? null;
        $within = is_numeric($within) && $within > 0 ? (float)$within : null;

        $point = $this->resolve($near, $country);
        $t = 'rumbo_' . preg_replace('/\W/', '', $handle);
        $query->subQuery->innerJoin([$t => Install::LOCATIONS], [
            'and',
            "[[$t.elementId]] = [[elements.id]]",
            "[[$t.siteId]] = [[elements_sites.siteId]]",
            ["$t.fieldId" => $fieldIds],
        ]);
        if ($country) {
            $query->subQuery->andWhere(["$t.countryCode" => $country]);
        }
        if ($point === null) {
            // A place we can't find matches nothing, rather than everything.
            $query->subQuery->andWhere('0=1');
            return null;
        }

        $km = $this->distanceSql($t, $point['lat'], $point['lng']);
        $factor = $unit === 'mi' ? 1 / Geo::KM_PER_MILE : 1.0;
        $alias = 'rumboDistance_' . preg_replace('/\W/', '', $handle);
        $query->subQuery->addSelect([$alias => new Expression("($km) * $factor")]);
        $query->query->addSelect([$alias => "subquery.$alias"]);
        $this->active[spl_object_id($query)][$handle] = $alias;

        if ($covers !== null) {
            $query->subQuery->andWhere(['not', ["$t.serviceRadius" => null]]);
            $query->subQuery->andWhere(new Expression("($km) <= [[$t.serviceRadius]]"));
        } elseif ($within !== null) {
            $radiusKm = $unit === 'mi' ? Geo::milesToKm($within) : $within;
            $box = Geo::boundingBox($point['lat'], $point['lng'], $radiusKm);
            $query->subQuery->andWhere(['between', "$t.lat", $box['minLat'], $box['maxLat']]);
            if ($box['minLng'] !== null) {
                $query->subQuery->andWhere(['between', "$t.lng", $box['minLng'], $box['maxLng']]);
            }
            $query->subQuery->andWhere(new Expression("($km) <= " . (float)$radiusKm));
        }
        return $alias;
    }

    /** @return array{lat:float,lng:float}|null */
    private function resolve(mixed $near, ?string $country): ?array
    {
        $this->lastError = null;
        $point = Geo::parseLatLng($near);
        if ($point !== null) {
            return $point;
        }
        if (is_string($near) && trim($near) !== '') {
            $result = Plugin::getInstance()->geocoding->place(trim($near), $country);
            if ($result !== null) {
                return ['lat' => $result->lat, 'lng' => $result->lng];
            }
            $this->lastError = Plugin::getInstance()->geocoding->lastError ?? 'not found';
            return null;
        }
        $this->lastError = 'no search point';
        return null;
    }

    private function distanceSql(string $t, float $lat, float $lng): string
    {
        $lat = sprintf('%.9F', $lat);
        $lng = sprintf('%.9F', $lng);
        // Haversine, clamped so rounding never pushes ASIN out of its domain.
        return sprintf(
            '%s * 2 * ASIN(SQRT(LEAST(1, POWER(SIN(RADIANS([[%2$s.lat]] - %3$s) / 2), 2) + COS(RADIANS(%3$s)) * COS(RADIANS([[%2$s.lat]])) * POWER(SIN(RADIANS([[%2$s.lng]] - %4$s) / 2), 2))))',
            self::EARTH_KM,
            $t,
            $lat,
            $lng
        );
    }

    /** Craft sets the order before afterPrepare, so a `distance` key is swapped here. */
    private function rewriteOrder(ElementQuery $query, ?string $alias): void
    {
        foreach (['subQuery', 'query'] as $which) {
            $order = $query->$which->orderBy;
            if (!is_array($order) || !array_key_exists('distance', $order)) {
                continue;
            }
            $new = [];
            foreach ($order as $key => $dir) {
                if ($key !== 'distance') {
                    $new[$key] = $dir;
                } elseif ($alias !== null) {
                    // The sub-query orders by its own select alias; the outer query by the sub-query column.
                    $new[$which === 'query' ? "subquery.$alias" : $alias] = $dir;
                }
            }
            $query->$which->orderBy = $new ?: null;
        }
    }

    /** @var array<string, float|null> handle => distance, for the element being built */
    private array $pending = [];

    /** Takes the distance columns out of the row, because Craft sets every row key on the element. */
    public function beforePopulate(ElementQuery $query, PopulateElementEvent $event): void
    {
        $this->pending = [];
        foreach ($this->active[spl_object_id($query)] ?? [] as $handle => $alias) {
            if (array_key_exists($alias, $event->row)) {
                $this->pending[$handle] = $event->row[$alias] === null ? null : (float)$event->row[$alias];
                unset($event->row[$alias]);
            }
        }
    }

    public function afterPopulate(ElementInterface $element): void
    {
        foreach ($this->pending as $handle => $distance) {
            $value = $element->getFieldValue($handle);
            if ($value instanceof LocationValue) {
                $value->distance = $distance;
            }
        }
        $this->pending = [];
    }
}
