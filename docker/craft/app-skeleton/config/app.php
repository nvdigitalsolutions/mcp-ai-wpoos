<?php
/**
 * Craft CMS app config — registers the oOS Core module.
 *
 * Published automatically by docker/setup.sh craft.
 * Merge this with your existing config/app.php.
 */

use craft\helpers\App;

return [
    'modules' => [
        'oos-core' => \Nvoos\Craft\Module\OosModule::class,
    ],
    'bootstrap' => [
        'oos-core',
    ],
];
