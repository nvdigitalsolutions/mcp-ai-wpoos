<?php

/**
 * One server's platform settings — `GET /server/{id}/settings`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Settings_Get` (Proposal 164 section
 * 164.133).
 *
 * ## One correction
 *
 * WordPress projected three fields — `php_version`, `mysql_version` and
 * `nginx_version` — and dropped the rest of the settings block, although the
 * tool's own description promised "PHP, MySQL, and nginx versions **and
 * settings**". A caller tuning `memory_limit` or `max_input_vars` could not read
 * it from the tool that advertised it. The three documented keys keep their
 * place, and `values` now carries every scalar field the block returned, so the
 * response is additive rather than reshaped.
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

class ServerSettingsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_server_settings_get';
    }

    public function getName(): string
    {
        return 'Cloudways: Get Server Settings';
    }

    public function getDescription(): string
    {
        return 'Returns a Cloudways server\'s PHP, MySQL, Redis and nginx versions plus every scalar platform setting. Read-only.';
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
            ),
            'required'             => array('server_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $serverId = $this->serverId($arguments);

        if ($serverId <= 0) {
            return $this->errors->validationFailed(
                'A valid server id is required.',
                array('server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        try {
            $result = $this->client->get('/server/' . $serverId . '/settings');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['settings'] ?? null)) {
            return $this->malformed('settings');
        }

        $settings = $result['settings'];
        $values   = array();

        foreach ($settings as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[ $key ] = $value;
            }
        }

        return $this->success(
            sprintf('Settings retrieved for server %d.', $serverId),
            array(
                'settings' => array(
                    'php_version'   => $this->text($settings['php_version'] ?? null),
                    'mysql_version' => $this->text($settings['mysql_version'] ?? null),
                    'nginx_version' => $this->text($settings['nginx_version'] ?? null),
                ),
                'values'   => $values,
            )
        );
    }
}
