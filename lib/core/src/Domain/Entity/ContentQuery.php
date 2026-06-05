<?php
/**
 * Immutable content query specification.
 *
 * Encapsulates all filtering, sorting, and pagination parameters
 * for querying a ContentStoreInterface.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class ContentQuery {

	/**
	 * @param string[]    $types      Filter by content types. Empty = all types.
	 * @param string[]    $statuses   Filter by statuses. Default: ['publish'].
	 * @param string|null $search     Free-text search across title and content.
	 * @param int|null    $authorId   Filter by author.
	 * @param int[]       $include    Specific IDs to include (post__in).
	 * @param int[]       $exclude    IDs to exclude (post__not_in).
	 * @param array       $metaQuery  Meta field filtering specification.
	 * @param array       $taxQuery   Taxonomy filtering specification.
	 * @param string      $orderBy    Sort field: 'date', 'title', 'modified', 'id'.
	 * @param string      $order      Sort direction: 'ASC' or 'DESC'.
	 * @param int         $page       1-based page number.
	 * @param int         $perPage    Items per page (1–100).
	 * @param int|null    $userId     User context for permission filtering.
	 */
	public function __construct(
		public array $types = array(),
		public array $statuses = array( 'publish' ),
		public ?string $search = null,
		public ?int $authorId = null,
		public array $include = array(),
		public array $exclude = array(),
		public array $metaQuery = array(),
		public array $taxQuery = array(),
		public string $orderBy = 'date',
		public string $order = 'DESC',
		public int $page = 1,
		public int $perPage = 20,
		public ?int $userId = null,
	) {}

	/**
	 * Create a query scoped to a specific user's accessible content.
	 */
	public function forUser( int $userId ): self {
		return new self(
			types: $this->types,
			statuses: $this->statuses,
			search: $this->search,
			authorId: $this->authorId,
			include: $this->include,
			exclude: $this->exclude,
			metaQuery: $this->metaQuery,
			taxQuery: $this->taxQuery,
			orderBy: $this->orderBy,
			order: $this->order,
			page: $this->page,
			perPage: $this->perPage,
			userId: $userId,
		);
	}

	/**
	 * Get the zero-based offset for SQL LIMIT/OFFSET.
	 */
	public function getOffset(): int {
		return ( $this->page - 1 ) * $this->perPage;
	}
}
