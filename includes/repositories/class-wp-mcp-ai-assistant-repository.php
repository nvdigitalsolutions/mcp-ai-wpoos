<?php
/**
 * Assistant Repository
 *
 * Handles database operations for assistants.
 * Part of Phase 4 refactoring (Milestone 9 - Repository Pattern).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assistant Repository class
 *
 * Responsible for:
 * - Assistant CRUD operations
 * - Assistant metadata management
 * - Assistant queries
 * - Data persistence and retrieval
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Assistant_Repository {

	/**
	 * Post type for assistants
	 *
	 * @var string
	 */
	private $post_type = 'mcp_ai_assistant';

	/**
	 * Find assistant by ID
	 *
	 * @param int $assistant_id Assistant ID.
	 * @return WP_Post|null Assistant post or null if not found.
	 */
	public function find_by_id( $assistant_id ) {
		$post = get_post( $assistant_id );

		if ( ! $post || $this->post_type !== $post->post_type ) {
			return null;
		}

		return $post;
	}

	/**
	 * Find all assistants
	 *
	 * @param array $args Query arguments.
	 * @return array Array of WP_Post objects.
	 */
	public function find_all( $args = array() ) {
		$defaults = array(
			'post_type'              => $this->post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,  // Performance: Skip counting total rows.
			'update_post_term_cache' => false, // Performance: Skip term cache if not needed.
			'update_post_meta_cache' => true,  // Keep meta cache for assistant configs.
		);

		$query_args = wp_parse_args( $args, $defaults );

		// Check cache only if caching is enabled and no custom args.
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			return WP_MCP_AI_Cache_Helper::get_assistants_list(
				$query_args,
				function () use ( $query_args ) {
					$query = new WP_Query( $query_args );
					return $query->posts;
				}
			);
		}

		$query = new WP_Query( $query_args );
		return $query->posts;
	}

	/**
	 * Find assistants by status
	 *
	 * @param string $status Post status (publish, draft, etc.).
	 * @return array Array of WP_Post objects.
	 */
	public function find_by_status( $status ) {
		return $this->find_all(
			array(
				'post_status' => $status,
			)
		);
	}

	/**
	 * Get assistant metadata
	 *
	 * @param int    $assistant_id Assistant ID.
	 * @param string $meta_key     Meta key (without prefix).
	 * @param mixed  $default      Default value if meta doesn't exist.
	 * @return mixed Meta value or default.
	 */
	public function get_meta( $assistant_id, $meta_key, $default = '' ) {
		$prefixed_key = $this->prefix_meta_key( $meta_key );
		$value        = get_post_meta( $assistant_id, $prefixed_key, true );

		return ( '' !== $value ) ? $value : $default;
	}

	/**
	 * Update assistant metadata
	 *
	 * @param int    $assistant_id Assistant ID.
	 * @param string $meta_key     Meta key (without prefix).
	 * @param mixed  $value        Meta value.
	 * @return bool True on success, false on failure.
	 */
	public function update_meta( $assistant_id, $meta_key, $value ) {
		$prefixed_key = $this->prefix_meta_key( $meta_key );
		$result       = update_post_meta( $assistant_id, $prefixed_key, $value );

		// Invalidate cache on successful update.
		if ( $result && class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::invalidate_assistant_cache( $assistant_id );
		}

		return $result;
	}

	/**
	 * Delete assistant metadata
	 *
	 * @param int    $assistant_id Assistant ID.
	 * @param string $meta_key     Meta key (without prefix).
	 * @return bool True on success, false on failure.
	 */
	public function delete_meta( $assistant_id, $meta_key ) {
		$prefixed_key = $this->prefix_meta_key( $meta_key );
		return delete_post_meta( $assistant_id, $prefixed_key );
	}

	/**
	 * Get all metadata for an assistant
	 *
	 * @param int $assistant_id Assistant ID.
	 * @return array Associative array of metadata (with prefixes stripped).
	 */
	public function get_all_meta( $assistant_id ) {
		// Check cache first.
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$cache_key = "assistant_meta_{$assistant_id}";
			$cached    = WP_MCP_AI_Cache_Helper::get( $cache_key );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		$all_meta    = get_post_meta( $assistant_id );
		$plugin_meta = array();

		foreach ( $all_meta as $key => $values ) {
			// Only include our plugin's meta keys.
			if ( 0 === strpos( $key, 'mcp_ai_' ) ) {
				$stripped_key                 = str_replace( 'mcp_ai_', '', $key );
				$plugin_meta[ $stripped_key ] = $values[0] ?? '';
			}
		}

		// Cache the result.
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			WP_MCP_AI_Cache_Helper::set( "assistant_meta_{$assistant_id}", $plugin_meta );
		}

		return $plugin_meta;
	}

	/**
	 * Create new assistant
	 *
	 * @param array $data Assistant data (title, content, meta).
	 * @return int|WP_Error Assistant ID on success, WP_Error on failure.
	 */
	public function create( $data ) {
		$post_data = array(
			'post_type'    => $this->post_type,
			'post_title'   => $data['title'] ?? '',
			'post_content' => $data['content'] ?? '',
			'post_status'  => $data['status'] ?? 'draft',
		);

		$assistant_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $assistant_id ) ) {
			return $assistant_id;
		}

		// Save metadata if provided.
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				$this->update_meta( $assistant_id, $key, $value );
			}
		}

		// Invalidate assistant list caches.
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::invalidate_assistant_caches();
		}

		return $assistant_id;
	}

	/**
	 * Update existing assistant
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param array $data         Updated data (title, content, meta).
	 * @return int|WP_Error Assistant ID on success, WP_Error on failure.
	 */
	public function update( $assistant_id, $data ) {
		$post_data = array(
			'ID' => $assistant_id,
		);

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = $data['title'];
		}

		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = $data['content'];
		}

		if ( isset( $data['status'] ) ) {
			$post_data['post_status'] = $data['status'];
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update metadata if provided.
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				$this->update_meta( $assistant_id, $key, $value );
			}
		}

		// Invalidate caches.
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::invalidate_assistant_cache( $assistant_id );
		}

		return $assistant_id;
	}

	/**
	 * Delete assistant
	 *
	 * @param int  $assistant_id  Assistant ID.
	 * @param bool $force_delete  Whether to bypass trash and force deletion.
	 * @return WP_Post|false|null Post data on success, false or null on failure.
	 */
	public function delete( $assistant_id, $force_delete = false ) {
		$result = wp_delete_post( $assistant_id, $force_delete );

		// Invalidate caches on successful deletion.
		if ( $result && class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::invalidate_assistant_caches();
		}

		return $result;
	}

	/**
	 * Count assistants by status
	 *
	 * @param string $status Post status.
	 * @return int Number of assistants.
	 */
	public function count_by_status( $status ) {
		$query = new WP_Query(
			array(
				'post_type'      => $this->post_type,
				'post_status'    => $status,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => false, // Need found_posts for counting.
			)
		);

		return $query->found_posts;
	}

	/**
	 * Search assistants by title or content
	 *
	 * @param string $search_term Search term.
	 * @param array  $args        Additional query args.
	 * @return array Array of WP_Post objects.
	 */
	public function search( $search_term, $args = array() ) {
		$defaults = array(
			'post_type'              => $this->post_type,
			'post_status'            => 'publish',
			's'                      => $search_term,
			'posts_per_page'         => -1,
			'no_found_rows'          => true,  // Performance: Skip counting for search.
			'update_post_term_cache' => false, // Performance: Skip term cache.
		);

		$query_args = wp_parse_args( $args, $defaults );
		$query      = new WP_Query( $query_args );

		return $query->posts;
	}

	/**
	 * Get default assistant ID from options
	 *
	 * @return int|null Default assistant ID or null.
	 */
	public function get_default_id() {
		$default = get_option( 'wp_mcp_ai_default_assistant' );
		return $default ? absint( $default ) : null;
	}

	/**
	 * Set default assistant
	 *
	 * @param int $assistant_id Assistant ID.
	 * @return bool True on success, false on failure.
	 */
	public function set_default( $assistant_id ) {
		return update_option( 'wp_mcp_ai_default_assistant', absint( $assistant_id ) );
	}

	/**
	 * Prefix meta key with plugin namespace
	 *
	 * @param string $meta_key Meta key without prefix.
	 * @return string Prefixed meta key.
	 */
	private function prefix_meta_key( $meta_key ) {
		// Don't add prefix if already prefixed.
		if ( 0 === strpos( $meta_key, 'mcp_ai_' ) ) {
			return $meta_key;
		}

		return 'mcp_ai_' . $meta_key;
	}

	/**
	 * Get preferred datasets for an assistant.
	 *
	 * @param int $assistant_id Assistant ID.
	 * @return array Array of preferred datasets or empty array.
	 */
	public function get_preferred_datasets( $assistant_id ) {
		$datasets = $this->get_meta( $assistant_id, 'preferred_datasets', array() );

		if ( ! is_array( $datasets ) ) {
			return array();
		}

		return $datasets;
	}
}
