<?php

/**
 * Update an application's PHP-FPM settings — `PUT /app/{server}/{app}/fpm/settings`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_FPM_Settings_Update` (Proposal 164
 * section 164.136). A partial body: only the fields the caller provided are
 * sent, so an update does not reset the other two.
 *
 * ## One correction
 *
 * WordPress passed all three values through `absint()` and dropped any that were
 * not `> 0`. A negative `max_children` was therefore sent as its absolute value,
 * and a zero disappeared from the body with no explanation. Here an absent field
 * stays absent and a provided field with an unusable value is named in a
 * validation error, so nothing is rewritten and nothing is silently dropped.
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

class FpmSettingsUpdateTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_fpm_settings_update';
    }

    public function getName(): string
    {
        return 'Cloudways: Update PHP-FPM Settings';
    }

    public function getDescription(): string
    {
        return 'Updates an application\'s PHP-FPM settings. Only the fields you pass change: max_children, max_requests and memory_limit.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id'    => array(
                    'type'        => 'integer',
                    'description' => 'The Cloudways server id.',
                    'minimum'     => 1,
                ),
                'app_id'       => array(
                    'type'        => 'integer',
                    'description' => 'The application id.',
                    'minimum'     => 1,
                ),
                'max_children' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum child processes (optional).',
                    'minimum'     => 1,
                ),
                'max_requests' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum requests per child process (optional).',
                    'minimum'     => 1,
                ),
                'memory_limit' => array(
                    'type'        => 'string',
                    'description' => 'PHP memory limit, e.g. 256M (optional).',
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

        $ints = $this->positiveInts($arguments, array('max_children', 'max_requests'));

        if (array() !== $ints['invalid']) {
            return $this->invalidFields($ints['invalid']);
        }

        $body = $ints['set'];

        if (array_key_exists('memory_limit', $arguments)) {
            $limit = $this->stringParam($arguments, 'memory_limit');

            if ('' === $limit) {
                return $this->invalidFields(array('memory_limit'), 'A non-empty value such as 256M is required.');
            }

            $body['memory_limit'] = $limit;
        }

        if (array() === $body) {
            return $this->errors->create(
                'cloudways_no_settings',
                'At least one setting must be provided: max_children, max_requests or memory_limit.'
            );
        }

        try {
            $result = $this->client->put('/app/' . $serverId . '/' . $appId . '/fpm/settings', $body);
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('PHP-FPM settings updated for app %d.', $appId),
            array(
                'fpm_settings' => $body,
                'result'       => $result,
            )
        );
    }
}
