<?php

/**
 * Permanently delete an application — `DELETE /app/{server}/{app}`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Delete` (Proposal 164 section 164.138).
 * One of the four destructive Cloudways tools.
 *
 * ## The confirmation is strict
 *
 * WordPress required `true === $arguments['confirm']`, and the port keeps that:
 * a truthy string is not a confirmation, and `"false"` is a non-empty string.
 * The schema marks the argument required, and the refusal names it.
 *
 * The WordPress capability flags said `non-reversible`, which is accurate — the
 * application, its files and its database are gone. There is no rollback tool to
 * pair with this one.
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

class AppDeleteTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_delete';
    }

    public function getName(): string
    {
        return 'Cloudways: Delete App';
    }

    public function getDescription(): string
    {
        return 'Permanently deletes an application with its files and database. Irreversible; requires confirm: true.';
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
                'confirm'   => array(
                    'type'        => 'boolean',
                    'description' => 'Set to true to confirm this irreversible action.',
                ),
            ),
            'required'             => array('server_id', 'app_id', 'confirm'),
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

        if (! $this->confirmed($arguments)) {
            return $this->errors->create(
                'cloudways_confirm_required',
                'You must set confirm to true to perform this destructive action.'
            );
        }

        try {
            $result = $this->client->delete(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id']
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success('Application permanently deleted.', array('result' => $result));
    }
}
