<?php

namespace facilo\rumbo\Craft;

use Craft;
use craft\helpers\App;
use facilo\rumbo\Plugin;

/** The visitor's rough position from a MaxMind GeoLite2 City file the site owner provides. */
class VisitorLocation
{
    public static function lookup(?string $ip = null): ?array
    {
        $path = App::parseEnv(Plugin::getInstance()->getSettings()->geoLiteDb);
        if (!$path || !is_file($path) || !class_exists(\GeoIp2\Database\Reader::class)) {
            return null;
        }
        $ip ??= (string)Craft::$app->getRequest()->getUserIP();
        try {
            $record = (new \GeoIp2\Database\Reader($path))->city($ip);
        } catch (\Throwable) {
            return null;
        }
        return [
            'lat' => $record->location->latitude,
            'lng' => $record->location->longitude,
            'city' => $record->city->name,
            'countryCode' => $record->country->isoCode,
        ];
    }
}
