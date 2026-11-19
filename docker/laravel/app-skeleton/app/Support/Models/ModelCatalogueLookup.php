<?php

namespace App\Support\Models;

use App\Models\ModelConfig;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\ModelLimitCatalogueInterface;
use Nvoos\Core\Domain\Contract\ModelRoutingCatalogueInterface;

/**
 * The model catalogue as the runtime sees it — Proposal 149 §69-§70.
 *
 * One reader for two consumers, because they read the same rows and would
 * otherwise need the same three guards twice:
 *
 *  - the **router** asks where it may retry (`fallbackFor`) and whether a hop
 *    target serves a model id (`serves`);
 *  - the **token budget** asks how large a model's context window and output
 *    budget are.
 *
 * The guards, shared by every answer:
 *
 *  1. **Unknown is not an error.** No table, no row, no value → null, and the
 *     caller keeps whatever behaviour it had before the catalogue existed. The
 *     catalogue can add a retry, drop an unusable model override on a hop, or
 *     replace a hardcoded limit; it never invents a refusal for an id it has
 *     never heard of.
 *  2. **Only servable rows count.** `active` and `preview` are routable;
 *     `deprecated` and `retired` are not. A retired target would turn one
 *     error into another.
 *  3. **Fail closed and report.** A query error returns null instead of
 *     throwing: a chat request is not the place to surface a schema problem.
 *
 * Provider vocabularies differ by one name — the router's NVIDIA client reports
 * `nvidia_nim`, the catalogue mirrors WordPress and stores `nvidia` — so the
 * alias is translated here rather than renaming either store.
 */
final class ModelCatalogueLookup implements ModelLimitCatalogueInterface, ModelRoutingCatalogueInterface
{
    /**
     * Router provider slug => catalogue provider slug.
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

    public function serves(string $provider, string $modelId): ?bool
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

            $known = ModelConfig::query()->where('model_id', $modelId)->exists();

            if (! $known) {
                // No opinion: a deployment name or a fine-tune must still be
                // sent, and an id the catalogue has never seen says nothing
                // about what a provider serves.
                return null;
            }

            $served = ModelConfig::query()
                ->whereIn('provider', array_values(array_unique([$aliased, $provider])))
                ->where('model_id', $modelId)
                ->whereIn('status', ModelConfig::SERVABLE_STATUSES)
                ->exists();
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        return $served;
    }

    public function contextWindow(string $model): ?int
    {
        return $this->limit($model, 'context_window');
    }

    public function maxOutputTokens(string $model): ?int
    {
        return $this->limit($model, 'max_output_tokens');
    }

    /**
     * The widest servable value for a limit column.
     *
     * The budget manager looks up by model id alone, and two providers can
     * publish the same id. The widest servable row wins: a limit that is too
     * low rejects requests the provider would have accepted, while a limit
     * that is too high only makes the pre-flight check less strict — the
     * provider still enforces its own.
     *
     * @param string $model  Model identifier.
     * @param string $column Catalogue column.
     */
    private function limit(string $model, string $column): ?int
    {
        $model = strtolower(trim($model));

        if ('' === $model) {
            return null;
        }

        try {
            if (! Schema::hasTable('nvoos_model_configs')) {
                return null;
            }

            $value = ModelConfig::query()
                ->whereRaw('lower(model_id) = ?', [$model])
                ->whereIn('status', ModelConfig::SERVABLE_STATUSES)
                ->max($column);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
