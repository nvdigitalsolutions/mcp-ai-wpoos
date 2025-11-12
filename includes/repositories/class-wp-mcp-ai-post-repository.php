<?php
/**
 * Post Repository
 *
 * Data access layer for WordPress posts.
 * Part of completing the Repository Pattern (Priority 2 from Architecture Review).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post Repository Class
 *
 * Provides abstraction layer for post CRUD operations.
 * Isolates WordPress post API from business logic.
 *
 * Benefits:
 * - Single source of truth for post operations
 * - Easier to test (can mock repository)
 * - Consistent data sanitization and validation
 * - Easier to swap storage backend if needed
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Post_Repository {

	/**
	 * Find a post by ID.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $output  Optional. The required return type. One of OBJECT, ARRAY_A, or ARRAY_N.
	 *                        Default OBJECT.
	 * @return WP_Post|array|null Post object/array on success, null on failure.
	 */
	public function find( $post_id, $output = OBJECT ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return null;
		}

		return get_post( $post_id, $output );
	}

	/**
	 * Find multiple posts by IDs.
	 *
	 * @param array $post_ids Array of post IDs.
	 * @return WP_Post[] Array of post objects, keyed by post ID.
	 */
	public function find_many( array $post_ids ) {
		$post_ids = array_filter( array_map( 'absint', $post_ids ) );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post__in'       => $post_ids,
				'posts_per_page' => count( $post_ids ),
				'post_type'      => 'any',
				'post_status'    => 'any',
			)
		);

		// Re-key by post ID for easier lookup.
		$keyed = array();
		foreach ( $posts as $post ) {
			$keyed[ $post->ID ] = $post;
		}

		return $keyed;
	}

	/**
	 * Query posts with given criteria.
	 *
	 * @param array $args Query arguments. Supports all WP_Query parameters.
	 * @return WP_Post[] Array of post objects.
	 */
	public function query( array $args = array() ) {
		$defaults = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		return get_posts( $args );
	}

	/**
	 * Create a new post.
	 *
	 * @param array $post_data Post data array.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function create( array $post_data ) {
		// Ensure required fields exist.
		$defaults = array(
			'post_status' => 'draft',
			'post_type'   => 'post',
		);

		$post_data = wp_parse_args( $post_data, $defaults );

		// Sanitize common fields.
		if ( isset( $post_data['post_title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $post_data['post_title'] );
		}

		if ( isset( $post_data['post_name'] ) ) {
			$post_data['post_name'] = sanitize_title( $post_data['post_name'] );
		}

		if ( isset( $post_data['post_status'] ) ) {
			$post_data['post_status'] = sanitize_key( $post_data['post_status'] );
		}

		if ( isset( $post_data['post_type'] ) ) {
			$post_data['post_type'] = sanitize_key( $post_data['post_type'] );
		}

		if ( isset( $post_data['post_author'] ) ) {
			$post_data['post_author'] = absint( $post_data['post_author'] );
		}

		// Allow filtering before creation.
		$post_data = apply_filters( 'wp_mcp_ai_post_repository_before_create', $post_data );

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Fire action after successful creation.
		do_action( 'wp_mcp_ai_post_repository_after_create', $post_id, $post_data );

		return $post_id;
	}

	/**
	 * Update an existing post.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $post_data Post data to update.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function update( $post_id, array $post_data ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_post_id',
				__( 'Invalid post ID provided.', 'wp-mcp-ai' )
			);
		}

		// Ensure post ID is set.
		$post_data['ID'] = $post_id;

		// Sanitize common fields.
		if ( isset( $post_data['post_title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $post_data['post_title'] );
		}

		if ( isset( $post_data['post_name'] ) ) {
			$post_data['post_name'] = sanitize_title( $post_data['post_name'] );
		}

		if ( isset( $post_data['post_status'] ) ) {
			$post_data['post_status'] = sanitize_key( $post_data['post_status'] );
		}

		if ( isset( $post_data['post_author'] ) ) {
			$post_data['post_author'] = absint( $post_data['post_author'] );
		}

		// Allow filtering before update.
		$post_data = apply_filters( 'wp_mcp_ai_post_repository_before_update', $post_data, $post_id );

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Fire action after successful update.
		do_action( 'wp_mcp_ai_post_repository_after_update', $post_id, $post_data );

		return $post_id;
	}

	/**
	 * Delete a post.
	 *
	 * @param int  $post_id      Post ID.
	 * @param bool $force_delete Whether to bypass trash and force deletion.
	 * @return WP_Post|false|null Post data on success, false or null on failure.
	 */
	public function delete( $post_id, $force_delete = false ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return false;
		}

		// Allow filtering before deletion.
		$should_delete = apply_filters( 'wp_mcp_ai_post_repository_before_delete', true, $post_id );

		if ( ! $should_delete ) {
			return false;
		}

		$result = wp_delete_post( $post_id, $force_delete );

		if ( $result ) {
			// Fire action after successful deletion.
			do_action( 'wp_mcp_ai_post_repository_after_delete', $post_id, $force_delete );
		}

		return $result;
	}

	/**
	 * Check if a post exists.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether post exists.
	 */
	public function exists( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return false;
		}

		return (bool) get_post( $post_id );
	}

	/**
	 * Get post meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed Meta value(s).
	 */
	public function get_meta( $post_id, $key, $single = true ) {
		$post_id = absint( $post_id );
		$key     = sanitize_key( $key );

		if ( ! $post_id || ! $key ) {
			return $single ? '' : array();
		}

		return get_post_meta( $post_id, $key, $single );
	}

	/**
	 * Update post meta value.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
	 */
	public function update_meta( $post_id, $meta_key, $meta_value ) {
		$post_id  = absint( $post_id );
		$meta_key = sanitize_key( $meta_key );

		if ( ! $post_id || ! $meta_key ) {
			return false;
		}

		return update_post_meta( $post_id, $meta_key, $meta_value );
	}

	/**
	 * Delete post meta value.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Optional. Meta value to delete. If provided, only delete meta with this value.
	 * @return bool True on success, false on failure.
	 */
	public function delete_meta( $post_id, $meta_key, $meta_value = '' ) {
		$post_id  = absint( $post_id );
		$meta_key = sanitize_key( $meta_key );

		if ( ! $post_id || ! $meta_key ) {
			return false;
		}

		return delete_post_meta( $post_id, $meta_key, $meta_value );
	}

	/**
	 * Get posts by post type.
	 *
	 * @param string $post_type Post type.
	 * @param array  $args      Optional. Additional query arguments.
	 * @return WP_Post[] Array of post objects.
	 */
	public function get_by_type( $post_type, array $args = array() ) {
		$post_type = sanitize_key( $post_type );

		if ( ! $post_type ) {
			return array();
		}

		$defaults = array(
			'post_type'      => $post_type,
			'posts_per_page' => 10,
			'post_status'    => 'publish',
		);

		$args = wp_parse_args( $args, $defaults );

		return $this->query( $args );
	}

	/**
	 * Get posts by author.
	 *
	 * @param int   $author_id User ID.
	 * @param array $args      Optional. Additional query arguments.
	 * @return WP_Post[] Array of post objects.
	 */
	public function get_by_author( $author_id, array $args = array() ) {
		$author_id = absint( $author_id );

		if ( ! $author_id ) {
			return array();
		}

		$defaults = array(
			'author'         => $author_id,
			'posts_per_page' => 10,
			'post_status'    => 'publish',
		);

		$args = wp_parse_args( $args, $defaults );

		return $this->query( $args );
	}

	/**
	 * Get posts by status.
	 *
	 * @param string $status Post status.
	 * @param array  $args   Optional. Additional query arguments.
	 * @return WP_Post[] Array of post objects.
	 */
	public function get_by_status( $status, array $args = array() ) {
		$status = sanitize_key( $status );

		if ( ! $status ) {
			return array();
		}

		$defaults = array(
			'post_status'    => $status,
			'posts_per_page' => 10,
		);

		$args = wp_parse_args( $args, $defaults );

		return $this->query( $args );
	}

	/**
	 * Search posts by title or content.
	 *
	 * @param string $search_term Search term.
	 * @param array  $args        Optional. Additional query arguments.
	 * @return WP_Post[] Array of post objects.
	 */
	public function search( $search_term, array $args = array() ) {
		$search_term = sanitize_text_field( $search_term );

		if ( ! $search_term ) {
			return array();
		}

		$defaults = array(
			's'              => $search_term,
			'posts_per_page' => 10,
		);

		$args = wp_parse_args( $args, $defaults );

		return $this->query( $args );
	}

	/**
	 * Count posts matching criteria.
	 *
	 * @param array $args Query arguments.
	 * @return int Number of posts found.
	 */
	public function count( array $args = array() ) {
		$defaults = array(
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$args  = wp_parse_args( $args, $defaults );
		$posts = $this->query( $args );

		return count( $posts );
	}
}
