<?php
/**
 * WordPress adapter: SchemaStoreInterface implementation.
 *
 * Wraps WordPress schema introspection functions (get_post_type_object,
 * get_object_taxonomies, get_post_stati, post_type_supports) behind
 * the framework-agnostic SchemaStoreInterface.
 *
 * @package Nvoos\WordPress
 * @since   1.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Entity\PostTypeSchema;
use Nvoos\Core\Domain\Entity\TaxonomySchema;

class SchemaStore implements SchemaStoreInterface {

	public function getPostType( string $type ): ?PostTypeSchema {
		if ( '' === $type ) {
			return null;
		}

		$pto = \get_post_type_object( \sanitize_key( $type ) );

		if ( ! $pto instanceof \WP_Post_Type ) {
			return null;
		}

		$labels       = $this->extractLabels( $pto );
		$capabilities = $this->extractCapabilities( $pto );
		$supports     = $this->extractSupports( $type );
		$statuses     = $this->extractStatuses();

		return new PostTypeSchema(
			slug: $pto->name,
			label: $pto->label ?? $pto->name,
			description: $pto->description ?? '',
			isPublic: (bool) ( $pto->public ?? false ),
			isHierarchical: (bool) ( $pto->hierarchical ?? false ),
			hasArchive: (bool) ( $pto->has_archive ?? false ),
			showInRest: (bool) ( $pto->show_in_rest ?? false ),
			restBase: $pto->rest_base ?? null,
			labels: $labels,
			capabilities: $capabilities,
			supports: $supports,
			statuses: $statuses,
		);
	}

	public function listPostTypes(): array {
		$types  = \get_post_types( array(), 'objects' );
		$result = array();

		foreach ( $types as $type ) {
			if ( $type instanceof \WP_Post_Type ) {
				$result[] = $this->getPostType( $type->name );
			}
		}

		return array_filter( $result );
	}

	public function getTaxonomy( string $taxonomy ): ?TaxonomySchema {
		if ( '' === $taxonomy ) {
			return null;
		}

		$tax = \get_taxonomy( \sanitize_key( $taxonomy ) );

		if ( ! $tax instanceof \WP_Taxonomy ) {
			return null;
		}

		return new TaxonomySchema(
			slug: $tax->name,
			label: $tax->label ?? $tax->name,
			isHierarchical: (bool) ( $tax->hierarchical ?? false ),
			isPublic: (bool) ( $tax->public ?? false ),
			description: $tax->description ?? '',
		);
	}

	public function listTaxonomies( string $postType ): array {
		if ( '' === $postType ) {
			return array();
		}

		$taxonomies = \get_object_taxonomies( \sanitize_key( $postType ), 'objects' );
		$result     = array();

		foreach ( $taxonomies as $tax ) {
			if ( $tax instanceof \WP_Taxonomy ) {
				$schema = $this->getTaxonomy( $tax->name );
				if ( null !== $schema ) {
					$result[] = $schema;
				}
			}
		}

		return $result;
	}

	public function getPostStatuses(): array {
		$statuses = array();
		$all      = \get_post_stati( array(), 'objects' );
		$excluded = array( 'auto-draft', 'inherit' );

		foreach ( $all as $slug => $obj ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			$statuses[ $slug ] = $obj->label ?? $slug;
		}

		return $statuses;
	}

	public function postTypeSupports( string $postType, string $feature ): bool {
		if ( '' === $postType || '' === $feature ) {
			return false;
		}

		return (bool) \post_type_supports( \sanitize_key( $postType ), \sanitize_key( $feature ) );
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * @return array<string, string>
	 */
	private function extractLabels( \WP_Post_Type $pto ): array {
		$labelMap = array(
			'name'               => 'name',
			'singular_name'      => 'singular_name',
			'add_new_item'       => 'add_new_item',
			'edit_item'          => 'edit_item',
			'view_item'          => 'view_item',
			'view_items'         => 'view_items',
			'search_items'       => 'search_items',
			'not_found'          => 'not_found',
			'not_found_in_trash' => 'not_found_in_trash',
			'all_items'          => 'all_items',
			'archives'           => 'archives',
		);

		$labels = array();
		foreach ( $labelMap as $prop => $key ) {
			if ( isset( $pto->labels->$prop ) ) {
				$labels[ $key ] = $pto->labels->$prop;
			}
		}

		return $labels;
	}

	/**
	 * @return array<string, string>
	 */
	private function extractCapabilities( \WP_Post_Type $pto ): array {
		$caps = array();
		if ( isset( $pto->cap ) ) {
			foreach ( (array) $pto->cap as $key => $value ) {
				if ( is_string( $value ) ) {
					$caps[ $key ] = $value;
				}
			}
		}
		return $caps;
	}

	/**
	 * @return string[]
	 */
	private function extractSupports( string $postType ): array {
		$allFeatures = array(
			'title',
			'editor',
			'author',
			'thumbnail',
			'excerpt',
			'trackbacks',
			'custom-fields',
			'comments',
			'revisions',
			'page-attributes',
			'post-formats',
		);

		$supports = array();
		foreach ( $allFeatures as $feature ) {
			if ( \post_type_supports( $postType, $feature ) ) {
				$supports[] = $feature;
			}
		}

		return $supports;
	}

	/**
	 * @return array<string, string>
	 */
	private function extractStatuses(): array {
		return $this->getPostStatuses();
	}
}
