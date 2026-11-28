<?php

namespace App\Services;

use App\Models\Assistant;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * The assistant write path, in one place (Proposal 149, Wave 2's fifth slice).
 *
 * Assistant writes were owned by `AssistantController` until the `/admin`
 * assistants resource needed the same five invariants. Calling `Model::save()`
 * from a Filament action would have been the obvious shortcut and the wrong
 * one: the form would then be the second implementation of rules the REST
 * surface already enforces, and the two would drift the first time one of them
 * changed.
 *
 * The invariants, in the order they are applied:
 *
 *  1. **Estate assignment** — `tenant_id` comes from `TenantContext` (which the
 *     panel's `SetTenantContext` middleware pins), never from the payload.
 *     `tenant_id` is not fillable, so a hand-crafted body cannot set it.
 *  2. **Per-estate slug uniqueness** — derived from the slug or the title, then
 *     suffixed (`-2`, `-3`, …) until free *within the estate*. The database
 *     unique index is `(tenant_id, slug)`, so the same slug in two estates is
 *     legal and must not be suffixed.
 *  3. **`primary_role_slugs` → `meta`** — WordPress keeps profession IDs in
 *     `_wp_mcp_ai_primary_roles`; here the slug is the identity and it lives in
 *     `meta`, which is what makes an `nvoos-assistant` bundle round-trip.
 *  4. **`advanced` → flat `meta` keys** — a 15-key whitelist with per-key
 *     coercion. Absent keys are untouched; present-but-empty keys are removed.
 *     This is what keeps the console's edit form readable against either
 *     runtime.
 *  5. **`is_default` exclusivity** — setting the flag clears it on every other
 *     assistant *in the same estate*. A global clear would silently un-default
 *     another customer's assistant.
 *
 * Validation is deliberately not here: field rules belong to the transport
 * (the controller's `validated()`, the Filament form's schema), and a bundle
 * import has its own. The writer owns invariants a transport cannot be trusted
 * with.
 *
 * `delete()` is a thin pass-through on purpose. It exists so the resource has
 * one seam for every write rather than importing the model's API directly.
 */
final class AssistantWriter
{
    /**
     * Advanced assistant settings mirrored from the WordPress editor.
     *
     * Whitelist + coercion rules for the additive `advanced` object (090-B
     * assistant edit parity). Keys are stored flat in `meta` so an
     * `nvoos-assistant` bundle round-trips between WordPress and Laravel.
     * Credentials are deliberately absent — token issuance is its own service
     * with its own capability and audit path.
     *
     * @var array<string, string>
     */
    public const ADVANCED_FIELD_TYPES = array(
        'fallback_chain' => 'string_list',
        'remote_connections' => 'string_list',
        'adaptive_tool_cap' => 'enum:on,off',
        'memory_files' => 'int_list',
        'vector_store_id' => 'string',
        'corpus_name' => 'string',
        'prompt_caching' => 'bool',
        'disable_tool_shortcuts' => 'bool',
        'tool_shortcuts' => 'list',
        'tool_prebuilt_shortcuts' => 'map',
        'tool_role_rules' => 'list',
        'external_action_id' => 'string',
        'external_action_type' => 'enum:workflow,assistant',
        'required_capability' => 'string',
        'preferred_datasets' => 'list',
    );

    /**
     * Create an assistant in the current estate.
     *
     * @param array<string, mixed> $data Validated payload, already flattened
     *                                   against whatever transport produced it.
     */
    public function create(array $data): Assistant
    {
        $tenantId = TenantContext::current();

        $data = $this->normalise($data);
        $data['slug'] = $this->uniqueSlug($this->slugSource($data), null, $tenantId);
        $data['status'] = $data['status'] ?? 'draft';

        if (! empty($data['is_default'])) {
            $this->clearOtherDefaults($tenantId);
        }

        $assistant = new Assistant($data);
        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }

    /**
     * Update an assistant in place.
     *
     * A payload without `slug` leaves the stored slug alone — the console's
     * edit form may not carry the field, and re-deriving it from a changed
     * title would silently break every saved link to the assistant.
     *
     * @param array<string, mixed> $data
     */
    public function update(Assistant $assistant, array $data): Assistant
    {
        $data = $this->normalise($data, $assistant);

        if (array_key_exists('slug', $data) && null !== $data['slug'] && '' !== trim((string) $data['slug'])) {
            $data['slug'] = $this->uniqueSlug(
                (string) $data['slug'],
                (int) $assistant->getKey(),
                (string) $assistant->tenant_id
            );
        } else {
            unset($data['slug']);
        }

        if (! empty($data['is_default'])) {
            $this->clearOtherDefaults((string) $assistant->tenant_id, (int) $assistant->getKey());
        }

        $assistant->fill($data)->save();

        return $assistant;
    }

    public function delete(Assistant $assistant): void
    {
        $assistant->delete();
    }

    /**
     * Refuse an assistant that belongs to another estate.
     *
     * Model binding resolves a row without tenancy, so a route that takes an
     * `Assistant` must guard here. `abort(404)` rather than `403` is deliberate:
     * a 403 confirms the record exists, which is the fact a cross-tenant probe
     * is asking for.
     */
    public function ensureInCurrentEstate(Assistant $assistant): void
    {
        abort_if(
            (string) $assistant->tenant_id !== TenantContext::current(),
            404
        );
    }

    /**
     * Apply every normalisation step, in order.
     *
     * Exposed so a caller that wants the flattened result without saving (an
     * import preview, a test) does not re-implement the pipeline.
     *
     * @param array<string, mixed> $data
     * @param Assistant|null       $assistant Existing record on update.
     *
     * @return array<string, mixed>
     */
    public function normalise(array $data, ?Assistant $assistant = null): array
    {
        return $this->withAdvancedFields($this->withPrimaryRoles($data, $assistant), $assistant);
    }

    /**
     * A slug that is free within the estate.
     *
     * The suffix starts at `-2` (never `-1`): "second one of these" is the
     * meaning. The lookup is tenant-scoped, mirroring the `(tenant_id, slug)`
     * unique index — without the scope a second estate's `support-bot` would
     * push the first estate's to `support-bot-2`.
     */
    public function uniqueSlug(string $value, ?int $ignoreId = null, ?string $tenantId = null): string
    {
        $tenantId = $tenantId ?? TenantContext::current();
        $base = Str::slug($value) ?: 'assistant';
        $slug = $base;
        $suffix = 2;

        while (Assistant::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Where the slug should be derived from.
     *
     * An empty `slug` is treated as absent. A form that submits every field
     * sends `''`, and `Str::slug('')` would otherwise land every untitled slug
     * on the literal fallback instead of the assistant's title.
     *
     * @param array<string, mixed> $data
     */
    public function slugSource(array $data): string
    {
        $slug = isset($data['slug']) ? trim((string) $data['slug']) : '';

        return '' !== $slug ? $slug : (string) ($data['title'] ?? '');
    }

    /**
     * Clear `is_default` on the estate's other assistants.
     *
     * Scoped to one estate by construction: the flag is per-estate (the tenancy
     * migration made it so), and a global clear would un-default a different
     * customer's assistant because this one was edited.
     */
    private function clearOtherDefaults(string $tenantId, ?int $ignoreId = null): void
    {
        Assistant::query()
            ->where('tenant_id', $tenantId)
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->update(array('is_default' => false));
    }

    /**
     * Move the profession selection into `meta`.
     *
     * WordPress keeps up to three profession post IDs in
     * `_wp_mcp_ai_primary_roles`; the catalogue is reference data here, so the
     * slugs live in `meta.primary_role_slugs` and `toRecord()` promotes them.
     *
     * @param array<string, mixed> $data      Validated payload.
     * @param Assistant|null       $assistant Existing record on update.
     *
     * @return array<string, mixed>
     */
    private function withPrimaryRoles(array $data, ?Assistant $assistant = null): array
    {
        if (! array_key_exists('primary_role_slugs', $data)) {
            return $data;
        }

        $slugs = array_values(array_unique($data['primary_role_slugs']));
        unset($data['primary_role_slugs']);

        $meta = $data['meta'] ?? ($assistant->meta ?? array());
        $meta['primary_role_slugs'] = $slugs;
        $data['meta'] = $meta;

        return $data;
    }

    /**
     * Normalise the console's `advanced` object into flat `meta` keys.
     *
     * Mirrors the WordPress REST contract: whitelist only, per-key coercion,
     * absent keys left untouched, present-but-empty values removed. The flat
     * keys match the `_wp_mcp_ai_*` post-meta keys an exported bundle carries,
     * so the same payload imports into either runtime.
     *
     * @param array<string, mixed> $data      Validated payload.
     * @param Assistant|null       $assistant Existing record on update.
     *
     * @return array<string, mixed>
     */
    private function withAdvancedFields(array $data, ?Assistant $assistant = null): array
    {
        if (! array_key_exists('advanced', $data)) {
            return $data;
        }

        $input = is_array($data['advanced']) ? $data['advanced'] : array();
        unset($data['advanced']);

        $meta = $data['meta'] ?? ($assistant->meta ?? array());
        $meta = is_array($meta) ? $meta : array();

        foreach (self::ADVANCED_FIELD_TYPES as $field => $type) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $this->normaliseAdvancedValue($type, $input[$field]);

            if ($value === null) {
                unset($meta[$field]);

                continue;
            }

            $meta[$field] = $value;
        }

        $data['meta'] = $meta;

        return $data;
    }

    /**
     * Coerce one advanced value; null means "clear the key".
     */
    private function normaliseAdvancedValue(string $type, mixed $value): mixed
    {
        if ($type === 'bool') {
            return (bool) $value ? true : null;
        }

        if (str_starts_with($type, 'enum:')) {
            $allowed = explode(',', substr($type, 5));
            $candidate = is_string($value) ? trim($value) : '';

            return in_array($candidate, $allowed, true) ? $candidate : null;
        }

        if ($type === 'int_list') {
            $ids = array();

            foreach (is_array($value) ? $value : array() as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            $ids = array_values(array_unique($ids));

            return $ids === array() ? null : $ids;
        }

        if ($type === 'string_list') {
            $items = array();

            foreach (is_array($value) ? $value : array() as $item) {
                if (! is_string($item)) {
                    continue;
                }

                $item = trim($item);
                if ($item !== '') {
                    $items[] = $item;
                }
            }

            $items = array_values(array_unique($items));

            return $items === array() ? null : $items;
        }

        if ($type === 'list') {
            $items = array();

            foreach (is_array($value) ? $value : array() as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            return $items === array() ? null : $items;
        }

        if ($type === 'map') {
            return (is_array($value) && $value !== array()) ? $value : null;
        }

        // Plain string.
        $string = is_string($value) ? trim($value) : '';

        return $string === '' ? null : $string;
    }
}
