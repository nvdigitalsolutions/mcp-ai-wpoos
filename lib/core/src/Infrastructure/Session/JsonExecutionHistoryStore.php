<?php

/**
 * JSON-lines execution history — one append-only file per session.
 *
 * Proposal 164 section 164.78:
 *
 *     {root}/{session_id}.jsonl
 *
 * One JSON object per line, appended under `LOCK_EX`, read oldest-first. The
 * append-only form is the right one for a call log: a rewrite is how a crash
 * loses the last minute of evidence, and nothing here needs to edit history.
 *
 * The read stops at the requested limit (the WordPress reader asks for the
 * first 1,000 in ascending order), so a long session costs a bounded decode.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Session;

use Nvoos\Core\Domain\Contract\ExecutionHistoryStoreInterface;

final class JsonExecutionHistoryStore implements ExecutionHistoryStoreInterface
{
    /** Characters allowed in a session id. */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    public function __construct(private readonly string $root)
    {
        if ('' === trim($root)) {
            throw new \InvalidArgumentException('Execution history root must not be empty.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function append(string $sessionId, array $record): void
    {
        $path = $this->path($sessionId);

        if (null === $path) {
            throw new \InvalidArgumentException('Invalid autonomous session id.');
        }

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create the execution history directory.');
        }

        if (! isset($record['at']) || '' === (string) $record['at']) {
            $record['at'] = gmdate('c');
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $line) {
            throw new \RuntimeException('Unable to encode the execution record as JSON.');
        }

        if (false === file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX)) {
            throw new \RuntimeException('Unable to append the execution record.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function forSession(string $sessionId, int $limit = self::DEFAULT_LIMIT): array
    {
        $path = $this->path($sessionId);

        if (null === $path || ! is_file($path)) {
            return array();
        }

        $limit   = max(1, min($limit, self::MAX_LIMIT));
        $handle  = fopen($path, 'rb');
        $records = array();

        if (false === $handle) {
            return array();
        }

        try {
            while (count($records) < $limit && false !== ( $line = fgets($handle) )) {
                $decoded = json_decode(trim($line), true);

                if (is_array($decoded)) {
                    $records[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $records;
    }

    /**
     * Absolute path for an id, or null when the id is not a safe segment.
     */
    private function path(string $sessionId): ?string
    {
        if (1 !== preg_match(self::SEGMENT_PATTERN, $sessionId)) {
            return null;
        }

        return $this->root . DIRECTORY_SEPARATOR . $sessionId . '.jsonl';
    }
}
