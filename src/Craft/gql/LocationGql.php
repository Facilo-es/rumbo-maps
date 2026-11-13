<?php

namespace facilo\rumbo\Craft\gql;

use craft\gql\GqlEntityRegistry;
use facilo\rumbo\Craft\fields\Location;
use facilo\rumbo\Craft\LocationValue;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/** GraphQL type and query argument for Location fields (F9). */
class LocationGql
{
    public static function type(Location $field): Type
    {
        $name = 'RumboLocation';
        if ($type = GqlEntityRegistry::getEntity($name)) {
            return $type;
        }
        $parts = new ObjectType([
            'name' => 'RumboAddressParts',
            'fields' => array_fill_keys(['number', 'street', 'city', 'postcode', 'county', 'state', 'country', 'countryCode'], Type::string()),
        ]);
        return GqlEntityRegistry::createEntity($name, new ObjectType([
            'name' => $name,
            'fields' => [
                'lat' => ['type' => Type::float(), 'resolve' => fn(LocationValue $v) => $v->lat === null ? null : (float)$v->lat],
                'lng' => ['type' => Type::float(), 'resolve' => fn(LocationValue $v) => $v->lng === null ? null : (float)$v->lng],
                'zoom' => Type::int(),
                'address' => Type::string(),
                'countryCode' => Type::string(),
                'serviceRadius' => Type::float(),
                'distance' => Type::float(),
                'parts' => ['type' => $parts, 'resolve' => fn(LocationValue $v) => $v->parts],
                'directionsUrl' => ['type' => Type::string(), 'resolve' => fn(LocationValue $v) => $v->directionsUrl()],
            ],
        ]));
    }

    public static function argumentType(): Type
    {
        $name = 'RumboLocationQuery';
        return GqlEntityRegistry::getEntity($name) ?: GqlEntityRegistry::createEntity($name, new InputObjectType([
            'name' => $name,
            'fields' => [
                'near' => Type::string(),
                'lat' => Type::float(),
                'lng' => Type::float(),
                'within' => Type::float(),
                'unit' => Type::string(),
                'country' => Type::string(),
                // The old plugin's shape, so existing queries keep working (F4).
                'location' => ['type' => Type::string(), 'description' => 'An address or place to search around, as in Maps by Ether Creative.'],
                'radius' => ['type' => Type::float(), 'description' => 'Distance to search within, in `unit`. Same as `within`.'],
                'coordinate' => self::coordinateType(),
                // Service areas (F5): locations whose service radius reaches this address or "lat,lng".
                'covers' => ['type' => Type::string(), 'description' => 'An address or "lat,lng". Returns locations whose service radius includes it.'],
            ],
        ]));
    }

    public static function coordinateType(): Type
    {
        $name = 'RumboCoordinateInput';
        return GqlEntityRegistry::getEntity($name) ?: GqlEntityRegistry::createEntity($name, new InputObjectType([
            'name' => $name,
            'fields' => [
                'lat' => Type::nonNull(Type::float()),
                'lng' => Type::nonNull(Type::float()),
            ],
        ]));
    }
}
