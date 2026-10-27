<?php

namespace Tests\Fixtures\DemoBrokenClass;

use App\Extensions\Addons\Addon;
use App\Extensions\ExtensionManifest;
use RuntimeException;

class DemoBrokenClass extends Addon
{
    public function __construct(ExtensionManifest $manifest, array $settings = [])
    {
        throw new RuntimeException('This add-on is broken.');
    }
}
