<?php

namespace App\Support\Security;

use App\Support\SettingsSchema;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saved settings snapshots, scoped to one estate — Proposal 149 §51.
 *
 * BEFORE THIS CLASS
 *
 * `SecurityController` named `nvoos_security_snapshots` in four methods with no
 * estate filter, while the payload it stores is the **estate's** settings
 * (`nvoos_tenant_settings`). So an estate administrator could list every
 * estate's snapshot labels and could restore another estate's payload into
 * their own settings — a configuration disclosure plus a cross-estate write.
 * The table now carries `tenant_id` and every statement here starts at
 * {@see self::query()}.
 *
 * THE ONE-HELPER RULE
 *
 * As with `ToolkitEngine` (§47), a second direct
 * `DB::table('nvoos_security_snapshots')` anywhere in the app is the defect
 * coming back. Add operations here, not beside here.
 *
 * The estate is a resolver closure rather than a constructor value because the
 * store can be resolved before `ResolveApiTenant` has run — the same reasoning
 * as `RemoteSiteStore` and `ToolkitEngine`.
 *
 * RESTORE IS NOT HERE. Restoring writes to the settings store and has its own
 * masking rule ("a masked placeholder is skipped, never written back"), so the
 * controller owns that path and asks this class only for a **scoped** `find()`.
 */
final class SecuritySnapshotStore
{
    private const TABLE = 'nvoos_security_snapshots';

    /** Newest snapshots returned by a list call. */
    public const LIST_LIMIT = 50;

    /**
     * @param Closure(): string $tenant Reads the current estate at query time.
     */
    public function __construct(private readonly Closure $tenant)
    {
    }

    /**
     * A page of snapshots, newest first, without their payloads.
     *
     * `keys` is the number of settings the snapshot recorded — useful in a list
     * and cheap to compute, because the payload is already in the row.
     *
     * @return list<array{snapshot_id: string, id: string, label: string, created_at: string, keys: int}>
     */
    public function list(int $limit = self::LIST_LIMIT): array
    {
        $limit = max(1, min(200, $limit));

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
     * How many snapshots this estate has, for a header that must not overstate
     * a capped list.
     */
    public function total(): int
    {
        return $this->query()->count();
    }

    /**
     * One raw row, scoped, or null.
     *
     * The controller's restore path needs the encoded payload; everything else
     * should use {@see self::detail()}, which decodes it once.
     */
    public function find(string $id): ?object
    {
        return $this->query()->where('id', $id)->first();
    }

    /**
     * One snapshot with its payload decoded, scoped, or null.
     *
     * @return array{snapshot_id: string, label: string, created_at: string, payload: array<string, mixed>}|null
     */
    public function detail(string $id): ?array
    {
        $row = $this->find($id);

        if (null === $row) {
            return null;
        }

        $decoded = json_decode((string) ($row->payload ?? ''), true);

        return array(
            'snapshot_id' => (string) $row->id,
            'label' => (string) ($row->label ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'payload' => is_array($decoded) ? $decoded : array(),
        );
    }

    /**
     * Persist a snapshot for this estate.
     *
     * The caller is responsible for masking sensitive keys before calling —
     * the masking rule lives with the settings catalogue, not here.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{snapshot_id: string, id: string, label: string, created_at: string, keys: int}
     */
    public function save(?string $label, array $payload): array
    {
        $id = (string) Str::uuid();
        $now = now();

        $this->query()->insert(array(
            'id' => $id,
            'tenant_id' => $this->tenantId(),
            'label' => null === $label || '' === trim($label) ? 'Snapshot '.$now->toDateTimeString() : trim($label),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ));

        $row = $this->find($id);

        return null === $row
            ? array('snapshot_id' => $id, 'id' => $id, 'label' => '', 'created_at' => '', 'keys' => 0)
            : $this->record($row);
    }

    /**
     * A payload as render rows: sorted by key, sensitive values masked.
     *
     * Static and payload-only on purpose. Masking is a property of the settings
     * catalogue (`SettingsSchema::sensitiveKeys()`), not of an estate's
     * snapshot, and both presentations need exactly this mapping — the admin
     * detail panel and the operator overview. One helper is what stops them
     * disagreeing about what "masked" means.
     *
     * Belt and braces: every snapshot this runtime writes is already masked at
     * write time, so this second pass only matters for a row that predates the
     * write-path mask or was inserted by hand. It is the one place a stored
     * secret could otherwise reach a screen, so it is not optional.
     *
     * @param array<string, mixed> $payload Decoded snapshot payload.
     *
     * @return list<array{key: string, masked: bool, value: string}>
     */
    public static function maskedRows(array $payload): array
    {
        $sensitive = array_flip(SettingsSchema::sensitiveKeys());
        $rows = array();

        foreach ($payload as $key => $value) {
            $key = (string) $key;

            $masked = isset($sensitive[$key]) || $value === SettingsSchema::MASKED_PLACEHOLDER;

            $rows[] = array(
                'key' => $key,
                'masked' => $masked,
                'value' => $masked
                    ? SettingsSchema::MASKED_PLACEHOLDER
                    : (is_scalar($value) || null === $value
                        ? (string) $value
                        : (string) json_encode($value)),
            );
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $rows;
    }

    /**
     * @return array{snapshot_id: string, id: string, label: string, created_at: string, keys: int}
     */
    private function record(object $row): array
    {
        $decoded = json_decode((string) ($row->payload ?? ''), true);

        return array(
            'snapshot_id' => (string) $row->id,
            'id' => (string) $row->id,
            'label' => (string) ($row->label ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'keys' => is_array($decoded) ? count($decoded) : 0,
        );
    }

    /**
     * The only entry point to `nvoos_security_snapshots`.
     */
    private function query(): Builder
    {
        return DB::table(self::TABLE)->where('tenant_id', $this->tenantId());
    }

    /**
     * The estate this store speaks for, read at query time.
     */
    private function tenantId(): string
    {
        return (string) ($this->tenant)();
    }
}
