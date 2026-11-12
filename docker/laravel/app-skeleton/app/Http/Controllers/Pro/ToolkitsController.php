<?php

namespace App\Http\Controllers\Pro;

use App\Support\Pro\ToolkitManifestLoader;
use App\Support\SettingsSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

/**
 * Generic Pro toolkit engine (Proposal 090-B §8).
 *
 * One controller serves every manifest-driven family: the manifest decides
 * which settings, entities and dashboard cards exist, and this controller
 * provides the CRUD, validation and aggregation behind them. No bespoke PHP
 * page is needed to add a family — only a JSON document.
 *
 * Entitlement is per-toolkit (`manifest.feature()`), not per-route: the list
 * filters out unentitled families, and direct access answers the typed 402.
 */
class ToolkitsController
{
    public function index(ToolkitManifestLoader $loader, ProEntitlementInterface $entitlement): JsonResponse
    {
        $toolkits = [];

        foreach ($loader->all() as $manifest) {
            if (! $entitlement->allows($manifest->feature())) {
                continue;
            }

            $toolkits[] = $this->summary($manifest);
        }

        return $this->ok([
            'toolkits' => $toolkits,
            'invalid' => $loader->errors(),
        ]);
    }

    public function show(
        string $slug,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->ok(['toolkit' => $manifest->toArray()]);
    }

    public function settings(
        string $slug,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
        SettingsStoreInterface $store,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        return $this->ok([
            'slug' => $manifest->slug(),
            'schema' => $manifest->settings(),
            'values' => $this->settingsValues($manifest, $store),
        ]);
    }

    public function updateSettings(
        Request $request,
        string $slug,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
        SettingsStoreInterface $store,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        $data = $request->validate([
            'values' => ['required', 'array'],
        ]);

        $schema = [];

        foreach ($manifest->settings() as $field) {
            $schema[(string) $field['key']] = $field;
        }

        $errors = [];
        $writes = [];

        foreach ($data['values'] as $key => $value) {
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

        if ($errors !== []) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'One or more settings are invalid.',
                    'fields' => $errors,
                ],
            ], 422);
        }

        foreach ($writes as $key => $value) {
            $store->set($this->settingKey($manifest->slug(), $key), $value);
        }

        return $this->ok([
            'slug' => $manifest->slug(),
            'values' => $this->settingsValues($manifest, $store),
        ]);
    }

    public function dashboard(
        string $slug,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        $cards = [];

        foreach ($manifest->dashboardCards() as $card) {
            $source = (string) ($card['source'] ?? '');
            $entityKey = str_starts_with($source, 'entity:') ? substr($source, 7) : '';
            $entity = $manifest->entity($entityKey);

            if ($entity === null) {
                continue;
            }

            $query = DB::table('nvoos_toolkit_records')
                ->where('toolkit', $manifest->slug())
                ->where('entity', $entityKey);

            $this->applyFilter($query, $card['filter'] ?? null);

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

            $cards[] = [
                'key' => (string) ($card['key'] ?? ''),
                'label' => (string) ($card['label'] ?? ''),
                'aggregate' => $aggregate,
                'value' => $value,
            ];
        }

        return $this->ok([
            'slug' => $manifest->slug(),
            'cards' => $cards,
        ]);
    }

    public function indexEntity(
        Request $request,
        string $slug,
        string $entity,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        $config = $manifest->entity($entity);

        if ($config === null) {
            return $this->fail('entity_not_found', 'Unknown entity "'.$entity.'".', 404);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('nvoos_toolkit_records')
            ->where('toolkit', $manifest->slug())
            ->where('entity', $entity);

        if ($search !== '') {
            $this->applySearch($query, $config, $search);
        }

        $filters = $request->query('filter');
        $filterErrors = [];

        if (is_array($filters)) {
            foreach ($filters as $column => $value) {
                if (! $this->entityHasColumn($config, (string) $column)) {
                    $filterErrors[] = (string) $column;
                    continue;
                }

                $query->whereRaw($this->jsonPath((string) $column).' = ?', [$value]);
            }
        }

        if ($filterErrors !== []) {
            return $this->fail(
                'unknown_filter_column',
                'Unknown filter column(s): '.implode(', ', $filterErrors).'.',
                422,
            );
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return $this->ok([
            'entity' => $entity,
            'records' => array_map([$this, 'record'], $rows->all()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public function showEntity(
        string $slug,
        string $entity,
        string $id,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        if ($manifest->entity($entity) === null) {
            return $this->fail('entity_not_found', 'Unknown entity "'.$entity.'".', 404);
        }

        $row = $this->findRow($manifest->slug(), $entity, $id);

        return $row === null
            ? $this->fail('record_not_found', 'Record not found.', 404)
            : $this->ok(['record' => $this->record($row)]);
    }

    public function storeEntity(
        Request $request,
        string $slug,
        string $entity,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        $config = $manifest->entity($entity);

        if ($config === null) {
            return $this->fail('entity_not_found', 'Unknown entity "'.$entity.'".', 404);
        }

        $payload = $request->input('data');

        if (! is_array($payload)) {
            return $this->fail('invalid_payload', 'Send the record as a `data` object.', 422);
        }

        [$errors, $normalized] = $this->validatePayload($config, $payload, true);

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        $idField = (string) $config['id_field'];
        $externalId = (string) $normalized[$idField];

        if ($this->findRow($manifest->slug(), $entity, $externalId) !== null) {
            return $this->fail('duplicate_record', 'A record with that '.$idField.' already exists.', 422);
        }

        $now = now();

        $internalId = DB::table('nvoos_toolkit_records')->insertGetId([
            'toolkit' => $manifest->slug(),
            'entity' => $entity,
            'external_id' => $externalId,
            'data' => json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->ok([
            'record' => $this->record(DB::table('nvoos_toolkit_records')->find($internalId)),
        ], 201);
    }

    public function updateEntity(
        Request $request,
        string $slug,
        string $entity,
        string $id,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        $config = $manifest->entity($entity);

        if ($config === null) {
            return $this->fail('entity_not_found', 'Unknown entity "'.$entity.'".', 404);
        }

        $row = $this->findRow($manifest->slug(), $entity, $id);

        if ($row === null) {
            return $this->fail('record_not_found', 'Record not found.', 404);
        }

        $payload = $request->input('data');

        if (! is_array($payload)) {
            return $this->fail('invalid_payload', 'Send the record as a `data` object.', 422);
        }

        [$errors, $normalized] = $this->validatePayload($config, $payload, false);

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        $idField = (string) $config['id_field'];

        if (array_key_exists($idField, $normalized) && (string) $normalized[$idField] !== (string) $row->external_id) {
            return $this->fail('immutable_id', 'The '.$idField.' field cannot be changed.', 422);
        }

        $existing = json_decode((string) $row->data, true);
        $existing = is_array($existing) ? $existing : [];
        $merged = array_merge($existing, $normalized);

        DB::table('nvoos_toolkit_records')->where('id', $row->id)->update([
            'data' => json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $this->ok([
            'record' => $this->record(DB::table('nvoos_toolkit_records')->find($row->id)),
        ]);
    }

    public function destroyEntity(
        string $slug,
        string $entity,
        string $id,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        [$manifest, $error] = $this->resolve($slug, $loader, $entitlement);

        if ($error !== null) {
            return $error;
        }

        if ($manifest->entity($entity) === null) {
            return $this->fail('entity_not_found', 'Unknown entity "'.$entity.'".', 404);
        }

        $deleted = DB::table('nvoos_toolkit_records')
            ->where('toolkit', $manifest->slug())
            ->where('entity', $entity)
            ->where('external_id', $id)
            ->delete();

        return $deleted > 0
            ? $this->ok(['deleted' => true])
            : $this->fail('record_not_found', 'Record not found.', 404);
    }

    /**
     * @return array{0: ToolkitManifest|null, 1: JsonResponse|null}
     */
    private function resolve(
        string $slug,
        ToolkitManifestLoader $loader,
        ProEntitlementInterface $entitlement,
    ): array {
        $manifest = $loader->find($slug);

        if ($manifest === null) {
            return [null, $this->fail('toolkit_not_found', 'Unknown toolkit "'.$slug.'".', 404)];
        }

        if (! $entitlement->allows($manifest->feature())) {
            return [null, response()->json([
                'success' => false,
                'error' => [
                    'code' => 'pro_entitlement_required',
                    'message' => 'The '.$manifest->feature().' toolkit requires a Pro entitlement.',
                    'feature' => $manifest->feature(),
                ],
            ], 402)];
        }

        return [$manifest, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ToolkitManifest $manifest): array
    {
        $raw = $manifest->toArray();

        return [
            'slug' => $manifest->slug(),
            'name' => $manifest->name(),
            'version' => (string) ($raw['version'] ?? ''),
            'description' => (string) ($raw['description'] ?? ''),
            'icon' => (string) ($raw['icon'] ?? ''),
            'feature' => $manifest->feature(),
            'screens' => $manifest->screens(),
            'entities' => array_map(
                static fn (array $entity): array => [
                    'key' => (string) ($entity['key'] ?? ''),
                    'label' => (string) ($entity['label'] ?? ''),
                    'icon' => (string) ($entity['icon'] ?? ''),
                ],
                $manifest->entities(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsValues(ToolkitManifest $manifest, SettingsStoreInterface $store): array
    {
        $values = [];

        foreach ($manifest->settings() as $field) {
            $key = (string) $field['key'];
            $value = $store->get($this->settingKey($manifest->slug(), $key), $field['default'] ?? null);

            if ((string) ($field['type'] ?? 'text') === 'secret') {
                $value = is_string($value) && $value !== '' ? SettingsSchema::MASKED_PLACEHOLDER : '';
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function settingKey(string $slug, string $key): string
    {
        return 'toolkit.'.$slug.'.'.$key;
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
                    ? [true, 0 + $value, null]
                    : [false, null, 'Must be a number.'];

            case 'boolean':
                if (is_bool($value)) {
                    return [true, $value, null];
                }

                if (in_array($value, [1, '1', 'true'], true)) {
                    return [true, true, null];
                }

                if (in_array($value, [0, '0', 'false'], true)) {
                    return [true, false, null];
                }

                return [false, null, 'Must be a boolean.'];

            case 'select':
                $options = (array) ($field['options'] ?? []);
                $allowed = array_is_list($options) ? $options : array_keys($options);

                return in_array($value, $allowed, true)
                    ? [true, $value, null]
                    : [false, null, 'Must be one of: '.implode(', ', $allowed).'.'];

            case 'text':
            case 'secret':
            default:
                return is_scalar($value)
                    ? [true, (string) $value, null]
                    : [false, null, 'Must be a string.'];
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
        $errors = [];
        $normalized = [];
        $columns = [];

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

        return [$errors, $normalized];
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
                    ? [true, 0 + $value, null]
                    : [false, null, 'Must be numeric.'];

            case 'boolean':
                if (is_bool($value)) {
                    return [true, $value, null];
                }

                if (in_array($value, [1, '1', 'true'], true)) {
                    return [true, true, null];
                }

                if (in_array($value, [0, '0', 'false'], true)) {
                    return [true, false, null];
                }

                return [false, null, 'Must be a boolean.'];

            case 'date':
                return is_string($value) && strtotime($value) !== false
                    ? [true, $value, null]
                    : [false, null, 'Must be a date.'];

            case 'enum':
                $allowed = (array) ($column['enum'] ?? []);

                return in_array($value, $allowed, true)
                    ? [true, $value, null]
                    : [false, null, 'Must be one of: '.implode(', ', $allowed).'.'];

            case 'string':
            default:
                return is_scalar($value)
                    ? [true, (string) $value, null]
                    : [false, null, 'Must be a string.'];
        }
    }

    private function entityHasColumn(array $config, string $column): bool
    {
        foreach ((array) ($config['columns'] ?? []) as $candidate) {
            if ((string) ($candidate['key'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param object $row
     * @return array<string, mixed>
     */
    private function record(object $row): array
    {
        $data = json_decode((string) ($row->data ?? ''), true);

        return [
            'id' => (string) ($row->external_id ?? $row->id),
            'entity' => (string) $row->entity,
            'data' => is_array($data) ? $data : [],
            'created_at' => $row->created_at !== null ? (string) $row->created_at : null,
            'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
        ];
    }

    private function findRow(string $toolkit, string $entity, string $id): ?object
    {
        return DB::table('nvoos_toolkit_records')
            ->where('toolkit', $toolkit)
            ->where('entity', $entity)
            ->where('external_id', $id)
            ->first();
    }

    /**
     * Portable JSON path expression. The column name always comes from a
     * validated manifest (key pattern), so interpolation is safe.
     */
    private function jsonPath(string $column): string
    {
        return "json_extract(data, '$.".str_replace("'", '', $column)."')";
    }

    private function applyFilter(object $query, mixed $filter): void
    {
        if (! is_array($filter)) {
            return;
        }

        $column = (string) ($filter['column'] ?? '');
        $expression = $this->jsonPath($column);
        $value = $filter['value'] ?? null;

        switch ((string) ($filter['op'] ?? 'eq')) {
            case 'neq':
                $query->whereRaw('('.$expression.' IS NULL OR '.$expression.' != ?)', [$value]);
                break;
            case 'lt':
                $query->whereRaw($expression.' < ?', [$value]);
                break;
            case 'lte':
                $query->whereRaw($expression.' <= ?', [$value]);
                break;
            case 'gt':
                $query->whereRaw($expression.' > ?', [$value]);
                break;
            case 'gte':
                $query->whereRaw($expression.' >= ?', [$value]);
                break;
            case 'like':
                $query->whereRaw($expression.' LIKE ?', ['%'.$value.'%']);
                break;
            case 'eq':
            default:
                $query->whereRaw($expression.' = ?', [$value]);
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
            (array) ($config['columns'] ?? []),
            static fn (array $column): bool => in_array((string) ($column['type'] ?? ''), ['string', 'enum', 'date'], true),
        );

        if ($columns === []) {
            return;
        }

        $query->where(function ($where) use ($columns, $search): void {
            foreach ($columns as $column) {
                $where->orWhereRaw($this->jsonPath((string) $column['key']).' LIKE ?', ['%'.$search.'%']);
            }
        });
    }

    /**
     * @param array<string, string> $errors
     */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'validation_failed',
                'message' => 'One or more fields are invalid.',
                'fields' => $errors,
            ],
        ], 422);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
