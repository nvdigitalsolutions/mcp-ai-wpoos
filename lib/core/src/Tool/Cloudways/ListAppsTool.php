<?php

/**
 * The applications on one server — `GET /app?server_id=`.
 *
 * Ported from `WP_MCP_AI_Tool_Cloudways_List_Apps`: same slug, `server_id`
 * required, four fields per app. The four are the ones the Pro toolkit's
 * consumers read; the v2 payload carries the app's stack, its FQDN and its
 * deployment state, and a consumer that needs those asks `cloudways_get_app`.
 *
 * An empty `apps` array is a valid answer — a server created for PHP only has
 * none — so only a missing array is refused.
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

class ListAppsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_list_apps';
    }

    public function getName(): string
    {
        return 'Cloudways: List Apps';
    }

    public function getDescription(): string
    {
        return 'Lists the applications installed on one Cloudways server with their id, label, type and status. Read-only.';
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

        $server_id = $this->serverId($arguments);

        if ($server_id <= 0) {
            return $this->errors->validationFailed(
                'A valid server id is required.',
                array('server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        try {
            $result = $this->client->get('/app', array('server_id' => $server_id));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['apps'] ?? null)) {
            return $this->malformed('apps');
        }

        $apps = array();

        foreach ($result['apps'] as $app) {
            if (! is_array($app)) {
                continue;
            }

            $apps[] = array(
                'id'       => $this->number($app['id'] ?? null),
                'label'    => $this->text($app['label'] ?? null),
                'app_type' => $this->text($app['app_type'] ?? null),
                'status'   => $this->text($app['status'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d app(s) on server %d.', count($apps), $server_id),
            array('apps' => $apps)
        );
    }
}
