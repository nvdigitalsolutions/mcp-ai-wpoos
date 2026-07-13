<?php
/**
 * Immutable taxonomy schema entity.
 *
 * Framework-agnostic representation of a taxonomy's registration metadata.
 * Returned by SchemaStoreInterface::getTaxonomy().
 *
 * @package Nvoos\Core
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class TaxonomySchema implements \JsonSerializable {

	/**
	 * @param string $slug          Taxonomy slug (e.g., 'category', 'post_tag').
	 * @param string $label         Human-readable label.
	 * @param bool   $isHierarchical Whether terms can have parent/child relationships.
	 * @param bool   $isPublic      Whether the taxonomy is publicly visible.
	 * @param string $description   Optional description.
	 */
	public function __construct(
		public string $slug,
		public string $label,
		public bool $isHierarchical = false,
		public bool $isPublic = true,
		public string $description = '',
	) {}

	public function jsonSerialize(): array {
		return array(
			'slug'            => $this->slug,
			'label'           => $this->label,
			'is_hierarchical' => $this->isHierarchical,
			'is_public'       => $this->isPublic,
			'description'     => $this->description,
		);
	}
}
