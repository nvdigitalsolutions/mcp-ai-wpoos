<?php

/**
 * Command value object for updating an existing content item.
 *
 * Only non-null fields are applied — null fields remain unchanged.
 * Meta fields are merged with existing meta, not replaced.
 *
 * ## Taxonomies are replaced, one taxonomy at a time
 *
 * `taxonomyInput` is the **complete desired term set** for each taxonomy it
 * names — an instruction to replace, not to append. Updating an item whose
 * categories are `[News, Tech]` with `[ 'category' => [ 'News' ] ]` leaves it
 * with `News` alone.
 *
 * This follows `wp_set_post_terms( $append = false )`, the WordPress default,
 * where keeping existing terms is the opt-in. It also follows RFC 7396 (JSON
 * Merge Patch), whose rule for arrays is replacement rather than concatenation,
 * and JSON:API, where a PATCH to a to-many relationship replaces it.
 *
 * Two consequences, implemented by every host:
 *
 * - An **empty outer array** means "no taxonomy change". It is the array
 *   equivalent of a null field.
 * - A taxonomy **named with an empty list** clears it. The key is present, so
 *   the caller has stated what the set should be: nothing.
 *
 * Appending is expressed by the caller, not by the store: send the union of the
 * current terms and the new ones. `auto_categorize_content` does exactly that,
 * because "add without removing" is its own promise to the operator.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class UpdateContentCommand
{
    /**
     * @param int                     $userId          Who is making the update.
     * @param string|null             $title
     * @param string|null             $content
     * @param string|null             $status
     * @param string|null             $excerpt
     * @param array<string, mixed>    $meta            Meta to merge (not replace).
     * @param array<string, string[]> $taxonomyInput   Complete term set per taxonomy; a named empty list clears it.
     */
    public function __construct(
        public int $userId,
        public ?string $title = null,
        public ?string $content = null,
        public ?string $status = null,
        public ?string $excerpt = null,
        public array $meta = array(),
        public array $taxonomyInput = array(),
    ) {
    }
}
