<?php

/**
 * Routing metrics contract for the oOS AI orchestration core.
 *
 * A model fallback is a silent success: the caller gets an answer and has no
 * way to know it came from a different model than the one it asked for. The
 * response marker (`_nvoos_routing`) tells the caller, but nothing counts.
 *
 * This is the counting seam. One method, called once per fallback attempt with
 * the outcome, so a store can answer "how often is this happening, and between
 * which models" without the router knowing what a store is.
 *
 * Implementations must never throw: a metrics write is not worth failing a
 * chat over, and the router does not wrap the call.
 *
 * @package Nvoos\Core
 * @since   1.7.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface RoutingMetricsInterface
{
    /**
     * Record one model-fallback attempt and its outcome.
     *
     * @param string $provider  Provider slug the retry ran on.
     * @param string $fromModel Model that failed.
     * @param string $toModel   Model that was tried instead.
     * @param bool   $succeeded Whether the retry produced a usable result.
     */
    public function recordModelFallback(
        string $provider,
        string $fromModel,
        string $toModel,
        bool $succeeded
    ): void;
}
