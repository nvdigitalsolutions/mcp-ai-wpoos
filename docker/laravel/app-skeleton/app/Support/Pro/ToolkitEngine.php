<?php

namespace App\Support\Pro;

use App\Support\SettingsSchema;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

/**
 * The manifest-driven toolkit engine (Proposal 090-B §8, extracted for 149 §46/P4).
 *
 * One engine serves every manifest family: the manifest decides which settings,
 * entities and dashboard cards exist, and this class provides the settings
 * read/write, entity CRUD, aggregation, duplicate detection and import behind
 * them. No bespoke PHP is needed to add a family — only a JSON document.
 *
 * WHY THIS EXISTS AS SOMETHING OTHER THAN A CONTROLLER:
 *
 * `ToolkitsController` owned the SQL directly, so the only way a Filament
 * renderer could reuse it was to call HTTP from inside the app — a network hop,
 * a loopback-auth problem, and two write paths for one mutation. This class is
 * the single writer; the controller is now a thin HTTP adapter over it, and the
 * panel (order item 10) calls the same methods. The contract's rule is one
 * writer per mutation, and this is that writer.
 *
 * ERROR SHAPE:
 *
 * Methods return data on success and throw {@see ToolkitException} on refusal,
 * because the callers map differently — the controller to
 * `{success:false, error:{code,message,fields?}}`, the panel to a notification —
 * and because `false`/`null` cannot distinguish "unknown entity" from "record
 * not found" from "invalid payload". Messages and codes are copied verbatim from
 * the controller they were extracted from; the console reads them.
 *
 * ENTITLEMENT IS NOT HERE:
 *
 * `ProEntitlementInterface::allows()` is a policy decision whose failure differs
 * per presentation (a typed 402 on the API, an absent nav item on the panel), so
 * it stays in the caller. This class resolves and operates; it does not gate.
 *
 * TENANCY — THE ONE-HELPER RULE (149 §47):
 *
 * `nvoos_toolkit_records` shipped with no estate column while every
 * `/pro/toolkits` route already resolved one, so an estate listed, edited and
 * deleted another's rows, and the global unique key refused two estates the same
 * business key. The table now carries `tenant_id`; **every** statement in this
 * class must start at {@see self::query()}. A second direct
 * `DB::table('nvoos_toolkit_records')` is that defect coming back, so add new
 * record operations by starting there rather than beside it.
 *
 * The estate is a resolver closure, not a constructor value, because this engine
 * may be resolved before `ResolveApiTenant` has run — the same reasoning as
 * {@see \App\Support\RemoteSites\RemoteSiteStore}.
 */
final class ToolkitEngine
{
    /** The record table; named once so {@see self::query()} is the only accessor. */
    private const TABLE = 'nvoos_toolkit_records';

    /** Mirrors the controller's import cap; the panel must not exceed it either. */
    public const MAX_IMPORT_ROWS = 500;

    /** Records returned per duplicate group. */
    public const DUPLICATES_PER_GROUP = 25;

    /**
     * @param Closure(): string $tenant Reads the current estate at query time.
     */
    public function __construct(
        private readonly ToolkitManifestLoader $loader,
        private readonly SettingsStoreInterface $store,
        private readonly Closure $tenant,
    ) {
    }

    /**
     * Every valid manifest, keyed by slug.
     *
     * @return array<string, ToolkitManifest>
     */
    public function manifests(): array
    {
        return $this->loader->all();
    }

    /**
     * Manifests that failed validation, as loader errors.
     *
     * @return array<int, string>
     */
    public function invalid(): array
    {
        return $this->loader->errors();
    }

    public function find(string $slug): ?ToolkitManifest
    {
        return $this->loader->find($slug);
    }

    /**
     * The catalogue projection of one manifest.
     *
     * @return array<string, mixed>
     */
    public function summary(ToolkitManifest $manifest): array
    {
        $raw = $manifest->toArray();

        return array(
            'slug' => $manifest->slug(),
            'name' => $manifest->name(),
            'version' => (string) ($raw['version'] ?? ''),
            'description' => (string) ($raw['description'] ?? ''),
            'icon' => (string) ($raw['icon'] ?? ''),
            'feature' => $manifest->feature(),
            'screens' => $manifest->screens(),
            'entities' => array_map(
                static fn (array $entity): array => array(
                    'key' => (string) ($entity['key'] ?? ''),
                    'label' => (string) ($entity['label'] ?? ''),
                    'icon' => (string) ($entity['icon'] ?? ''),
                ),
                $manifest->entities(),
            ),
        );
    }

    // ── Settings ────────────────────────────────────────────────────────

    /**
     * Stored settings values, with `secret` fields masked.
     *
     * @return array<string, mixed>
     */
    public function settingsValues(ToolkitManifest $manifest): array
    {
        $values = array();

        foreach ($manifest->settings() as $field) {
            $key = (string) $field['key'];
            $value = $this->store->get($this->settingKey($manifest->slug(), $key), $field['default'] ?? null);

            if ((string) ($field['type'] ?? 'text') === 'secret') {
                $value = is_string($value) && $value !== '' ? SettingsSchema::MASKED_PLACEHOLDER : '';
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Write the supplied settings, leaving absent keys untouched.
     *
     * A `secret` field sent blank or as the mask is skipped: the console's update
     * contract is "leave it blank to keep the stored one".
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed> The stored values after the write.
     *
     * @throws ToolkitException On an unknown or invalid setting.
     */
    public function writeSettings(ToolkitManifest $manifest, array $values): array
    {
        $schema = array();

        foreach ($manifest->settings() as $field) {
            $schema[(string) $field['key']] = $field;
        }

        $errors = array();
        $writes = array();

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (! isset($schema[$key])) {
                $errors[$key] = 'Unknown setting.';

                continue;
            }

            $field = $schema[$key];
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'secret' && ($value === SettingsSchema::MASKED_PLACEHOLDER || $value === '' || $value === null)) {
                continue;
            }

            [$valid, $cast, $message] = $this->castSetting($field, $value);

            if (! $valid) {
                $errors[$key] = (string) $message;

                continue;
            }

            $writes[$key] = $cast;
        }

        if ($errors !== array()) {
            throw ToolkitException::validation($errors, 'One or more settings are invalid.');
        }

        foreach ($writes as $key => $value) {
            $this->store->set($this->settingKey($manifest->slug(), $key), $value);
        }

        return $this->settingsValues($manifest);
    }

    /**
     * The stored settings key. Namespaced so two toolkits cannot collide.
     */
    public function settingKey(string $slug, string $key): string
    {
        return 'toolkit.'.$slug.'.'.$key;
    }

    // ── Dashboard ───────────────────────────────────────────────────────

    /**
     * Compute one manifest's dashboard cards.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dashboard(ToolkitManifest $manifest): array
    {
        // Dashboard filters may bind to a toolkit setting, so resolve the stored
        // values once and hand them to the filter compiler.
        $settings = $this->settingsValues($manifest);
        $cards = array();

        foreach ($manifest->dashboardCards() as $card) {
            $source = (string) ($card['source'] ?? '');
            $entityKey = str_starts_with($source, 'entity:') ? substr($source, 7) : '';
            $entity = $manifest->entity($entityKey);

            if ($entity === null) {
                continue;
            }

            $query = $this->query()
                ->where('toolkit', $manifest->slug())
                ->where('entity', $entityKey);

            $this->applyFilters($query, $card['filter'] ?? null, $settings);

            $aggregate = (string) ($card['aggregate'] ?? 'count');
            $value = 0;

            if ($aggregate === 'count') {
                $value = $query->count();
            } else {
                $column = (string) ($card['column'] ?? '');
                $expression = $this->jsonPath($column);
                $raw = $query->selectRaw(strtoupper($aggregate).'('.$expression.') as aggregate_value')
                    ->value('aggregate_value');

                $value = is_numeric($raw) ? 0 + $raw : 0;
            }

            $cards[] = array(
                'key' => (string) ($card['key'] ?? ''),
                'label' => (string) ($card['label'] ?? ''),
                'aggregate' => $aggregate,
                'value' => $value,
            );
        }

        return $cards;
    }

    // ── Entities ────────────────────────────────────────────────────────

    /**
     * One entity's manifest config, or null when the manifest does not declare it.
     *
     * @return array<string, mixed>|null
     */
    public function entity(ToolkitManifest $manifest, string $entityKey): ?array
    {
        return $manifest->entity($entityKey);
    }

    /**
     * A page of records.
     *
     * `per_page` is clamped to 1–100 and `page` floored at 1 here rather than in
     * the caller, so the panel and the API answer the same query identically.
     *
     * @param array<string, mixed>|null $filter Column => value equality filters.
     *
     * @return array{records: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     *
     * @throws ToolkitException
     */
    public function listRecords(
        ToolkitManifest $manifest,
        string $entity,
        int $perPage = 25,
        int $page = 1,
        string $search = '',
        ?array $filter = null,
    ): array {
        $config = $this->requireEntity($manifest, $entity);

        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $search = trim($search);

        $query = $this->query()
            ->where('toolkit', $manifest->slug())
            ->where('entity', $entity);

        if ($search !== '') {
            $this->applySearch($query, $config, $search);
        }

        $filterErrors = array();

        if (is_array($filter)) {
            foreach ($filter as $column => $value) {
                if (! $this->entityHasColumn($config, (string) $column)) {
                    $filterErrors[] = (string) $column;

                    continue;
                }

                $query->whereRaw($this->jsonPath((string) $column).' = ?', array($value));
            }
        }

        if ($filterErrors !== array()) {
            throw new ToolkitException(
                'unknown_filter_column',
                422,
                'Unknown filter column(s): '.implode(', ', $filterErrors).'.'
            );
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return array(
            'records' => array_map(array($this, 'record'), $rows->all()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        );
    }

    /**
     * One record by its business key, or null.
     *
     * @return array<string, mixed>|null
     *
     * @throws ToolkitException When the entity is unknown.
     */
    public function findRecord(ToolkitManifest $manifest, string $entity, string $id): ?array
    {
        $this->requireEntity($manifest, $entity);

        $row = $this->findRow($manifest->slug(), $entity, $id);

        return $row === null ? null : $this->record($row);
    }

    /**
     * Create one record.
     *
     * @param mixed $payload The raw `data` payload; validated here, not by the caller.
     *
     * @return array<string, mixed> The stored record.
     *
     * @throws ToolkitException
     */
    public function createRecord(ToolkitManifest $manifest, string $entity, mixed $payload): array
    {
        $config = $this->requireEntity($manifest, $entity);

        if (! is_array($payload)) {
            throw ToolkitException::invalidPayload('Send the record as a `data` object.');
        }

        [$errors, $normalized] = $this->validatePayload($config, $payload, true);

        if ($errors !== array()) {
            throw ToolkitException::validation($errors);
        }

        $idField = (string) $config['id_field'];
        $externalId = (string) $normalized[$idField];

        if ($this->findRow($manifest->slug(), $entity, $externalId) !== null) {
            throw new ToolkitException('duplicate_record', 422, 'A record with that '.$idField.' already exists.');
        }

        $now = now();

        $internalId = $this->query()->insertGetId(array(
            'tenant_id' => $this->tenantId(),
            'toolkit' => $manifest->slug(),
            'entity' => $entity,
            'external_id' => $externalId,
            'data' => json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return $this->record($this->query()->find($internalId));
    }

    /**
     * Update one record, merging the supplied fields into the stored payload.
     *
     * @param mixed $payload The raw `data` payload.
     *
     * @return array<string, mixed> The stored record.
     *
     * @throws ToolkitException
     */
    public function updateRecord(ToolkitManifest $manifest, string $entity, string $id, mixed $payload): array
    {
        $config = $this->requireEntity($manifest, $entity);

        $row = $this->findRow($manifest->slug(), $entity, $id);

        if ($row === null) {
            throw ToolkitException::recordNotFound();
        }

        if (! is_array($payload)) {
            throw ToolkitException::invalidPayload('Send the record as a `data` object.');
        }

        [$errors, $normalized] = $this->validatePayload($config, $payload, false);

        if ($errors !== array()) {
            throw ToolkitException::validation($errors);
        }

        $idField = (string) $config['id_field'];

        if (array_key_exists($idField, $normalized) && (string) $normalized[$idField] !== (string) $row->external_id) {
            throw new ToolkitException('immutable_id', 422, 'The '.$idField.' field cannot be changed.');
        }

        $existing = json_decode((string) $row->data, true);
        $existing = is_array($existing) ? $existing : array();
        $merged = array_merge($existing, $normalized);

        $this->query()->where('id', $row->id)->update(array(
            'data' => json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ));

        return $this->record($this->query()->find($row->id));
    }

    /**
     * Delete one record by its business key.
     *
     * @throws ToolkitException When the entity is unknown.
     */
    public function deleteRecord(ToolkitManifest $manifest, string $entity, string $id): bool
    {
        $this->requireEntity($manifest, $entity);

        $deleted = $this->query()
            ->where('toolkit', $manifest->slug())
            ->where('entity', $entity)
            ->where('external_id', $id)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Server-side duplicate detection for one text/enum column.
     *
     * Groups the whole entity, not a page: `GROUP BY json_extract(...)` with a
     * `HAVING COUNT(*) > 1` is the same statement on MySQL and SQLite, so the
     * consolidate screen does not have to load every record to scan it.
     *
     * @return array{column: string, scanned: int, groups: array<int, array<string, mixed>>, truncated: bool}
     *
     * @throws ToolkitException
     */
    public function duplicates(ToolkitManifest $manifest, string $entity, string $column, int $limit = 25): array
    {
        $config = $this->requireEntity($manifest, $entity);

        $declared = null;

        foreach ((array) ($config['columns'] ?? array()) as $candidate) {
            if ((string) ($candidate['key'] ?? '') === $column) {
                $declared = $candidate;

                break;
            }
        }

        if ($declared === null) {
            throw ToolkitException::validation(array('column' => 'Choose a column of this entity.'));
        }

        if (! in_array((string) ($declared['type'] ?? ''), array('string', 'enum'), true)) {
            throw ToolkitException::validation(array('column' => 'Only text or list columns can be grouped.'));
        }

        $limit = max(1, min(100, $limit));

        $expression = $this->jsonPath($column);
        $base = $this->query()
            ->where('toolkit', $manifest->slug())
            ->where('entity', $entity);

        $scanned = (clone $base)->count();

        $collisions = (clone $base)
            ->selectRaw($expression.' as group_value, COUNT(*) as total')
            ->whereRaw($expression.' IS NOT NULL')
            ->groupByRaw($expression)
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->get();

        $values = array();

        foreach ($collisions as $row) {
            $values[] = (string) $row->group_value;
        }

        $groups = array();

        if ($values !== array()) {
            $rows = (clone $base)
                ->where(function ($where) use ($expression, $values): void {
                    foreach ($values as $value) {
                        $where->orWhereRaw($expression.' = ?', array($value));
                    }
                })
                ->orderBy('id')
                ->get();

            $buckets = array();

            foreach ($rows as $row) {
                $record = $this->record($row);
                $key = isset($record['data'][$column]) ? (string) $record['data'][$column] : '';

                if (! in_array($key, $values, true)) {
                    continue;
                }

                $buckets[$key][] = $record;
            }

            foreach ($values as $value) {
                if (! isset($buckets[$value])) {
                    continue;
                }

                $groups[] = array(
                    'value' => $value,
                    'count' => count($buckets[$value]),
                    'records' => array_slice($buckets[$value], 0, self::DUPLICATES_PER_GROUP),
                );
            }
        }

        return array(
            'column' => $column,
            'scanned' => $scanned,
            'groups' => $groups,
            'truncated' => count($groups) >= $limit,
        );
    }

    /**
     * Bulk create with a dry-run preview.
     *
     * Rows are validated with the same compiler as `createRecord`, so a pasted
     * sheet behaves exactly like the form. Existing business keys are skipped
     * (default) or reported as failures — the caller chooses.
     *
     * @return array<string, mixed>
     *
     * @throws ToolkitException
     */
    public function import(
        ToolkitManifest $manifest,
        string $entity,
        mixed $rows,
        bool $preview = false,
        bool $skipExisting = true,
    ): array {
        $config = $this->requireEntity($manifest, $entity);

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw ToolkitException::invalidPayload('Send `rows` as a list of record objects.');
        }

        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw new ToolkitException(
                'too_many_rows',
                422,
                'Import at most '.self::MAX_IMPORT_ROWS.' rows at a time.'
            );
        }

        $idField = (string) $config['id_field'];

        $seen = array();
        $valid = array();
        $failed = array();
        $duplicates = 0;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $failed[] = array('index' => $index, 'fields' => array('row' => 'Each row must be an object.'));

                continue;
            }

            [$errors, $normalized] = $this->validatePayload($config, $row, true);

            if ($errors !== array()) {
                $failed[] = array('index' => $index, 'fields' => $errors);

                continue;
            }

            $externalId = (string) $normalized[$idField];

            if (isset($seen[$externalId]) || $this->findRow($manifest->slug(), $entity, $externalId) !== null) {
                if ($skipExisting) {
                    $duplicates++;

                    continue;
                }

                // Reported, not skipped: `duplicates` counts skipped rows only,
                // so valid + duplicates + failed always equals the batch size.
                $failed[] = array(
                    'index' => $index,
                    'fields' => array($idField => 'A record with that value already exists.'),
                );

                continue;
            }

            $seen[$externalId] = true;
            $valid[] = $normalized;
        }

        if ($preview) {
            return array(
                'preview' => true,
                'total' => count($rows),
                'valid' => count($valid),
                'duplicates' => $duplicates,
                'failed' => $failed,
                'rows' => $valid,
            );
        }

        $created = 0;

        if ($valid !== array()) {
            $now = now();

            DB::transaction(function () use ($valid, $manifest, $entity, $idField, $now, &$created): void {
                foreach (array_chunk($valid, 100) as $chunk) {
                    $insert = array();

                    foreach ($chunk as $normalized) {
                        $insert[] = array(
                            'tenant_id' => $this->tenantId(),
                            'toolkit' => $manifest->slug(),
                            'entity' => $entity,
                            'external_id' => (string) $normalized[$idField],
                            'data' => json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            'created_at' => $now,
                            'updated_at' => $now,
                        );
                    }

                    $this->query()->insert($insert);
                    $created += count($chunk);
                }
            });
        }

        return array(
            'preview' => false,
            'total' => count($rows),
            'created' => $created,
            'duplicates' => $duplicates,
            'failed' => $failed,
        );
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * The entity config, or a typed refusal when the manifest lacks it.
     *
     * @return array<string, mixed>
     *
     * @throws ToolkitException
     */
    private function requireEntity(ToolkitManifest $manifest, string $entity): array
    {
        $config = $manifest->entity($entity);

        if ($config === null) {
            throw ToolkitException::entityNotFound($entity);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $field
     * @return array{0: bool, 1: mixed, 2: string|null}
     */
    private function castSetting(array $field, mixed $value): array
    {
        switch ((string) ($field['type'] ?? 'text')) {
            case 'number':
                return is_numeric($value)
                    ? array(true, 0 + $value, null)
                    : array(false, null, 'Must be a number.');

            case 'boolean':
                if (is_bool($value)) {
                    return array(true, $value, null);
                }

                if (in_array($value, array(1, '1', 'true'), true)) {
                    return array(true, true, null);
                }

                if (in_array($value, array(0, '0', 'false'), true)) {
                    return array(true, false, null);
                }

                return array(false, null, 'Must be a boolean.');

            case 'select':
                $options = (array) ($field['options'] ?? array());
                $allowed = array_is_list($options) ? $options : array_keys($options);

                return in_array($value, $allowed, true)
                    ? array(true, $value, null)
                    : array(false, null, 'Must be one of: '.implode(', ', $allowed).'.');

            case 'text':
            case 'secret':
            default:
                return is_scalar($value)
                    ? array(true, (string) $value, null)
                    : array(false, null, 'Must be a string.');
        }
    }

    /**
     * Validate a record payload against the manifest's columns.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function validatePayload(array $config, array $payload, bool $creating): array
    {
        $errors = array();
        $normalized = array();
        $columns = array();

        foreach ((array) $config['columns'] as $column) {
            $columns[(string) $column['key']] = $column;
        }

        foreach ($payload as $key => $value) {
            if (! isset($columns[(string) $key])) {
                $errors[(string) $key] = 'Unknown field.';
            }
        }

        foreach ($columns as $key => $column) {
            $present = array_key_exists($key, $payload);
            $required = (bool) ($column['required'] ?? false);
            $isIdField = $key === (string) $config['id_field'];

            if (! $present) {
                // Required means "supplied on create, not empty on update";
                // a partial update may omit it.
                if ($creating && ($required || $isIdField)) {
                    $errors[$key] = 'This field is required.';
                }

                continue;
            }

            $value = $payload[$key];

            if ($required && (null === $value || (is_string($value) && trim($value) === ''))) {
                $errors[$key] = 'This field is required.';

                continue;
            }

            [$valid, $cast, $message] = $this->castColumn($column, $value);

            if (! $valid) {
                $errors[$key] = (string) $message;

                continue;
            }

            $normalized[$key] = $cast;
        }

        if ($creating) {
            $idField = (string) $config['id_field'];

            if (isset($normalized[$idField]) && trim((string) $normalized[$idField]) === '') {
                $errors[$idField] = 'This field is required.';
            }
        }

        return array($errors, $normalized);
    }

    /**
     * @param array<string, mixed> $column
     * @return array{0: bool, 1: mixed, 2: string|null}
     */
    private function castColumn(array $column, mixed $value): array
    {
        switch ((string) ($column['type'] ?? 'string')) {
            case 'number':
            case 'currency':
                return is_numeric($value)
                    ? array(true, 0 + $value, null)
                    : array(false, null, 'Must be numeric.');

            case 'boolean':
                if (is_bool($value)) {
                    return array(true, $value, null);
                }

                if (in_array($value, array(1, '1', 'true'), true)) {
                    return array(true, true, null);
                }

                if (in_array($value, array(0, '0', 'false'), true)) {
                    return array(true, false, null);
                }

                return array(false, null, 'Must be a boolean.');

            case 'date':
                return is_string($value) && strtotime($value) !== false
                    ? array(true, $value, null)
                    : array(false, null, 'Must be a date.');

            case 'enum':
                $allowed = (array) ($column['enum'] ?? array());

                return in_array($value, $allowed, true)
                    ? array(true, $value, null)
                    : array(false, null, 'Must be one of: '.implode(', ', $allowed).'.');

            case 'string':
            default:
                return is_scalar($value)
                    ? array(true, (string) $value, null)
                    : array(false, null, 'Must be a string.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function entityHasColumn(array $config, string $column): bool
    {
        foreach ((array) ($config['columns'] ?? array()) as $candidate) {
            if ((string) ($candidate['key'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(object $row): array
    {
        $data = json_decode((string) ($row->data ?? ''), true);

        return array(
            'id' => (string) ($row->external_id ?? $row->id),
            'entity' => (string) $row->entity,
            'data' => is_array($data) ? $data : array(),
            'created_at' => $row->created_at !== null ? (string) $row->created_at : null,
            'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
        );
    }

    private function findRow(string $toolkit, string $entity, string $id): ?object
    {
        return $this->query()
            ->where('toolkit', $toolkit)
            ->where('entity', $entity)
            ->where('external_id', $id)
            ->first();
    }

    /**
     * The only entry point to `nvoos_toolkit_records`.
     *
     * Every method above starts here so the estate scope cannot be forgotten
     * one method at a time — the defect §47 fixed was exactly a query that
     * named the table and stopped at toolkit+entity. `insert()` ignores the
     * where clause, so writes still carry `tenant_id` explicitly.
     */
    private function query(): Builder
    {
        return DB::table(self::TABLE)->where('tenant_id', $this->tenantId());
    }

    /**
     * The estate this engine speaks for, read at query time.
     *
     * A resolver rather than a constructor value: the engine can be resolved
     * before `ResolveApiTenant` has run, and a value captured then would be the
     * default estate forever.
     */
    private function tenantId(): string
    {
        return (string) ($this->tenant)();
    }

    /**
     * Portable JSON path expression. The column name always comes from a
     * validated manifest (key pattern), so interpolation is safe.
     *
     * MySQL returns a JSON value from `json_extract`, so a string comes back
     * quoted (`"Tee"`) and every comparison, group and aggregate would miss.
     * SQLite already unquotes, so only MySQL gets the wrapper — tests run on
     * SQLite and would not surface the difference.
     */
    private function jsonPath(string $column): string
    {
        $path = "'$.".str_replace("'", '', $column)."'";

        return DB::connection()->getDriverName() === 'mysql'
            ? 'json_unquote(json_extract(data, '.$path.'))'
            : 'json_extract(data, '.$path.')';
    }

    private function applyFilters(object $query, mixed $filters, array $settings = array()): void
    {
        if (! is_array($filters) || $filters === array()) {
            return;
        }

        // One filter object = one condition; a list is ANDed.
        if (! array_is_list($filters)) {
            $filters = array($filters);
        }

        foreach ($filters as $filter) {
            $this->applyFilter($query, $filter, $settings);
        }
    }

    /**
     * Apply one dashboard filter.
     *
     * A filter's value is either a literal (`value`) or the runtime value of a
     * declared setting (`setting`). A setting that is unset or null disables
     * that condition rather than filtering everything out.
     */
    private function applyFilter(object $query, mixed $filter, array $settings = array()): void
    {
        if (! is_array($filter)) {
            return;
        }

        $column = (string) ($filter['column'] ?? '');
        $expression = $this->jsonPath($column);

        if (array_key_exists('setting', $filter)) {
            $settingKey = (string) $filter['setting'];

            if (! array_key_exists($settingKey, $settings) || $settings[$settingKey] === null) {
                return;
            }

            $value = $settings[$settingKey];
        } else {
            $value = $filter['value'] ?? null;
        }

        switch ((string) ($filter['op'] ?? 'eq')) {
            case 'neq':
                $query->whereRaw('('.$expression.' IS NULL OR '.$expression.' != ?)', array($value));

                break;

            case 'lt':
                $query->whereRaw($expression.' < ?', array($value));

                break;

            case 'lte':
                $query->whereRaw($expression.' <= ?', array($value));

                break;

            case 'gt':
                $query->whereRaw($expression.' > ?', array($value));

                break;

            case 'gte':
                $query->whereRaw($expression.' >= ?', array($value));

                break;

            case 'like':
                $query->whereRaw($expression.' LIKE ?', array('%'.$value.'%'));

                break;

            case 'eq':
            default:
                $query->whereRaw($expression.' = ?', array($value));

                break;
        }
    }

    /**
     * Search the entity's textual columns.
     *
     * @param array<string, mixed> $config
     */
    private function applySearch(object $query, array $config, string $search): void
    {
        $columns = array_filter(
            (array) ($config['columns'] ?? array()),
            static fn (array $column): bool => in_array((string) ($column['type'] ?? ''), array('string', 'enum', 'date'), true),
        );

        if ($columns === array()) {
            return;
        }

        $query->where(function ($where) use ($columns, $search): void {
            foreach ($columns as $column) {
                $where->orWhereRaw($this->jsonPath((string) $column['key']).' LIKE ?', array('%'.$search.'%'));
            }
        });
    }
}
