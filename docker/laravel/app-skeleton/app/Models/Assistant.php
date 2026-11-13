<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assistant extends Model
{
    /**
     * Tenant used until tenant resolution exists (090-g Phase 1).
     *
     * Not part of $fillable: the tenant is never supplied by a request body.
     */
    public const DEFAULT_TENANT = 'default';

    /**
     * Advanced settings promoted from the flat `meta` keys to `advanced`.
     *
     * Same wire names and defaults as the WordPress REST contract
     * (090-B assistant edit parity), so the console can render one form
     * against either runtime.
     *
     * @var array<string, mixed>
     */
    public const ADVANCED_FIELD_DEFAULTS = [
        'fallback_chain' => [],
        'adaptive_tool_cap' => '',
        'memory_files' => [],
        'vector_store_id' => '',
        'corpus_name' => '',
        'prompt_caching' => false,
        'disable_tool_shortcuts' => false,
        'tool_shortcuts' => [],
        'tool_prebuilt_shortcuts' => [],
        'tool_role_rules' => [],
        'external_action_id' => '',
        'external_action_type' => '',
        'required_capability' => '',
        'preferred_datasets' => [],
    ];

    protected $fillable = [
        'title',
        'slug',
        'status',
        'description',
        'system_prompt',
        'provider',
        'model',
        'temperature',
        'max_tokens',
        'tools',
        'meta',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'meta' => 'array',
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The console's assistant record shape.
     *
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'slug' => (string) $this->slug,
            'status' => (string) $this->status,
            'description' => $this->description,
            'system_prompt' => $this->system_prompt,
            'provider' => $this->provider,
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
            'tools' => $this->tools ?? [],
            'meta' => $this->meta ?? [],
            'is_default' => (bool) $this->is_default,
            'is_preset' => false,
            'primary_role_slugs' => $this->primaryRoleSlugs(),
            'advanced' => $this->advancedFields(),
        ];
    }

    /**
     * Advanced settings, promoted from the flat `meta` keys.
     *
     * Missing keys read as their type default (empty string, empty list,
     * false), matching the WordPress read shape.
     *
     * @return array<string, mixed>
     */
    public function advancedFields(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $fields = [];

        foreach (self::ADVANCED_FIELD_DEFAULTS as $field => $default) {
            $fields[$field] = array_key_exists($field, $meta) ? $meta[$field] : $default;
        }

        return $fields;
    }

    /**
     * Profession catalogue slugs assigned to this assistant.
     *
     * WordPress stores up to three profession post IDs in
     * `_wp_mcp_ai_primary_roles`. The catalogue is reference data here, so the
     * slug is the identity; the array lives in `meta` to keep the schema flat.
     *
     * @return string[]
     */
    public function primaryRoleSlugs(): array
    {
        $slugs = $this->meta['primary_role_slugs'] ?? [];

        return is_array($slugs) ? array_values(array_filter($slugs, 'is_string')) : [];
    }

    /**
     * The canonical `nvoos-assistant` bundle entry.
     *
     * @return array<string, mixed>
     */
    public function toBundleEntry(): array
    {
        return [
            'title' => (string) $this->title,
            'slug' => (string) $this->slug,
            'status' => (string) $this->status,
            'content' => $this->system_prompt,
            'excerpt' => $this->description,
            'meta' => array_merge($this->meta ?? [], [
                'provider' => $this->provider,
                'model' => $this->model,
                'temperature' => $this->temperature,
                'max_tokens' => $this->max_tokens,
                'tools' => $this->tools ?? [],
                'is_default' => (bool) $this->is_default,
            ]),
        ];
    }
}
