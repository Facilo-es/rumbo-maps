<?php

namespace facilo\rumbo\Craft\feedme;

use Cake\Utility\Hash;
use craft\feedme\base\Field;
use craft\feedme\base\FieldInterface;
use craft\feedme\helpers\DataHelper;
use facilo\rumbo\Craft\fields\Location;

/**
 * Feed Me support (F13): map an address, a latitude and longitude, or both. Nothing is looked up
 * during the import, so a feed never stalls; entries without coordinates get them from the queue.
 * Only loaded when Feed Me is installed.
 */
class LocationFeedMe extends Field implements FieldInterface
{
    public static string $name = 'Rumbo Maps';
    public static string $class = Location::class;

    public function getMappingTemplate(): string
    {
        // Feed Me's own address/lat/lng/zoom mapping rows fit this field as they are.
        return 'feed-me/_includes/fields/simple-map';
    }

    public function parseField(): mixed
    {
        $fields = Hash::get($this->fieldInfo, 'fields');
        if (!$fields) {
            return null;
        }
        $data = [];
        foreach ($fields as $handle => $info) {
            $value = DataHelper::fetchValue($this->feedData, $info, $this->feed);
            if ($value !== null && $value !== '') {
                $data[$handle] = is_string($value) ? trim($value) : $value;
            }
        }
        if (!isset($data['lat'], $data['lng']) || !is_numeric($data['lat']) || !is_numeric($data['lng'])) {
            unset($data['lat'], $data['lng']);
        }
        if (!$data || (!isset($data['lat']) && trim((string)($data['address'] ?? '')) === '')) {
            return null;
        }
        // A position from the feed is the site's own data; one we look up later says where it came from.
        $data['source'] = isset($data['lat']) ? 'import' : 'pending';
        return $data;
    }
}
