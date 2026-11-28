<?php

/**
 * Restore an application — `POST /app/{server}/{app}/restore`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Restore` (Proposal 164 section 164.138).
 * No body; the operation is the request.
 *
 * Restoring overwrites the live application with its most recent backup. No
 * confirmation argument is required, and that is deliberate: the platform's own
 * undo is `cloudways_app_restore_rollback`, which takes the same two ids and
 * needs no extra state, so the pair is reversible in the sense the toolkit's
 * flags claim. A delete, which has no such undo, requires `confirm` instead.
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

class AppRestoreTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_restore';
    }

    public function getName(): string
    {
        return 'Cloudways: Restore App';
    }

    public function getDescription(): string
    {
        return 'Restores an application from its most recent backup. Overwrites current data; cloudways_app_restore_rollback undoes it.';
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
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/restore'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success('Application restore initiated.', array('result' => $result));
    }
}
