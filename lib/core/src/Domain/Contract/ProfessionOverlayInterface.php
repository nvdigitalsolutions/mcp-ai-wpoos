<?php

/**
 * Profession Overlay — optional read contract for tenant-authored professions.
 *
 * The catalogue has two tiers (Proposal 149 §170):
 *
 *  - **base** — code-owned reference data: a versioned directory of JSON
 *    documents, the WordPress profession CPT seed, or a licensed catalogue.
 *    Shared by every estate and never written by a runtime.
 *  - **overlay** — records an estate authored or overrode: `save_profession`'s
 *    destination, and the only tier a tenant can change.
 *
 * Reads merge the two with the overlay winning per slug. Writes are a separate
 * contract ({@see ProfessionWriteInterface}) because a host may serve the base
 * catalogue read-only while another provides a store.
 *
 * The split matters for the same reason {@see ProfessionWriteInterface} exists:
 * a decorator that merges two sources must not also be the thing that persists
 * one. Keeping the overlay read behind its own interface lets the merge live in
 * the shared package while the storage lives with the host — JSON files on a
 * bare library install, a tenant-scoped table in the app.
 *
 * @package  Nvoos\Core
 * @since    2.2.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * The tenant-owned half of the profession catalogue.
 *
 * @since 2.2.0
 */
interface ProfessionOverlayInterface
{
    /**
     * Every overlay record, keyed by normalised slug.
     *
     * Records carry the same shape the base repository returns from
     * `getBySlug()['profession']` (`id`, `slug`, `name`, `description`,
     * `category`, `role_description`, `expertise`, `warnings`,
     * `knowledge_base`, `default_tools`), so a reader can treat either tier
     * identically.
     *
     * @return array<string, array<string, mixed>> Slug => normalised record.
     */
    public function overlays(): array;

    /**
     * Opaque fingerprint of the overlay's current state.
     *
     * The merge decorator appends this to the base version to form
     * `catalogueVersion()`, so a write invalidates every cached catalogue read
     * without a purge — the same generational invalidation
     * {@see \Nvoos\Core\Infrastructure\Profession\CachedProfessionRepository}
     * performs for the base.
     *
     * Must return a different value after a write. Never empty.
     *
     * @return string Version string.
     */
    public function overlayVersion(): string;
}
