<?php

/**
 * One application's cron jobs — `GET /app/{server}/{app}/cron`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Cron_List_Get` (Proposal 164 section
 * 164.134). Three fields per job: id, schedule and command. The command is
 * returned as the API sends it, trimmed but not rewritten, because a caller
 * deciding whether a job is safe to keep needs to see the command it runs.
 *
 * Non-array entries are dropped rather than becoming an empty job, and an empty
 * list is a valid answer: an application with no scheduled jobs is ordinary.
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

class CronJobsTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_cron_list_get';
    }

    public function getName(): string
    {
        return 'Cloudways: List Cron Jobs';
    }

    public function getDescription(): string
    {
        return 'Lists an application\'s scheduled cron jobs with their schedule and command. Read-only.';
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

        $serverId = $pair['server_id'];
        $appId    = $pair['app_id'];

        try {
            $result = $this->client->get('/app/' . $serverId . '/' . $appId . '/cron');
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['cron_jobs'] ?? null)) {
            return $this->malformed('cron_jobs');
        }

        $jobs = array();

        foreach ($result['cron_jobs'] as $job) {
            if (! is_array($job)) {
                continue;
            }

            $jobs[] = array(
                'id'       => $this->number($job['id'] ?? 0),
                'schedule' => $this->text($job['schedule'] ?? null),
                'command'  => $this->text($job['command'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d cron %s.', count($jobs), 1 === count($jobs) ? 'job' : 'jobs'),
            array('cron_jobs' => $jobs)
        );
    }
}
