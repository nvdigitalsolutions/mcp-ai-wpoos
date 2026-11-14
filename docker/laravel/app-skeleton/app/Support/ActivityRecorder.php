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
            'agent_id' => $agentId,
            'message' => mb_substr($message, 0, 500),
            'context' => array() === $context ? null : (string) json_encode($context),
            'created_at' => now(),
        ));
    }

    /**
     * The newest events, in the shape the console's feed renders.
     *
     * @return array<int, array{type: string, agent_id: int|null, message: string, timestamp: int}>
     */
    public function recent(int $limit = 8): array
    {
        $rows = DB::table('nvoos_activity_events')
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
     * @return int Rows deleted.
     */
    public function prune(int $days = 30): int
    {
        return DB::table('nvoos_activity_events')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
