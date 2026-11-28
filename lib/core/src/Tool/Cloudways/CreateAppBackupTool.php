<?php

/**
 * Queue a backup of one application — `POST /app/{server}/{app}/backup`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Create_App_Backup` (Proposal 164 section
 * 164.142).
 *
 * The request has no body: the target in the path is the whole instruction, so
 * an empty array is sent rather than an invented payload.
 *
 * ## Asynchronous, and the answer says so
 *
 * Cloudways queues the backup and returns straight away. The response travels
 * under `result` unchanged because it names the operation a caller polls with
 * `cloudways_get_operation_status` — projecting it into a friendlier shape here
 * would drop the one field the next step needs.
 *
 * Each call queues a new backup, so calling twice is not the same as calling
 * once. There is no `confirm`: a backup is additive and destroys nothing.
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

class CreateAppBackupTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_create_app_backup';
    }

    public function getName(): string
    {
        return 'Cloudways: Create App Backup';
    }

    public function getDescription(): string
    {
        return 'Queues a backup of one application. Asynchronous: the response is returned unchanged because it names the operation to poll with cloudways_get_operation_status.';
    }

    public function getParametersSchema(): array
    {
        return $this->targetSchema();
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
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/backup',
                array()
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Backup initiated for app %d.', $pair['app_id']),
            array('result' => $result)
        );
    }
}
