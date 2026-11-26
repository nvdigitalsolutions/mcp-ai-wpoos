<?php

/**
 * Rename a server — `PUT /server/{server_id}/label`.
 *
 * Ported from `WP_MCP_AI_Tool_Cloudways_Update_Server_Label`: same slug, same
 * two arguments, same body, and the API's answer travels as the payload
 * unchanged, as it does in WordPress.
 *
 * The length rule is the API's, not this tool's. WordPress declares
 * `minLength: 1` and nothing else; the v2 endpoint has its own bounds, and
 * guessing them here would produce a refusal for a label Cloudways would have
 * accepted. An out-of-range label therefore comes back as the API's message
 * with its status, which is the accurate answer.
 *
 * Whitespace is trimmed before the emptiness check, so a label of spaces is
 * refused locally rather than sent as an empty name that Cloudways would
 * accept and the operator would then see as a blank server.
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

class UpdateServerLabelTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_update_server_label';
    }

    public function getName(): string
    {
        return 'Cloudways: Update Server Label';
    }

    public function getDescription(): string
    {
        return 'Renames a Cloudways server. The label is trimmed and must not be empty; the API decides whether the new name is acceptable and its answer is returned unchanged.';
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
                    'description' => 'The new server label.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
            ),
            'required'             => array('server_id', 'label'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $server_id = $this->serverId($arguments);
        $label     = trim($this->stringParam($arguments, 'label'));

        if ($server_id <= 0) {
            return $this->errors->validationFailed(
                'A valid server id is required.',
                array('server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        if ('' === $label) {
            return $this->errors->validationFailed(
                'A label is required.',
                array('label' => array('The new name for the server.'))
            );
        }

        try {
            $result = $this->client->put('/server/' . $server_id . '/label', array('label' => $label));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Server renamed to "%s" (ID: %d).', $label, $server_id),
            $result
        );
    }
}
