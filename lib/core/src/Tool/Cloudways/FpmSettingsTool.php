<?php

/**
 * One application's PHP-FPM settings — `GET /app/{server}/{app}/fpm/settings`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Get` (Proposal 164
 * section 164.134). Four values, the ones the writer tool can change, so a
 * read-modify-write round trip is complete without a second call.
 *
 * The two counts are integers and the two strings are strings, matching the
 * writer's schema; WordPress cast them the same way but warned on a missing key,
 * which the port does not.
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

class FpmSettingsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_fpm_settings_get';
    }

    public function getName(): string
    {
        return 'Cloudways: Get PHP-FPM Settings';
    }

    public function getDescription(): string
    {
        return 'Returns an application\'s PHP-FPM settings: max_children, max_requests, memory_limit and process_manager. Read-only.';
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
            $result = $this->client->get('/app/' . $serverId . '/' . $appId . '/fpm/settings');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['fpm_settings'] ?? null)) {
            return $this->malformed('fpm_settings');
        }

        $fpm = $result['fpm_settings'];

        return $this->success(
            'PHP-FPM settings retrieved.',
            array(
                'fpm_settings' => array(
                    'max_children'    => $this->number($fpm['max_children'] ?? 0),
                    'max_requests'    => $this->number($fpm['max_requests'] ?? 0),
                    'memory_limit'    => $this->text($fpm['memory_limit'] ?? null),
                    'process_manager' => $this->text($fpm['process_manager'] ?? null),
                ),
            )
        );
    }
}
