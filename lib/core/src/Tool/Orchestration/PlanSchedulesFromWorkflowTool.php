<?php

/**
 * Turn a free-form workflow into planned Pro Schedules.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Plan_Schedules_From_Workflow` (Proposal 164
 * section 164.80). The tool parses a list of responsibilities — structured
 * objects or a plain multi-line text — infers a cadence, priority and first run
 * for each, and either previews the plan (`dry_run`) or persists it.
 *
 * ## Inference is deliberately conservative
 *
 * Cadence and priority come from keyword tables, in the order WordPress used
 * them: explicit `suggested_*` first, then the keyword pass, then the supplied
 * default. The port reproduces the tables verbatim, including the quirk that an
 * **invalid** `suggested_cadence` falls through to the default rather than to
 * inference.
 *
 * ## First runs are offset per item
 *
 * Non-task schedule ids hash the name and first-run timestamp, so two items
 * planned for the same 09:00 would collide. WordPress added 60 seconds per item
 * (`base_offset`) for exactly that reason; the port keeps the offset because
 * the id rule is unchanged.
 *
 * ## Where WordPress read site state, the port cannot
 *
 * - `default_time` is interpreted in **UTC** (the port has no site timezone).
 * - `notify_email` defaults to `''` instead of `get_option('admin_email')`; an
 *   invalid address is dropped rather than failing the whole batch, which is
 *   what `sanitize_email()` effectively did.
 * - `default_assistant_id` is accepted when positive without a post-status
 *   check, because the core has no post store. A host that only schedules live
 *   assistants should validate before calling.
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
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class PlanSchedulesFromWorkflowTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** WordPress truncated a planned schedule name to this length. */
    private const MAX_NAME_LENGTH = 80;

    /** Seconds added to each successive first run so ids do not collide. */
    private const ITEM_OFFSET_SECONDS = 60;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'plan_schedules_from_workflow';
    }

    public function getName(): string
    {
        return 'Plan Schedules From Workflow';
    }

    public function getDescription(): string
    {
        return 'Converts a workflow list (structured items or plain text) into Pro Schedules, inferring cadence and priority, with optional dry-run preview.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'workflow_items'       => array(
                    'type'        => 'array',
                    'description' => 'List of workflow items. Each entry may be a plain string (one responsibility per line) or an object with title, description, suggested_cadence, suggested_time, priority, tags.',
                    'items'       => array(
                        'oneOf' => array(
                            array(
                                'type'       => 'object',
                                'properties' => array(
                                    'title'             => array(
                                        'type'        => 'string',
                                        'description' => 'Short title for the schedule (max 80 chars).',
                                    ),
                                    'description'       => array(
                                        'type'        => 'string',
                                        'description' => 'Full description / instruction for the assistant or task.',
                                    ),
                                    'suggested_cadence' => array(
                                        'type'        => 'string',
                                        'enum'        => ScheduleInterval::slugs(),
                                        'description' => 'Optional cadence override for this item.',
                                    ),
                                    'suggested_time'    => array(
                                        'type'        => 'string',
                                        'description' => 'Optional preferred time-of-day in HH:MM (24h, UTC).',
                                    ),
                                    'priority'          => array(
                                        'type'        => 'integer',
                                        'minimum'     => 1,
                                        'maximum'     => 10,
                                        'description' => 'Optional priority override (1 = highest).',
                                    ),
                                    'tags'              => array(
                                        'type'        => 'array',
                                        'items'       => array('type' => 'string'),
                                        'description' => 'Optional extra tags for this item.',
                                    ),
                                ),
                            ),
                            array('type' => 'string'),
                        ),
                    ),
                ),
                'workflow_text'        => array(
                    'type'        => 'string',
                    'description' => 'Alternative input: a single multi-line string. Each non-empty line becomes one workflow item. Lines starting with "## " set the current category for following lines.',
                ),
                'category'             => array(
                    'type'        => 'string',
                    'description' => 'Optional category (e.g. "marketing", "mass-market"). Used as a tag and as a fallback name prefix.',
                ),
                'default_assistant_id' => array(
                    'type'        => 'integer',
                    'description' => 'Optional assistant post ID. When provided, scheduled events fire as assistant_run; when omitted they fall back to a wp_mcp_ai_workflow_reminder task hook.',
                ),
                'default_cadence'      => array(
                    'type'        => 'string',
                    'enum'        => ScheduleInterval::slugs(),
                    'description' => 'Default cadence applied when an item gives no hint. Defaults to "daily".',
                ),
                'default_time'         => array(
                    'type'        => 'string',
                    'description' => 'Default time-of-day in HH:MM (24h, UTC). Defaults to "09:00".',
                ),
                'notify_on_failure'    => array(
                    'type'        => 'boolean',
                    'description' => 'Notify the configured recipients on failure. Defaults to true.',
                ),
                'notify_email'         => array(
                    'type'        => 'string',
                    'description' => 'Email address(es) for failure notifications. Comma-separate multiple recipients.',
                ),
                'dry_run'              => array(
                    'type'        => 'boolean',
                    'description' => 'When true, return the parsed plan without persisting any schedules.',
                    'default'     => false,
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
        return array('write', 'requires-capability', 'state-changing', 'bulk');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $userId = (int) ($context['user_id'] ?? 0);
        $items  = array();

        if (isset($arguments['workflow_items']) && is_array($arguments['workflow_items'])) {
            $items = array_values($arguments['workflow_items']);
        }

        $category = $this->stringParam($arguments, 'category');

        if (
            isset($arguments['workflow_text']) && is_string($arguments['workflow_text'])
            && '' !== trim($arguments['workflow_text'])
        ) {
            $items = array_merge($items, $this->parseText($arguments['workflow_text'], $category));
        }

        if (array() === $items) {
            return $this->errors->create(
                'no_workflow_items',
                'Provide at least one workflow item via workflow_items or workflow_text.'
            );
        }

        $cadence = Text::sanitizeKey($this->stringParam($arguments, 'default_cadence', 'daily'));

        if (! ScheduleInterval::isValid($cadence)) {
            $cadence = 'daily';
        }

        $defaultTime = $this->stringParam($arguments, 'default_time', '09:00');

        if (1 !== preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $defaultTime)) {
            $defaultTime = '09:00';
        }

        $assistantId       = abs($this->intParam($arguments, 'default_assistant_id', 0));
        $notifyOnFailure   = $this->boolParam($arguments, 'notify_on_failure', true);
        $notifyEmail       = isset($arguments['notify_email']) && is_scalar($arguments['notify_email'])
            ? (string) ScheduleDefinition::emails((string) $arguments['notify_email'])
            : '';
        $dryRun            = $this->boolParam($arguments, 'dry_run', false);
        $now               = time();

        $defaults = array(
            'category'             => $category,
            'default_cadence'      => $cadence,
            'default_time'         => $defaultTime,
            'default_assistant_id' => $assistantId,
            'notify_on_failure'    => $notifyOnFailure,
            'notify_email'         => $notifyEmail,
        );

        $plan        = array();
        $created     = array();
        $errors      = array();
        $baseOffset  = 0;

        foreach ($items as $rawItem) {
            $normalised = $this->normaliseItem($rawItem, $defaults, $baseOffset);

            if (is_string($normalised)) {
                $errors[] = array(
                    'item'    => is_array($rawItem) ? $rawItem : array('title' => (string) $rawItem),
                    'message' => $normalised,
                );

                continue;
            }

            // Distinct first-run timestamps per item; see the class comment.
            $baseOffset += self::ITEM_OFFSET_SECONDS;

            $record = $this->record($normalised, $userId, $now);

            $plan[] = array(
                'schedule_id'   => $record['id'],
                'name'          => $record['name'],
                'description'   => $record['description'],
                'schedule_type' => $record['schedule_type'],
                'schedule'      => $record['schedule'],
                'timestamp'     => $record['timestamp'],
                'next_run'      => ScheduleDefinition::iso($record['timestamp']),
                'priority'      => $record['priority'],
                'tags'          => $record['tags'],
            );

            if ($dryRun) {
                continue;
            }

            try {
                $stored = $this->schedules->save($record);
            } catch (\Throwable $error) {
                $errors[] = array(
                    'item'    => $normalised,
                    'message' => $error->getMessage(),
                    'code'    => 'schedule_create_failed',
                );

                continue;
            }

            $created[] = array(
                'schedule_id' => $stored['id'],
                'name'        => $stored['name'],
                'cadence'     => $stored['schedule'],
                'next_run'    => ScheduleDefinition::iso((int) $stored['timestamp']),
                'tags'        => $stored['tags'],
            );
        }

        $message = $dryRun
            ? 'Preview plan generated. No schedules were persisted.'
            : sprintf(
                'Created %1$d of %2$d planned schedules.',
                count($created),
                count($plan)
            );

        return $this->success(
            $message,
            array(
                'plan'    => $plan,
                'created' => $created,
                'skipped' => array(),
                'errors'  => $errors,
                'summary' => array(
                    'total'    => count($items),
                    'planned'  => count($plan),
                    'created'  => count($created),
                    'errors'   => count($errors),
                    'dry_run'  => $dryRun,
                    'category' => $category,
                ),
                'message' => $message,
            )
        );
    }

    /**
     * Parse a multi-line workflow text into raw items.
     *
     * A `## Heading` line sets the category for the lines that follow; the
     * heading itself is not an item. Bullet and numbering prefixes are stripped.
     *
     * @return list<array<string, mixed>>
     */
    private function parseText(string $text, string $category): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);

        if (! is_array($lines)) {
            return array();
        }

        $active   = $category;
        $items    = array();

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            if (0 === strpos($line, '## ')) {
                $active = Text::singleLine(trim(substr($line, 3)));

                continue;
            }

            $stripped = preg_replace('/^(?:[-*•]|\d+[\.\)])\s+/', '', $line);

            if (null === $stripped) {
                continue;
            }

            $stripped = trim($stripped);

            if ('' === $stripped) {
                continue;
            }

            $items[] = array(
                'title'       => $stripped,
                'description' => $stripped,
                'tags'        => '' !== $active ? array($active) : array(),
            );
        }

        return $items;
    }

    /**
     * Normalise one raw item.
     *
     * @param mixed                $raw        Raw item (string or array).
     * @param array<string, mixed> $defaults   Batch defaults.
     * @param int                  $baseOffset Seconds already consumed by earlier items.
     *
     * @return array<string, mixed>|string The payload, or a human-readable reason it was rejected.
     */
    private function normaliseItem(mixed $raw, array $defaults, int $baseOffset): array|string
    {
        if (is_string($raw)) {
            $item = array('title' => $raw, 'description' => $raw);
        } elseif (is_array($raw)) {
            $item = $raw;
        } else {
            return 'Workflow item must be a string or object.';
        }

        $title = isset($item['title']) && is_scalar($item['title'])
            ? Text::singleLine((string) $item['title'])
            : '';

        $description = isset($item['description']) && is_scalar($item['description'])
            ? Text::multiline((string) $item['description'])
            : $title;

        if ('' === $title && '' !== $description) {
            $title = $description;
        }

        if ('' === $title) {
            return 'Workflow item must have a title or description.';
        }

        $name = mb_substr($title, 0, self::MAX_NAME_LENGTH);

        // Cadence: explicit > inferred > default; an invalid explicit value
        // falls to the default, as WordPress did.
        $cadence = '';

        if (isset($item['suggested_cadence']) && is_scalar($item['suggested_cadence'])) {
            $cadence = Text::sanitizeKey((string) $item['suggested_cadence']);
        }

        if ('' === $cadence) {
            $cadence = $this->inferCadence($title . ' ' . $description, (string) $defaults['default_cadence']);
        }

        if (! ScheduleInterval::isValid($cadence)) {
            $cadence = (string) $defaults['default_cadence'];
        }

        // Time: explicit > default.
        $time = '';

        if (isset($item['suggested_time']) && is_scalar($item['suggested_time'])) {
            $candidate = trim((string) $item['suggested_time']);

            if (1 === preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $candidate)) {
                $time = $candidate;
            }
        }

        if ('' === $time) {
            $time = (string) $defaults['default_time'];
        }

        $timestamp = $this->firstRunAt($time) + $baseOffset;

        // Priority: explicit > inferred > 5.
        $priority = isset($item['priority']) && is_numeric($item['priority'])
            ? max(1, min(10, (int) $item['priority']))
            : $this->inferPriority($title . ' ' . $description);

        $tags = array('planned-from-workflow');

        if ('' !== (string) $defaults['category']) {
            $tags[] = (string) $defaults['category'];
        }

        if (isset($item['tags']) && is_array($item['tags'])) {
            foreach ($item['tags'] as $tag) {
                if (is_scalar($tag)) {
                    $tags[] = Text::singleLine((string) $tag);
                }
            }
        }

        $tags = array_values(array_unique(array_filter($tags, static fn (string $tag): bool => '' !== $tag)));

        $payload = array(
            'name'              => $name,
            'description'       => $description,
            'schedule'          => $cadence,
            'timestamp'         => $timestamp,
            'enabled'           => true,
            'priority'          => $priority,
            'tags'              => $tags,
            'notify_on_failure' => (bool) $defaults['notify_on_failure'],
            'notify_email'      => (string) $defaults['notify_email'],
        );

        if ((int) $defaults['default_assistant_id'] > 0) {
            $payload['schedule_type']    = Schedule::TYPE_ASSISTANT_RUN;
            $payload['assistant_config'] = array(
                'assistant_id' => (int) $defaults['default_assistant_id'],
                'message'      => "It's time for the following recurring responsibility:\n\n"
                    . $description
                    . "\n\nPlease proceed and report back when complete.",
                'context'      => array(),
            );

            return $payload;
        }

        $payload['schedule_type'] = Schedule::TYPE_TASK;
        $payload['hook']          = 'wp_mcp_ai_workflow_reminder';
        $payload['args']          = array(
            (string) json_encode(
                array(
                    'title'       => $title,
                    'description' => $description,
                    'tags'        => $tags,
                ),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        );

        return $payload;
    }

    /**
     * Turn a normalised payload into a storable record.
     *
     * @param array<string, mixed> $payload Normalised payload.
     *
     * @return array<string, mixed>
     */
    private function record(array $payload, int $userId, int $now): array
    {
        $type = ScheduleDefinition::type($payload['schedule_type']);
        $hook = (string) ($payload['hook'] ?? '');
        $args = isset($payload['args']) && is_array($payload['args']) ? array_values($payload['args']) : array();

        if ('' === $hook) {
            $hook = match ($type) {
                Schedule::TYPE_WORKFLOW          => 'wp_mcp_ai_pro_workflow_run',
                Schedule::TYPE_ASSISTANT_RUN     => 'wp_mcp_ai_pro_assistant_run',
                Schedule::TYPE_CHANNEL_BROADCAST => 'wp_mcp_ai_pro_channel_broadcast',
                default                          => '',
            };
        }

        return array_merge(
            $payload,
            array(
                'id'            => ScheduleDefinition::idFor(
                    $type,
                    $hook,
                    $args,
                    (string) $payload['name'],
                    (int) $payload['timestamp']
                ),
                'hook'          => $hook,
                'args'          => $args,
                'display'       => ScheduleDefinition::display(array()),
                'created_at'    => $now,
                'created_by'    => $userId,
                'updated_at'    => $now,
                'updated_by'    => $userId,
            )
        );
    }

    /**
     * Infer a cadence from natural-language keywords.
     *
     * Order is specificity: hourly, twice-daily, monthly, weekly, daily. The
     * final heuristic maps follow-up style responsibilities to daily.
     */
    private function inferCadence(string $text, string $fallback): string
    {
        $text = mb_strtolower($text);

        $rules = array(
            'hourly'     => array('hourly', 'every hour'),
            'twicedaily' => array('twice a day', 'twice daily'),
            'daily'      => array('daily', 'each day', 'every day', 'morning routine'),
            'weekly'     => array('weekly', 'each week', 'every week', 'monday', 'friday'),
            'monthly'    => array('monthly', 'each month', 'every month'),
        );

        foreach (array('hourly', 'twicedaily', 'monthly', 'weekly', 'daily') as $candidate) {
            if (! ScheduleInterval::isValid($candidate)) {
                continue;
            }

            foreach ($rules[ $candidate ] as $needle) {
                if (str_contains($text, $needle)) {
                    return $candidate;
                }
            }
        }

        if (1 === preg_match('/\b(follow.?up|review|check|respond|monitor)\b/', $text)) {
            return 'daily';
        }

        return ScheduleInterval::isValid($fallback) ? $fallback : 'daily';
    }

    /**
     * Infer a priority (1 = highest, 10 = lowest) from keywords.
     */
    private function inferPriority(string $text): int
    {
        $text = mb_strtolower($text);

        if (1 === preg_match('/\b(urgent|asap|critical|emergency)\b/', $text)) {
            return 1;
        }

        if (1 === preg_match('/\b(approval|approvals|sign.?off|escalat)\b/', $text)) {
            return 2;
        }

        if (1 === preg_match('/\b(follow.?up|payment|invoice|deadline)\b/', $text)) {
            return 3;
        }

        return 5;
    }

    /**
     * The next UTC occurrence of `HH:MM`, one day out when it is within a minute.
     */
    private function firstRunAt(string $time): int
    {
        $parts  = explode(':', $time);
        $hour   = isset($parts[0]) ? max(0, min(23, (int) $parts[0])) : 9;
        $minute = isset($parts[1]) ? max(0, min(59, (int) $parts[1])) : 0;

        $now = time();
        $day = (int) gmdate('Ymd', $now);

        $target = gmmktime($hour, $minute, 0, (int) substr((string) $day, 4, 2), (int) substr((string) $day, 6, 2), (int) substr((string) $day, 0, 4));

        if (false === $target) {
            return $now + 3600;
        }

        if ($target <= $now + self::ITEM_OFFSET_SECONDS) {
            $target += 86400;
        }

        return $target;
    }
}
