<?php
/**
 * Command value object for updating an existing content item.
 *
 * Only non-null fields are applied — null fields remain unchanged.
 * Meta fields are merged with existing meta, not replaced.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Entity;

final readonly class UpdateContentCommand
{
    /**
     * @param string|null               $title
     * @param string|null               $content
     * @param string|null               $status
     * @param string|null               $excerpt
     * @param array<string, mixed>      $meta            Meta to merge (not replace).
     * @param array<string, string[]>   $taxonomyInput
     * @param int                       $userId           Who is making the update.
     */
    public function __construct(
        public ?string $title = null,
        public ?string $content = null,
        public ?string $status = null,
        public ?string $excerpt = null,
        public array $meta = [],
        public array $taxonomyInput = [],
        public int $userId,
    ) {}
}
