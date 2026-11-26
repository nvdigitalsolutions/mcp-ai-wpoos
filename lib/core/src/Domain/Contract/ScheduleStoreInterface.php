<?php

/**
 * Pro Schedule definitions, run history and result envelopes.
 *
 * Proposal 164 section 164.80. WordPress keeps the three in options:
 * `wp_mcp_ai_pro_schedules`, `wp_mcp_ai_pro_schedule_history` and
 * `wp_mcp_ai_pro_schedule_results`. This contract keeps the shapes and lets a
 * host choose the medium; `JsonScheduleStore` is the bundled implementation.
 *
 * ## The definition
 *
 * ```
 * id                        string   md5 of the type-specific key (see below)
 * name, description         string
 * schedule_type             task | workflow | assistant_run | channel_broadcast
 * hook                      string   the WordPress hook, or the synthetic hook per type
 * args                      list     positional hook arguments (task)
 * workflow_steps            list     {tool_slug, arguments, label} (workflow)
 * assistant_config          array    {assistant_id, message, context, max_agentic_iterations}
 * broadcast_config          array    {message, channels, credentials}
 * display                   array    {result_capture, public_render, public_fields,
 *                                     result_retention, widget_defaults}
 * schedule                  string   ScheduleInterval slug, `single` for one-shot
 * timestamp                 int      the next (or only) run; 0 = not armed
 * enabled                   bool
 * priority                  int      1 (highest) – 10 (lowest)
 * tags                      list<string>
 * timeout                   int      seconds; 0 = no limit
 * callback_url              string   webhook target
 * callback_secret           string   HMAC secret
 * notify_on_failure         bool
 * notify_email              string
 * notify_channels           list<string>
 * notify_channel_credentials array
 * result_delivery           array
 * max_retries               int      0–5
 * retry_delay               int      seconds, minimum 60
 * retry_count               int
 * last_run_status           string   never | success | failure | timeout
 * last_run_time             int
 * last_run_duration         int
 * last_error                string
 * run_count                 int
 * created_at, updated_at    int
 * created_by, updated_by    int
 * ```
 *
 * ## The id
 *
 * WordPress derives the id with `md5()`: for `task` schedules from the hook and
 * args, so re-creating an identical task schedule updates it rather than
 * duplicating it; for the other types from the type, name and first-run
 * timestamp. The port keeps that rule — it is why `create_pro_schedule` can be
 * used as an upsert — and implementations must treat `save()` as an upsert.
 *
 * ## History and results
 *
 * A **run** is `{run_id, status, start_time, duration, error}`.
 * An **envelope** is `{summary, data, render, response, status, error,
 * generated_at}` — the shape the scheduled-result renderer consumes.
 *
 * History is a ring buffer of the last {@see MAX_HISTORY_PER_SCHEDULE} runs.
 * Results are capped by the schedule's `display.result_retention` (default
 * {@see DEFAULT_RESULT_RETENTION}, at most {@see MAX_RESULT_RETENTION}).
 * Implementations enforce both; a caller cannot ask for more.
 *
 * ## Who writes what
 *
 * The tools in this group write definitions only. A host's scheduler writes
 * runs and envelopes; the readers here (`get_schedule_run_history`,
 * `get_schedule_latest_result`, `render_schedule_result`) surface them. That is
 * the same split as WordPress, where the manager records runs and the tools
 * read them.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ScheduleStoreInterface
{
    public const TYPE_TASK              = 'task';
    public const TYPE_WORKFLOW          = 'workflow';
    public const TYPE_ASSISTANT_RUN     = 'assistant_run';
    public const TYPE_CHANNEL_BROADCAST = 'channel_broadcast';

    /** @var list<string> */
    public const TYPES = array(
        self::TYPE_TASK,
        self::TYPE_WORKFLOW,
        self::TYPE_ASSISTANT_RUN,
        self::TYPE_CHANNEL_BROADCAST,
    );

    public const STATUS_NEVER   = 'never';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_RUNNING = 'running';

    public const MAX_HISTORY_PER_SCHEDULE = 50;

    public const DEFAULT_RESULT_RETENTION = 10;
    public const MAX_RESULT_RETENTION     = 100;

    /**
     * Insert or replace a definition by its `id`, preserving history/results.
     *
     * @param array<string, mixed> $schedule Definition; missing fields take the documented defaults.
     *
     * @return array<string, mixed> The stored record.
     *
     * @throws \InvalidArgumentException When the id is unusable or the record is malformed.
     */
    public function save(array $schedule): array;

    /**
     * One definition, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $scheduleId): ?array;

    /**
     * Definitions matching the filters, highest priority first then by name.
     *
     * ```
     * enabled       bool    exact enabled state
     * schedule_type string  exact type
     * tag           string  the tag is present
     * ```
     *
     * @param array<string, mixed> $filters See above.
     *
     * @return list<array<string, mixed>>
     */
    public function all(array $filters = array()): array;

    /**
     * Remove a definition and everything stored with it.
     */
    public function delete(string $scheduleId): bool;

    /**
     * Append a run to the history ring buffer.
     *
     * @param array<string, mixed> $run See the class comment.
     */
    public function appendRun(string $scheduleId, array $run): void;

    /**
     * A schedule's runs, newest first.
     *
     * @param int $limit Maximum entries; clamped to 1..MAX_HISTORY_PER_SCHEDULE.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $scheduleId, int $limit = 20): array;

    /**
     * Append a result envelope, keeping the schedule's retention window.
     *
     * @param array<string, mixed> $envelope See the class comment.
     */
    public function appendResult(string $scheduleId, array $envelope): void;

    /**
     * The most recent envelope, or null.
     *
     * @return array<string, mixed>|null
     */
    public function latestResult(string $scheduleId): ?array;

    /**
     * Envelopes, newest first.
     *
     * @param int $limit Clamped to 1..MAX_RESULT_RETENTION.
     *
     * @return list<array<string, mixed>>
     */
    public function results(string $scheduleId, int $limit = 10): array;
}
