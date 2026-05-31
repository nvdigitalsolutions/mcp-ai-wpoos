<?php
/**
 * Content store contract for the oOS AI orchestration core.
 *
 * Abstracts CRUD operations on content items (posts, pages, custom types)
 * so that tools and the agentic loop never depend on WordPress functions
 * (get_post, wp_insert_post, WP_Query) or any other framework's ORM.
 *
 * Each platform adapter implements this interface:
 *  - WordPress: wraps get_post / wp_insert_post / WP_Query
 *  - Laravel:   wraps Eloquent models
 *  - Craft CMS:  wraps Element queries
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Contract;

use Oos\Core\Domain\Entity\ContentCollection;
use Oos\Core\Domain\Entity\ContentItem;
use Oos\Core\Domain\Entity\ContentQuery;
use Oos\Core\Domain\Entity\CreateContentCommand;
use Oos\Core\Domain\Entity\UpdateContentCommand;

interface ContentStoreInterface
{
    /**
     * Find a single content item by ID.
     *
     * @param int      $id      Content item identifier.
     * @param int|null $userId  Optional user context for permission filtering.
     *                          When null, permission checks are skipped (system context).
     *
     * @return ContentItem|null  Null when not found, not accessible, or wrong type.
     */
    public function find(int $id, ?int $userId = null): ?ContentItem;

    /**
     * Query content with filtering and pagination.
     *
     * @return ContentCollection  Collection with items, total count, and pagination metadata.
     */
    public function query(ContentQuery $query): ContentCollection;

    /**
     * Create a new content item.
     *
     * @return ContentItem  The newly created item with its assigned ID.
     *
     * @throws \Oos\Core\Domain\Error\AccessDeniedException  When user lacks permission.
     * @throws \Oos\Core\Domain\Error\ValidationException    When data fails validation.
     */
    public function create(CreateContentCommand $command): ContentItem;

    /**
     * Update an existing content item.
     *
     * Only the fields present (non-null) in the command are updated.
     * Meta fields are merged, not replaced.
     *
     * @return ContentItem  The updated item.
     *
     * @throws \Oos\Core\Domain\Error\NotFoundException       When the item does not exist.
     * @throws \Oos\Core\Domain\Error\AccessDeniedException   When user lacks permission.
     */
    public function update(int $id, UpdateContentCommand $command): ContentItem;

    /**
     * Delete a content item permanently.
     *
     * @throws \Oos\Core\Domain\Error\NotFoundException       When the item does not exist.
     * @throws \Oos\Core\Domain\Error\AccessDeniedException   When user lacks permission.
     */
    public function delete(int $id, int $userId): void;

    /**
     * Get all metadata for a content item.
     *
     * @return array<string, mixed>  Key-value pairs of all meta fields.
     */
    public function getMeta(int $id): array;

    /**
     * Get taxonomy terms assigned to a content item.
     *
     * @return array<string, array<int, string>>  Taxonomy slug → [term names].
     */
    public function getTaxonomyTerms(int $id): array;

    /**
     * Check if a user can perform an operation on a content item.
     *
     * @param string $operation  One of: 'read', 'edit', 'delete'.
     */
    public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool;
}
