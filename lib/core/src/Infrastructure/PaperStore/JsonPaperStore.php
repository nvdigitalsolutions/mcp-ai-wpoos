<?php
/**
 * JSON Paper Store — one file per record under a configured root.
 *
 * Layout mirrors the WordPress Paper Store so the same records can be read by
 * either runtime:
 *
 *     {root}/{collection}/{id}.json
 *
 * Each file holds a single record as pretty-printed JSON — no envelope, no
 * sidecar index. Listing a collection therefore reads the directory; that is
 * deliberate, because a stale index is worse than a directory read at the
 * sizes this store is used for (hundreds, not millions, of records).
 *
 * Writes are atomic: the payload goes to a temporary file in the destination
 * directory and is then renamed over the target, so a reader never observes a
 * half-written record and a crash mid-write leaves the previous version intact.
 *
 * Collection names and ids are validated as single path segments. Anything
 * containing a separator, a drive letter or `..` is rejected rather than
 * normalised, because silently rewriting a caller's id turns a path-traversal
 * attempt into a successful write somewhere else.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\PaperStore;

use Nvoos\Core\Domain\Contract\PaperStoreInterface;

/**
 * Filesystem-backed Paper Store.
 *
 * @since 1.3.0
 */
final class JsonPaperStore implements PaperStoreInterface
{
    /** Maximum records a single query may return. */
    public const MAX_LIMIT = 200;

    /** Default page size. */
    public const DEFAULT_LIMIT = 25;

    /** Characters allowed in a collection name or record id. */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    /**
     * @param string $root Absolute path to the store root.
     */
    public function __construct(private readonly string $root)
    {
        if ('' === trim($root)) {
            throw new \InvalidArgumentException('Paper Store root must not be empty.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function collections(): array
    {
        if (! is_dir($this->root)) {
            return array();
        }

        $collections = array();

        foreach ((array) scandir($this->root) as $entry) {
            if (! is_string($entry) || ! $this->isSegment($entry)) {
                continue;
            }

            $dir = $this->root . DIRECTORY_SEPARATOR . $entry;

            if (! is_dir($dir)) {
                continue;
            }

            foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*.json') as $file) {
                if (is_file((string) $file)) {
                    $collections[] = $entry;
                    break;
                }
            }
        }

        sort($collections);

        return $collections;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $collection, string $id): bool
    {
        $path = $this->path($collection, $id);

        return null !== $path && is_file($path);
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $collection, string $id): ?array
    {
        $path = $this->path($collection, $id);

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

        return $this->hydrate($record, $collection, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function query(string $collection, array $criteria = array()): array
    {
        // Validate the sort before the empty-collection shortcut, so a caller
        // bug is reported the same way whether or not records exist yet.
        $order = $this->resolveOrder($criteria);

        $dir = $this->collectionPath($collection);

        if (null === $dir || ! is_dir($dir)) {
            return array('records' => array(), 'total' => 0);
        }

        $records = array();

        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*.json') as $file) {
            $raw = file_get_contents((string) $file);

            if (false === $raw) {
                continue;
            }

            $record = json_decode($raw, true);

            if (! is_array($record)) {
                continue;
            }

            $id = isset($record['id']) && is_scalar($record['id'])
                ? (string) $record['id']
                : basename((string) $file, '.json');

            $record = $this->hydrate($record, $collection, $id);

            if ($this->matches($record, $criteria)) {
                $records[] = $record;
            }
        }

        $records = $this->sort($records, $order);
        $total = count($records);

        $offset = max(0, (int) ($criteria['offset'] ?? 0));
        $limit = (int) ($criteria['limit'] ?? self::DEFAULT_LIMIT);
        $limit = $limit < 1 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);

        return array(
            'records' => array_slice($records, $offset, $limit),
            'total' => $total,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function save(string $collection, array $record): array
    {
        $dir = $this->collectionPath($collection);

        if (null === $dir) {
            throw new \InvalidArgumentException('Invalid Paper Store collection name.');
        }

        $title = isset($record['title']) && is_scalar($record['title'])
            ? trim((string) $record['title'])
            : '';

        if ('' === $title) {
            throw new \InvalidArgumentException('A Paper Store record requires a title.');
        }

        $id = isset($record['id']) && is_scalar($record['id']) && '' !== (string) $record['id']
            ? (string) $record['id']
            : $this->slug($title);

        if (! $this->isSegment($id)) {
            throw new \InvalidArgumentException('Invalid Paper Store record id.');
        }

        $path = $dir . DIRECTORY_SEPARATOR . $id . '.json';
        $existing = $this->find($collection, $id);
        $now = gmdate('c');

        $record['id'] = $id;
        $record['collection'] = $collection;
        $record['title'] = $title;
        $record['schemaVersion'] = self::SCHEMA_VERSION;
        $record['created_at'] = $existing['created_at'] ?? ($record['created_at'] ?? $now);
        $record['updated_at'] = $now;

        if (! isset($record['tags']) || ! is_array($record['tags'])) {
            $record['tags'] = array();
        }

        if (! isset($record['meta']) || ! is_array($record['meta'])) {
            $record['meta'] = array();
        }

        if (! isset($record['type']) || '' === $record['type']) {
            $record['type'] = $collection;
        }

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create the Paper Store collection directory.');
        }

        $this->writeAtomically($path, $record);

        return $this->hydrate($record, $collection, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $collection, string $id): bool
    {
        $path = $this->path($collection, $id);

        if (null === $path || ! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Write JSON via a temporary file and an atomic rename.
     *
     * @param array<string, mixed> $record Record to encode.
     */
    private function writeAtomically(string $path, array $record): void
    {
        $json = json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (false === $json) {
            throw new \RuntimeException('Unable to encode the Paper Store record as JSON.');
        }

        $dir = dirname($path);
        $tmp = @tempnam($dir, '.paper-');

        if (false === $tmp) {
            throw new \RuntimeException('Unable to create a temporary file for the Paper Store write.');
        }

        if (false === file_put_contents($tmp, $json . "\n", LOCK_EX)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write the Paper Store record.');
        }

        // rename() replaces the target on POSIX and Windows alike, so readers
        // see either the old record or the new one, never a partial file.
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to publish the Paper Store record.');
        }
    }

    /**
     * Normalise a stored record into the contract shape.
     *
     * @param array<string, mixed> $record Raw decoded record.
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $record, string $collection, string $id): array
    {
        $record['id'] = $id;
        $record['collection'] = $collection;
        $record['title'] = isset($record['title']) && is_scalar($record['title'])
            ? (string) $record['title']
            : '';
        $record['description'] = isset($record['description']) && is_scalar($record['description'])
            ? (string) $record['description']
            : '';
        $record['status'] = isset($record['status']) && is_scalar($record['status'])
            ? (string) $record['status']
            : 'draft';
        $record['type'] = isset($record['type']) && is_scalar($record['type'])
            ? (string) $record['type']
            : $collection;
        $record['tags'] = isset($record['tags']) && is_array($record['tags'])
            ? array_values(array_map('strval', $record['tags']))
            : array();
        $record['meta'] = isset($record['meta']) && is_array($record['meta']) ? $record['meta'] : array();

        if (! array_key_exists('body', $record)) {
            $record['body'] = null;
        }

        $record['schemaVersion'] = isset($record['schemaVersion']) ? (int) $record['schemaVersion'] : self::SCHEMA_VERSION;

        return $record;
    }

    /**
     * Whether a record satisfies every supplied criterion.
     *
     * @param array<string, mixed> $record   Candidate record.
     * @param array<string, mixed> $criteria Query criteria.
     */
    private function matches(array $record, array $criteria): bool
    {
        if (isset($criteria['tags']) && is_string($criteria['tags']) && '' !== $criteria['tags']) {
            if (! in_array($criteria['tags'], (array) $record['tags'], true)) {
                return false;
            }
        }

        foreach (array('status', 'type') as $field) {
            if (isset($criteria[$field]) && is_string($criteria[$field]) && '' !== $criteria[$field]) {
                if ((string) $record[$field] !== $criteria[$field]) {
                    return false;
                }
            }
        }

        if (isset($criteria['search']) && is_string($criteria['search']) && '' !== trim($criteria['search'])) {
            $needle = strtolower(trim($criteria['search']));
            $haystack = strtolower((string) $record['title'] . "\n" . (string) $record['description']);

            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the requested sort field and direction.
     *
     * Unknown fields throw for the same reason `ContentQuery` order fields
     * throw: silently falling back to `updated_at` hides a caller bug.
     *
     * @param array<string, mixed> $criteria Query criteria.
     *
     * @return array{0:string,1:int} Field name and direction multiplier.
     */
    private function resolveOrder(array $criteria): array
    {
        $field = isset($criteria['order_by']) && is_string($criteria['order_by'])
            ? $criteria['order_by']
            : 'updated_at';

        if (! in_array($field, array('updated_at', 'created_at', 'title', 'id'), true)) {
            throw new \InvalidArgumentException("Paper Store cannot order by '{$field}'.");
        }

        $direction = isset($criteria['direction']) && 'asc' === strtolower((string) $criteria['direction'])
            ? 1
            : -1;

        return array($field, $direction);
    }

    /**
     * Sort a candidate set by the resolved field.
     *
     * @param list<array<string, mixed>> $records Candidates.
     * @param array{0:string,1:int}      $order   Resolved order.
     *
     * @return list<array<string, mixed>>
     */
    private function sort(array $records, array $order): array
    {
        list($field, $direction) = $order;

        usort(
            $records,
            static function (array $a, array $b) use ($field, $direction): int {
                $left = (string) ($a[$field] ?? '');
                $right = (string) ($b[$field] ?? '');

                // Records written by the WordPress driver already order
                // correctly as strings (ISO 8601 and sanitized ids), so a
                // lexical compare is the honest one for all four fields.
                return $direction * strcmp($left, $right);
            }
        );

        return array_values($records);
    }

    /**
     * Absolute path of a record file, or null when either segment is invalid.
     */
    private function path(string $collection, string $id): ?string
    {
        $dir = $this->collectionPath($collection);

        if (null === $dir || ! $this->isSegment($id)) {
            return null;
        }

        return $dir . DIRECTORY_SEPARATOR . $id . '.json';
    }

    /**
     * Absolute path of a collection directory, or null when the name is invalid.
     */
    private function collectionPath(string $collection): ?string
    {
        if (! $this->isSegment($collection)) {
            return null;
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $collection;
    }

    /**
     * Whether the value is a single, safe path segment.
     */
    private function isSegment(string $value): bool
    {
        return 1 === preg_match(self::SEGMENT_PATTERN, $value);
    }

    /**
     * Derive a slug from a title.
     */
    private function slug(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        if ('' === $slug) {
            $slug = 'record-' . bin2hex(random_bytes(4));
        }

        return substr($slug, 0, 64);
    }
}
