<?php

namespace App\Services;

use App\Models\Assistant;
use App\Support\BlueprintInstallException;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Installs a blueprint as an assistant (090-b task 2.4, write half).
 *
 * Mirrors `WP_MCP_AI_Blueprint_Installer::install()`:
 *
 * - both blueprint schemas are accepted — CRM-style (`name` + `meta`) and the
 *   Healthcare-style direct-post form (`post_title` + `meta_input`);
 * - flat/legacy blueprints with the CRM keys at the top level are normalised
 *   into the CRM shape first;
 * - the title is the identity: an existing assistant with the same title is a
 *   conflict unless `overwrite` is set;
 * - `_blueprint_source` records provenance so the catalogue's `installed` /
 *   `assistant_id` columns resolve.
 *
 * Two deliberate differences, because the runtimes store different things:
 *
 * - **Profession.** WordPress resolves a profession post ID into
 *   `_wp_mcp_ai_primary_roles`. Laravel keys professions by slug, so the slug
 *   lands in `meta.primary_role_slugs` — the field the assistant editor reads.
 * - **Model.** WordPress always writes a concrete model by resolving a
 *   per-provider default out of `wp_mcp_ai_settings`. There is no equivalent
 *   settings surface here, so a model is only set when the blueprint names one;
 *   otherwise the runtime default applies rather than a guessed model name.
 */
final class BlueprintInstaller
{
    public function __construct(private readonly BlueprintCatalogue $catalogue)
    {
    }

    /**
     * Install (or overwrite) a blueprint as an assistant.
     *
     * @return array{assistant_id:int, name:string}
     *
     * @throws BlueprintInstallException When the blueprint is missing or the title is taken.
     */
    public function install(string $toolkit, string $slug, bool $overwrite = false): array
    {
        $data = $this->catalogue->raw($toolkit, $slug);

        if (null === $data) {
            throw BlueprintInstallException::notFound($slug);
        }

        $mapped = $this->map($data, $slug);

        $existing = Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->where('title', $mapped['title'])
            ->first();

        if (null !== $existing && ! $overwrite) {
            throw BlueprintInstallException::duplicate($mapped['title']);
        }

        $assistant = $existing ?? new Assistant();

        $assistant->title = $mapped['title'];
        $assistant->system_prompt = $mapped['system_prompt'];
        $assistant->status = 'publish';
        $assistant->tools = $mapped['tools'];
        $assistant->provider = $mapped['provider'];
        $assistant->model = $mapped['model'];
        $assistant->temperature = $mapped['temperature'];

        // Merge rather than replace: an overwrite repopulates the keys the
        // blueprint owns and leaves anything the user added alone.
        $assistant->meta = array_merge(
            is_array($assistant->meta) ? $assistant->meta : [],
            $mapped['meta']
        );

        if (null === $existing) {
            $assistant->slug = $this->uniqueSlug($mapped['title']);
            $assistant->tenant_id = TenantContext::current();
        }

        $assistant->save();

        return [
            'assistant_id' => (int) $assistant->id,
            'name' => $mapped['title'],
        ];
    }

    /**
     * Normalise a blueprint into assistant columns plus meta.
     *
     * @param  array<string,mixed>  $data
     * @return array{title:string, system_prompt:string, tools:list<string>, provider:?string, model:?string, temperature:?float, meta:array<string,mixed>}
     */
    private function map(array $data, string $slug): array
    {
        if (isset($data['post_title'])) {
            return $this->mapHealthcareStyle($data);
        }

        $raw = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        // Flat/legacy blueprints carry the CRM keys at the top level.
        if ([] === $raw) {
            $flat = [
                'profession' => 'profession',
                'tools' => 'available_tools',
                'instructions' => 'instructions',
                'version' => 'version',
                'tags' => 'tags',
            ];

            foreach ($flat as $source => $target) {
                if (! empty($data[$source])) {
                    $raw[$target] = $data[$source];
                }
            }
        }

        $title = (string) ($data['name'] ?? ucwords(str_replace('-', ' ', $slug)));
        $systemPrompt = ! empty($raw['instructions'])
            ? (string) $raw['instructions']
            : (string) ($data['description'] ?? '');

        $meta = [
            'mcp_ai_required_capability' => ! empty($raw['required_capability'])
                ? $this->key((string) $raw['required_capability'])
                : 'edit_posts',
        ];

        if (! empty($raw['profession'])) {
            $meta['primary_role_slugs'] = [$this->key((string) $raw['profession'])];
        }

        // WordPress also persists the abstracted keys themselves so the
        // catalogue can read channels / framework / auto_reply_enabled back.
        foreach ($raw as $rawKey => $value) {
            $normalised = $this->key((string) $rawKey);

            if ('' === $normalised || str_starts_with($normalised, '_wp_mcp_ai_') || str_starts_with($normalised, 'mcp_ai_')) {
                continue;
            }

            $meta[$normalised] = $value;
        }

        $meta['_blueprint_source'] = $this->key($slug);

        return [
            'title' => $title,
            'system_prompt' => $systemPrompt,
            'tools' => $this->stringList($raw['available_tools'] ?? []),
            'provider' => ! empty($raw['provider'])
                ? $this->key((string) $raw['provider'])
                : (string) config('nvoos.default_provider', 'openai'),
            'model' => ! empty($raw['model'])
                ? (string) $raw['model']
                : $this->nullableString(config('nvoos.default_model')),
            'temperature' => array_key_exists('temperature', $raw)
                ? (float) $raw['temperature']
                : (float) config('nvoos.default_temperature', 0.7),
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{title:string, system_prompt:string, tools:list<string>, provider:?string, model:?string, temperature:?float, meta:array<string,mixed>}
     */
    private function mapHealthcareStyle(array $data): array
    {
        $meta = is_array($data['meta_input'] ?? null) ? $data['meta_input'] : [];

        if (! empty($data['profession']) && ! isset($meta['primary_role_slugs'])) {
            $meta['primary_role_slugs'] = [$this->key((string) $data['profession'])];
        }

        return [
            'title' => (string) $data['post_title'],
            'system_prompt' => (string) ($data['post_content'] ?? ''),
            'tools' => $this->stringList($meta['_wp_mcp_ai_tools'] ?? []),
            'provider' => $this->nullableString($meta['_wp_mcp_ai_provider'] ?? null),
            'model' => $this->nullableString($meta['_wp_mcp_ai_model'] ?? null),
            'temperature' => isset($meta['_wp_mcp_ai_temperature'])
                ? (float) $meta['_wp_mcp_ai_temperature']
                : null,
            'meta' => $this->stripCanonical($meta),
        ];
    }

    /**
     * Drop the keys that became columns, keeping the rest as meta.
     *
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function stripCanonical(array $meta): array
    {
        $canonical = [
            '_wp_mcp_ai_tools',
            '_wp_mcp_ai_system_prompt',
            '_wp_mcp_ai_provider',
            '_wp_mcp_ai_model',
            '_wp_mcp_ai_temperature',
        ];

        foreach ($canonical as $key) {
            unset($meta[$key]);
        }

        return $meta;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_string($item) && '' !== $item) {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || '' === trim($value)) {
            return null;
        }

        return $value;
    }

    /**
     * WordPress `sanitize_key()`: lowercase, alphanumerics plus `-` and `_`.
     */
    private function key(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($value));
    }

    /**
     * Slug that is unique within the tenant, mirroring the assistant editor.
     */
    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'assistant';
        $slug = $base;
        $suffix = 2;

        while (Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
