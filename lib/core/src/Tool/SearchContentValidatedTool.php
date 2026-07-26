<?php
/**
 * Search Content (Validated) tool — searches content with taxonomy and meta filters.
 *
 * Enhanced search supporting taxonomy queries and post-meta filtering.
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Search_Content_Validated.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;

class SearchContentValidatedTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'search_content_validated';
	}

	public function getName(): string {
		return 'Search Content (Validated)';
	}

	public function getDescription(): string {
		return 'Search published content by keyword, post type, taxonomy terms, and metadata filters.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search_term'       => array(
					'type'        => 'string',
					'description' => 'Keyword or phrase to search for.',
				),
				'post_type'         => array(
					'type'        => 'string',
					'description' => 'Limit results to a specific content type. Use "any" to search across all public types.',
					'default'     => 'any',
				),
				'limit'             => array(
					'type'        => 'integer',
					'description' => 'Maximum number of results (1-50). Default: 10.',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'taxonomy_filters'  => array(
					'type'        => 'array',
					'description' => 'Optional taxonomy filters. Each filter requires taxonomy and terms.',
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'taxonomy', 'terms' ),
						'properties'           => array(
							'taxonomy' => array(
								'type'        => 'string',
								'description' => 'Taxonomy slug (e.g. category, post_tag).',
							),
							'terms'    => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'minItems'    => 1,
								'description' => 'List of term slugs to match.',
							),
							'operator' => array(
								'type'        => 'string',
								'enum'        => array( 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS' ),
								'description' => 'Comparison operator. Default: IN.',
								'default'     => 'IN',
							),
							'field'    => array(
								'type'        => 'string',
								'enum'        => array( 'slug', 'name', 'term_id', 'term_taxonomy_id' ),
								'description' => 'Term field to query against. Default: slug.',
								'default'     => 'slug',
							),
						),
						'additionalProperties' => false,
					),
				),
				'taxonomy_relation' => array(
					'type'        => 'string',
					'enum'        => array( 'AND', 'OR' ),
					'description' => 'Logical relation between multiple taxonomy filters. Default: AND.',
					'default'     => 'AND',
				),
				'meta_filters'      => array(
					'type'        => 'array',
					'description' => 'Optional post meta filters.',
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'key', 'value' ),
						'properties'           => array(
							'key'     => array(
								'type'        => 'string',
								'description' => 'Meta key to compare.',
							),
							'value'   => array(
								'type'        => 'string',
								'description' => 'Meta value to compare.',
							),
							'compare' => array(
								'type'        => 'string',
								'enum'        => array( '=', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' ),
								'description' => 'Comparison operator. Default: =.',
								'default'     => '=',
							),
							'type'    => array(
								'type'        => 'string',
								'enum'        => array( 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' ),
								'description' => 'Data type for comparison casting.',
							),
						),
						'additionalProperties' => false,
					),
				),
				'meta_relation'     => array(
					'type'        => 'string',
					'enum'        => array( 'AND', 'OR' ),
					'description' => 'Logical relation between multiple meta filters. Default: AND.',
					'default'     => 'AND',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$searchTerm = $this->stringParam( $arguments, 'search_term' );
		$postType   = $this->stringParam( $arguments, 'post_type', 'any' );
		$limit      = $this->intParam( $arguments, 'limit', 10 );
		$limit      = \max( 1, \min( 50, $limit ) );

		$types = 'any' === $postType ? array() : array( $postType );

		// Build taxonomy query from filters.
		$taxQuery = $this->buildTaxQuery( $arguments );

		// Build meta query from filters.
		$metaQuery = $this->buildMetaQuery( $arguments );

		// Require at least one filter criterion.
		if ( '' === $searchTerm && array() === $taxQuery && array() === $metaQuery ) {
			return $this->errors->validationFailed(
				'Provide a search term, taxonomy filter, or meta filter.',
				array( 'search_term' => array( 'At least one search criterion is required.' ) ),
			);
		}

		$query = new ContentQuery(
			types: $types,
			statuses: array( 'publish' ),
			search: '' !== $searchTerm ? $searchTerm : null,
			perPage: $limit,
			page: 1,
			metaQuery: $metaQuery,
			taxQuery: $taxQuery,
			userId: $context['user_id'] ?? null,
		);

		$result = $this->content->query( $query );

		if ( ! $result->hasItems() ) {
			return $this->emptyResult(
				'' !== $searchTerm
					? "No results found for: {$searchTerm}"
					: 'No results found for the given filters.',
			);
		}

		$items = array_map(
			fn( $i ) => array(
				'id'         => $i->id,
				'title'      => $i->title,
				'type'       => $i->type,
				'excerpt'    => $i->excerpt,
				'created_at' => $i->createdAt->format( 'c' ),
				'slug'       => $i->slug,
			),
			$result->items,
		);

		return $this->collection(
			"Found {$result->total} result(s).",
			$items,
			$result->total,
		);
	}

	/**
	 * Build a tax_query-compatible array from taxonomy_filters parameter.
	 */
	private function buildTaxQuery( array $arguments ): array {
		$filters = $this->arrayParam( $arguments, 'taxonomy_filters' );

		if ( array() === $filters ) {
			return array();
		}

		$relation = \strtoupper( $this->stringParam( $arguments, 'taxonomy_relation', 'AND' ) );

		$clauses = array();

		foreach ( $filters as $filter ) {
			if ( ! \is_array( $filter ) ) {
				continue;
			}

			$taxonomy = \trim( (string) ( $filter['taxonomy'] ?? '' ) );
			if ( '' === $taxonomy ) {
				continue;
			}

			$terms    = (array) ( $filter['terms'] ?? array() );
			$operator = \strtoupper( \trim( (string) ( $filter['operator'] ?? 'IN' ) ) );
			$field    = \strtolower( \trim( (string) ( $filter['field'] ?? 'slug' ) ) );

			if ( ! \in_array( $operator, array( 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$operator = 'IN';
			}

			if ( ! \in_array( $field, array( 'slug', 'name', 'term_id', 'term_taxonomy_id' ), true ) ) {
				$field = 'slug';
			}

			$clause = array(
				'taxonomy' => $taxonomy,
				'field'    => $field,
				'operator' => $operator,
			);

			if ( ! \in_array( $operator, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$sanitized = array_filter(
					\array_map( '\strval', $terms ),
					static fn( string $t ): bool => '' !== \trim( $t ),
				);

				if ( array() === $sanitized ) {
					continue;
				}

				$clause['terms'] = \array_values( $sanitized );
			}

			$clauses[] = $clause;
		}

		if ( array() === $clauses ) {
			return array();
		}

		if ( \count( $clauses ) > 1 || 'AND' !== $relation ) {
			\array_unshift( $clauses, array( 'relation' => $relation ) );
		}

		return $clauses;
	}

	/**
	 * Build a meta_query-compatible array from meta_filters parameter.
	 */
	private function buildMetaQuery( array $arguments ): array {
		$filters = $this->arrayParam( $arguments, 'meta_filters' );

		if ( array() === $filters ) {
			return array();
		}

		$relation = \strtoupper( $this->stringParam( $arguments, 'meta_relation', 'AND' ) );

		$clauses = array();

		foreach ( $filters as $filter ) {
			if ( ! \is_array( $filter ) ) {
				continue;
			}

			$key = \trim( (string) ( $filter['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$compare = \strtoupper( \trim( (string) ( $filter['compare'] ?? '=' ) ) );
			if ( ! \in_array( $compare, array( '=', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$compare = '=';
			}

			$clause = array(
				'key'     => $key,
				'compare' => $compare,
			);

			if ( \in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				// No value needed.
			} elseif ( \in_array( $compare, array( 'IN', 'NOT IN' ), true ) ) {
				$value = $filter['value'] ?? '';
				$value = \is_array( $value ) ? $value : array( $value );
				$value = \array_filter( \array_map( '\strval', $value ) );
				if ( array() === $value ) {
					continue;
				}
				$clause['value'] = \array_values( $value );
			} else {
				$value = \trim( (string) ( $filter['value'] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				$clause['value'] = $value;
			}

			if ( ! empty( $filter['type'] ) ) {
				$type = \strtoupper( \trim( (string) $filter['type'] ) );
				if ( \in_array( $type, array( 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' ), true ) ) {
					$clause['type'] = $type;
				}
			}

			$clauses[] = $clause;
		}

		if ( array() === $clauses ) {
			return array();
		}

		if ( \count( $clauses ) > 1 || 'AND' !== $relation ) {
			\array_unshift( $clauses, array( 'relation' => $relation ) );
		}

		return $clauses;
	}
}
