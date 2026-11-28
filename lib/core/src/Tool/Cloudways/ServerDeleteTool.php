<?php

/**
 * Permanently delete a server — `DELETE /server/{server_id}`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Delete` (Proposal 164 section
 * 164.143). The last of the family's destructive tools and the largest blast
 * radius in the toolkit: every application, database and file on the machine is
 * destroyed, and no tool in this family can bring any of it back.
 *
 * The description states that where the schema is read rather than relying on
 * the caller having read a docblock. The confirmation is the strict literal
 * `true` that `cloudways_app_delete` established — a truthy string is not a
 * confirmation — and the validation names `confirm` rather than leaning on the
 * schema, because a schema is a hint to a client and the check is the guard.
 *
 * `cloudways_server_stop` is the reversible alternative for a machine that only
 * needs to be offline, and `cloudways_create_server_backup` is the step to take
 * first if there is any doubt. Both are named in the refusal path's neighbours
 * because a caller that reaches for delete usually wants one of them.
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

class ServerDeleteTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_server_delete';
    }

    public function getName(): string
    {
        return 'Cloudways: Delete Server';
    }

    public function getDescription(): string
    {
        return 'Permanently deletes a server with every application, database and file on it. Irreversible; requires confirm: true. Use cloudways_server_stop to take a machine offline instead.';
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
                'confirm'   => array(
                    'type'        => 'boolean',
                    'description' => 'Set to true to confirm this irreversible action.',
                ),
            ),
            'required'             => array('server_id', 'confirm'),
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

        if (! $this->confirmed($arguments)) {
            return $this->errors->create(
                'cloudways_confirm_required',
                'You must set confirm to true to perform this destructive action.'
            );
        }

        try {
            $result = $this->client->delete('/server/' . $serverId);
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success('Server permanently deleted.', array('result' => $result));
    }
}
