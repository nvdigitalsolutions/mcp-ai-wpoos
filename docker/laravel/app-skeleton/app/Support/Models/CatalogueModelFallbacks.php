<?php

namespace App\Support\Models;

use App\Models\ModelConfig;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\ModelFallbackCatalogueInterface;

/**
 * The model catalogue as the router sees it — Proposal 149 §69.
 *
 * The table already says, per row, which model to try when this one fails.
 * Until now nothing read that column at request time, so a rate-limited or
 * withdrawn model took its whole provider down with it even when a healthy
 * sibling was one row away.
 *
 * Three rules keep the answer safe:
 *
 *  1. **Unknown is not an error.** No table, no row, no fallback → null, and
 *     the router falls through to provider failover exactly as before. The
 *     catalogue is never a whitelist; a custom or fine-tuned id the catalogue
 *     has never heard of still gets sent.
 *  2. **The target must be servable.** `active` and `preview` are routable;
 *     `deprecated` and `retired` are not. Pointing a failure at a retired
 *     model would convert one error into another.
 *  3. **The naming row's provider wins.** `resolveFallback()` already applies
 *     that tie-break for ids two providers publish (`gpt-4o`), which is the
 *     same rule the operator page uses.
 *
 * Provider vocabularies differ by one name: the router's client reports
 * `nvidia_nim`, the catalogue stores `nvidia`. The adapter translates rather
 * than making either side adopt the other's spelling, because both are already
 * persisted in their own stores.
 *
 * Failures are swallowed and reported: routing must never fail because the
 * catalogue could not be read, and a chat request is not the place to surface
 * a schema problem.
 */
final class CatalogueModelFallbacks implements ModelFallbackCatalogueInterface
{
    /**
     * Router provider slug => catalogue provider slug.
     *
     * Only the client name differs; the catalogue mirrors the WordPress
     * vocabulary, so the adapter is the right place to reconcile them.
     *
     * @var array<string, string>
     */
    private const PROVIDER_ALIASES = [
        'nvidia_nim' => 'nvidia',
    ];

    public function fallbackFor(string $provider, string $modelId): ?string
    {
        $provider = trim($provider);
        $modelId = trim($modelId);

        if ('' === $provider || '' === $modelId) {
            return null;
        }

        $aliased = self::PROVIDER_ALIASES[$provider] ?? $provider;

        try {
            if (! Schema::hasTable('nvoos_model_configs')) {
                return null;
            }

            $row = ModelConfig::query()
                ->whereIn('provider', array_values(array_unique([$aliased, $provider])))
                ->where('model_id', $modelId)
                ->first(['provider', 'fallback_model_id']);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        $fallbackId = is_string($row?->fallback_model_id) ? trim($row->fallback_model_id) : '';

        if ('' === $fallbackId || $fallbackId === $modelId) {
            return null;
        }

        // Resolve against the naming row's own provider, not the caller's
        // slug, so the §68 tie-break applies to the row that named the id.
        $target = ModelConfigCatalogue::resolveFallback((string) $row->provider, $fallbackId);

        if (null === $target) {
            return null;
        }

        if (! in_array($target->status, ModelConfig::SERVABLE_STATUSES, true)) {
            return null;
        }

        return (string) $target->model_id;
    }
}
