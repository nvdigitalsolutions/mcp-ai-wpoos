<?php

/**
 * Purge one application's cache — `POST /app/{server}/{app}/cache/purge`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Purge_App_Cache` (Proposal 164 section
 * 164.136). No body; the operation is the request.
 *
 * The API's answer is returned under `result` unchanged, because it names the
 * operation a caller may want to poll through `cloudways_get_operation_status`.
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

class PurgeAppCacheTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_purge_app_cache';
    }

    public function getName(): string
    {
        return 'Cloudways: Purge App Cache';
    }

    public function getDescription(): string
    {
        return 'Purges an application\'s cache (Varnish, Redis/Memcached and application cache) without changing its settings.';
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
            $result = $this->client->post('/app/' . $serverId . '/' . $appId . '/cache/purge');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Cache purged for app %d.', $appId),
            array('result' => $result)
        );
    }
}
