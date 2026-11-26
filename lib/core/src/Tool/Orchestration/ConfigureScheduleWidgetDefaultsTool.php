<?php

/**
 * Update the display/widget-binding settings on a Pro Schedule.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Configure_Schedule_Widget_Defaults` (Proposal 164
 * section 164.80). Only the display keys the caller supplies change; everything
 * else keeps its stored value, and the merged result is re-sanitised so a
 * partial update cannot smuggle an out-of-range retention count or an unknown
 * render mode into the record.
 *
 * ## Why the merge happens here
 *
 * WordPress delegated the merge to `WP_MCP_AI_Pro_Schedule_Manager::
 * update_schedule()` and only sanitised the keys it saw. The store's `save()` is
 * a full-record upsert, so the tool reads the record, replaces `display`, and
 * writes it back. A display-only write leaves `timestamp` untouched: the
 * re-arm rule treats it as a metadata change, and a consumed one-shot must not
 * be re-armed by changing a widget default.
 *
 * ## One deliberate divergence: `widget_defaults` merges deeply
 *
 * WordPress merged `display` with a shallow `array_merge()` and then
 * re-sanitised, so supplying only `widget_defaults.title` reset the stored
 * `render_mode` to `summary-card` and `refresh_interval` to 0. That is silent
 * data loss in a partial update — the tool's schema advertises every
 * `widget_defaults` property as optional, so the caller had no way to know.
 * The port merges that one nested object key-by-key instead; a caller that
 * supplies the whole object gets byte-identical behaviour, and a caller that
 * supplies one key keeps the rest.
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

class ConfigureScheduleWidgetDefaultsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The display keys this tool may change. */
    private const DISPLAY_KEYS = array(
        'result_capture',
        'public_render',
        'public_fields',
        'result_retention',
        'widget_defaults',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'configure_schedule_widget_defaults';
    }

    public function getName(): string
    {
        return 'Configure Schedule Widget Defaults';
    }

    public function getDescription(): string
    {
        return 'Updates the display/widget-binding settings on a Pro Schedule (capture mode, public visibility, public-field allow-list, retention, render defaults).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'schedule_id'      => array(
                    'type'        => 'string',
                    'description' => 'ID of the schedule to configure.',
                    'minLength'   => 1,
                ),
                'result_capture'   => array(
                    'type'        => 'string',
                    'enum'        => array('disabled', 'summary', 'full'),
                    'description' => 'How much of the run output to capture into the result envelope.',
                ),
                'public_render'    => array(
                    'type'        => 'boolean',
                    'description' => 'When true, the renderer surfaces a redacted envelope to anonymous visitors.',
                ),
                'public_fields'    => array(
                    'type'        => 'array',
                    'description' => 'Allow-list of dotted JSON paths exposed to the public renderer (e.g. ["summary","data.top_3"]).',
                    'items'       => array('type' => 'string'),
                ),
                'result_retention' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => Schedule::MAX_RESULT_RETENTION,
                    'description' => 'Number of result envelopes to retain (separate from the run-history ring buffer).',
                ),
                'widget_defaults'  => array(
                    'type'        => 'object',
                    'description' => 'Default render mode/title/refresh interval for the Scheduled Result widget.',
                    'properties'  => array(
                        'render_mode'      => array(
                            'type' => 'string',
                            'enum' => ScheduleDefinition::RENDER_MODES,
                        ),
                        'title'            => array('type' => 'string'),
                        'refresh_interval' => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                            'maximum' => 3600,
                        ),
                    ),
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
        return array('write', 'state-changing', 'local-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $userId     = (int) ($context['user_id'] ?? 0);
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

        $supplied = array();

        foreach (self::DISPLAY_KEYS as $key) {
            if (array_key_exists($key, $arguments)) {
                $supplied[ $key ] = $arguments[ $key ];
            }
        }

        if (array() === $supplied) {
            return $this->errors->validationFailed(
                'No display setting was supplied.',
                array(
                    'display' => array(
                        'Supply at least one of: result_capture, public_render, public_fields, result_retention, widget_defaults.',
                    ),
                )
            );
        }

        $storedWidgets = isset($schedule['display']['widget_defaults']) && is_array($schedule['display']['widget_defaults'])
            ? $schedule['display']['widget_defaults']
            : array();

        if (isset($supplied['widget_defaults']) && is_array($supplied['widget_defaults'])) {
            $supplied['widget_defaults'] = array_merge($storedWidgets, $supplied['widget_defaults']);
        }

        $schedule['display']    = ScheduleDefinition::display(
            array_merge($schedule['display'], $supplied)
        );
        $schedule['updated_at'] = time();
        $schedule['updated_by'] = $userId;

        try {
            $stored = $this->schedules->save($schedule);
        } catch (\Throwable $error) {
            return $this->errors->create('schedule_update_failed', $error->getMessage());
        }

        return $this->success(
            sprintf('Display settings updated for schedule "%s".', (string) $stored['name']),
            array(
                'schedule_id' => $scheduleId,
                'display'     => $stored['display'],
            )
        );
    }
}
