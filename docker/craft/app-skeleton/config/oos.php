<?php
/**
 * oOS Core configuration for Craft CMS.
 *
 * Published automatically by docker/setup.sh craft.
 *
 * @see \Nvoos\Craft\Module\OosModule
 */

use craft\helpers\App;

return [
    'default_provider'     => App::env('OOS_DEFAULT_PROVIDER') ?: 'openai',
    'default_model'        => App::env('OOS_DEFAULT_MODEL') ?: 'gpt-4o-mini',
    'content_section'      => 'posts',
    'cache_duration'       => (int) App::env('OOS_CACHE_DURATION') ?: 3600,
    'queue_ttr'            => (int) App::env('OOS_QUEUE_TTR') ?: 300,
    'storage_volume'       => App::env('OOS_STORAGE_VOLUME') ?: 'uploads',
    'mesh_api_key'         => App::env('OOS_MESH_API_KEY') ?: '',
];
