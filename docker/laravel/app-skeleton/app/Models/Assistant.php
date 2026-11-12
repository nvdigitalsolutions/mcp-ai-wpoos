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
        ];
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
