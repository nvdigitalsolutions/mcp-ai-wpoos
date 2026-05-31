<?php
/**
 * Command value object for creating a new content item.
 *
 * Encapsulates all required and optional fields for content creation.
 * The ContentStoreInterface::create() method consumes this.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Entity;

final readonly class CreateContentCommand
{
    /**
     * @param string                    $title
     * @param string                    $type            Content type slug.
     * @param string                    $status          'publish', 'draft', 'private', 'pending'.
     * @param string                    $content         Raw content body.
     * @param int                       $authorId        User ID of the author.
     * @param string|null               $excerpt         Optional summary.
     * @param array<string, mixed>      $meta            Meta fields to set on creation.
     * @param array<string, string[]>   $taxonomyInput   Taxonomy slug → [term names or IDs].
     */
    public function __construct(
        public string $title,
        public string $type,
        public string $status = 'publish',
        public string $content = '',
        public int $authorId,
        public ?string $excerpt = null,
        public array $meta = [],
        public array $taxonomyInput = [],
    ) {}
}
