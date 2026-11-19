<?php

/**
 * Model routing catalogue contract for the oOS AI orchestration core.
 *
 * Two routing questions, one store:
 *
 *  - **Where may I retry?** A provider failing is not the only failure worth
 *    routing around: a single model can be rate-limited, retired mid-day, or
 *    erroring while its provider is healthy. `fallbackFor()` names a servable
 *    sibling on the same provider.
 *  - **Does this provider serve this model?** An ordered fallback chain is a
 *    list of provider slugs, and carrying the primary model's id to a provider
 *    that does not publish it turns a failover into a second failure.
 *    `serves()` answers that without guessing.
 *
 * Both answers have a third state — `null` — meaning "the catalogue has no
 * opinion". Neither method is a whitelist: a model the catalogue has never
 * heard of is still sent, and a deployment with no catalogue pays nothing.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ModelRoutingCatalogueInterface
{
    /**
     * The model to retry on the same provider when $modelId fails.
     *
     * Returns null when the catalogue has no row for the pair, the row names
     * no fallback, the fallback resolves to the same id, or the fallback is
     * not currently servable. Callers treat null as "no model-level retry" and
     * fall through to provider-level failover.
     *
     * @param string $provider Provider slug (`anthropic`, `openai`, …).
     * @param string $modelId  Model identifier that just failed.
     * @return string|null Fallback model id, or null.
     */
    public function fallbackFor(string $provider, string $modelId): ?string;

    /**
     * Whether $provider publishes $modelId.
     *
     * Three-state on purpose:
     *
     *  - `true`  — the catalogue holds the pair and the row is servable;
     *  - `false` — the catalogue knows the model id (it has a row for it under
     *              some provider) and has no servable row for this provider, so
     *              sending the id there would be a request the provider cannot
     *              answer;
     *  - `null`  — no opinion. The id is unknown to the catalogue, which says
     *              nothing about whether the provider serves it.
     *
     * Only `false` should change behaviour: a router drops the model override
     * on that hop and lets the provider apply its own default. `null` must be
     * treated as "keep the request as it is".
     *
     * @param string $provider Provider slug.
     * @param string $modelId  Model identifier.
     */
    public function serves(string $provider, string $modelId): ?bool;
}
