<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote;

/**
 * Sync state persistence stub.
 *
 * Tracks per-source sync state (last cursor, page token,
 * incremental offsets). Full implementation in the
 * `nvoos-graphify-remote` addon.
 *
 * @since 1.0.0
 */
class StateStore
{
    /**
     * Get the last sync cursor for a source.
     *
     * @param string $slug Source slug.
     * @return string|null
     */
    public function getCursor( string $slug ): ?string {
        return null;
    }

    /**
     * Set the sync cursor for a source.
     *
     * @param string $slug   Source slug.
     * @param string $cursor Cursor value.
     * @return void
     */
    public function setCursor( string $slug, string $cursor ): void {}
}
