<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An agent-memory record — Proposal 149 §40.
 *
 * Read-only by construction: the table is written by
 * `Nvoos\Laravel\Adapter\MemoryStore` (the `store`/`update` tools and the console
 * API), never by a surface. The model exists because a Filament table needs an
 * Eloquent source, and it carries the estate scope so the rule lives in one place
 * rather than in each query.
 *
 * No casts on `content`: it is free text and every consumer wants the raw string.
 * `tags` and `metadata` are the exceptions — both columns are JSON, and a
 * half-written value must not 500 the page.
 */
class Memory extends Model
{
    protected $table = 'nvoos_memories';

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
     * A memory row can quote a colleague, a customer or an internal decision.
     * Without this, `/admin` would list every estate's memory and the inspector
     * would render another estate's content — API1/BOLA rather than a display bug.
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
     * `tags` is a JSON column; a half-written row must not 500 the page.
     *
     * @return array<int, string>
     */
    public function getTagsAttribute(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }

    /**
     * `metadata` is a JSON column, decoded to the map the store reports.
     *
     * Returns an empty map rather than null so a reader never has to tell
     * "absent" from "empty", which is what `MemoryStoreInterface::get()` promises
     * on the domain side.
     *
     * @return array<string, mixed>
     */
    public function getMetadataAttribute(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether the row is past its TTL.
     *
     * The store filters expired rows out of recall, so an expired entry is
     * invisible to the assistant but still visible here — which is the point of a
     * diagnostic surface: "why did it forget that" has an answer.
     */
    public function getIsExpiredAttribute(): bool
    {
        if (null === $this->expires_at) {
            return false;
        }

        $expires = strtotime((string) $this->expires_at);

        return false !== $expires && $expires <= time();
    }

    /**
     * A single-line excerpt for the list row.
     */
    public function getPreviewAttribute(): string
    {
        $collapsed = preg_replace('/\s+/', ' ', (string) $this->content);

        return mb_substr(trim((string) $collapsed), 0, 120);
    }
}
