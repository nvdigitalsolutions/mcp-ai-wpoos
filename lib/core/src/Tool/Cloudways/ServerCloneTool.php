<?php

/**
 * Clone a whole server — `POST /server/{id}/clone`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Clone` (Proposal 164 section 164.139).
 * A clone is a new, separately billed server, so the response carries the
 * operation to poll.
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

class ServerCloneTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_server_clone';
    }

    public function getName(): string
    {
        return 'Cloudways: Clone Server';
    }

    public function getDescription(): string
    {
        return 'Clones a whole server, applications included, as a new billed server under a new label.';
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
                'label'     => array(
                    'type'        => 'string',
                    'description' => 'Label for the cloned server.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('server_id', 'label'),
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

        $label = $this->label($arguments);

        if ('' === $label) {
            return $this->errors->validationFailed(
                'A valid label is required.',
                array('label' => array('A short name for the cloned server.'))
            );
        }

        try {
            $result = $this->client->post('/server/' . $serverId . '/clone', array('label' => $label));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            'Server clone initiated.',
            array(
                'label'  => $label,
                'result' => $result,
            )
        );
    }
}
