<?php
/**
 * Schema store interface — queries content type and taxonomy metadata.
 *
 * Framework-agnostic contract for introspecting the host platform's
 * content model (registered post types, taxonomies, post statuses,
 * supported features). Platform adapters implement this to wrap
 * WordPress's get_post_type_object(), Laravel's Schema registry, etc.
 *
 * @package Nvoos\Core
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\PostTypeSchema;
use Nvoos\Core\Domain\Entity\TaxonomySchema;

interface SchemaStoreInterface {

	/**
	 * Get the schema for a specific post type.
	 *
	 * @param string $type  Post type slug.
	 * @return PostTypeSchema|null  Null when the post type is not registered.
	 */
	public function getPostType( string $type ): ?PostTypeSchema;

	/**
	 * List all registered post types.
	 *
	 * @return PostTypeSchema[]
	 */
	public function listPostTypes(): array;

	/**
	 * Get the schema for a specific taxonomy.
	 *
	 * @param string $taxonomy  Taxonomy slug.
	 * @return TaxonomySchema|null  Null when not registered.
	 */
	public function getTaxonomy( string $taxonomy ): ?TaxonomySchema;

	/**
	 * List taxonomies registered for a given post type.
	 *
	 * @param string $postType  Post type slug.
	 * @return TaxonomySchema[]
	 */
	public function listTaxonomies( string $postType ): array;

	/**
	 * Get all available post statuses with their labels.
	 *
	 * @return array<string, string>  Status slug → label.
	 */
	public function getPostStatuses(): array;

	/**
	 * Check whether a post type supports a specific feature.
	 *
	 * @param string $postType  Post type slug.
	 * @param string $feature   Feature name (e.g., 'title', 'editor', 'thumbnail').
	 */
	public function postTypeSupports( string $postType, string $feature ): bool;
}
