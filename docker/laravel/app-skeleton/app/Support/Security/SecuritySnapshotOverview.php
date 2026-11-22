<?php

namespace App\Support\Security;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The operator-wide half of the security-snapshot surface — Proposal 149 §171.
 *
 * WHY THIS IS NOT `SecuritySnapshotStore`
 *
 * `SecuritySnapshotStore` is deliberately estate-scoped: every statement it
 * issues starts at a `tenant_id` filter, because the payload it reads is an
 * estate's *configuration* and a cross-estate read there was the defect §51
 * fixed. That class must never grow an unfiltered query.
 *
 * The operator overview asks a different question — "which estates have saved a
 * snapshot, and when" — and its answer is cross-estate by definition, the way
 * `/ops/audit-log` is. So it is a second reader with a second audience, not a
 * loosened scope on the first. It is read-only: creating and restoring a
 * snapshot remain console writes (`writer: console` in the contract), and a
 * panel action here would be a second settings writer.
 *
 * WHAT IT DOES NOT DO
 *
 * No posture scan, no header preview, no self-test and no chart. Those are live
 * checks the console runs on demand, and the posture chart is a Wave 6 canvas
 * bundle. A page load that ran them would turn a records view into a slow,
 * surprising request — the reasoning §51 recorded for the admin half.
 *
 * MASKING
 *
 * The detail payload goes through `SecuritySnapshotStore::maskedRows()`, the
 * same mapping the admin detail panel uses. One helper, one definition of
 * "masked": a second copy here is how the two presentations would start
 * disagreeing about which keys are safe to show.
 */
final class SecuritySnapshotOverview
{
    private const TABLE = 'nvoos_security_snapshots';

    /** Newest snapshots a page renders. */
    public const LIMIT = 100;

    /**
     * Label used when a row carries no estate.
     *
     * The column is not nullable, but a row written before §51's migration (or
     * by hand) could hold an empty string. Rendering it as the fallback estate
     * is honest: that is where the migration attributed the pre-tenancy rows.
     */
    private const FALLBACK_ESTATE = 'default';

    /**
     * A page of snapshots across every estate, newest first, without payloads.
     *
     * @return list<array{snapshot_id: string, estate: string, label: string, created_at: string, keys: int}>
     */
    public function rows(int $limit = self::LIMIT): array
    {
        $limit = max(1, min(500, $limit));

        $rows = $this->query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $records = array();

        foreach ($rows->all() as $row) {
            $records[] = $this->record($row);
        }

        return $records;
    }

    /**
     * How many snapshots exist across every estate.
     */
    public function total(): int
    {
        return $this->query()->count();
    }

    /**
     * Snapshot count per estate, busiest first.
     *
     * A header figure, not a filter source: the list is already capped, and an
     * estate with no snapshots would be an option with nothing behind it.
     *
     * @return array<string, int> Estate => count.
     */
    public function estates(): array
    {
        $rows = $this->query()
            ->select('tenant_id', DB::raw('COUNT(*) as total'))
            ->groupBy('tenant_id')
            ->orderByDesc('total')
            ->orderBy('tenant_id')
            ->get();

        $estates = array();

        foreach ($rows->all() as $row) {
            $estates[$this->estateLabel((string) ($row->tenant_id ?? ''))] = (int) $row->total;
        }

        return $estates;
    }

    /**
     * One snapshot with its payload masked for render, or null.
     *
     * @return array{snapshot_id: string, estate: string, label: string, created_at: string, rows: list<array{key: string, masked: bool, value: string}>, masked_count: int}|null
     */
    public function detail(string $id): ?array
    {
        $row = $this->query()->where('id', $id)->first();

        if (null === $row) {
            return null;
        }

        $decoded = json_decode((string) ($row->payload ?? ''), true);
        $payload = is_array($decoded) ? $decoded : array();

        $rows = SecuritySnapshotStore::maskedRows($payload);

        return array(
            'snapshot_id' => (string) $row->id,
            'estate' => $this->estateLabel((string) ($row->tenant_id ?? '')),
            'label' => (string) ($row->label ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'rows' => $rows,
            'masked_count' => count(
                array_filter($rows, static fn (array $rendered): bool => (bool) $rendered['masked'])
            ),
        );
    }

    /**
     * @return array{snapshot_id: string, estate: string, label: string, created_at: string, keys: int}
     */
    private function record(object $row): array
    {
        $decoded = json_decode((string) ($row->payload ?? ''), true);

        return array(
            'snapshot_id' => (string) $row->id,
            'estate' => $this->estateLabel((string) ($row->tenant_id ?? '')),
            'label' => (string) ($row->label ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'keys' => is_array($decoded) ? count($decoded) : 0,
        );
    }

    private function estateLabel(string $tenantId): string
    {
        return '' === $tenantId ? self::FALLBACK_ESTATE : $tenantId;
    }

    /**
     * The one entry point to the table, deliberately unfiltered.
     *
     * `SecuritySnapshotStore` owns every ESTATE-SCOPED statement; this reader is
     * the operator-wide exception, and the audience is what makes it safe —
     * `SecurityOverview::canAccess()` is the operator policy.
     */
    private function query(): Builder
    {
        return DB::table(self::TABLE);
    }
}
