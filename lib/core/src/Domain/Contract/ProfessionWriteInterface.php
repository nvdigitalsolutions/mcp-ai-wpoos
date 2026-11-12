<?php
/**
 * Profession Write — optional domain contract.
 *
 * Professions are reference data, and not every runtime lets an application
 * write them: the Laravel catalogue is code-owned and read-only, while
 * WordPress stores professions in a CPT with an admin editor. Splitting the
 * write path into its own interface keeps `ProfessionRepositoryInterface`
 * honest for both, instead of forcing every adapter to implement a `save()`
 * it cannot honour.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Optional write capability for a profession catalogue.
 *
 * @since 2.0.0
 */
interface ProfessionWriteInterface
{
    /**
     * Create or update a profession.
     *
     * @param array<string, mixed> $data Profession fields. `slug` and `title`
     *                                   are required; `description`, `category`,
     *                                   `role_description`, `expertise`,
     *                                   `warnings`, `knowledge_base` and
     *                                   `default_tools` are optional.
     *
     * @return array{saved: bool, action?: string, profession?: array<string, mixed>, error?: string}
     *         `action` is `created` or `updated`.
     */
    public function save(array $data): array;
}
