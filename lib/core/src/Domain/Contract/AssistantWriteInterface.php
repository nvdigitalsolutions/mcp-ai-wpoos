<?php

/**
 * Creating an assistant, as a host capability.
 *
 * The Laravel runtime already had one write path — `App\Services\AssistantWriter`
 * — created because the REST controller and the `/admin` resource would
 * otherwise have been two implementations of the same five invariants. This
 * contract is the second transport onto that path, not a second writer: a tool
 * that saved the model directly would be the drift the writer exists to prevent.
 *
 * ## What the host owns, and the contract does not restate
 *
 * - **The estate.** `tenant_id` is not a parameter, because an assistant is
 *   estate-owned configuration and a payload that could name its estate would be
 *   a cross-estate write primitive. The host takes it from its ambient scope.
 * - **The slug.** Derived from the title (or a supplied slug), made unique
 *   *within the estate*, because the database index is `(tenant_id, slug)`.
 * - **The status.** A created assistant is a draft. Publishing is an operator
 *   action on a surface with a human in front of it.
 * - **`is_default`.** Not a field here: promoting one assistant to estate default
 *   clears the flag on every other assistant in the estate, which is far more
 *   blast radius than a tool call should carry.
 *
 * ## Return shape
 *
 * Success reports the fields a caller needs to continue: the id, the slug and the
 * status the host actually applied (not the one it was asked for). Failure is
 * `success: false` plus a message safe to show a model — the host logs the
 * exception; it does not leak a driver error into a transcript.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface AssistantWriteInterface
{
    /**
     * Create a draft assistant in the host's current estate.
     *
     * @param array{
     *     title: string,
     *     description?: string,
     *     system_prompt?: string,
     *     provider?: string,
     *     model?: string,
     *     temperature?: float,
     *     max_tokens?: int,
     *     tools?: list<string>
     * } $draft
     *
     * @return array{success: bool, assistant_id?: int, slug?: string, status?: string, error?: string}
     */
    public function create(array $draft): array;
}
