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
        ];
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
