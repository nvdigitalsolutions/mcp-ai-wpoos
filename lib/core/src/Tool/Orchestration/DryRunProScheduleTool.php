<?php

/**
 * Read-only "what would this schedule do" inspector.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule` (Proposal 164 section
 * 164.80). The tool projects upcoming runs and describes the configured action
 * without dispatching a hook, calling a network service or recording a run.
 *
 * ## Warnings, not silence
 *
 * WordPress assembled four warnings so a caller learns *why* nothing would
 * fire: the schedule is paused, it has no cadence, its cadence is not
 * registered, or no runs could be projected. The port keeps all four. The
 * empty-cadence branch is unreachable for a record read back through
 * `JsonScheduleStore` — hydration defaults a missing cadence to `single` — but
 * it costs one comparison and keeps the projection honest if another store
 * implementation returns a sparser record.
 *
 * ## Timezone
 *
 * WordPress reported `wp_timezone_string()` so the caller could interpret the
 * timestamps. The framework-free core has no site timezone, and every timestamp
 * it returns is UTC, so the field reports `UTC` rather than pretending
 * otherwise.
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
use Nvoos\Core\Domain\ValueObject\ScheduleInterval;
use Nvoos\Core\Tool\AbstractTool;

class DryRunProScheduleTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'dry_run_pro_schedule';
    }

    public function getName(): string
    {
        return 'Dry-run Pro Schedule';
    }

    public function getDescription(): string
    {
        return 'Simulates a Pro Schedule without executing it. Returns the next N upcoming runs and a summary of the action the schedule would perform (task hook, assistant message, broadcast channels, workflow steps).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'schedule_id' => array(
                    'type'        => 'string',
                    'description' => 'Schedule identifier to dry-run.',
                    'minLength'   => 1,
                ),
                'count'       => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 30,
                    'default'     => 5,
                    'description' => 'How many upcoming run timestamps to project.',
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
            return $this->errors->create('missing_schedule_id', 'A schedule_id is required.');
        }

        $schedule = $this->schedules->find($scheduleId);

        if (null === $schedule) {
            return $this->errors->create('schedule_not_found', 'Schedule not found.');
        }

        $count = max(1, min(30, $this->intParam($arguments, 'count', 5)));
        $times = ScheduleDefinition::nextRuns($schedule, $count);

        $nextRuns = array();

        foreach ($times as $when) {
            $nextRuns[] = array(
                'timestamp' => $when,
                'iso8601'   => ScheduleDefinition::iso($when),
            );
        }

        $cadence = (string) $schedule['schedule'];
        $enabled = (bool) $schedule['enabled'];
        $warnings = array();

        if (! $enabled) {
            $warnings[] = 'Schedule is currently paused and would not dispatch.';
        }

        if ('' === $cadence) {
            $warnings[] = 'No cadence is configured; the schedule will not fire.';
        } elseif (ScheduleInterval::SINGLE !== $cadence && ! ScheduleInterval::isValid($cadence)) {
            $warnings[] = sprintf(
                'Cadence "%s" is not registered; the schedule will not fire.',
                $cadence
            );
        }

        if (array() === $nextRuns) {
            $warnings[] = 'No upcoming runs are projected. The schedule may be one-shot and already fired, or paused.';
        }

        return $this->success(
            'Dry run complete. No schedule was executed.',
            array(
                'schedule_id'    => $scheduleId,
                'name'           => (string) $schedule['name'],
                'schedule_type'  => (string) $schedule['schedule_type'],
                'cadence'        => $cadence,
                'enabled'        => $enabled,
                'timezone'       => 'UTC',
                'next_runs'      => $nextRuns,
                'action'         => $this->previewAction($schedule),
                'warnings'       => $warnings,
                'would_dispatch' => $enabled && array() !== $nextRuns,
            )
        );
    }

    /**
     * A type-specific summary of what the schedule would do.
     *
     * `assistant_name` is `''` because the core has no post store to resolve
     * the assistant from — WordPress looked the title up with `get_post()`.
     *
     * @param array<string, mixed> $schedule Schedule record.
     *
     * @return array<string, mixed>
     */
    private function previewAction(array $schedule): array
    {
        switch ((string) $schedule['schedule_type']) {
            case Schedule::TYPE_ASSISTANT_RUN:
                $config = $schedule['assistant_config'];

                return array(
                    'type'           => Schedule::TYPE_ASSISTANT_RUN,
                    'assistant_id'   => (int) ($config['assistant_id'] ?? 0),
                    'assistant_name' => '',
                    'message'        => (string) ($config['message'] ?? ''),
                );

            case Schedule::TYPE_WORKFLOW:
                $steps = array();

                foreach ($schedule['workflow_steps'] as $index => $step) {
                    $steps[] = array(
                        'index'     => (int) $index,
                        'tool_slug' => (string) ($step['tool_slug'] ?? ''),
                        'label'     => (string) ($step['label'] ?? ''),
                    );
                }

                return array(
                    'type'  => Schedule::TYPE_WORKFLOW,
                    'steps' => $steps,
                );

            case Schedule::TYPE_CHANNEL_BROADCAST:
                $config = $schedule['broadcast_config'];

                return array(
                    'type'     => Schedule::TYPE_CHANNEL_BROADCAST,
                    'channels' => isset($config['channels']) && is_array($config['channels'])
                        ? array_values($config['channels'])
                        : array(),
                    'message'  => (string) ($config['message'] ?? ''),
                );

            default:
                return array(
                    'type' => Schedule::TYPE_TASK,
                    'hook' => (string) $schedule['hook'],
                    'args' => $schedule['args'],
                );
        }
    }
}
