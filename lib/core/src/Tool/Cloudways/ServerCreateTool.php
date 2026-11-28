<?php

/**
 * Create a server with an initial application — `POST /server`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Server_Create` (Proposal 164 section
 * 164.139). Five arguments, two of them enums.
 *
 * Server creation is billed from the moment the API accepts it and takes several
 * minutes, so the response carries the operation to poll rather than pretending
 * the server exists. No confirmation argument: a create is recoverable by
 * deleting the server, and the WordPress toolkit only requires `confirm` on
 * irreversible actions.
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
use Nvoos\Core\Support\Text;

class ServerCreateTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_server_create';
    }

    public function getName(): string
    {
        return 'Cloudways: Create Server';
    }

    public function getDescription(): string
    {
        return 'Creates a Cloudways server with an initial application. Billed from acceptance and takes several minutes; poll the returned operation.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'cloud'  => array(
                    'type'        => 'string',
                    'description' => 'The cloud provider.',
                    'enum'        => self::CLOUDS,
                ),
                'size'   => array(
                    'type'        => 'string',
                    'description' => 'The server size, e.g. "2GB".',
                    'minLength'   => 1,
                ),
                'region' => array(
                    'type'        => 'string',
                    'description' => 'The deployment region.',
                    'minLength'   => 1,
                ),
                'app'    => array(
                    'type'        => 'string',
                    'description' => 'The initial application type.',
                    'enum'        => self::APP_TYPES,
                ),
                'label'  => array(
                    'type'        => 'string',
                    'description' => 'The server label.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('cloud', 'size', 'region', 'app', 'label'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $cloud  = Text::sanitizeKey($this->stringParam($arguments, 'cloud'));
        $size   = trim($this->stringParam($arguments, 'size'));
        $region = trim($this->stringParam($arguments, 'region'));
        $app    = Text::sanitizeKey($this->stringParam($arguments, 'app'));
        $label  = $this->label($arguments);

        $invalid = array();

        if (! in_array($cloud, self::CLOUDS, true)) {
            $invalid['cloud'] = array('One of: ' . implode(', ', self::CLOUDS) . '.');
        }

        if ('' === $size) {
            $invalid['size'] = array('The size, e.g. 2GB.');
        }

        if ('' === $region) {
            $invalid['region'] = array('The region id, e.g. lon1.');
        }

        if (! in_array($app, self::APP_TYPES, true)) {
            $invalid['app'] = array('One of: ' . implode(', ', self::APP_TYPES) . '.');
        }

        if ('' === $label) {
            $invalid['label'] = array('A short name for the server.');
        }

        if (array() !== $invalid) {
            return $this->errors->validationFailed('One or more arguments are unusable.', $invalid);
        }

        try {
            $result = $this->client->post(
                '/server',
                array(
                    'cloud'  => $cloud,
                    'size'   => $size,
                    'region' => $region,
                    'app'    => $app,
                    'label'  => $label,
                )
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            'Server creation initiated.',
            array(
                'cloud'  => $cloud,
                'size'   => $size,
                'region' => $region,
                'app'    => $app,
                'label'  => $label,
                'result' => $result,
            )
        );
    }
}
