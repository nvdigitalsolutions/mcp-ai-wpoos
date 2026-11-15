<?php
/**
 * Laravel adapter: SchemaStoreInterface implementation.
 *
 * Laravel has no registry equivalent to register_post_type(), so the content
 * model is declared in `config/oos.content_types` / `oos.taxonomies` and
 * adapted into the same PostTypeSchema / TaxonomySchema the WordPress adapter
 * returns. Tools therefore introspect either runtime identically.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Contracts\Config\Repository;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Entity\PostTypeSchema;
use Nvoos\Core\Domain\Entity\TaxonomySchema;

class SchemaStore implements SchemaStoreInterface {

	/**
	 * @var array<string, mixed>
	 */
	private array $contentTypes;

	/**
	 * @var array<string, mixed>
	 */
	private array $statuses;

	/**
	 * @var array<string, mixed>
	 */
	private array $taxonomies;

	/**
	 * Configuration is read once per instance so a long-lived worker does not
	 * observe a half-applied config change mid-request.
	 */
	public function __construct( Repository $config ) {
		$this->contentTypes = (array) $config->get( 'oos.content_types', array() );
		$this->statuses     = (array) $config->get( 'oos.content_statuses', array() );
		$this->taxonomies   = (array) $config->get( 'oos.taxonomies', array() );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPostType( string $type ): ?PostTypeSchema {
		$key = $this->key( $type );

		if ( '' === $key || ! isset( $this->contentTypes[ $key ] ) || ! is_array( $this->contentTypes[ $key ] ) ) {
			return null;
		}

		$definition = $this->contentTypes[ $key ];

		return new PostTypeSchema(
			slug: $key,
			label: (string) ( $definition['label'] ?? $key ),
			description: (string) ( $definition['description'] ?? '' ),
			isPublic: (bool) ( $definition['public'] ?? true ),
			isHierarchical: (bool) ( $definition['hierarchical'] ?? false ),
			hasArchive: (bool) ( $definition['has_archive'] ?? false ),
			showInRest: (bool) ( $definition['show_in_rest'] ?? false ),
			restBase: isset( $definition['rest_base'] ) ? (string) $definition['rest_base'] : null,
			labels: (array) ( $definition['labels'] ?? array() ),
			capabilities: (array) ( $definition['capabilities'] ?? array() ),
			supports: $this->stringList( $definition['supports'] ?? array() ),
			statuses: $this->stringList( $definition['statuses'] ?? array_keys( $this->statuses ) ),
			metaFields: (array) ( $definition['meta_fields'] ?? array() ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return PostTypeSchema[]
	 */
	public function listPostTypes(): array {
		$result = array();

		foreach ( array_keys( $this->contentTypes ) as $type ) {
			$schema = $this->getPostType( (string) $type );

			if ( null !== $schema ) {
				$result[] = $schema;
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getTaxonomy( string $taxonomy ): ?TaxonomySchema {
		$key = $this->key( $taxonomy );

		if ( '' === $key || ! isset( $this->taxonomies[ $key ] ) || ! is_array( $this->taxonomies[ $key ] ) ) {
			return null;
		}

		$definition = $this->taxonomies[ $key ];

		return new TaxonomySchema(
			slug: $key,
			label: (string) ( $definition['label'] ?? $key ),
			isHierarchical: (bool) ( $definition['hierarchical'] ?? false ),
			isPublic: (bool) ( $definition['public'] ?? true ),
			description: (string) ( $definition['description'] ?? '' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return TaxonomySchema[]
	 */
	public function listTaxonomies( string $postType ): array {
		$key = $this->key( $postType );

		if ( '' === $key ) {
			return array();
		}

		$result = array();

		foreach ( $this->taxonomies as $slug => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$attached = array_map(
				fn ( mixed $type ): string => $this->key( (string) $type ),
				(array) ( $definition['post_types'] ?? array() )
			);

			if ( ! in_array( $key, $attached, true ) ) {
				continue;
			}

			$schema = $this->getTaxonomy( (string) $slug );

			if ( null !== $schema ) {
				$result[] = $schema;
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, string>
	 */
	public function getPostStatuses(): array {
		$statuses = array();

		foreach ( $this->statuses as $slug => $label ) {
			$statuses[ (string) $slug ] = (string) $label;
		}

		return $statuses;
	}

	/**
	 * {@inheritdoc}
	 */
	public function postTypeSupports( string $postType, string $feature ): bool {
		$schema = $this->getPostType( $postType );

		return null !== $schema && $schema->supports( $this->key( $feature ) );
	}

	/**
	 * Normalise a slug the way WordPress's sanitize_key() does.
	 */
	private function key( string $value ): string {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $value ) );
	}

	/**
	 * @param mixed $value Candidate list.
	 * @return string[]
	 */
	private function stringList( mixed $value ): array {
		return array_values(
			array_filter(
				(array) $value,
				static fn ( mixed $item ): bool => is_string( $item ) && '' !== $item
			)
		);
	}
}
