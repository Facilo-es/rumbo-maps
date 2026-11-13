<?php

namespace facilo\rumbo\Craft\web;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class FieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__, 2) . '/web/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['rumbo-field.js'];
        $this->css = ['rumbo-field.css'];
        parent::init();
    }
}
