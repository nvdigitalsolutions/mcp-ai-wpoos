<?php

/**
 * Queue a backup of every application on a server — `POST /server/{server_id}/backup`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Create_Server_Backup` (Proposal 164 section
 * 164.142).
 *
 * The server-scoped sibling of `cloudways_create_app_backup`: no body, the
 * server id in the path is the instruction, and the API's answer travels under
 * `result` unchanged because it names the operation to poll with
 * `cloudways_get_operation_status`.
 *
 * A server backup covers every app on the machine and is heavier than the
 * app-scoped call. The description says so; no confirm is required, because a
 * backup is additive and destroys nothing, and each call queues an additional
 * backup rather than replacing one.
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

class CreateServerBackupTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_create_server_backup';
    }

    public function getName(): string
    {
        return 'Cloudways: Create Server Backup';
    }

    public function getDescription(): string
    {
        return 'Queues a full backup of a server, covering every application on it. Asynchronous: the response names the operation to poll with cloudways_get_operation_status.';
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
            $result = $this->client->post('/server/' . $serverId . '/backup', array());
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Backup initiated for server %d.', $serverId),
            array('result' => $result)
        );
    }
}
