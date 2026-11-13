<?php

namespace facilo\rumbo\Craft;

use Craft;
use craft\elements\Address;
use craft\helpers\Queue;
use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Geo;
use facilo\rumbo\Craft\jobs\GeocodeAddress;
use facilo\rumbo\Plugin;

/**
 * Craft's own Address elements (users, Commerce stores and orders) on maps and in nearest
 * queries (F14, Pro). With the setting on, a saved address gets its latitude and longitude
 * in a queue job, written to Craft's own latitude and longitude attributes.
 */
class CraftAddresses
{
    /** True while the job saves an address, so that save doesn't queue another lookup. */
    public static bool $saving = false;

    /** Address fields whose change means the position must be looked up again. */
    private const TEXT_ATTRIBUTES = ['addressLine1', 'addressLine2', 'addressLine3', 'locality', 'dependentLocality', 'postalCode', 'administrativeArea', 'countryCode'];

    public static function afterSave(Address $address, bool $isNew): ?string
    {
        $plugin = Plugin::getInstance();
        if (self::$saving || !$plugin->isPro() || !$plugin->getSettings()->geocodeAddresses || $address->getIsDraft() || $address->getIsRevision()) {
            return null;
        }
        if (self::oneLine($address) === '') {
            return null;
        }
        $changed = $isNew;
        foreach (self::TEXT_ATTRIBUTES as $attribute) {
            $changed = $changed || $address->isAttributeDirty($attribute);
        }
        $missing = $address->latitude === null || $address->latitude === '' || $address->longitude === null || $address->longitude === '';
        if (!$changed && !$missing) {
            return null;
        }
        return Queue::push(new GeocodeAddress(['addressId' => (int)$address->id]));
    }

    /** Looks up one address and saves its position. Returns true when it found one. */
    public static function geocode(int $addressId): bool
    {
        $address = Address::find()->id($addressId)->status(null)->one();
        if (!$address instanceof Address) {
            return false;
        }
        $text = self::oneLine($address);
        $result = $text === '' ? null : Plugin::getInstance()->geocoding->place($text, $address->countryCode ?: null);
        if ($result === null) {
            Craft::info("No position found for address $addressId.", 'rumbo-maps');
            return false;
        }
        $address->latitude = (string)$result->lat;
        $address->longitude = (string)$result->lng;
        self::$saving = true;
        try {
            return Craft::$app->getElements()->saveElement($address, false, false, false);
        } finally {
            self::$saving = false;
        }
    }

    public static function parts(Address $address): AddressParts
    {
        return AddressParts::fromArray([
            'street' => trim(implode(' ', array_filter([$address->addressLine1, $address->addressLine2]))) ?: null,
            'city' => $address->locality,
            'postcode' => $address->postalCode,
            'state' => $address->administrativeArea,
            'countryCode' => $address->countryCode,
            'country' => $address->countryCode ? (Craft::$app->getAddresses()->getCountryRepository()->get($address->countryCode)->getName()) : null,
        ]);
    }

    public static function oneLine(Address $address): string
    {
        try {
            return self::parts($address)->oneLine();
        } catch (\Throwable) {
            return '';
        }
    }

    public static function toLocation(Address $address): LocationValue
    {
        $parts = self::parts($address)->toArray();
        return new LocationValue([
            'lat' => $address->latitude,
            'lng' => $address->longitude,
            'address' => self::parts($address)->oneLine(),
            'parts' => $parts,
            'countryCode' => $address->countryCode,
            'source' => 'craft-address',
            'elementId' => (int)$address->id,
            'siteId' => (int)$address->siteId,
        ]);
    }

    /**
     * Addresses with a position, as locations: `rumbo.addresses({ ownerId: currentUser.id })`,
     * or the nearest first: `rumbo.addresses({ near: 'Valencia', within: 50, unit: 'km', limit: 10 })`.
     * Other keys are criteria for Craft's address query.
     *
     * @return LocationValue[]
     */
    public static function find(array $options = []): array
    {
        $near = $options['near'] ?? $options['location'] ?? null;
        $within = isset($options['within']) ? (float)$options['within'] : (isset($options['radius']) ? (float)$options['radius'] : null);
        $unit = in_array($options['unit'] ?? 'km', ['mi', 'miles'], true) ? 'mi' : 'km';
        $limit = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
        $criteria = array_diff_key($options, array_flip(['near', 'location', 'within', 'radius', 'unit', 'limit']));

        $query = Address::find()->status(null);
        Craft::configure($query, $criteria);
        $query->andWhere(['not', ['addresses.latitude' => null]])->andWhere(['not', ['addresses.longitude' => null]])
            ->andWhere(['not', ['addresses.latitude' => '']]);

        $point = null;
        if ($near !== null && $near !== '') {
            $point = Geo::parseLatLng($near);
            if ($point === null && is_string($near)) {
                $found = Plugin::getInstance()->geocoding->place($near);
                $point = $found ? ['lat' => $found->lat, 'lng' => $found->lng] : null;
            }
            if ($point === null) {
                return [];
            }
        }

        $out = [];
        foreach ($query->all() as $address) {
            $value = self::toLocation($address);
            if (!$value->hasPosition()) {
                continue;
            }
            if ($point !== null) {
                $km = Geo::distanceKm((float)$point['lat'], (float)$point['lng'], (float)$value->lat, (float)$value->lng);
                $value->distance = round($unit === 'mi' ? Geo::kmToMiles($km) : $km, 3);
                if ($within !== null && $value->distance > $within) {
                    continue;
                }
            }
            $out[] = $value;
        }
        if ($point !== null) {
            usort($out, fn(LocationValue $a, LocationValue $b) => $a->distance <=> $b->distance);
        }
        return $limit ? array_slice($out, 0, $limit) : $out;
    }
}
