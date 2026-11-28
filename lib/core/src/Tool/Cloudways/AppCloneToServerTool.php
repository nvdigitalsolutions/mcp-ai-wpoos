<?php

/**
 * Clone an application to another server — `POST /app/{server}/{app}/clone-to-server`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Clone_To_Server` (Proposal 164 section
 * 164.138). The target server must already exist and use a compatible database
 * engine; the API enforces that and its message is surfaced unchanged.
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

class AppCloneToServerTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_clone_to_server';
    }

    public function getName(): string
    {
        return 'Cloudways: Clone App To Server';
    }

    public function getDescription(): string
    {
        return 'Clones an application to a different server under a new label.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id'        => array(
                    'type'        => 'integer',
                    'description' => 'The source server id.',
                    'minimum'     => 1,
                ),
                'app_id'           => array(
                    'type'        => 'integer',
                    'description' => 'The source application id.',
                    'minimum'     => 1,
                ),
                'target_server_id' => array(
                    'type'        => 'integer',
                    'description' => 'The destination server id.',
                    'minimum'     => 1,
                ),
                'label'            => array(
                    'type'        => 'string',
                    'description' => 'Label for the clone on the target server.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('server_id', 'app_id', 'target_server_id', 'label'),
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

        $target = max(0, $this->intParam($arguments, 'target_server_id'));

        if ($target <= 0) {
            return $this->errors->validationFailed(
                'A valid target server id is required.',
                array('target_server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        $label = $this->label($arguments);

        if ('' === $label) {
            return $this->errors->validationFailed(
                'A valid label is required.',
                array('label' => array('A short name for the clone.'))
            );
        }

        try {
            $result = $this->client->post(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/clone-to-server',
                array(
                    'target_server_id' => $target,
                    'label'            => $label,
                )
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            'Application clone initiated.',
            array(
                'target_server_id' => $target,
                'label'            => $label,
                'result'           => $result,
            )
        );
    }
}
