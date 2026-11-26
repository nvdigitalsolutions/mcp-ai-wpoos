<?php

/**
 * JSON-backed autonomous session store — one file per session.
 *
 * Proposal 164 section 164.78. Mirrors `JsonPaperStore`'s layout and discipline:
 *
 *     {root}/{session_id}.json
 *
 * Atomic writes (temporary file + rename), a strict single-segment id rule, and
 * a directory scan for queries. Sessions are few — one per running workflow —
 * so the scan is the right trade: an index would need its own invalidation and
 * would be wrong more often than the directory.
 *
 * ## Hydration
 *
 * `find()` and `query()` return the documented defaults for every field the
 * record does not carry, so a host that stores only what changed still
 * satisfies the readers. `create()` applies the same defaults and stamps the
 * three timestamps WordPress writes: `started_at`, `last_activity` and
 * `expires_at` (24 hours out).
 *
 * ## Timestamps
 *
 * ISO 8601 UTC (`gmdate('c')`), not WordPress's site-local `Y-m-d H:i:s`. Every
 * comparison in the tools goes through `strtotime()`, so both parse; UTC is the
 * one that does not change meaning when a host moves.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Session;

use Nvoos\Core\Domain\Contract\AutonomousSessionStoreInterface;

final class JsonAutonomousSessionStore implements AutonomousSessionStoreInterface
{
    /** Maximum records a query may return. */
    public const MAX_LIMIT = 200;

    /** Default page size. */
    public const DEFAULT_LIMIT = 25;

    /** Characters allowed in a session id. */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    public function __construct(private readonly string $root)
    {
        if ('' === trim($root)) {
            throw new \InvalidArgumentException('Autonomous session store root must not be empty.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $session): array
    {
        $id = isset($session['session_id']) && is_scalar($session['session_id'])
            ? trim((string) $session['session_id'])
            : '';

        if ('' === $id) {
            $id = self::uuid4();
        }

        if (! self::isSegment($id)) {
            throw new \InvalidArgumentException('Invalid autonomous session id.');
        }

        $path = $this->path($id);

        if (null === $path) {
            throw new \InvalidArgumentException('Invalid autonomous session id.');
        }

        if (is_file($path)) {
            throw new \RuntimeException('An autonomous session with that id already exists.');
        }

        $now = gmdate('c');

        $session['session_id']    = $id;
        $session['started_at']    = isset($session['started_at']) && '' !== (string) $session['started_at']
            ? (string) $session['started_at']
            : $now;
        $session['last_activity'] = isset($session['last_activity']) && '' !== (string) $session['last_activity']
            ? (string) $session['last_activity']
            : $now;
        $session['expires_at']    = isset($session['expires_at']) && '' !== (string) $session['expires_at']
            ? (string) $session['expires_at']
            : gmdate('c', time() + self::DEFAULT_TTL);
        $session['created_at']    = isset($session['created_at']) && '' !== (string) $session['created_at']
            ? (string) $session['created_at']
            : $now;

        $session = $this->hydrate($session, $id);

        $this->write($path, $session);

        return $session;
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $sessionId): ?array
    {
        $path = $this->path($sessionId);

        if (null === $path || ! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if (false === $raw) {
            return null;
        }

        $record = json_decode($raw, true);

        if (! is_array($record)) {
            return null;
        }

        return $this->hydrate($record, $sessionId);
    }

    /**
     * {@inheritDoc}
     */
    public function update(string $sessionId, array $changes): ?array
    {
        $session = $this->find($sessionId);

        if (null === $session) {
            return null;
        }

        $merged = array_merge($session, $changes);

        if (! isset($changes['last_activity']) || '' === (string) $changes['last_activity']) {
            $merged['last_activity'] = gmdate('c');
        }

        $merged = $this->hydrate($merged, $sessionId);
        $path   = $this->path($sessionId);

        if (null === $path) {
            return null;
        }

        $this->write($path, $merged);

        return $merged;
    }

    /**
     * {@inheritDoc}
     */
    public function query(array $criteria = array()): array
    {
        $sessions = array();

        if (! is_dir($this->root)) {
            return array('sessions' => array(), 'total' => 0);
        }

        foreach ((array) glob($this->root . DIRECTORY_SEPARATOR . '*.json') as $file) {
            $raw = file_get_contents((string) $file);

            if (false === $raw) {
                continue;
            }

            $record = json_decode($raw, true);

            if (! is_array($record)) {
                continue;
            }

            $id = isset($record['session_id']) && is_scalar($record['session_id'])
                ? (string) $record['session_id']
                : basename((string) $file, '.json');

            $sessions[] = $this->hydrate($record, $id);
        }

        $sessions = $this->filter($sessions, $criteria);

        $order = 'started_at' === (string) ($criteria['order'] ?? '')
            ? 'started_at'
            : 'last_activity';

        usort(
            $sessions,
            static function (array $a, array $b) use ($order): int {
                $left  = strtotime((string) ( $a[ $order ] ?? '' ));
                $right = strtotime((string) ( $b[ $order ] ?? '' ));

                return ( false === $right ? 0 : $right ) <=> ( false === $left ? 0 : $left );
            }
        );

        $total  = count($sessions);
        $offset = max(0, (int) ($criteria['offset'] ?? 0));
        $limit  = (int) ($criteria['limit'] ?? self::DEFAULT_LIMIT);
        $limit  = $limit < 1 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);

        return array(
            'sessions' => array_slice($sessions, $offset, $limit),
            'total'    => $total,
        );
    }

    /**
     * @param list<array<string, mixed>> $sessions
     * @param array<string, mixed>       $criteria
     *
     * @return list<array<string, mixed>>
     */
    private function filter(array $sessions, array $criteria): array
    {
        $statuses = isset($criteria['statuses']) && is_array($criteria['statuses'])
            ? array_values(array_map('strval', $criteria['statuses']))
            : array();
        $status   = isset($criteria['status']) && is_string($criteria['status'])
            ? $criteria['status']
            : '';
        $since    = isset($criteria['since']) && is_string($criteria['since'])
            ? strtotime($criteria['since'])
            : false;

        $kept = array();

        foreach ($sessions as $session) {
            if (array() !== $statuses) {
                if (! in_array((string) $session['status'], $statuses, true)) {
                    continue;
                }
            } elseif ('' !== $status && (string) $session['status'] !== $status) {
                continue;
            }

            if (false !== $since) {
                $activity = strtotime((string) $session['last_activity']);

                if (false !== $activity && $activity < $since) {
                    continue;
                }
            }

            $kept[] = $session;
        }

        return $kept;
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
        $record['session_id']         = $id;
        $record['plan_id']            = (int) ($record['plan_id'] ?? 0);
        $record['assistant_id']       = (int) ($record['assistant_id'] ?? 0);
        $record['user_id']            = (int) ($record['user_id'] ?? 0);
        $record['status']             = (string) ($record['status'] ?? self::STATUS_ACTIVE);
        $record['iteration_count']    = (int) ($record['iteration_count'] ?? 0);
        $record['max_iterations']     = (int) ($record['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS);
        $record['health_status']      = (string) ($record['health_status'] ?? self::HEALTH_HEALTHY);
        $record['circuit_breaker']    = (string) ($record['circuit_breaker'] ?? 'closed');
        $record['token_usage']        = (int) ($record['token_usage'] ?? 0);
        $record['token_budget']       = (int) ($record['token_budget'] ?? self::DEFAULT_TOKEN_BUDGET);
        $record['success_rate']       = (float) ($record['success_rate'] ?? 100.0);
        $record['error_count']        = (int) ($record['error_count'] ?? 0);
        $record['consecutive_errors'] = (int) ($record['consecutive_errors'] ?? 0);
        $record['completion_score']   = (int) ($record['completion_score'] ?? 0);
        $record['exit_signal']        = (bool) ($record['exit_signal'] ?? false);
        $record['started_at']         = (string) ($record['started_at'] ?? '');
        $record['last_activity']      = (string) ($record['last_activity'] ?? '');
        $record['expires_at']         = (string) ($record['expires_at'] ?? '');
        $record['completed_at']       = (string) ($record['completed_at'] ?? '');
        $record['last_tool']          = (string) ($record['last_tool'] ?? '');
        $record['last_error']         = (string) ($record['last_error'] ?? '');
        $record['circuit_opened_at']  = isset($record['circuit_opened_at']) && null !== $record['circuit_opened_at']
            ? (int) $record['circuit_opened_at']
            : null;
        $record['stop_reason']        = (string) ($record['stop_reason'] ?? '');

        return $record;
    }

    /**
     * Write atomically.
     *
     * @param array<string, mixed> $record Record.
     */
    private function write(string $path, array $record): void
    {
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new \RuntimeException('Unable to encode the autonomous session as JSON.');
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create the autonomous session directory.');
        }

        $tmp = @tempnam($dir, '.session-');

        if (false === $tmp) {
            throw new \RuntimeException('Unable to create a temporary file for the session write.');
        }

        if (false === file_put_contents($tmp, $json . "\n", LOCK_EX)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write the autonomous session.');
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to publish the autonomous session.');
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

    /**
     * RFC 4122 version 4 id from `random_bytes()`.
     */
    private static function uuid4(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr(( ord($bytes[6]) & 0x0f ) | 0x40);
        $bytes[8] = chr(( ord($bytes[8]) & 0x3f ) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
