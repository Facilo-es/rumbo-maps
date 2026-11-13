<?php

namespace facilo\rumbo\Craft\web;

use craft\web\AssetBundle;

/** One small classic-script loader (it finds its folder from its own src); it pulls in the map library only when a map scrolls into view. */
class FrontAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__, 2) . '/web/dist';
        $this->css = ['rumbo-locator.css'];
        $this->js = ['rumbo-loader.js'];
        parent::init();
    }
}
