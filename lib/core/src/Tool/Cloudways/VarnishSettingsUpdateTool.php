<?php

/**
 * Update an application's Varnish TTL — `PUT /app/{server}/{app}/varnish/settings`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Varnish_Settings_Update` (Proposal 164
 * section 164.136). One field, `ttl`, sent as a partial body.
 *
 * ## One correction
 *
 * WordPress sent `absint( $ttl )` only when it was `> 0`, so a negative TTL
 * became its absolute value and a zero was dropped without explanation. A
 * provided value that is not positive is now named in a validation error, and an
 * absent one is the only way to leave the TTL alone.
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

class VarnishSettingsUpdateTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_varnish_settings_update';
    }

    public function getName(): string
    {
        return 'Cloudways: Update Varnish Settings';
    }

    public function getDescription(): string
    {
        return 'Updates an application\'s Varnish cache TTL in seconds. Only the ttl you pass changes.';
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
                'ttl'       => array(
                    'type'        => 'integer',
                    'description' => 'Cache TTL in seconds (optional).',
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

        $ints = $this->positiveInts($arguments, array('ttl'));

        if (array() !== $ints['invalid']) {
            return $this->invalidFields($ints['invalid'], 'A positive number of seconds is required.');
        }

        if (array() === $ints['set']) {
            return $this->errors->create(
                'cloudways_no_settings',
                'A Varnish setting must be provided: ttl.'
            );
        }

        try {
            $result = $this->client->put(
                '/app/' . $serverId . '/' . $appId . '/varnish/settings',
                $ints['set']
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Varnish settings updated for app %d.', $appId),
            array(
                'varnish_settings' => $ints['set'],
                'result'           => $result,
            )
        );
    }
}
