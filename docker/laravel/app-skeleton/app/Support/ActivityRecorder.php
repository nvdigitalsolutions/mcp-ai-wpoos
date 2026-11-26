<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The activity feed behind the command-centre overview.
 *
 * One row is one thing that happened: a workflow run finishing, and later a
 * chat turn. The point of a shared recorder is that the feed is written by the
 * paths that actually do the work, rather than reconstructed later from
 * whatever tables happen to be nearby — which is what made the Pro feed
 * unportable.
 *
 * Rows are append-only and pruned by age; nothing updates one.
 *
 * ## The estate (Proposal 149 §106)
 *
 * A row records the estate that **produced** the event, taken from
 * `TenantContext` at write time and never from the caller — a second source
 * would be a second truth to keep in step (§152 §9.3). The column arrived with
 * `2026_11_20_120000_add_tenant_to_activity_events`; rows older than it read as
 * `default`, which is the only defensible attribution for a row that records
 * none (§152 §3 D3).
 *
 * Three writers run without a pinned context — the media sweeper, the media
 * optimiser job and the health sampler. Their events land on `default` rather
 * than on the estate whose media or health they describe. That is a real
 * limitation, not a rounding error: `nvoos_files` carries no tenant either, so
 * there is nothing to pin *from*. Fixing it means giving the media tables an
 * estate first, which is the `media` surface's own slice, not this one.
 */
final class ActivityRecorder
{
    /**
     * Record one event.
     *
     * @param string               $type    Dotted type, e.g. `workflow.completed`.
     * @param string               $message Human-readable line for the feed.
     * @param int|null             $agentId Assistant the event belongs to, when it has one.
     * @param array<string, mixed> $context Structured detail for debugging.
     */
    public function record(string $type, string $message = '', ?int $agentId = null, array $context = array()): void
    {
        DB::table('nvoos_activity_events')->insert(array(
            'type' => mb_substr($type, 0, 64),
            'tenant_id' => TenantContext::current(),
            'agent_id' => $agentId,
            'message' => mb_substr($message, 0, 500),
            'context' => array() === $context ? null : (string) json_encode($context),
            'created_at' => now(),
        ));
    }

    /**
     * The newest events for one estate, in the shape the console's feed renders.
     *
     * `$tenantId` is required rather than defaulted: `null` means every estate,
     * and only an operator surface may ask for that. A default would let a new
     * call site leak without noticing (§152 §3 D5).
     *
     * @return array<int, array{type: string, agent_id: int|null, message: string, timestamp: int}>
     */
    public function recent(int $limit, ?string $tenantId): array
    {
        $rows = DB::table('nvoos_activity_events')
            ->when(null !== $tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();

        return $rows->map(static fn (object $row): array => array(
            'type' => (string) $row->type,
            'agent_id' => null === $row->agent_id ? null : (int) $row->agent_id,
            'message' => (string) $row->message,
            // The client's `ActivityEvent.timestamp` is a unix second.
            'timestamp' => (int) (strtotime((string) $row->created_at) ?: 0),
        ))->all();
    }

    /**
     * Drop events older than the retention window.
     *
     * Deliberately **platform-wide**: retention is a storage policy, not a
     * read, and a per-estate prune command would let one estate's silent
     * failure grow the table for everyone (§152 §3 D7).
     *
     * @return int Rows deleted.
     */
    public function prune(int $days = 30): int
    {
        return DB::table('nvoos_activity_events')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
