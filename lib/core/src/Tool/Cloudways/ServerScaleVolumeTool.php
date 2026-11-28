<?php

/**
 * Resize a server's data volume — `PUT /server/{id}/volume/scale`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Scale_Volume` (Proposal 164 section
 * 164.139). Amazon and GCE only; the API refuses the call elsewhere and its
 * message names the constraint.
 *
 * A volume can only grow, so this is the one "scale" that is not reversible —
 * WordPress still flagged it `reversible`, which the port's description corrects
 * in words rather than in flags, because the flag set is the toolkit's shared
 * vocabulary.
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

class ServerScaleVolumeTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_server_scale_volume';
    }

    public function getName(): string
    {
        return 'Cloudways: Scale Server Volume';
    }

    public function getDescription(): string
    {
        return 'Grows a server\'s data volume in GB. Amazon and GCE only, and a volume cannot be shrunk again.';
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
                'size_gb'   => array(
                    'type'        => 'integer',
                    'description' => 'The desired volume size in GB.',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('server_id', 'size_gb'),
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

        $ints = $this->positiveInts($arguments, array('size_gb'));

        if (array() !== $ints['invalid']) {
            return $this->invalidFields($ints['invalid'], 'A positive number of GB is required.');
        }

        if (array() === $ints['set']) {
            return $this->errors->validationFailed(
                'A valid volume size in GB is required.',
                array('size_gb' => array('The new volume size, in GB.'))
            );
        }

        $size = $ints['set']['size_gb'];

        try {
            $result = $this->client->put('/server/' . $serverId . '/volume/scale', array('size_gb' => $size));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Server %1$d volume scaling to %2$d GB initiated.', $serverId, $size),
            array(
                'size_gb' => $size,
                'result'  => $result,
            )
        );
    }
}
