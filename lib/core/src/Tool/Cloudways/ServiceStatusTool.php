<?php

/**
 * The services running on one server — `GET /service/{server_id}`.
 *
 * Ported from `WP_MCP_AI_Tool_Cloudways_Service_Status`: same slug, `server_id`
 * required, and the same two fields per service. The payload carries a status
 * string (typically `running`), not a boolean: Cloudways also reports states
 * like `stopped` and, during a restart, `restarting`, and collapsing those into
 * true/false would hide the one state a caller polls through.
 *
 * A missing `services` array is refused rather than reported as an empty list,
 * for the same reason as the app list: an account with no services and an API
 * change look identical in a count.
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

class ServiceStatusTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_service_status';
    }

    public function getName(): string
    {
        return 'Cloudways: Service Status';
    }

    public function getDescription(): string
    {
        return 'Lists the services on one Cloudways server with their current status, e.g. nginx and mysql. Read-only.';
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
            $result = $this->client->get('/service/' . $server_id);
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['services'] ?? null)) {
            return $this->malformed('services');
        }

        $services = array();

        foreach ($result['services'] as $service) {
            if (! is_array($service)) {
                continue;
            }

            $services[] = array(
                'name'   => $this->text($service['name'] ?? null),
                'status' => $this->text($service['status'] ?? null),
            );
        }

        return $this->success(
            sprintf('Service status retrieved for server %d.', $server_id),
            array('services' => $services)
        );
    }
}
