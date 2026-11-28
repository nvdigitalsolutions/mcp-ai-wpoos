<?php

/**
 * Resize a server — `PUT /server/{id}/scale`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Scale` (Proposal 164 section
 * 164.139). One size string, sent unchanged: the Cloudways size vocabulary
 * differs per provider and per tier, so validating it here would be a second,
 * worse copy of a list the API already owns and rejects by name.
 *
 * ## One correction
 *
 * WordPress built its success message with
 * `sprintf( __( 'Server scaling initiated.' ), esc_html( $size ) )` — the format
 * string has no placeholder, so the size was escaped and discarded. The message
 * now names the size, which is what makes the response usable in a log.
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

class ServerScaleTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_server_scale';
    }

    public function getName(): string
    {
        return 'Cloudways: Scale Server';
    }

    public function getDescription(): string
    {
        return 'Resizes a server to a new CPU/RAM plan. Billing changes and the resize is asynchronous.';
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
                'size'      => array(
                    'type'        => 'string',
                    'description' => 'The desired size, e.g. "2GB" or "c-8GB".',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('server_id', 'size'),
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

        $size = trim($this->stringParam($arguments, 'size'));

        if ('' === $size) {
            return $this->errors->validationFailed(
                'A valid server size is required.',
                array('size' => array('The plan size, e.g. 2GB.'))
            );
        }

        try {
            $result = $this->client->put('/server/' . $serverId . '/scale', array('size' => $size));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Server %1$d scaling to %2$s initiated.', $serverId, $size),
            array(
                'size'   => $size,
                'result' => $result,
            )
        );
    }
}
