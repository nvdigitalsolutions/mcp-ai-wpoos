<?php

/**
 * One application's Varnish settings — `GET /app/{server}/{app}/varnish/settings`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Get` (Proposal 164
 * section 164.134). `ttl` is an integer, `enabled` a boolean and the two path
 * lists are kept as the API sends them; the writer accepts the same four, so a
 * read-modify-write round trip needs no second call.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\CloudwaysApiException;

class VarnishSettingsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_varnish_settings_get';
    }

    public function getName(): string
    {
        return 'Cloudways: Get Varnish Settings';
    }

    public function getDescription(): string
    {
        return 'Returns an application\'s Varnish settings: ttl, enabled, cacheable_paths and exclusions. Read-only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id' => array(
                    'type'        => 'integer',
                    'description' => 'The Cloudways server id.',
                    'minimum'     => 1,
                ),
                'app_id'    => array(
                    'type'        => 'integer',
                    'description' => 'The application id.',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('server_id', 'app_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $pair = $this->appPair($arguments);

        if (! is_array($pair) || ! isset($pair['app_id'])) {
            return $pair;
        }

        $serverId = $pair['server_id'];
        $appId    = $pair['app_id'];

        try {
            $result = $this->client->get('/app/' . $serverId . '/' . $appId . '/varnish/settings');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['varnish_settings'] ?? null)) {
            return $this->malformed('varnish_settings');
        }

        $varnish = $result['varnish_settings'];

        return $this->success(
            'Varnish settings retrieved.',
            array(
                'varnish_settings' => array(
                    'ttl'             => $this->number($varnish['ttl'] ?? 0),
                    'enabled'         => ! empty($varnish['enabled']),
                    'cacheable_paths' => $this->text($varnish['cacheable_paths'] ?? null),
                    'exclusions'      => $this->text($varnish['exclusions'] ?? null),
                ),
            )
        );
    }
}
