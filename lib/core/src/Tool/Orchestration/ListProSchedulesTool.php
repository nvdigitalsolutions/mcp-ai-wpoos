<?php

/**
 * List Pro Schedule definitions.
 *
 * Port of `WP_MCP_AI_Pro_Tool_List_Pro_Schedules` (Proposal 164 section
 * 164.80). Definitions are sorted by priority (1 first) then name, as the
 * WordPress manager sorts them.
 *
 * ## Corrected
 *
 * - **`schedule_type` accepts all four types.** The WordPress schema enum
 *   listed three and the manager supports four, so a `channel_broadcast`
 *   schedule could not be asked for by name. The port uses the store's
 *   vocabulary.
 * - **An unknown type or filter is refused**, rather than silently returning an
 *   empty list that looks like "no schedules".
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
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class ListProSchedulesTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'list_pro_schedules';
    }

    public function getName(): string
    {
        return 'List Pro Schedules';
    }

    public function getDescription(): string
    {
        return 'Lists all pro managed scheduled tasks, workflows, assistant runs and channel broadcasts with their status and next run times.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'enabled_only'  => array(
                    'type'        => 'boolean',
                    'description' => 'When true, returns only enabled schedules.',
                    'default'     => false,
                ),
                'schedule_type' => array(
                    'type'        => 'string',
                    'enum'        => Schedule::TYPES,
                    'description' => 'Filter by schedule type.',
                ),
                'tag'           => array(
                    'type'        => 'string',
                    'description' => 'Filter schedules that have this tag.',
                ),
            ),
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

        $type   = '';
        $errors = array();

        if (isset($arguments['schedule_type']) && '' !== (string) $arguments['schedule_type']) {
            $type = Text::sanitizeKey((string) $arguments['schedule_type']);

            if (! in_array($type, Schedule::TYPES, true)) {
                $errors['schedule_type'] = array('Unknown schedule type.');
            }
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The schedule filter is not valid.', $errors);
        }

        $filters = array();

        if ($this->boolParam($arguments, 'enabled_only', false)) {
            $filters['enabled'] = true;
        }

        if ('' !== $type) {
            $filters['schedule_type'] = $type;
        }

        $tag = $this->stringParam($arguments, 'tag');

        if ('' !== $tag) {
            $filters['tag'] = $tag;
        }

        $results = array();

        foreach ($this->schedules->all($filters) as $schedule) {
            $nextRun = ScheduleDefinition::nextRun($schedule);
            $lastRun = (int) $schedule['last_run_time'];

            $results[] = array(
                'schedule_id'     => $schedule['id'],
                'name'            => $schedule['name'],
                'description'     => $schedule['description'],
                'schedule_type'   => $schedule['schedule_type'],
                'hook'            => $schedule['hook'],
                'schedule'        => $schedule['schedule'],
                'enabled'         => $schedule['enabled'],
                'priority'        => $schedule['priority'],
                'tags'            => $schedule['tags'],
                'last_run_status' => $schedule['last_run_status'],
                'last_run_time'   => $lastRun > 0 ? ScheduleDefinition::iso($lastRun) : null,
                'run_count'       => $schedule['run_count'],
                'next_run'        => null === $nextRun ? null : ScheduleDefinition::iso($nextRun),
                'created_at'      => ScheduleDefinition::iso((int) $schedule['created_at']),
            );
        }

        return $this->success(
            sprintf('Found %d schedule(s).', count($results)),
            array(
                'schedules' => $results,
                'total'     => count($results),
            )
        );
    }
}
