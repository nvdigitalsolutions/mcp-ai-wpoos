<?php

/**
 * Tenant resolver — the current estate, for package code.
 *
 * The application pins the estate in a host class that `lib/core` and the
 * package must not reference. Package code that needs to namespace a key by
 * estate (a cached team, for example) depends on this instead, and the host
 * supplies the answer.
 *
 * A bare package resolves to `default`, which is the same attribution the rest
 * of the runtime uses when no estate is pinned.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface TenantResolverInterface
{
    /**
     * The estate this operation acts for.
     */
    public function current(): string;
}
