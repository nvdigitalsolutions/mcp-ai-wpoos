<?php
/**
 * Assistants Export Provider.
 *
 * Exports and imports AI assistant configurations stored in the
 * mcp_ai_assistant custom post type, including post meta such as
 * system prompts, tool assignments, and model selections.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import provider for assistant CPT posts and meta.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_Assistants extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Post type slug handled by this provider.
	 *
	 * @since 1.2.0
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_assistant';

	/**
	 * Get the unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'assistants';
	}

	/**
	 * Get the human-readable label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Assistants', 'mcp-ai-wpoos' );
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
			'AI assistant configurations: system prompts, tool assignments, model selections, and all post meta.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return post_type_exists( self::POST_TYPE );
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
	 * Count of published and draft assistant posts.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		$counts = wp_count_posts( self::POST_TYPE );
		if ( ! $counts ) {
			return 0;
		}
		return (int) ( ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 ) );
	}

	/**
	 * Export all assistant posts with their post meta.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function export(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			return array( 'posts' => array() );
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

		return array( 'posts' => $exported_posts );
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
		if ( ! isset( $data['posts'] ) || ! is_array( $data['posts'] ) ) {
			return new \WP_Error(
				'assistants_missing_posts',
				__( 'Assistant data is missing the "posts" array.', 'mcp-ai-wpoos' )
			);
		}

		foreach ( $data['posts'] as $index => $post_data ) {
			if ( empty( $post_data['post_title'] ) ) {
				return new \WP_Error(
					'assistants_missing_title',
					sprintf(
						/* translators: %d: post index */
						__( 'Assistant at index %d is missing a post_title.', 'mcp-ai-wpoos' ),
						$index
					)
				);
			}
		}

		return true;
	}

	/**
	 * Import assistant posts into the current site.
	 *
	 * Uses slug-based matching: if a post with the same slug exists,
	 * it is updated; otherwise a new post is inserted. Post meta is
	 * deleted and re-added for each imported post.
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

		if ( empty( $data['posts'] ) ) {
			return $counts;
		}

		foreach ( $data['posts'] as $post_data ) {
			$existing_id = 0;

			// Try to find an existing post by slug.
			if ( ! empty( $post_data['post_name'] ) ) {
				$existing = get_page_by_path( $post_data['post_name'], OBJECT, self::POST_TYPE );
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
				'post_type'    => self::POST_TYPE,
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

		$this->log_action( 'imported', $counts );

		return $counts;
	}
}
