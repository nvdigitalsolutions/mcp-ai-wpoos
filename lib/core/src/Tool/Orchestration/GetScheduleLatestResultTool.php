<?php

/**
 * The latest result envelope for one Pro Schedule.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Schedule_Latest_Result` (Proposal 164 section
 * 164.80). The envelope is the structured shape a host runner records after a
 * run — `{summary, data, render, response, status, error, generated_at}` — and
 * the same one `render_schedule_result` turns into HTML.
 *
 * ## Capability
 *
 * WordPress accepted `read_private_posts` **or** `manage_options`. The core
 * contract names one capability, so the narrower one is declared; an
 * administrator role holds `read_private_posts` in WordPress, and
 * `AbstractTool::authorize()` itself only requires an authenticated user, so a
 * host enforcing the declared capability does not lock out the broader role.
 *
 * ## No envelope is not an error
 *
 * A schedule that has never run is a normal state, so the tool succeeds with
 * `envelope: null` and a message rather than failing — the same choice as
 * WordPress.
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

class GetScheduleLatestResultTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_schedule_latest_result';
    }

    public function getName(): string
    {
        return 'Get Schedule Latest Result';
    }

    public function getDescription(): string
    {
        return 'Returns the structured result envelope (summary, data, render hint) produced by the most recent run of a Pro Schedule.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'schedule_id' => array(
                    'type'        => 'string',
                    'description' => 'ID of the schedule whose latest result to retrieve.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('schedule_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read_private_posts';
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
        $this->authorize($context, 'read_private_posts');

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

        $envelope = $this->schedules->latestResult($scheduleId);
        $name     = (string) $schedule['name'];

        if (null === $envelope) {
            $message = 'No runs recorded for this schedule yet.';

            return $this->success(
                $message,
                array(
                    'schedule_id' => $scheduleId,
                    'name'        => $name,
                    'envelope'    => null,
                    'message'     => $message,
                )
            );
        }

        return $this->success(
            sprintf('Latest result retrieved for schedule "%s".', $name),
            array(
                'schedule_id' => $scheduleId,
                'name'        => $name,
                'envelope'    => $envelope,
            )
        );
    }
}
