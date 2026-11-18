<?php

/**
 * Model limit catalogue contract for the oOS AI orchestration core.
 *
 * `TokenBudgetManager` has always carried a hardcoded snapshot of context
 * windows and output budgets. A snapshot is right on the day it is written and
 * wrong the moment a provider ships a model or changes a limit — and the
 * runtime already keeps a maintained catalogue of exactly these two numbers.
 *
 * This contract is the seam: the budget manager asks, the platform answers,
 * and an unknown model falls back to the snapshot. The lookup is by model id
 * alone because the pre-flight check runs inside a provider client, where the
 * payload is built, and the model — not the provider — is what the client
 * knows; implementations decide how to break a tie when two providers publish
 * the same id.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ModelLimitCatalogueInterface
{
    /**
     * The context window for $model, in tokens.
     *
     * Returns null when the model is unknown or the catalogue row carries no
     * limit, so the caller can fall back to its own table.
     *
     * @param string $model Model identifier.
     */
    public function contextWindow(string $model): ?int;

    /**
     * The maximum output tokens for $model.
     *
     * Returns null when the model is unknown or the catalogue row carries no
     * limit.
     *
     * @param string $model Model identifier.
     */
    public function maxOutputTokens(string $model): ?int;
}
