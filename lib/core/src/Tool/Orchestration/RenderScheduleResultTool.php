<?php

/**
 * Render a Pro Schedule's latest result as sanitised HTML.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Render_Schedule_Result` (Proposal 164 section
 * 164.80). This tool is the chat-facing wrapper around
 * `ScheduledResultRenderer`: it resolves the schedule and its latest envelope
 * from the store, then asks the renderer for one of the six canonical modes.
 *
 * ## The renderer is pure
 *
 * WordPress's static renderer fetched the schedule and envelope itself. The
 * port keeps `ScheduledResultRenderer::render()` free of the store for the two
 * inputs a caller already holds, and this tool supplies them — the renderer
 * still reads `results()` for the `timeline` mode, which is inherently
 * multi-run.
 *
 * ## Options
 *
 * Defaults come from the schedule's stored `display.widget_defaults`; explicit
 * arguments win. `render_mode` outside the six modes falls back to
 * `summary-card`; `truncate_chars` is clamped to 0–4000 and applies to `raw`
 * only.
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

class RenderScheduleResultTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
        private readonly ScheduledResultRenderer $renderer,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'render_schedule_result';
    }

    public function getName(): string
    {
        return 'Render Schedule Result';
    }

    public function getDescription(): string
    {
        return 'Renders the latest run of a Pro Schedule as sanitized HTML using one of six canonical modes (summary-card, list, table, metric, timeline, raw).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'schedule_id'    => array(
                    'type'        => 'string',
                    'description' => 'ID of the schedule to render.',
                    'minLength'   => 1,
                ),
                'render_mode'    => array(
                    'type'        => 'string',
                    'enum'        => ScheduleDefinition::RENDER_MODES,
                    'default'     => 'summary-card',
                    'description' => 'Render mode.',
                ),
                'title'          => array(
                    'type'        => 'string',
                    'description' => 'Optional override for the tile title.',
                ),
                'show_last_run'  => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Whether to show the last-run timestamp.',
                ),
                'truncate_chars' => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'maximum'     => 4000,
                    'default'     => 0,
                    'description' => 'Soft-truncate raw text at N characters (0 = off).',
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

        $opts = array(
            'truncate_chars' => max(0, min(4000, $this->intParam($arguments, 'truncate_chars', 0))),
            'show_last_run'  => $this->boolParam($arguments, 'show_last_run', true),
        );

        if (isset($arguments['render_mode']) && is_scalar($arguments['render_mode'])) {
            $mode = Text::sanitizeKey((string) $arguments['render_mode']);

            if (in_array($mode, ScheduleDefinition::RENDER_MODES, true)) {
                $opts['render_mode'] = $mode;
            }
        }

        if (isset($arguments['title']) && is_scalar($arguments['title'])) {
            $opts['title'] = Text::singleLine((string) $arguments['title']);
        }

        $html = $this->renderer->render(
            $schedule,
            $this->schedules->latestResult($scheduleId),
            $opts
        );

        return $this->success(
            sprintf('Rendered the latest result for schedule "%s".', (string) $schedule['name']),
            array(
                'schedule_id' => $scheduleId,
                'html'        => $html,
            )
        );
    }
}
