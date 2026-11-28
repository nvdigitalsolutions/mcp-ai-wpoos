<?php

/**
 * Undo the most recent restore — `POST /app/{server}/{app}/restore/rollback`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Restore_Rollback` (Proposal 164 section
 * 164.138). No body. Only the most recent restore can be undone, which is why
 * `cloudways_app_restore`'s description says so.
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

class AppRestoreRollbackTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_restore_rollback';
    }

    public function getName(): string
    {
        return 'Cloudways: Roll Back App Restore';
    }

    public function getDescription(): string
    {
        return 'Rolls back the most recent restore of an application.';
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
            ),
            'required'             => array('server_id', 'app_id'),
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

        try {
            $result = $this->client->post(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/restore/rollback'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success('Restore rollback initiated.', array('result' => $result));
    }
}
