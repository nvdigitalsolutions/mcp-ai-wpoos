<?php

/**
 * One application's details — `GET /app?server_id=&app_id=`.
 *
 * Ported from `WP_MCP_AI_Tool_Cloudways_Get_App`. Note the path: Cloudways v2
 * has no `/app/{id}` route, so the single-app read is the collection endpoint
 * with both ids in the query, exactly as WordPress does it. Both ids are
 * required and refused before the call — a request with one of them would
 * return the whole server's app list, which is not what the caller asked for
 * and is easy to misread as a successful lookup.
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

class GetAppTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_get_app';
    }

    public function getName(): string
    {
        return 'Cloudways: Get App';
    }

    public function getDescription(): string
    {
        return 'Returns one Cloudways application by server id and app id: label, type and status. Read-only.';
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
                    'description' => 'The Cloudways application id.',
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

        $server_id = $this->serverId($arguments);
        $app_id    = $this->appId($arguments);

        if ($server_id <= 0) {
            return $this->errors->validationFailed(
                'A valid server id is required.',
                array('server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        if ($app_id <= 0) {
            return $this->errors->validationFailed(
                'A valid app id is required.',
                array('app_id' => array('The id shown by cloudways_list_apps.'))
            );
        }

        try {
            $result = $this->client->get(
                '/app',
                array('server_id' => $server_id, 'app_id' => $app_id)
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['app'] ?? null)) {
            return $this->malformed('app');
        }

        $app = $result['app'];

        $data = array(
            'id'       => $this->number($app['id'] ?? $app_id),
            'label'    => $this->text($app['label'] ?? null),
            'app_type' => $this->text($app['app_type'] ?? null),
            'status'   => $this->text($app['status'] ?? null),
        );

        return $this->success(
            'App details for ' . ( '' !== $data['label'] ? $data['label'] : '#' . $app_id ) . '.',
            array('app' => $data)
        );
    }
}
