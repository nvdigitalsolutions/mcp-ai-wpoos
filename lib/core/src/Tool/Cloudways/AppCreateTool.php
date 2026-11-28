<?php

/**
 * Create an application on a server — `POST /app`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Create` (Proposal 164 section 164.138).
 * Three arguments: the server, the application type and a label.
 *
 * The five types are the WordPress toolkit's own enum, kept verbatim so an
 * existing call validates the same way; Cloudways maps them to stack versions.
 * `wordpressdefault` and `phpstack`, which the Cloudways MCP surface also
 * accepts, are not part of this tool — adding them would be a new capability,
 * not parity.
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

class AppCreateTool extends CloudwaysTool
{
    /** The application types this tool accepts. */
    public const APPS = array('wordpress', 'woocommerce', 'laravel', 'magento', 'php');

    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_create';
    }

    public function getName(): string
    {
        return 'Cloudways: Create App';
    }

    public function getDescription(): string
    {
        return 'Creates an application on an existing server: wordpress, woocommerce, laravel, magento or php.';
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
                'app'       => array(
                    'type'        => 'string',
                    'description' => 'The application type.',
                    'enum'        => self::APPS,
                ),
                'label'     => array(
                    'type'        => 'string',
                    'description' => 'The application label.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('server_id', 'app', 'label'),
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

        $app = Text::sanitizeKey($this->stringParam($arguments, 'app'));

        if (! in_array($app, self::APPS, true)) {
            return $this->errors->validationFailed(
                'A valid application type is required.',
                array('app' => array('One of: ' . implode(', ', self::APPS) . '.'))
            );
        }

        $label = $this->label($arguments);

        if ('' === $label) {
            return $this->errors->validationFailed(
                'A valid label is required.',
                array('label' => array('A short name for the new application.'))
            );
        }

        try {
            $result = $this->client->post(
                '/app',
                array(
                    'server_id' => $serverId,
                    'app'       => $app,
                    'label'     => $label,
                )
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            'Application creation initiated.',
            array(
                'app'    => $app,
                'label'  => $label,
                'result' => $result,
            )
        );
    }
}
