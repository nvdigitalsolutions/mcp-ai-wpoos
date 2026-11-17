<?php

namespace App\Support\Admin;

use App\Models\AdminAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reader for the admin audit trail — Proposal 149 §25.
 *
 * One consumer today: the read-only `/ops/audit-log` page. It exists as a class
 * rather than inline page code for the same reason the other readers do — the
 * row shape and the filter vocabulary are a contract, and the page should be
 * the thing that changes least.
 *
 * Two row kinds share `nvoos_admin_audit` (see `App\Models\AdminAudit`):
 * request rows (`resource` null) answer "who tried", record rows (`resource`
 * set) answer "what changed". The reader normalises both into one shape and
 * keeps a `kind` field, because a page that shows only one kind silently hides
 * half the trail.
 *
 * There is no tenant scope here on purpose: the operator panel is cross-estate.
 * `estate` is a filter value, and an empty string means "no estate" — an
 * operator action, which is a real row rather than a missing one.
 *
 * The payload and diff columns are already redacted at write time
 * (`AdminAuditLogger::redact()`), so this reader does not attempt a second
 * redaction pass. Re-redacting would give false confidence: the write-time pass
 * is the one that runs on every path.
 */
final class AuditTrail
{
    /** Hard ceiling on a single page render; the table grows forever. */
    public const LIMIT = 200;

    /**
     * Newest rows first, filtered.
     *
     * `$limit` defaults to the page ceiling; a report passes a higher one
     * because a compliance export that silently stops at 200 rows would look
     * complete. The default keeps the page honest about its window.
     *
     * @param array{panel?: string, estate?: string, kind?: string, status?: string, search?: string} $filters
     * @return list<array<string, mixed>>
     */
    public static function rows(array $filters = array(), int $limit = self::LIMIT): array
    {
        $query = AdminAudit::query()->orderByDesc('created_at')->orderByDesc('id');

        self::applyFilters($query, $filters);

        $rows = $query->limit(max(1, $limit))->get();

        $names = self::actorNames($rows->pluck('user_id')->filter()->unique()->all());

        return array_values($rows->map(
            static fn (AdminAudit $row): array => self::normalise($row, $names)
        )->all());
    }

    /**
     * Counts a header can show without the view walking the rows.
     *
     * `refused` counts request rows with a 4xx/5xx status — the half of the
     * trail that answers "who tried and was stopped".
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, record: int, refused: int, estates: int}
     */
    public static function totals(array $rows): array
    {
        $record = 0;
        $refused = 0;
        $estates = array();

        foreach ($rows as $row) {
            if ('record' === $row['kind']) {
                ++$record;
            }

            if ((int) $row['status'] >= 400) {
                ++$refused;
            }

            $estates[(string) $row['estate']] = true;
        }

        return array(
            'total' => count($rows),
            'record' => $record,
            'refused' => $refused,
            'estates' => count($estates),
        );
    }

    /**
     * Panels present in the trail, as id => label.
     *
     * Derived from the table rather than a constant so a panel added later
     * appears in the filter without a code change.
     *
     * @return array<string, string>
     */
    public static function panels(): array
    {
        $panels = AdminAudit::query()
            ->select('panel')
            ->distinct()
            ->orderBy('panel')
            ->pluck('panel')
            ->all();

        $options = array();

        foreach ($panels as $panel) {
            $id = (string) $panel;
            $options[$id] = '' === $id ? '(unknown)' : ucfirst($id);
        }

        return $options;
    }

    /**
     * Resources present in the trail, as slug => label. Request rows are not
     * resources, so they are excluded.
     *
     * @return array<string, string>
     */
    public static function resources(): array
    {
        $resources = AdminAudit::query()
            ->whereNotNull('resource')
            ->select('resource')
            ->distinct()
            ->orderBy('resource')
            ->pluck('resource')
            ->all();

        $options = array();

        foreach ($resources as $resource) {
            $id = (string) $resource;
            $options[$id] = ucfirst(str_replace(array('-', '_'), ' ', $id));
        }

        return $options;
    }

    /**
     * Estates present in the trail, as value => label.
     *
     * `operator` is a value rather than a wildcard: rows with no estate are
     * operator actions, and they are a category the reader can select. An estate
     * id that no longer exists still appears while its rows remain, which is the
     * point of an audit trail.
     *
     * @return array<string, string>
     */
    public static function estates(): array
    {
        $ids = AdminAudit::query()
            ->select('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        $options = array();

        foreach ($ids as $id) {
            if (null === $id || '' === (string) $id) {
                $options['operator'] = 'Operator (no estate)';
                continue;
            }

            $options[(string) $id] = (string) $id;
        }

        ksort($options);

        return $options;
    }

    /**
     * @param array{panel?: string, estate?: string, kind?: string, status?: string, search?: string} $filters
     */
    private static function applyFilters(Builder $query, array $filters): void
    {
        $panel = trim((string) ($filters['panel'] ?? ''));
        $estate = trim((string) ($filters['estate'] ?? ''));
        $kind = trim((string) ($filters['kind'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        if ('' !== $panel) {
            $query->where('panel', $panel);
        }

        if ('' !== $estate) {
            // `operator` selects the rows with no estate, which is a category
            // rather than a wildcard — an operator action belongs to no single
            // estate and hiding it would break the trail's continuity.
            if ('operator' === $estate) {
                $query->whereNull('tenant_id');
            } else {
                $query->where('tenant_id', $estate);
            }
        }

        if ('record' === $kind) {
            $query->whereNotNull('resource');
        } elseif ('request' === $kind) {
            $query->whereNull('resource');
        }

        if ('refused' === $status) {
            $query->where('status', '>=', 400);
        } elseif ('ok' === $status) {
            $query->where('status', '<', 400);
        }

        if ('' !== $search) {
            $like = '%'.$search.'%';

            $query->where(static function (Builder $inner) use ($like): void {
                $inner->whereRaw('LOWER(path) LIKE ?', array($like))
                    ->orWhereRaw('LOWER(COALESCE(resource, \'\')) LIKE ?', array($like))
                    ->orWhereRaw('LOWER(COALESCE(action, \'\')) LIKE ?', array($like))
                    ->orWhereRaw('LOWER(COALESCE(record_id, \'\')) LIKE ?', array($like));
            });
        }
    }

    /**
     * @param array<int, mixed> $userIds
     * @return array<int, string>
     */
    private static function actorNames(array $userIds): array
    {
        if (array() === $userIds) {
            return array();
        }

        return User::query()
            ->whereIn('id', array_map('intval', $userIds))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * One row, in the shape the page renders.
     *
     * @param array<int, string> $names
     * @return array<string, mixed>
     */
    private static function normalise(AdminAudit $row, array $names): array
    {
        $resource = (string) ($row->resource ?? '');
        $action = (string) ($row->action ?? '');
        $recordId = (string) ($row->record_id ?? '');
        $method = (string) $row->method;
        $path = (string) $row->path;

        $kind = '' === $resource ? 'request' : 'record';

        $label = 'record' === $kind
            ? trim($resource.' '.$action.( '' === $recordId ? '' : ' '.$recordId ))
            : trim($method.' '.$path);

        $userId = (int) ($row->user_id ?? 0);

        return array(
            'id' => (int) $row->id,
            'at' => $row->created_at?->toIso8601String() ?? '',
            'estate' => (string) ($row->tenant_id ?? ''),
            'panel' => (string) $row->panel,
            'kind' => $kind,
            'actor' => $userId > 0 ? (string) ($names[$userId] ?? 'user #'.$userId) : '(system)',
            'label' => $label,
            'method' => $method,
            'status' => (int) $row->status,
            'resource' => $resource,
            'action' => $action,
            'record_id' => $recordId,
            'has_diff' => null !== $row->before || null !== $row->after,
        );
    }
}
