<?php

/**
 * Shared rules for Pro Schedule definitions.
 *
 * Proposal 164 section 164.80. Six tools validate, derive and project the same
 * record, and WordPress spreads those rules across a 5,000-line manager. One
 * helper keeps them identical: the id rule, the documented defaults, the
 * display sanitiser, the interval projection and the value clamps.
 *
 * ## The id rule
 *
 * WordPress hashes a type-specific key with `md5()`: hook + args for `task`,
 * type + name + first-run timestamp for the others. The consequence is that
 * creating an identical task schedule **updates** it — which is how the
 * WordPress tool doubles as an upsert — while two other schedules with the same
 * name need different timestamps to coexist. The helper reproduces the rule
 * exactly, and `plan_schedules_from_workflow` still offsets first runs by 60
 * seconds per item for the same reason WordPress does.
 *
 * ## Interval projection
 *
 * `next_runs` extrapolates from the stored `timestamp` with the interval in
 * seconds. It answers nothing for a disabled or unarmed schedule, and at most
 * one time for `single`. Because the interval is fixed seconds, the projected
 * local clock time drifts by an hour across a daylight-saving change — the
 * WordPress rule (Trac #17957), named in the readiness row rather than fixed by
 * silently switching to cron expressions.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ScheduleStoreInterface as Schedule;
use Nvoos\Core\Domain\ValueObject\ScheduleInterval;
use Nvoos\Core\Support\Text;

final class ScheduleDefinition
{
    /** The render modes the widget and the renderer share. */
    public const RENDER_MODES = array('summary-card', 'list', 'table', 'metric', 'timeline', 'raw');

    /** The channels the WordPress tool accepts for broadcasts and notifications. */
    public const CHANNELS = array('telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp');

    /**
     * The WordPress id for a definition.
     *
     * @param list<mixed> $args Hook arguments (task only).
     */
    public static function idFor(
        string $type,
        string $hook,
        array $args,
        string $name,
        int $timestamp,
    ): string {
        $key = Schedule::TYPE_TASK === $type
            ? array('hook' => $hook, 'args' => $args)
            : array('type' => $type, 'name' => $name, 'ts' => $timestamp);

        $encoded = json_encode($key);

        return md5(false === $encoded ? serialize($key) : $encoded);
    }

    /**
     * The first run when the caller does not supply one.
     *
     * One-shot schedules keep the documented 60-second default; a recurring
     * schedule starts one full interval out, so a `daily` schedule does not fire
     * almost immediately after creation.
     */
    public static function defaultTimestamp(string $schedule, ?int $now = null): int
    {
        $now = $now ?? time();

        if (ScheduleInterval::SINGLE === $schedule) {
            return $now + 60;
        }

        return $now + max(60, ScheduleInterval::seconds($schedule));
    }

    /**
     * The next run, or null when the schedule is paused or unarmed.
     */
    public static function nextRun(array $schedule): ?int
    {
        if (empty($schedule['enabled'])) {
            return null;
        }

        $timestamp = (int) ( $schedule['timestamp'] ?? 0 );

        return $timestamp > 0 ? $timestamp : null;
    }

    /**
     * Project the next N runs from the stored timestamp and interval.
     *
     * @return list<int>
     */
    public static function nextRuns(array $schedule, int $count): array
    {
        $count = max(1, $count);
        $start = self::nextRun($schedule);

        if (null === $start) {
            return array();
        }

        $cadence = (string) ( $schedule['schedule'] ?? ScheduleInterval::SINGLE );

        if (ScheduleInterval::SINGLE === $cadence) {
            return array($start);
        }

        $interval = ScheduleInterval::seconds($cadence);

        if ($interval <= 0) {
            return array($start);
        }

        $times = array();

        for ($index = 0; $index < $count; ++$index) {
            $times[] = $start + ( $index * $interval );
        }

        return $times;
    }

    /**
     * ISO 8601 UTC, or null for an unset timestamp.
     */
    public static function iso(int $timestamp): ?string
    {
        return $timestamp > 0 ? gmdate('c', $timestamp) : null;
    }

    /**
     * A valid schedule type, or `task`.
     */
    public static function type(mixed $value): string
    {
        return is_string($value) && in_array($value, Schedule::TYPES, true)
            ? $value
            : Schedule::TYPE_TASK;
    }

    /**
     * Sanitise the display/widget binding settings, as WordPress does.
     *
     * @return array<string, mixed>
     */
    public static function display(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : array();

        $capture = isset($raw['result_capture']) && is_scalar($raw['result_capture'])
            ? Text::sanitizeKey((string) $raw['result_capture'])
            : 'summary';

        if (! in_array($capture, array('disabled', 'summary', 'full'), true)) {
            $capture = 'summary';
        }

        $fields = array();

        if (isset($raw['public_fields']) && is_array($raw['public_fields'])) {
            foreach ($raw['public_fields'] as $field) {
                if (! is_string($field)) {
                    continue;
                }

                $field = (string) preg_replace('/[^a-zA-Z0-9_.\[\]\-]/', '', $field);

                if ('' !== $field) {
                    $fields[] = $field;
                }
            }
        }

        $widgets = isset($raw['widget_defaults']) && is_array($raw['widget_defaults'])
            ? $raw['widget_defaults']
            : array();

        $mode = isset($widgets['render_mode']) && is_string($widgets['render_mode'])
            && in_array($widgets['render_mode'], self::RENDER_MODES, true)
            ? $widgets['render_mode']
            : 'summary-card';

        return array(
            'result_capture'   => $capture,
            'public_render'    => ! empty($raw['public_render']),
            'public_fields'    => $fields,
            'result_retention' => isset($raw['result_retention'])
                ? max(1, min(Schedule::MAX_RESULT_RETENTION, (int) $raw['result_retention']))
                : Schedule::DEFAULT_RESULT_RETENTION,
            'widget_defaults'  => array(
                'render_mode'      => $mode,
                'title'            => isset($widgets['title']) && is_scalar($widgets['title'])
                    ? Text::singleLine((string) $widgets['title'])
                    : '',
                'refresh_interval' => isset($widgets['refresh_interval'])
                    ? max(0, min(3600, (int) $widgets['refresh_interval']))
                    : 0,
            ),
        );
    }

    /**
     * Non-empty, unique tags in first-appearance order.
     *
     * @return list<string>
     */
    public static function tags(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $tags = array();

        foreach ($raw as $tag) {
            if (! is_scalar($tag)) {
                continue;
            }

            $clean = Text::singleLine((string) $tag);

            if ('' !== $clean) {
                $tags[] = $clean;
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Sanitised workflow steps; invalid entries are dropped.
     *
     * @return list<array{tool_slug: string, arguments: array<string, mixed>, label: string}>
     */
    public static function workflowSteps(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $steps = array();

        foreach ($raw as $step) {
            if (! is_array($step) || empty($step['tool_slug']) || ! is_scalar($step['tool_slug'])) {
                continue;
            }

            $slug = Text::sanitizeKey((string) $step['tool_slug']);

            if ('' === $slug) {
                continue;
            }

            $steps[] = array(
                'tool_slug' => $slug,
                'arguments' => isset($step['arguments']) && is_array($step['arguments']) ? $step['arguments'] : array(),
                'label'     => isset($step['label']) && is_scalar($step['label'])
                    ? Text::singleLine((string) $step['label'])
                    : '',
            );
        }

        return $steps;
    }

    public static function priority(mixed $value, int $fallback = 5): int
    {
        return is_numeric($value) ? max(1, min(10, (int) $value)) : $fallback;
    }

    public static function maxRetries(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(5, (int) $value)) : 0;
    }

    public static function retryDelay(mixed $value): int
    {
        return is_numeric($value) ? max(60, (int) $value) : 300;
    }

    public static function timeout(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * A normalised comma-separated recipient list, or null when any address is
     * invalid.
     */
    public static function emails(string $raw): ?string
    {
        $tokens = preg_split('/[\s,;]+/', trim($raw));

        if (! is_array($tokens)) {
            return '';
        }

        $valid = array();

        foreach ($tokens as $token) {
            $token = trim((string) $token);

            if ('' === $token) {
                continue;
            }

            if (false === filter_var($token, FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            $valid[] = $token;
        }

        return implode(', ', array_values(array_unique($valid)));
    }

    /**
     * A valid HTTP(S) URL, `''` for none, or null when invalid.
     */
    public static function callbackUrl(string $raw): ?string
    {
        $url = trim($raw);

        if ('' === $url) {
            return '';
        }

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, array('http', 'https'), true) ? $url : null;
    }
}
