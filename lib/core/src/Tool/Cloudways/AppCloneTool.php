<?php

/**
 * Clone an application on the same server — `POST /app/{server}/{app}/clone`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Clone` (Proposal 164 section 164.138).
 * One argument beyond the target: the new label.
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

class AppCloneTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_clone';
    }

    public function getName(): string
    {
        return 'Cloudways: Clone App';
    }

    public function getDescription(): string
    {
        return 'Clones an application on the same server under a new label.';
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
                'app_id'    => array(
                    'type'        => 'integer',
                    'description' => 'The application id.',
                    'minimum'     => 1,
                ),
                'label'     => array(
                    'type'        => 'string',
                    'description' => 'Label for the clone.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('server_id', 'app_id', 'label'),
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

        $label = $this->label($arguments);

        if ('' === $label) {
            return $this->errors->validationFailed(
                'A valid label is required.',
                array('label' => array('A short name for the clone.'))
            );
        }

        try {
            $result = $this->client->post(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/clone',
                array('label' => $label)
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            'Application clone initiated.',
            array(
                'label'  => $label,
                'result' => $result,
            )
        );
    }
}
