<?php

/**
 * Delete a Pro Schedule and everything stored with it.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule` (Proposal 164 section
 * 164.80). The store removes the definition, its run history and its result
 * envelopes together — WordPress cleared the history and result options, and
 * leaving either behind here would leak runs of a schedule nobody can name.
 *
 * Deletion is irreversible. The schema therefore requires `schedule_id`, and
 * the WordPress guidance says to pause with `update_pro_schedule` when the
 * intent is only to stop.
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

class DeleteProScheduleTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'delete_pro_schedule';
    }

    public function getName(): string
    {
        return 'Delete Pro Schedule';
    }

    public function getDescription(): string
    {
        return 'Permanently removes a named pro schedule and clears its execution history.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'schedule_id' => array(
                    'type'        => 'string',
                    'description' => 'Unique ID of the schedule to delete.',
                    'minLength'   => 1,
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
        return array('requires-capability', 'state-changing', 'write', 'destructive');
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

        $this->schedules->delete($scheduleId);

        return $this->success(
            sprintf('Schedule "%s" deleted successfully.', $schedule['name']),
            array(
                'deleted'     => true,
                'schedule_id' => $scheduleId,
                'name'        => $schedule['name'],
                'message'     => sprintf('Schedule "%s" deleted successfully.', $schedule['name']),
            )
        );
    }
}
