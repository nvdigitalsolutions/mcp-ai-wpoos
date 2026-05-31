<?php
/**
 * Immutable content collection (paginated result set).
 *
 * Returned by ContentStoreInterface::query() to provide items,
 * total count, and pagination metadata in a single value object.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Entity;

final readonly class ContentCollection implements \JsonSerializable
{
    /**
     * @param ContentItem[] $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
    ) {}

    public function hasItems(): bool
    {
        return [] !== $this->items;
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->totalPages;
    }

    public function jsonSerialize(): array
    {
        return [
            'items'       => $this->items,
            'total'       => $this->total,
            'page'        => $this->page,
            'per_page'    => $this->perPage,
            'total_pages' => $this->totalPages,
        ];
    }
}
