<?php

/**
 * Every server on the Cloudways account — `GET /server`.
 *
 * Ported from `WP_MCP_AI_Tool_Cloudways_List_Servers`: same slug, no arguments,
 * and the same nine fields per server, because those are the fields the Pro
 * toolkit's own consumers read. The v2 response carries more (the embedded app
 * list, the FQDN, the PHP version); widening this payload would change a
 * contract that has an existing caller, so a consumer that wants more asks
 * `cloudways_get_server` or `cloudways_list_apps`.
 *
 * A response without a `servers` array is refused rather than reported as
 * "found 0 servers". The two are different facts: an empty account and an API
 * change look identical in a count, and only one of them is the operator's
 * problem.
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

class ListServersTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_list_servers';
    }

    public function getName(): string
    {
        return 'Cloudways: List Servers';
    }

    public function getDescription(): string
    {
        return 'Lists every server on the connected Cloudways account with its status, cloud, region, public IP, app count, RAM and instance type. Read-only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        try {
            $result = $this->client->get('/server');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['servers'] ?? null)) {
            return $this->malformed('servers');
        }

        $servers = array();

        foreach ($result['servers'] as $server) {
            if (! is_array($server)) {
                continue;
            }

            $servers[] = array(
                'id'            => $this->number($server['id'] ?? null),
                'label'         => $this->text($server['label'] ?? null),
                'status'        => $this->text($server['status'] ?? null),
                'cloud'         => $this->text($server['cloud'] ?? null),
                'region'        => $this->text($server['region'] ?? null),
                'public_ip'     => $this->text($server['public_ip'] ?? null),
                'app_count'     => $this->number($server['app_count'] ?? null),
                'ram'           => $this->text($server['ram'] ?? null),
                'instance_type' => $this->text($server['instance_type'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d server(s).', count($servers)),
            array('servers' => $servers)
        );
    }
}
