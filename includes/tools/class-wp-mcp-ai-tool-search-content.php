<?php
/**
 * Tool that searches WordPress content with taxonomy and meta filters.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches published content using WP_Query with optional filters.
 */
class WP_MCP_AI_Tool_Search_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Content', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search published posts by keyword, post type, taxonomy terms, and metadata.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search_term'       => array(
					'type'        => 'string',
					'description' => __( 'Keyword or phrase to search for.', 'wp-mcp-ai' ),
				),
				'post_type'         => array(
					'type'        => 'string',
					'description' => __( 'Limit results to a specific post type. Use "any" to search across public types.', 'wp-mcp-ai' ),
					'default'     => 'any',
				),
				'limit'             => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'taxonomy_filters'  => array(
					'type'        => 'array',
					'description' => __( 'Optional taxonomy filters. Each filter requires a taxonomy name and one or more terms.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'taxonomy', 'terms' ),
						'properties'           => array(
							'taxonomy' => array(
								'type'        => 'string',
								'description' => __( 'Taxonomy to filter by (e.g. category, post_tag).', 'wp-mcp-ai' ),
							),
							'terms'    => array(
								'type'        => 'array',
								'items'       => array(
									'type' => 'string',
								),
								'minItems'    => 1,
								'description' => __( 'List of terms to match. Accepts slugs by default.', 'wp-mcp-ai' ),
							),
							'operator' => array(
								'type'        => 'string',
								'enum'        => array( 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS' ),
								'description' => __( 'Comparison operator.', 'wp-mcp-ai' ),
								'default'     => 'IN',
							),
							'field'    => array(
								'type'        => 'string',
								'enum'        => array( 'slug', 'name', 'term_id', 'term_taxonomy_id' ),
								'description' => __( 'Term field to query against.', 'wp-mcp-ai' ),
								'default'     => 'slug',
							),
						),
						'additionalProperties' => false,
					),
				),
				'taxonomy_relation' => array(
					'type'        => 'string',
					'enum'        => array( 'AND', 'OR' ),
					'description' => __( 'Logical relation between multiple taxonomy filters.', 'wp-mcp-ai' ),
					'default'     => 'AND',
				),
				'meta_filters'      => array(
					'type'        => 'array',
					'description' => __( 'Optional post meta filters.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'key', 'value' ),
						'properties'           => array(
							'key'     => array(
								'type'        => 'string',
								'description' => __( 'Meta key to compare.', 'wp-mcp-ai' ),
							),
							'value'   => array(
								'type'        => 'string',
								'description' => __( 'Meta value to compare. Can be a string or JSON-encoded array for IN/NOT IN comparisons.', 'wp-mcp-ai' ),
							),
							'compare' => array(
								'type'        => 'string',
								'enum'        => array( '=', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' ),
								'description' => __( 'Meta comparison operator.', 'wp-mcp-ai' ),
								'default'     => '=',
							),
							'type'    => array(
								'type'        => 'string',
								'enum'        => array( 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' ),
								'description' => __( 'Data type to cast the meta value for comparisons.', 'wp-mcp-ai' ),
							),
						),
						'additionalProperties' => false,
					),
				),
				'meta_relation'     => array(
					'type'        => 'string',
					'enum'        => array( 'AND', 'OR' ),
					'description' => __( 'Logical relation between multiple meta filters.', 'wp-mcp-ai' ),
					'default'     => 'AND',
				),
			),
			'required'             => array( 'search_term' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search content.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$search_term = isset( $arguments['search_term'] ) ? sanitize_text_field( $arguments['search_term'] ) : '';
		$post_type   = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'any';
		$limit       = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit       = $limit > 0 ? min( $limit, 50 ) : 10;

		$taxonomy_filters = $this->prepare_taxonomy_filters( $arguments );
		$meta_filters     = $this->prepare_meta_filters( $arguments );

		if ( '' === $search_term && empty( $taxonomy_filters ) && empty( $meta_filters ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_criteria', __( 'Provide a search term, taxonomy filter, or meta filter to narrow the results.', 'wp-mcp-ai' ) );
		}

		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			'no_found_rows'          => true,  // Performance: Skip counting total rows.
			'update_post_term_cache' => false, // Performance: Skip term cache if not using taxonomy data.
			'update_post_meta_cache' => true,  // Keep meta cache as we need post meta.
		);

		if ( '' !== $search_term ) {
			$query_args['s'] = $search_term;
		}

		if ( ! empty( $taxonomy_filters ) ) {
			$query_args['tax_query'] = $taxonomy_filters;
		}

		if ( ! empty( $meta_filters ) ) {
			$query_args['meta_query'] = $meta_filters;
		}

		$query = new WP_Query( $query_args );

		$results = array();
		foreach ( $query->posts as $post ) {
			$post_id = $post instanceof WP_Post ? $post->ID : $post;

			$results[] = array(
				'ID'        => $post_id,
				'title'     => get_the_title( $post_id ),
				'permalink' => get_permalink( $post_id ),
				'excerpt'   => wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 30 ),
				'date'      => get_the_date( DATE_W3C, $post_id ),
				'post_type' => get_post_type( $post_id ),
			);
		}

		return $results;
	}

	/**
	 * Prepare a tax_query argument from tool inputs.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function prepare_taxonomy_filters( array $arguments ) {
		if ( empty( $arguments['taxonomy_filters'] ) || ! is_array( $arguments['taxonomy_filters'] ) ) {
			return array();
		}

		$relation = 'AND';
		if ( ! empty( $arguments['taxonomy_relation'] ) ) {
			$requested_relation = strtoupper( sanitize_text_field( $arguments['taxonomy_relation'] ) );
			if ( in_array( $requested_relation, array( 'AND', 'OR' ), true ) ) {
				$relation = $requested_relation;
			}
		}

		$tax_query = array();

		foreach ( $arguments['taxonomy_filters'] as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$taxonomy = isset( $filter['taxonomy'] ) ? sanitize_key( $filter['taxonomy'] ) : '';
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = array();
			if ( isset( $filter['terms'] ) && is_array( $filter['terms'] ) ) {
				$terms = $filter['terms'];
			}

			$operator = isset( $filter['operator'] ) ? strtoupper( sanitize_text_field( $filter['operator'] ) ) : 'IN';
			$field    = isset( $filter['field'] ) ? sanitize_key( $filter['field'] ) : 'slug';

			if ( ! in_array( $operator, array( 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$operator = 'IN';
			}

			if ( ! in_array( $field, array( 'slug', 'name', 'term_id', 'term_taxonomy_id' ), true ) ) {
				$field = 'slug';
			}

			$sanitized_terms = array();
			foreach ( $terms as $term ) {
				if ( in_array( $field, array( 'term_id', 'term_taxonomy_id' ), true ) ) {
					$sanitized_terms[] = absint( $term );
				} else {
					$sanitized_terms[] = sanitize_text_field( $term );
				}
			}

			$sanitized_terms = array_filter(
				$sanitized_terms,
				static function ( $value ) {
					if ( is_string( $value ) ) {
						return '' !== $value;
					}

					return null !== $value;
				}
			);

			$clause = array(
				'taxonomy' => $taxonomy,
				'field'    => $field,
				'operator' => $operator,
			);

			if ( ! in_array( $operator, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				if ( empty( $sanitized_terms ) ) {
					continue;
				}

				$clause['terms'] = array_values( $sanitized_terms );
			}

			$tax_query[] = $clause;
		}

		if ( empty( $tax_query ) ) {
			return array();
		}

		if ( count( $tax_query ) > 1 || 'AND' !== $relation ) {
			array_unshift( $tax_query, array( 'relation' => $relation ) );
		}

		return $tax_query;
	}

	/**
	 * Prepare a meta_query argument from tool inputs.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function prepare_meta_filters( array $arguments ) {
		if ( empty( $arguments['meta_filters'] ) || ! is_array( $arguments['meta_filters'] ) ) {
			return array();
		}

		$relation = 'AND';
		if ( ! empty( $arguments['meta_relation'] ) ) {
			$requested_relation = strtoupper( sanitize_text_field( $arguments['meta_relation'] ) );
			if ( in_array( $requested_relation, array( 'AND', 'OR' ), true ) ) {
				$relation = $requested_relation;
			}
		}

		$meta_query = array();

		foreach ( $arguments['meta_filters'] as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$key = isset( $filter['key'] ) ? sanitize_key( $filter['key'] ) : '';
			if ( '' === $key ) {
				continue;
			}

			$compare = isset( $filter['compare'] ) ? strtoupper( sanitize_text_field( $filter['compare'] ) ) : '=';
			if ( ! in_array( $compare, array( '=', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$compare = '=';
			}

			$value = isset( $filter['value'] ) ? $filter['value'] : '';
			if ( in_array( $compare, array( 'IN', 'NOT IN' ), true ) ) {
				$value = is_array( $value ) ? $value : array( $value );
				$value = array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) );
				if ( empty( $value ) ) {
					continue;
				}
			} elseif ( in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$value = null;
			} else {
				$value = sanitize_text_field( (string) $value );
				if ( '' === $value ) {
					continue;
				}
			}

			$clause = array(
				'key'     => $key,
				'compare' => $compare,
			);

			if ( null !== $value ) {
				$clause['value'] = $value;
			}

			if ( ! empty( $filter['type'] ) ) {
				$type = strtoupper( sanitize_text_field( $filter['type'] ) );
				if ( in_array( $type, array( 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' ), true ) ) {
					$clause['type'] = $type;
				}
			}

			$meta_query[] = $clause;
		}

		if ( empty( $meta_query ) ) {
			return array();
		}

		if ( count( $meta_query ) > 1 || 'AND' !== $relation ) {
			array_unshift( $meta_query, array( 'relation' => $relation ) );
		}

		return $meta_query;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires 'read' capability.
			'cacheable',            // Results can be cached.
		);
	}
}
