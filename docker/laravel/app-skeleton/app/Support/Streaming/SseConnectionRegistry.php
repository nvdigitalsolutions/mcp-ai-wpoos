<?php

namespace App\Support\Streaming;

use App\Support\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Simultaneous SSE connections per user — Proposal 149 §185.
 *
 * `max_sse_connections_per_user` (default 5, 0 = unlimited) was writable on
 * `/ops/settings` and read by nothing. A stream holds a PHP worker for its whole
 * life, so an uncapped user can exhaust the pool with a handful of tabs — which
 * is exactly what the field describes.
 *
 * ## Why this is an approximation, and what it costs
 *
 * Concurrency is not observable in PHP-FPM. There is no registry of live
 * requests, and one worker cannot see another's death. So the count is a cache
 * counter with a TTL, released in a `finally` when the stream ends:
 *
 * | Situation | Result |
 * |---|---|
 * | Normal stream completes or the client disconnects | `finally` releases the slot |
 * | Worker killed (OOM, deploy, SIGKILL) | the slot expires with the TTL |
 * | No acquire or release for the TTL | the counter resets, even mid-stream |
 *
 * The third row is the cost: a user holding several long streams that never open
 * a new one has their count reset after `TTL` and can open more. That fails
 * **open** — it under-counts — which is the safe direction for a control whose
 * failure mode is locking a user out of chat. A hard kill leaves a slot taken for
 * at most `TTL`, which is bounded rather than permanent.
 *
 * Renewal mid-stream is not available: the controller hands the whole stream to
 * `ChatOrchestrator::handleChatStreaming()`, so there is no loop here to touch.
 * `TTL` is therefore generous rather than tight.
 *
 * ## Failure behaviour
 *
 * If the store or cache is unavailable, `acquire()` returns true: refusing every
 * stream because a counter is unreadable would be the outage the cap exists to
 * prevent.
 */
final class SseConnectionRegistry
{
    /** Seconds a slot survives without being touched. See the class docblock. */
    public const TTL = 600;

    private const PREFIX = 'sse-connections:';

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Reserve a slot, or refuse when the user is at the cap.
     *
     * Anonymous callers (`$userId <= 0`) are never counted: the route behind this
     * requires authentication, and sharing one bucket across every unidentified
     * caller would let one client block all of them.
     */
    public function acquire(int $userId): bool
    {
        $limit = $this->limit();

        if ($userId <= 0 || $limit <= 0) {
            return true;
        }

        try {
            $key = $this->key($userId);
            $count = (int) Cache::get($key, 0);

            if ($count >= $limit) {
                return false;
            }

            Cache::put($key, $count + 1, self::TTL);

            return true;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Release a slot. Safe to call when nothing was acquired, and never negative.
     */
    public function release(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $key = $this->key($userId);
            $count = (int) Cache::get($key, 0);

            if ($count <= 1) {
                Cache::forget($key);

                return;
            }

            Cache::put($key, $count - 1, self::TTL);
        } catch (Throwable) {
            // Best effort: the TTL clears the slot even if this never runs.
        }
    }

    /**
     * The user's current count, for tests and diagnostics.
     */
    public function active(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            return (int) Cache::get($this->key($userId), 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The cap, clamped to the field's bounds. `0` means unlimited.
     */
    public function limit(): int
    {
        try {
            return max(0, min(50, (int) $this->settings->value('max_sse_connections_per_user')));
        } catch (Throwable) {
            return 0;
        }
    }

    private function key(int $userId): string
    {
        return self::PREFIX.$userId;
    }
}
