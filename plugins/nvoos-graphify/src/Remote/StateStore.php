<?php
/**
 * Per-source state store for sync watermarks.
 *
 * Lightweight key/value store backed by the graph meta table.
 * No new schema migration required.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Remote;

use NvoosGraphify\Graph\Db;

/**
 * Per-source state storage helper.
 *
 * @since 1.0.0
 */
final class StateStore
{
    /**
     * Meta-key prefix for per-source state rows.
     *
     * @var string
     */
    public const META_PREFIX = 'remote_state_';

    /**
     * Build the meta key for a given source slug.
     *
     * @since 1.0.0
     * @param string $slug Source slug.
     * @return string
     */
    private static function key(string $slug): string
    {
        return self::META_PREFIX . sanitize_key($slug);
    }

    /**
     * Return the full state array for a source.
     *
     * @since 1.0.0
     * @param string $slug Source slug.
     * @return array<string,mixed> Associative state array; empty when no state exists.
     */
    public static function getState(string $slug): array
    {
        $value = Db::getMeta(self::key($slug), array());
        return is_array($value) ? $value : array();
    }

    /**
     * Read a single field from the state for a source.
     *
     * @since 1.0.0
     * @param string $slug    Source slug.
     * @param string $field   Field name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get(string $slug, string $field, mixed $default = null): mixed
    {
        $state = self::getState($slug);
        $field = sanitize_key($field);
        return array_key_exists($field, $state) ? $state[$field] : $default;
    }

    /**
     * Set a single field in the state for a source.
     *
     * @since 1.0.0
     * @param string $slug  Source slug.
     * @param string $field Field name (sanitised).
     * @param mixed  $value Value (must be JSON-serialisable).
     * @return void
     */
    public static function set(string $slug, string $field, mixed $value): void
    {
        $state                        = self::getState($slug);
        $state[sanitize_key($field)] = $value;
        Db::setMeta(self::key($slug), $state);
    }

    /**
     * Replace the entire state array for a source.
     *
     * @since 1.0.0
     * @param string               $slug  Source slug.
     * @param array<string,mixed>  $state Full state array.
     * @return void
     */
    public static function replace(string $slug, array $state): void
    {
        Db::setMeta(self::key($slug), $state);
    }

    /**
     * Clear all state for a source.
     *
     * @since 1.0.0
     * @param string $slug Source slug.
     * @return void
     */
    public static function clear(string $slug): void
    {
        Db::setMeta(self::key($slug), array());
    }

    /**
     * Convenience: record a successful sync timestamp + optional cursor.
     *
     * @since 1.0.0
     * @param string      $slug   Source slug.
     * @param string|null $cursor Optional cursor / watermark value.
     * @return void
     */
    public static function markSynced(string $slug, ?string $cursor = null): void
    {
        $state                 = self::getState($slug);
        $state['last_sync_at'] = gmdate('c');
        if (null !== $cursor) {
            $state['last_cursor'] = $cursor;
        }
        self::replace($slug, $state);
    }
}
