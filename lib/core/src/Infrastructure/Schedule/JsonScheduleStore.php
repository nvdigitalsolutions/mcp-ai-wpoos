<?php

/**
 * JSON-backed schedule store — one file per definition.
 *
 * Proposal 164 section 164.80:
 *
 *     {root}/{id}.json   {"schedule": {...}, "history": [...], "results": [...]}
 *
 * Keeping the three arrays in one file is deliberate: a run's history entry and
 * its result envelope belong to the same definition, and one atomic write per
 * append is simpler to reason about than three files that can disagree after a
 * crash. Definitions are few, so the directory scan for `all()` is the right
 * trade — the same reasoning as the autonomous-session store.
 *
 * `save()` is an upsert that **preserves** the history and results already
 * stored for the id, which is what makes the WordPress md5 id useful: editing a
 * task schedule by re-creating it does not discard its run record.
 *
 * Hydration fills every documented default, so a reader can index a field
 * without an existence check — the same rule as the session store.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Schedule;

use Nvoos\Core\Domain\Contract\ScheduleStoreInterface;

final class JsonScheduleStore implements ScheduleStoreInterface
{
    /** Characters allowed in a schedule id (md5 hex fits comfortably). */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    public function __construct(private readonly string $root)
    {
        if ('' === trim($root)) {
            throw new \InvalidArgumentException('Schedule store root must not be empty.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function save(array $schedule): array
    {
        $id = isset($schedule['id']) && is_scalar($schedule['id'])
            ? trim((string) $schedule['id'])
            : '';

        if (! self::isSegment($id)) {
            throw new \InvalidArgumentException('Invalid schedule id.');
        }

        $stored = $this->read($id);

        $record = $this->hydrate($schedule, $id);
        $this->write(
            $id,
            array(
                'schedule' => $record,
                'history'  => $stored['history'],
                'results'  => $stored['results'],
            )
        );

        return $record;
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $scheduleId): ?array
    {
        if (! self::isSegment($scheduleId)) {
            return null;
        }

        $stored = $this->read($scheduleId);

        return null === $stored['schedule'] ? null : $stored['schedule'];
    }

    /**
     * {@inheritDoc}
     */
    public function all(array $filters = array()): array
    {
        if (! is_dir($this->root)) {
            return array();
        }

        $matches = array();

        foreach ((array) glob($this->root . DIRECTORY_SEPARATOR . '*.json') as $file) {
            $id     = basename((string) $file, '.json');
            $stored = $this->read($id);

            if (null === $stored['schedule']) {
                continue;
            }

            $schedule = $stored['schedule'];

            if (isset($filters['enabled']) && (bool) $schedule['enabled'] !== (bool) $filters['enabled']) {
                continue;
            }

            if (isset($filters['schedule_type']) && (string) $schedule['schedule_type'] !== (string) $filters['schedule_type']) {
                continue;
            }

            if (
                isset($filters['tag']) && '' !== (string) $filters['tag']
                && ! in_array((string) $filters['tag'], $schedule['tags'], true)
            ) {
                continue;
            }

            $matches[] = $schedule;
        }

        usort(
            $matches,
            static function (array $left, array $right): int {
                if ($left['priority'] !== $right['priority']) {
                    return $left['priority'] <=> $right['priority'];
                }

                $byName = strcmp((string) $left['name'], (string) $right['name']);

                return 0 !== $byName ? $byName : strcmp((string) $left['id'], (string) $right['id']);
            }
        );

        return $matches;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $scheduleId): bool
    {
        $path = $this->path($scheduleId);

        if (null === $path || ! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * {@inheritDoc}
     */
    public function appendRun(string $scheduleId, array $run): void
    {
        $stored = $this->read($scheduleId);

        if (null === $stored['schedule']) {
            throw new \RuntimeException('Schedule not found.');
        }

        $history   = $stored['history'];
        $history[] = $run;
        $history   = array_slice($history, -self::MAX_HISTORY_PER_SCHEDULE);

        $this->write(
            $scheduleId,
            array(
                'schedule' => $stored['schedule'],
                'history'  => array_values($history),
                'results'  => $stored['results'],
            )
        );
    }

    /**
     * {@inheritDoc}
     */
    public function history(string $scheduleId, int $limit = 20): array
    {
        $stored = $this->read($scheduleId);

        $limit = max(1, min($limit, self::MAX_HISTORY_PER_SCHEDULE));

        return array_slice(array_reverse($stored['history']), 0, $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function appendResult(string $scheduleId, array $envelope): void
    {
        $stored = $this->read($scheduleId);

        if (null === $stored['schedule']) {
            throw new \RuntimeException('Schedule not found.');
        }

        $retention = (int) ( $stored['schedule']['display']['result_retention'] ?? self::DEFAULT_RESULT_RETENTION );
        $retention = max(1, min($retention, self::MAX_RESULT_RETENTION));

        $results   = $stored['results'];
        $results[] = $envelope;
        $results   = array_slice($results, -$retention);

        $this->write(
            $scheduleId,
            array(
                'schedule' => $stored['schedule'],
                'history'  => $stored['history'],
                'results'  => array_values($results),
            )
        );
    }

    /**
     * {@inheritDoc}
     */
    public function latestResult(string $scheduleId): ?array
    {
        $stored = $this->read($scheduleId);

        if (array() === $stored['results']) {
            return null;
        }

        $envelope = end($stored['results']);

        return is_array($envelope) ? $envelope : null;
    }

    /**
     * {@inheritDoc}
     */
    public function results(string $scheduleId, int $limit = 10): array
    {
        $stored = $this->read($scheduleId);

        $limit = max(1, min($limit, self::MAX_RESULT_RETENTION));

        return array_slice(array_reverse($stored['results']), 0, $limit);
    }

    /**
     * Read one file's three arrays, with safe defaults.
     *
     * @return array{schedule: array<string, mixed>|null, history: list<array<string, mixed>>, results: list<array<string, mixed>>}
     */
    private function read(string $id): array
    {
        $empty = array('schedule' => null, 'history' => array(), 'results' => array());
        $path  = $this->path($id);

        if (null === $path || ! is_file($path)) {
            return $empty;
        }

        $raw = file_get_contents($path);

        if (false === $raw) {
            return $empty;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $empty;
        }

        $schedule = isset($decoded['schedule']) && is_array($decoded['schedule'])
            ? $this->hydrate($decoded['schedule'], $id)
            : null;

        return array(
            'schedule' => $schedule,
            'history'  => isset($decoded['history']) && is_array($decoded['history'])
                ? array_values(array_filter($decoded['history'], 'is_array'))
                : array(),
            'results'  => isset($decoded['results']) && is_array($decoded['results'])
                ? array_values(array_filter($decoded['results'], 'is_array'))
                : array(),
        );
    }

    /**
     * Fill every documented default.
     *
     * @param array<string, mixed> $record Raw record.
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $record, string $id): array
    {
        $record['id']            = $id;
        $record['name']          = (string) ($record['name'] ?? '');
        $record['description']   = (string) ($record['description'] ?? '');
        $record['schedule_type'] = in_array($record['schedule_type'] ?? '', self::TYPES, true)
            ? (string) $record['schedule_type']
            : self::TYPE_TASK;
        $record['hook']          = (string) ($record['hook'] ?? '');
        $record['args']          = isset($record['args']) && is_array($record['args'])
            ? array_values($record['args'])
            : array();
        $record['workflow_steps'] = isset($record['workflow_steps']) && is_array($record['workflow_steps'])
            ? array_values(array_filter($record['workflow_steps'], 'is_array'))
            : array();
        $record['assistant_config'] = isset($record['assistant_config']) && is_array($record['assistant_config'])
            ? $record['assistant_config']
            : array();
        $record['broadcast_config'] = isset($record['broadcast_config']) && is_array($record['broadcast_config'])
            ? $record['broadcast_config']
            : array();
        $record['workflow_builder_id'] = (string) ($record['workflow_builder_id'] ?? '');
        $record['display']       = $this->hydrateDisplay($record['display'] ?? array());
        $record['schedule']      = (string) ($record['schedule'] ?? 'single');
        $record['timestamp']     = max(0, (int) ($record['timestamp'] ?? 0));
        $record['enabled']       = (bool) ($record['enabled'] ?? true);
        $record['priority']      = max(1, min(10, (int) ($record['priority'] ?? 5)));
        $record['tags']          = isset($record['tags']) && is_array($record['tags'])
            ? array_values(array_map('strval', $record['tags']))
            : array();
        $record['timeout']       = max(0, (int) ($record['timeout'] ?? 0));
        $record['callback_url']  = (string) ($record['callback_url'] ?? '');
        $record['callback_secret'] = (string) ($record['callback_secret'] ?? '');
        $record['notify_on_failure'] = (bool) ($record['notify_on_failure'] ?? false);
        $record['notify_email']  = (string) ($record['notify_email'] ?? '');
        $record['notify_channels'] = isset($record['notify_channels']) && is_array($record['notify_channels'])
            ? array_values(array_map('strval', $record['notify_channels']))
            : array();
        $record['notify_channel_credentials'] = isset($record['notify_channel_credentials']) && is_array($record['notify_channel_credentials'])
            ? $record['notify_channel_credentials']
            : array();
        $record['result_delivery'] = isset($record['result_delivery']) && is_array($record['result_delivery'])
            ? $record['result_delivery']
            : array();
        $record['max_retries']   = max(0, min(5, (int) ($record['max_retries'] ?? 0)));
        $record['retry_delay']   = max(60, (int) ($record['retry_delay'] ?? 300));
        $record['retry_count']   = max(0, (int) ($record['retry_count'] ?? 0));
        $record['last_run_status'] = (string) ($record['last_run_status'] ?? self::STATUS_NEVER);
        $record['last_run_time'] = max(0, (int) ($record['last_run_time'] ?? 0));
        $record['last_run_duration'] = max(0, (int) ($record['last_run_duration'] ?? 0));
        $record['last_error']    = (string) ($record['last_error'] ?? '');
        $record['run_count']     = max(0, (int) ($record['run_count'] ?? 0));
        $record['created_at']    = max(0, (int) ($record['created_at'] ?? 0));
        $record['updated_at']    = max(0, (int) ($record['updated_at'] ?? 0));
        $record['created_by']    = max(0, (int) ($record['created_by'] ?? 0));
        $record['updated_by']    = max(0, (int) ($record['updated_by'] ?? 0));

        return $record;
    }

    /**
     * @param mixed $display Raw display settings.
     *
     * @return array<string, mixed>
     */
    private function hydrateDisplay(mixed $display): array
    {
        $display = is_array($display) ? $display : array();

        $widgets = isset($display['widget_defaults']) && is_array($display['widget_defaults'])
            ? $display['widget_defaults']
            : array();

        return array(
            'result_capture'   => in_array($display['result_capture'] ?? '', array('disabled', 'summary', 'full'), true)
                ? (string) $display['result_capture']
                : 'summary',
            'public_render'    => ! empty($display['public_render']),
            'public_fields'    => isset($display['public_fields']) && is_array($display['public_fields'])
                ? array_values(array_map('strval', $display['public_fields']))
                : array(),
            'result_retention' => max(1, min(self::MAX_RESULT_RETENTION, (int) ($display['result_retention'] ?? self::DEFAULT_RESULT_RETENTION))),
            'widget_defaults'  => array(
                'render_mode'      => (string) ($widgets['render_mode'] ?? 'summary-card'),
                'title'            => (string) ($widgets['title'] ?? ''),
                'refresh_interval' => max(0, min(3600, (int) ($widgets['refresh_interval'] ?? 0))),
            ),
        );
    }

    /**
     * Write one file atomically.
     *
     * @param array{schedule: array<string, mixed>, history: list<array<string, mixed>>, results: list<array<string, mixed>>} $payload Payload.
     */
    private function write(string $id, array $payload): void
    {
        $path = $this->path($id);

        if (null === $path) {
            throw new \InvalidArgumentException('Invalid schedule id.');
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new \RuntimeException('Unable to encode the schedule as JSON.');
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create the schedule directory.');
        }

        $tmp = @tempnam($dir, '.schedule-');

        if (false === $tmp) {
            throw new \RuntimeException('Unable to create a temporary file for the schedule write.');
        }

        if (false === file_put_contents($tmp, $json . "\n", LOCK_EX)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write the schedule.');
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to publish the schedule.');
        }
    }

    /**
     * Absolute path for an id, or null when the id is not a safe segment.
     */
    private function path(string $id): ?string
    {
        return self::isSegment($id)
            ? $this->root . DIRECTORY_SEPARATOR . $id . '.json'
            : null;
    }

    private static function isSegment(string $value): bool
    {
        return 1 === preg_match(self::SEGMENT_PATTERN, $value);
    }
}
