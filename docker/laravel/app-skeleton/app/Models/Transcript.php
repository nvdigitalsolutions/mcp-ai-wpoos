<?php

namespace App\Models;

use App\Support\TenantContext;
use App\Support\Transcripts\TranscriptPreview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A chat transcript — Proposal 149 §39.
 *
 * Read-only by construction: the table is written by
 * `Nvoos\Laravel\Adapter\TranscriptStore` (the chat path and the conversation
 * importer), never by a surface. The model exists because a Filament table needs
 * an Eloquent source, and it carries the estate scope so the rule lives in one
 * place rather than in each query.
 *
 * No casts on purpose. `messages` and `metadata` are JSON columns whose only
 * consumer here is {@see TranscriptPreview}; casting them would change what the
 * console's `show` payload receives, because the store decodes them itself.
 */
class Transcript extends Model
{
    protected $table = 'nvoos_transcripts';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** The store owns both timestamps. */
    public $timestamps = false;

    /** Nothing on this model is mass-assignable. */
    protected $guarded = ['*'];

    /**
     * Scope every query to the current estate.
     *
     * Transcripts are conversations. Without this, `/admin` would list every
     * estate's sessions and the inspect modal would render another estate's
     * messages — API1/BOLA rather than a display bug.
     *
     * The store applies the same scope through its own `query()` helper; both
     * resolve `TenantContext`, which `SetTenantContext` pins to the panel tenant
     * on every `/admin` request.
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
     * Turn count for the list row.
     *
     * Derived, not stored: `messages` is the only source of truth for how many
     * turns a session has, and a counter column would be one more thing the
     * importer could get wrong.
     */
    public function getTurnCountAttribute(): int
    {
        return count($this->decodedMessages());
    }

    /**
     * The first user message, truncated — the same rule the console list uses.
     */
    public function getPreviewAttribute(): string
    {
        return TranscriptPreview::firstUserMessage($this->decodedMessages());
    }

    /**
     * `messages` is a JSON column; a half-written row must not 500 the page.
     *
     * @return array<int, mixed>
     */
    public function decodedMessages(): array
    {
        $decoded = json_decode((string) $this->getRawOriginal('messages'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
