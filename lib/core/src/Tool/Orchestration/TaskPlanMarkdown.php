<?php

/**
 * The markdown task-plan format, shared by the plan and template tools.
 *
 * Proposal 164 section 164.79. Six tools read or write the same checkbox list,
 * and WordPress implements that list four times with small differences. One
 * implementation here means `create_task_plan`, `get_task_plan`,
 * `update_task_plan`, `instantiate_template`, `create_execution_prompt` and the
 * template placeholders cannot drift apart.
 *
 * ## The format
 *
 * ```
 * # {plan name}
 *
 * ## Goal
 * {goal}
 *
 * ## Tasks
 * - [ ] First task (Priority: High)
 * - [x] Second task
 *
 * ## Status
 * Progress: 50%
 * Created: 2026-11-27 10:00:00
 * ```
 *
 * The strict task pattern is WordPress's `^- \[([ x])\] (.+)$`: a task starts at
 * column zero and has exactly one space after the closing bracket. A loose
 * pattern (`/^\s*-\s*\[ \]\s*(.+)$/`) is kept for the prompt builder, because
 * WordPress uses it there to catch hand-indented lists. Both remain, named,
 * rather than one silently standing in for the other.
 *
 * ## Two corrections
 *
 * - **Appending tasks is string surgery, not `preg_replace`.** WordPress's
 *   `new_tasks` path used `preg_replace` with a pattern whose non-capturing
 *   group was mangled to `( ? ( :`, so the pattern did not compile, the
 *   replacement returned null and the stored plan was wiped. It also spliced
 *   caller text straight into a replacement string, where `$1` and `\1` have
 *   meaning. The port finds the last task line and splices new lines after it.
 *   A plan with no `## Tasks` heading gets one appended rather than being left
 *   with tasks that cannot be found again.
 * - **CRLF is normalised on entry.** A hand-edited file with Windows line
 *   endings parses the same as one written here; the WordPress regex would keep
 *   the carriage return in the task text.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

final class TaskPlanMarkdown
{
    /** WordPress's strict task line. */
    public const TASK_PATTERN = '/^- \[([ x])\] (.+)$/';

    /** The prompt builder's looser unchecked-task matcher. */
    private const LOOSE_TASK_PATTERN = '/^\s*-\s*\[ \]\s*(.+)$/';

    /** The priority suffix WordPress writes and parses. */
    private const PRIORITY_PATTERN = '/\(Priority: (high|medium|low)\)/i';

    /** Template placeholders. */
    private const PLACEHOLDER_PATTERN = '/\{\{(\w+)\}\}/';

    /**
     * Render a new plan.
     *
     * @param list<array{text: string, priority: string}> $tasks Tasks, already validated.
     */
    public static function render(string $planName, string $goal, array $tasks): string
    {
        $markdown  = '# ' . $planName . "\n\n";
        $markdown .= "## Goal\n" . $goal . "\n\n";
        $markdown .= "## Tasks\n";

        foreach ($tasks as $task) {
            $markdown .= self::taskLine($task) . "\n";
        }

        $markdown .= "\n## Status\n";
        $markdown .= "Progress: 0%\n";
        $markdown .= 'Created: ' . gmdate('Y-m-d H:i:s') . "\n";

        return $markdown;
    }

    /**
     * Parse tasks, completion and progress.
     *
     * @return array{
     *     tasks: list<array{index: int, completed: bool, text: string, priority: string|null}>,
     *     total: int,
     *     completed: int,
     *     progress: float
     * }
     */
    public static function parse(string $markdown): array
    {
        $tasks = array();

        foreach (explode("\n", self::normalise($markdown)) as $line) {
            if (1 !== preg_match(self::TASK_PATTERN, $line, $match)) {
                continue;
            }

            $text     = $match[2];
            $priority = null;

            if (1 === preg_match(self::PRIORITY_PATTERN, $text, $priorityMatch)) {
                $priority = strtolower($priorityMatch[1]);
                $text     = trim(str_replace($priorityMatch[0], '', $text));
            }

            $tasks[] = array(
                'index'     => count($tasks),
                'completed' => 'x' === $match[1],
                'text'      => $text,
                'priority'  => $priority,
            );
        }

        $total     = count($tasks);
        $completed = 0;

        foreach ($tasks as $task) {
            if ($task['completed']) {
                ++$completed;
            }
        }

        return array(
            'tasks'     => $tasks,
            'total'     => $total,
            'completed' => $completed,
            'progress'  => self::progress($completed, $total),
        );
    }

    /**
     * Check or uncheck one task by its zero-based index.
     *
     * An index outside the list changes nothing, which is the WordPress rule.
     */
    public static function setTaskStatus(string $markdown, int $index, bool $completed): string
    {
        $lines = explode("\n", self::normalise($markdown));
        $seen  = -1;

        foreach ($lines as $position => $line) {
            if (1 !== preg_match(self::TASK_PATTERN, $line, $match)) {
                continue;
            }

            ++$seen;

            if ($seen === $index) {
                $lines[ $position ] = sprintf('- [%s] %s', $completed ? 'x' : ' ', $match[2]);
                break;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Append tasks after the last task line in the `## Tasks` section.
     *
     * @param list<array{text: string, priority: string}> $tasks Tasks, already validated.
     */
    public static function appendTasks(string $markdown, array $tasks): string
    {
        $markdown = self::normalise($markdown);
        $lines    = explode("\n", $markdown);
        $heading  = null;

        foreach ($lines as $position => $line) {
            if ('## Tasks' === rtrim($line)) {
                $heading = $position;
                break;
            }
        }

        if (null === $heading) {
            $markdown = rtrim($markdown) . "\n\n## Tasks\n";

            foreach ($tasks as $task) {
                $markdown .= self::taskLine($task) . "\n";
            }

            return $markdown;
        }

        $last = $heading;

        for ($position = $heading + 1, $count = count($lines); $position < $count; ++$position) {
            if (1 === preg_match(self::TASK_PATTERN, $lines[ $position ])) {
                $last = $position;
                continue;
            }

            if ('' === trim($lines[ $position ])) {
                continue;
            }

            break;
        }

        $newLines = array();

        foreach ($tasks as $task) {
            $newLines[] = self::taskLine($task);
        }

        array_splice($lines, $last + 1, 0, $newLines);

        return implode("\n", $lines);
    }

    /**
     * The first unchecked task from a parsed list.
     *
     * @param list<array{completed: bool, text: string}> $tasks Parsed tasks.
     */
    public static function nextIncomplete(array $tasks): ?string
    {
        foreach ($tasks as $task) {
            if (! $task['completed']) {
                return (string) $task['text'];
            }
        }

        return null;
    }

    /**
     * The first unchecked task using the prompt builder's loose pattern.
     */
    public static function nextIncompleteFromMarkdown(string $markdown): ?string
    {
        foreach (explode("\n", self::normalise($markdown)) as $line) {
            if (1 === preg_match(self::LOOSE_TASK_PATTERN, $line, $match)) {
                return trim($match[1]);
            }
        }

        return null;
    }

    /**
     * Distinct placeholders, in first-appearance order.
     *
     * @return list<string>
     */
    public static function placeholders(string $markdown): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $markdown, $matches);

        return array_values(array_unique($matches[1] ?? array()));
    }

    /**
     * The task count under the loose matcher, as the instantiator counts it.
     */
    public static function countTasks(string $markdown): int
    {
        $count = 0;

        foreach (explode("\n", self::normalise($markdown)) as $line) {
            if (1 === preg_match('/^- \[([ x])\] /', $line)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Percentage complete, rounded to one decimal as WordPress does.
     */
    public static function progress(int $completed, int $total): float
    {
        return $total > 0 ? round(( $completed / $total ) * 100, 1) : 0.0;
    }

    /**
     * One rendered task line.
     *
     * @param array{text: string, priority: string} $task Task.
     */
    private static function taskLine(array $task): string
    {
        $priority = '' !== $task['priority']
            ? ' (Priority: ' . ucfirst($task['priority']) . ')'
            : '';

        return '- [ ] ' . $task['text'] . $priority;
    }

    /**
     * Normalise line endings so a hand-edited file parses identically.
     */
    private static function normalise(string $markdown): string
    {
        return str_replace(array("\r\n", "\r"), "\n", $markdown);
    }
}
