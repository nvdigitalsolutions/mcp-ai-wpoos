<?php

/**
 * One server's details — `GET /server/{id}`.
 *
 * Ported from `WP_MCP_AI_Tool_Cloudways_Get_Server`: same slug, `server_id`
 * required, the same eight fields. The WordPress fields are the shared nine
 * from the list minus `app_count`, which the single-server response does not
 * carry; this port keeps that shape rather than inventing a zero count that
 * reads as "this server has no apps".
 *
 * A missing or zero id is refused before the request, so a caller that forgot
 * the argument gets a validation error instead of a 404 from Cloudways that
 * says nothing about which argument was wrong.
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

class GetServerTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_get_server';
    }

    public function getName(): string
    {
        return 'Cloudways: Get Server';
    }

    public function getDescription(): string
    {
        return 'Returns one Cloudways server by id: label, status, cloud, region, public IP, RAM and instance type. Read-only.';
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
            $result = $this->client->get('/server/' . $server_id);
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['server'] ?? null)) {
            return $this->malformed('server');
        }

        $server = $result['server'];

        $data = array(
            'id'            => $this->number($server['id'] ?? $server_id),
            'label'         => $this->text($server['label'] ?? null),
            'status'        => $this->text($server['status'] ?? null),
            'cloud'         => $this->text($server['cloud'] ?? null),
            'region'        => $this->text($server['region'] ?? null),
            'public_ip'     => $this->text($server['public_ip'] ?? null),
            'ram'           => $this->text($server['ram'] ?? null),
            'instance_type' => $this->text($server['instance_type'] ?? null),
        );

        return $this->success(
            'Server details for ' . ( '' !== $data['label'] ? $data['label'] : '#' . $server_id ) . '.',
            array('server' => $data)
        );
    }
}
