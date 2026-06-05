<?php
/**
 * Immutable content item entity.
 *
 * Framework-agnostic representation of a content item (post, page,
 * custom type, or any publishable entity). Used by ContentStoreInterface
 * and all tools that read content.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class ContentItem implements \JsonSerializable {

	/**
	 * @param int                               $id
	 * @param string                            $title
	 * @param string                            $content
	 * @param string                            $status    'publish', 'draft', 'private', 'pending', 'trash'
	 * @param string                            $type      'post', 'page', 'mcp_ai_assistant', etc.
	 * @param int                               $authorId
	 * @param \DateTimeImmutable                $createdAt
	 * @param \DateTimeImmutable                $updatedAt
	 * @param array<string, mixed>              $meta      Key-value metadata fields.
	 * @param array<string, array<int, string>> $taxonomy  Taxonomy slug → [term names].
	 * @param string|null                       $excerpt
	 * @param string|null                       $slug
	 */
	public function __construct(
		public int $id,
		public string $title,
		public string $content,
		public string $status,
		public string $type,
		public int $authorId,
		public \DateTimeImmutable $createdAt,
		public \DateTimeImmutable $updatedAt,
		public array $meta = array(),
		public array $taxonomy = array(),
		public ?string $excerpt = null,
		public ?string $slug = null,
	) {}

	/**
	 * Check if the item is publicly visible.
	 */
	public function isPublished(): bool {
		return 'publish' === $this->status;
	}

	/**
	 * Get a single meta field value, with a default fallback.
	 */
	public function getMetaValue( string $key, mixed $default = null ): mixed {
		return $this->meta[ $key ] ?? $default;
	}

	/**
	 * Get terms for a specific taxonomy.
	 *
	 * @return string[]
	 */
	public function getTerms( string $taxonomy ): array {
		return $this->taxonomy[ $taxonomy ] ?? array();
	}

	public function jsonSerialize(): array {
		return array(
			'id'         => $this->id,
			'title'      => $this->title,
			'content'    => $this->content,
			'status'     => $this->status,
			'type'       => $this->type,
			'author_id'  => $this->authorId,
			'created_at' => $this->createdAt->format( 'c' ),
			'updated_at' => $this->updatedAt->format( 'c' ),
			'meta'       => $this->meta,
			'taxonomy'   => $this->taxonomy,
			'excerpt'    => $this->excerpt,
			'slug'       => $this->slug,
		);
	}
}
