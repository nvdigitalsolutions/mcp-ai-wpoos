<?php
/**
 * CPTs Export Provider.
 *
 * Exports and imports plugin-owned custom post types beyond assistants:
 * tasks, task plans, vault folders/items, audit records, site templates,
 * and peer configurations.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import provider for miscellaneous plugin CPTs.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_CPTs extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Post type slugs handled by this provider.
	 *
	 * @since 1.2.0
	 *
	 * @var string[]
	 */
	const POST_TYPES = array(
		'mcp_ai_task',
		'mcp_task_plan',
		'mcp_vault_folder',
		'mcp_vault_item',
		'mcp_ai_audit',
		'mcp_site_template',
		'ai_peer',
		'mcp_test_reg',
	);

	/**
	 * Get the unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'cpts';
	}

	/**
	 * Get the human-readable label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Tasks, Vault & Audit', 'mcp-ai-wpoos' );
	}

	/**
	 * Get the description for the UI.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'Task plans, vault folders and items, audit records, site templates, and peer configurations.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Always available because the post types may or may not be
	 * registered; we simply skip missing ones during export/import.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Whether exported data contains sensitive values.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool {
		return false;
	}

	/**
	 * Sum of published and draft posts across all handled post types.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		$total = 0;

		foreach ( self::POST_TYPES as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			$counts = wp_count_posts( $post_type );
			if ( ! $counts ) {
				continue;
			}
			$total += (int) ( ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 ) );
		}

		return $total;
	}

	/**
	 * Export all handled CPT posts with their post meta.
	 *
	 * Returns a nested array keyed by post type, each containing
	 * a 'posts' array of post data.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function export(): array {
		$result = array();

		foreach ( self::POST_TYPES as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $posts ) ) {
				$result[ $post_type ] = array( 'posts' => array() );
				continue;
			}

			$exported_posts = array();

			foreach ( $posts as $post ) {
				$post_data = array(
					'post_title'   => $post->post_title,
					'post_name'    => $post->post_name,
					'post_excerpt' => $post->post_excerpt,
					'post_content' => $post->post_content,
					'post_status'  => $post->post_status,
				);

				// Collect post meta, filtering out internal WordPress meta.
				$all_meta  = get_post_meta( $post->ID );
				$post_meta = array();

				foreach ( $all_meta as $meta_key => $meta_values ) {
					// Skip internal WordPress meta keys starting with underscore,
					// unless they begin with _wp_mcp_ai.
					if ( '_' === $meta_key[0] && 0 !== strpos( $meta_key, '_wp_mcp_ai' ) ) {
						continue;
					}

					// Unwrap single-value arrays.
					$post_meta[ $meta_key ] = count( $meta_values ) === 1
						? maybe_unserialize( $meta_values[0] )
						: array_map( 'maybe_unserialize', $meta_values );
				}

				$post_data['meta'] = $post_meta;
				$exported_posts[]  = $post_data;
			}

			$result[ $post_type ] = array( 'posts' => $exported_posts );
		}

		return $result;
	}

	/**
	 * Validate import data before committing.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data ) {
		if ( empty( $data ) ) {
			return new \WP_Error(
				'cpts_empty',
				__( 'CPT data is empty.', 'mcp-ai-wpoos' )
			);
		}

		foreach ( $data as $post_type => $section ) {
			if ( ! isset( $section['posts'] ) || ! is_array( $section['posts'] ) ) {
				return new \WP_Error(
					'cpts_missing_posts',
					sprintf(
						/* translators: %s: post type slug */
						__( 'Post type "%s" is missing the "posts" array.', 'mcp-ai-wpoos' ),
						$post_type
					)
				);
			}
		}

		return true;
	}

	/**
	 * Import CPT posts into the current site.
	 *
	 * Uses slug-based matching: if a post with the same slug exists
	 * within the same post type, it is updated; otherwise a new post
	 * is inserted. Post meta is deleted and re-added for each imported
	 * post.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return array Array with created/updated/skipped/errors counts.
	 */
	public function import( array $data ) {
		$counts = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		foreach ( $data as $post_type => $section ) {
			if ( empty( $section['posts'] ) ) {
				continue;
			}

			foreach ( $section['posts'] as $post_data ) {
				$existing_id = 0;

				// Try to find an existing post by slug within the post type.
				if ( ! empty( $post_data['post_name'] ) ) {
					$existing = get_page_by_path( $post_data['post_name'], OBJECT, $post_type );
					if ( $existing ) {
						$existing_id = $existing->ID;
					}
				}

				// Extract meta before building post array.
				$meta = isset( $post_data['meta'] ) && is_array( $post_data['meta'] )
					? $post_data['meta']
					: array();
				unset( $post_data['meta'] );

				$post_args = array(
					'post_type'    => $post_type,
					'post_title'   => $post_data['post_title'] ?? '',
					'post_name'    => $post_data['post_name'] ?? '',
					'post_excerpt' => $post_data['post_excerpt'] ?? '',
					'post_content' => $post_data['post_content'] ?? '',
					'post_status'  => $post_data['post_status'] ?? 'publish',
				);

				if ( $existing_id > 0 ) {
					$post_args['ID'] = $existing_id;
					$result          = wp_update_post( $post_args, true );
					if ( is_wp_error( $result ) ) {
						++$counts['errors'];
						continue;
					}
					++$counts['updated'];
					$post_id = $result;
				} else {
					$result = wp_insert_post( $post_args, true );
					if ( is_wp_error( $result ) ) {
						++$counts['errors'];
						continue;
					}
					++$counts['created'];
					$post_id = $result;
				}

				// Delete all existing post meta and re-add from import data.
				$existing_meta = get_post_meta( $post_id );
				foreach ( $existing_meta as $meta_key => $meta_values ) {
					delete_post_meta( $post_id, $meta_key );
				}

				foreach ( $meta as $meta_key => $meta_value ) {
					if ( is_array( $meta_value ) ) {
						foreach ( $meta_value as $single_value ) {
							add_post_meta( $post_id, $meta_key, $single_value );
						}
					} else {
						add_post_meta( $post_id, $meta_key, $meta_value );
					}
				}
			}
		}

		$this->log_action( 'imported', $counts );

		return $counts;
	}
}
