<?php
/**
 * Profession Repository.
 *
 * Data access layer for professions.
 * Handles all database queries and caching for profession data.
 * Separates data access from business logic (service) and presentation (CPT).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles profession data persistence and retrieval.
 */
class WP_MCP_AI_Profession_Repository {
	/**
	 * Cache group for professions.
	 */
	const CACHE_GROUP = 'wp_mcp_ai_professions';

	/**
	 * Cache expiration time (1 hour).
	 */
	const CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Find all professions.
	 *
	 * @param array $args Query arguments.
	 * @return WP_Post[] Array of profession posts.
	 */
	public function find_all( $args = array() ) {
		$cache_key = 'all_professions_' . md5( wp_json_encode( $args ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$defaults = array(
			'post_type'      => WP_MCP_AI_Profession_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$query_args  = wp_parse_args( $args, $defaults );
		$query       = new WP_Query( $query_args );
		$professions = $query->posts;

		wp_cache_set( $cache_key, $professions, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $professions;
	}

	/**
	 * Find professions by category.
	 *
	 * @param string $category Category slug.
	 * @return WP_Post[] Array of profession posts.
	 */
	public function find_by_category( $category ) {
		$cache_key = 'category_' . sanitize_key( $category );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Profession_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => WP_MCP_AI_Profession_CPT::META_CATEGORY,
					'value'   => sanitize_key( $category ),
					'compare' => '=',
				),
			),
			'no_found_rows'  => true,
		);

		$query       = new WP_Query( $args );
		$professions = $query->posts;

		wp_cache_set( $cache_key, $professions, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $professions;
	}

	/**
	 * Find one profession by slug or ID.
	 *
	 * @param string|int $profession Profession slug or ID.
	 * @return WP_Post|null Profession post or null if not found.
	 */
	public function find_one( $profession ) {
		if ( is_numeric( $profession ) ) {
			$post = get_post( absint( $profession ) );

			if ( $post && WP_MCP_AI_Profession_CPT::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
				return $post;
			}

			return null;
		}

		$cache_key = 'profession_' . sanitize_key( $profession );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Profession_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'name'           => sanitize_title( $profession ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			$post = $query->posts[0];
			wp_cache_set( $cache_key, $post, self::CACHE_GROUP, self::CACHE_EXPIRATION );
			return $post;
		}

		return null;
	}

	/**
	 * Find multiple professions by slugs or IDs.
	 *
	 * @param array $profession_ids Array of profession slugs or IDs.
	 * @return WP_Post[] Array of profession posts indexed by post_name.
	 */
	public function find_many( array $profession_ids ) {
		if ( empty( $profession_ids ) ) {
			return array();
		}

		$professions = array();
		$numeric_ids = array();
		$slugs       = array();

		// Separate numeric IDs from slugs.
		foreach ( $profession_ids as $id ) {
			if ( is_numeric( $id ) ) {
				$numeric_ids[] = absint( $id );
			} else {
				$slugs[] = sanitize_key( $id );
			}
		}

		// Get by IDs if any.
		if ( ! empty( $numeric_ids ) ) {
			$args = array(
				'post_type'      => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'post__in'       => $numeric_ids,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			);

			$query = new WP_Query( $args );
			foreach ( $query->posts as $post ) {
				$professions[ $post->post_name ] = $post;
			}
		}

		// Get by slugs if any.
		if ( ! empty( $slugs ) ) {
			foreach ( $slugs as $slug ) {
				$post = $this->find_one( $slug );
				if ( $post ) {
					$professions[ $post->post_name ] = $post;
				}
			}
		}

		return $professions;
	}

	/**
	 * Get profession count by category.
	 *
	 * @return array Category => count pairs.
	 */
	public function get_category_counts() {
		$cache_key = 'category_counts';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value as category, COUNT(*) as count
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				AND p.post_type = %s
				AND p.post_status = 'publish'
				GROUP BY meta_value",
				WP_MCP_AI_Profession_CPT::META_CATEGORY,
				WP_MCP_AI_Profession_CPT::POST_TYPE
			)
		);

		$counts = array();
		foreach ( $results as $result ) {
			$counts[ $result->category ] = absint( $result->count );
		}

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $counts;
	}

	/**
	 * Clear profession cache.
	 *
	 * @param int|null $post_id Optional post ID to clear specific caches.
	 */
	public function clear_cache( $post_id = null ) {
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && isset( $post->post_name ) ) {
				wp_cache_delete( 'profession_' . $post->post_name, self::CACHE_GROUP );
			}
		}

		// Clear all professions cache (we use pattern matching if available).
		wp_cache_delete( 'all_professions_' . md5( wp_json_encode( array() ) ), self::CACHE_GROUP );
		wp_cache_delete( 'category_counts', self::CACHE_GROUP );

		// Clear category caches.
		$categories = array( 'advisory', 'creative', 'technical', 'healthcare', 'legal', 'financial', 'other' );
		foreach ( $categories as $category ) {
			wp_cache_delete( 'category_' . $category, self::CACHE_GROUP );
		}
	}

	/**
	 * Create or update a profession.
	 *
	 * @param array $data Profession data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function save( array $data ) {
		$post_data = array(
			'post_type'    => WP_MCP_AI_Profession_CPT::POST_TYPE,
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_name'    => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '',
			'post_content' => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'post_status'  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'publish',
		);

		if ( isset( $data['id'] ) && $data['id'] > 0 ) {
			$post_data['ID'] = absint( $data['id'] );
			$post_id         = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save meta fields.
		if ( isset( $data['category'] ) ) {
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, sanitize_key( $data['category'] ) );
		}

		if ( isset( $data['role_description'] ) ) {
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_ROLE_DESCRIPTION, wp_kses_post( $data['role_description'] ) );
		}

		if ( isset( $data['expertise'] ) && is_array( $data['expertise'] ) ) {
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, array_map( 'sanitize_text_field', $data['expertise'] ) );
		}

		if ( isset( $data['warnings'] ) && is_array( $data['warnings'] ) ) {
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_WARNINGS, array_map( 'sanitize_text_field', $data['warnings'] ) );
		}

		if ( isset( $data['knowledge_base'] ) ) {
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, wp_kses_post( $data['knowledge_base'] ) );
		}

		if ( isset( $data['default_tools'] ) && is_array( $data['default_tools'] ) ) {
			$sanitized_tools = array_map( 'sanitize_key', $data['default_tools'] );
			$sanitized_tools = array_filter( $sanitized_tools ); // Remove empty values.
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, array_values( $sanitized_tools ) );
		}

		if ( isset( $data['supported_mime_types'] ) && is_array( $data['supported_mime_types'] ) ) {
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, array_map( 'sanitize_text_field', $data['supported_mime_types'] ) );
		}

		// Clear cache.
		$this->clear_cache( $post_id );

		return $post_id;
	}
}
