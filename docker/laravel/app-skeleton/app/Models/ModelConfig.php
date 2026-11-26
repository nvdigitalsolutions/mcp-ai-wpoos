<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A text/chat model's limits, prices and lifecycle — Proposal 149 §68.
 *
 * This is reference data, not estate data: there is no `tenant_id`, and writes
 * go through `/ops`. The settings model selects and the provider fallbacks read
 * the same rows, so the catalogue an operator edits is the list an estate
 * chooses from.
 *
 * Media models are deliberately absent. `media-model-catalog.json` describes
 * image/video/audio capabilities (modes, aspect ratios, resolutions, duration)
 * and their prices are per second or per image, not per token; a row here with
 * a per-1M cost would be a wrong answer, not a partial one.
 */
class ModelConfig extends Model
{
    protected $table = 'nvoos_model_configs';

    protected $fillable = [
        'model_id',
        'name',
        'provider',
        'context_window',
        'max_output_tokens',
        'tpm_limit',
        'rpm_limit',
        'tpd_limit',
        'rpd_limit',
        'input_cost_per_million',
        'output_cost_per_million',
        'fallback_model_id',
        'status',
        'sunset_date',
        'supports_streaming',
        'supports_function_calling',
        'supports_vision',
        'tier',
        'notes',
    ];

    /**
     * The lifecycle vocabulary. `active` and `preview` are selectable;
     * `deprecated` is kept visible for the row that already uses it; `retired`
     * is never offered.
     *
     * @var list<string>
     */
    public const STATUSES = ['active', 'preview', 'deprecated', 'retired'];

    /**
     * The statuses a new default or fallback may select.
     *
     * @var list<string>
     */
    public const SERVABLE_STATUSES = ['active', 'preview'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'tpm_limit' => 'integer',
            'rpm_limit' => 'integer',
            'tpd_limit' => 'integer',
            'rpd_limit' => 'integer',
            'input_cost_per_million' => 'decimal:6',
            'output_cost_per_million' => 'decimal:6',
            'supports_streaming' => 'boolean',
            'supports_function_calling' => 'boolean',
            'supports_vision' => 'boolean',
            'sunset_date' => 'date',
        ];
    }

    /**
     * Models a new selection may use.
     */
    public function scopeServable(Builder $query): Builder
    {
        return $query->whereIn('status', self::SERVABLE_STATUSES);
    }

    /**
     * The fallback this model points at, when it exists.
     *
     * A fallback is stored as a bare id, so an id two providers both publish
     * (`gpt-4o`) matches more than one row; a to-one relation would then pick
     * by insertion order. Consumers that need the row an operator meant should
     * call `ModelConfigCatalogue::resolveFallback()`, which prefers the naming
     * row's own provider. This relation stays for the common, unambiguous case.
     */
    public function fallback(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_model_id', 'model_id');
    }
}
