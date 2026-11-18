<?php

/**
 * Model fallback catalogue contract for the oOS AI orchestration core.
 *
 * A provider failing is not the only failure worth routing around: a single
 * model can be rate-limited, retired mid-day, or erroring while its provider
 * is healthy. This contract exposes the one catalogue fact the router needs
 * to retry on the same provider before it gives up on that provider's slack —
 * a servable sibling model.
 *
 * It is deliberately not a whitelist. `fallbackFor()` returns null for any
 * model the catalogue does not know, and the router keeps behaving exactly as
 * it did before a catalogue was attached: unknown models are still sent, and
 * a deployment with no catalogue pays nothing.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ModelFallbackCatalogueInterface
{
    /**
     * The model to retry on the same provider when $modelId fails.
     *
     * Returns null when the catalogue has no row for the pair, the row names
     * no fallback, the fallback resolves to the same id, or the fallback is
     * not currently servable. Callers treat null as "no model-level retry" and
     * fall through to provider-level failover.
     *
     * @param string $provider Provider slug (`anthropic`, `openai`, …). The
     *                         caller's own vocabulary; implementations
     *                         reconcile aliases with their store.
     * @param string $modelId  Model identifier that just failed.
     * @return string|null Fallback model id, or null.
     */
    public function fallbackFor(string $provider, string $modelId): ?string;
}
