<?php

/**
 * Execution history for one Pro Schedule.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Schedule_Run_History` (Proposal 164 section
 * 164.80). The store keeps a 50-entry ring buffer per schedule; `limit` caps how
 * many of the newest entries the caller sees.
 *
 * ## Timestamps
 *
 * WordPress formatted `start_time` and `last_run_time` with `wp_date(DATE_ATOM)`
 * — site timezone, offset included. The port emits UTC ISO 8601 through the
 * shared `ScheduleDefinition::iso()` helper, and returns `null` for an unset
 * timestamp instead of WordPress's epoch rendering, so "no run recorded" is
 * distinguishable from "ran at midnight in 1970".
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ScheduleStoreInterface as Schedule;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

class GetScheduleRunHistoryTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_schedule_run_history';
    }

    public function getName(): string
    {
        return 'Get Schedule Run History';
    }

    public function getDescription(): string
    {
        return 'Retrieves execution history for a named pro schedule, including run times, durations, and any failure messages.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'schedule_id' => array(
                    'type'        => 'string',
                    'description' => 'Unique ID of the schedule whose history to retrieve.',
                    'minLength'   => 1,
                ),
                'limit'       => array(
                    'type'        => 'integer',
                    'description' => 'Maximum number of history entries to return (1-50). Defaults to 20.',
                    'minimum'     => 1,
                    'maximum'     => Schedule::MAX_HISTORY_PER_SCHEDULE,
                    'default'     => 20,
                ),
            ),
            'required'             => array('schedule_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'local-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $scheduleId = $this->stringParam($arguments, 'schedule_id');

        if ('' === $scheduleId) {
            return $this->errors->create('missing_id', 'A schedule_id is required.');
        }

        $schedule = $this->schedules->find($scheduleId);

        if (null === $schedule) {
            return $this->errors->create(
                'not_found',
                sprintf('Schedule "%s" not found.', $scheduleId)
            );
        }

        $limit = max(1, min(Schedule::MAX_HISTORY_PER_SCHEDULE, $this->intParam($arguments, 'limit', 20)));

        $formatted = array();

        foreach ($this->schedules->history($scheduleId, $limit) as $entry) {
            $formatted[] = array(
                'status'     => (string) ($entry['status'] ?? ''),
                'start_time' => ScheduleDefinition::iso((int) ($entry['start_time'] ?? 0)),
                'duration'   => (int) ($entry['duration'] ?? 0),
                'error'      => (string) ($entry['error'] ?? ''),
            );
        }

        $lastRun = (int) $schedule['last_run_time'];

        return $this->success(
            sprintf(
                'Retrieved %d run(s) for schedule "%s".',
                count($formatted),
                (string) $schedule['name']
            ),
            array(
                'schedule_id'   => $scheduleId,
                'name'          => (string) $schedule['name'],
                'hook'          => (string) $schedule['hook'],
                'run_count'     => (int) $schedule['run_count'],
                'last_status'   => (string) $schedule['last_run_status'],
                'last_run_time' => ScheduleDefinition::iso($lastRun),
                'history'       => $formatted,
                'total_entries' => count($formatted),
            )
        );
    }
}
