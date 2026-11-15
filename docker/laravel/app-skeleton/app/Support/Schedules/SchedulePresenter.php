<?php

namespace App\Support\Schedules;

/**
 * The console-facing shape of a schedule row.
 *
 * Shared by the Schedule Manager controller and the preset installer so an
 * installed preset and a hand-created schedule present identically to the
 * ScheduleManagerClient.
 */
final class SchedulePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(object $row): array
    {
        $tags = json_decode((string) ($row->tags ?? ''), true);

        return [
            'id' => (string) $row->id,
            'name' => (string) ($row->name ?? ''),
            'description' => $row->description,
            'schedule_type' => (string) ($row->schedule_type ?? 'cron'),
            'schedule' => (string) ($row->cron_expression ?? ''),
            'enabled' => (bool) ($row->enabled ?? true),
            'next_run' => $row->next_run_at !== null ? (string) $row->next_run_at : null,
            'last_run' => $row->last_run_at !== null ? (string) $row->last_run_at : null,
            'priority' => (int) ($row->priority ?? 10),
            'tags' => is_array($tags) ? array_values($tags) : [],
            'run_count' => (int) ($row->run_count ?? 0),
            'handler' => (string) $row->handler,
            'payload' => json_decode((string) ($row->payload ?? ''), true),
        ];
    }
}
