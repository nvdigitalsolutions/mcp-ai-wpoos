<?php

/**
 * Command value object for creating a new content item.
 *
 * Encapsulates all required and optional fields for content creation.
 * The ContentStoreInterface::create() method consumes this.
 *
 * `taxonomyInput` is the item's **complete term set** for each taxonomy it
 * names, using the same rule as {@see UpdateContentCommand} so a caller can move
 * between create and update without the field changing meaning. On create the
 * item starts with no terms, so setting the whole set and replacing it are the
 * same operation.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class CreateContentCommand
{
    /**
     * @param string                  $title
     * @param string                  $type            Content type slug.
     * @param int                     $authorId        User ID of the author.
     * @param string                  $status          'publish', 'draft', 'private', 'pending'.
     * @param string                  $content         Raw content body.
     * @param string|null             $excerpt         Optional summary.
     * @param array<string, mixed>    $meta            Meta fields to set on creation.
     * @param array<string, string[]> $taxonomyInput   Taxonomy slug → complete term set (names or IDs).
     */
    public function __construct(
        public string $title,
        public string $type,
        public int $authorId,
        public string $status = 'publish',
        public string $content = '',
        public ?string $excerpt = null,
        public array $meta = array(),
        public array $taxonomyInput = array(),
    ) {
    }
}
