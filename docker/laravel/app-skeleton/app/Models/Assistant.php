<?php

namespace App\Models;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assistant extends Model
{
    /**
     * Tenant used until tenant resolution exists (090-g Phase 1).
     *
     * Not part of $fillable: the tenant is never supplied by a request body.
     */
    public const DEFAULT_TENANT = 'default';

    /**
     * Scope every query to the current estate.
     *
     * The rule `Approval`, `Memory`, `Transcript` and `WorkflowRun` already
     * apply, and the assistant is the estate-owned record if any model is: its
     * provider, model, system prompt and tools are the estate's configuration.
     *
     * Global rather than per-call-site because per-call-site had been tried.
     * Twenty-six hand-written `tenant_id` filters across eighteen files had
     * accumulated, one per leak (§27, §39, §40, §42, §47, §89) — each correct,
     * and each a symptom of a model that did not carry its own invariant
     * (Proposal 153).
     *
     * Route-model binding goes through `newQuery()`, so `{assistant}` now
     * answers 404 for a foreign id without every controller remembering to ask.
     *
     * Three callers deliberately read across estates and opt out with
     * `withoutGlobalScopes()`:
     *
     *  - `Filament\Operator\Pages\Credentials` lists every estate's assistants;
     *  - `EstateCredentials` takes the estate as an argument, so the ambient one
     *    is not its to apply;
     *  - `TraceRuns::titles()` labels a run whose estate it already resolved.
     *
     * Adding a fourth is a reviewable diff, and it should say why.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $query->where(
                $query->getModel()->getTable().'.tenant_id',
                TenantContext::current()
            );
        });
    }

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
        // Which Remote Sites connections this assistant may use. Mirrors
        // WordPress's `_wp_mcp_ai_pro_remote_connections` meta: empty means
        // unrestricted, and the literal "settings" grants the site-wide
        // credential fallback.
        'remote_connections' => [],
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

    /**
     * Virtual attributes exposed through `attributesToArray()`.
     *
     * `primary_role_slugs` and `advanced` live in `meta`, but the `/admin`
     * assistants form binds fields to those names. Filament fills a form from
     * the record's attribute array, so without the append the fields would
     * hydrate empty on every edit and the first save would wipe the stored
     * values. `toRecord()` and `toBundleEntry()` are explicit, so the console
     * wire shape is unaffected.
     *
     * @var list<string>
     */
    protected $appends = ['advanced', 'primary_role_slugs'];

    /**
     * The estate that owns this assistant (Proposal 149 T-0.7).
     *
     * Named `tenant` because the admin panel declares
     * `->tenant(Tenant::class, 'slug', 'tenant')`; Filament resolves that
     * ownership relationship to `whereBelongsTo($tenant, 'tenant')`, so the
     * name is the scoping contract, not a style choice.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

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
     * Form/serialisation binding for `advanced`.
     *
     * Returns every whitelisted key with its type default, so the panel form
     * always hydrates the full set and can clear a value explicitly. The
     * REST/console shape stays `toRecord()`'s explicit `advancedFields()`.
     *
     * @return array<string, mixed>
     */
    public function getAdvancedAttribute(): array
    {
        return $this->advancedFields();
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
     * Form/serialisation binding for `primary_role_slugs`.
     *
     * Reads through `primaryRoleSlugs()` so there is one definition of "what
     * the selection is" — a form that disagrees with the wire record would be
     * the same drift the writer exists to prevent.
     *
     * @return string[]
     */
    public function getPrimaryRoleSlugsAttribute(): array
    {
        return $this->primaryRoleSlugs();
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
