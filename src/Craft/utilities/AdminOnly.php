<?php

namespace facilo\rumbo\Craft\utilities;

use Craft;

/**
 * Rumbo's utilities move data, test secret keys and import files, so only admins see them, even when a
 * user group has been given the utility permission. The controller actions check for an admin too.
 */
trait AdminOnly
{
    public static function isSelectable(): bool
    {
        return Craft::$app->getRequest()->getIsConsoleRequest() || Craft::$app->getUser()->getIsAdmin();
    }
}
