<?php

namespace facilo\rumbo\Craft\jobs;

use Craft;
use craft\queue\BaseJob;
use facilo\rumbo\Craft\CraftAddresses;

/** Finds the latitude and longitude of one of Craft's own addresses (F14). */
class GeocodeAddress extends BaseJob
{
    public int $addressId;

    public function execute($queue): void
    {
        CraftAddresses::geocode($this->addressId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('rumbo-maps', 'Finding the position of an address');
    }
}
