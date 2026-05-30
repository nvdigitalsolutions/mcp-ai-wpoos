<?php
/**
 * Shared Blueprint Installer — installs curated assistant blueprints.
 *
 * Reusable across all Pro toolkits. Each import tool delegates to this installer,
 * which handles file discovery, JSON parsing, duplicate detection, post insertion,
 * and meta population. Supports both the abstract CRM-style blueprint format
 * ({name, description, meta}) and the direct WordPress-style Healthcare format
 * ({post_title, post_status, post_content, meta_input}).
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared blueprint installer.
 *
 * Each toolkit's import tool calls the static methods on this class to
 * keep the file-load / parse / insert / meta logic consistent.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Blueprint_Installer {

	/**
	 * Load and parse a blueprint JSON file.
	 *
	 * @since  2.3.0
	 *
	 * @param  string $blueprint_dir  Absolute path to the examples directory.
	 * @param  string $blueprint_slug Sanitised blueprint slug (without .json).
	 * @return array|WP_Error         Parsed data or WP_Error.
	 */
	public static function load_blueprint( $blueprint_dir, $blueprint_slug ) {
		$file = trailingslashit( $blueprint_dir ) . $blueprint_slug . '.json';

		if ( ! file_exists( $file ) ) {
			return new WP_Error(
				'blueprint_not_found',
				sprintf(
					/* translators: %s: blueprint slug */
					__( 'Blueprint "%s" not found.', 'mcp-ai-wpoos-pro' ),
					esc_html( $blueprint_slug )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local blueprint JSON, not a remote URL.
		$json = file_get_contents( $file );
		if ( false === $json ) {
			return new WP_Error(
				'blueprint_read_error',
				sprintf(
					/* translators: %s: blueprint slug */
					__( 'Could not read blueprint "%s".', 'mcp-ai-wpoos-pro' ),
					esc_html( $blueprint_slug )
				)
			);
		}

		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error(
				'blueprint_invalid_json',
				sprintf(
					/* translators: %s: blueprint slug */
					__( 'Blueprint "%s" contains invalid JSON.', 'mcp-ai-wpoos-pro' ),
					esc_html( $blueprint_slug )
				)
			);
		}

		return $data;
	}

	/**
	 * Install a blueprint as an mcp_ai_assistant post.
	 *
	 * Supports two blueprint schemas:
	 *
	 * **CRM-style (abstract):**
	 * ```json
	 * { "name": "...", "description": "...", "meta": { ... } }
	 * ```
	 *
	 * **Healthcare-style (direct WP):**
	 * ```json
	 * { "post_title": "...", "post_status": "...", "post_content": "...", "meta_input": { ... } }
	 * ```
	 *
	 * When the Healthcare-style keys are present they take precedence (the
	 * `wp_insert_post` array is built directly from them).  Otherwise the
	 * CRM-style keys are mapped onto `post_title` / `post_content` / post meta.
	 *
	 * @since  2.3.0
	 *
	 * @param  array  $data        Parsed blueprint JSON.
	 * @param  string $blueprint_slug Slug used as the `_blueprint_source` post meta value.
	 * @param  bool   $overwrite   Whether to overwrite an existing assistant with the same title.
	 * @return array|WP_Error      Success envelope or WP_Error.
	 */
	public static function install( array $data, $blueprint_slug, $overwrite = false ) {
		// ── Resolve post data from either format ──
		if ( isset( $data['post_title'] ) ) {
			// Healthcare-style: direct WordPress post fields.
			$post_title   = $data['post_title'];
			$post_content = $data['post_content'] ?? '';
			$post_status  = $data['post_status'] ?? 'publish';
			$meta_input   = $data['meta_input'] ?? array();
		} else {
			// CRM-style: abstracted blueprint.
			$post_title   = $data['name'] ?? ucwords( str_replace( '-', ' ', $blueprint_slug ) );
			$post_content = $data['description'] ?? '';
			$post_status  = 'publish';
			$meta_input   = $data['meta'] ?? array();
		}

		// Use WP_Query instead of deprecated get_page_by_title().
		$existing_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'title'          => $post_title,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'no_found_rows'  => true,
			)
		);
		$existing_id    = $existing_query->have_posts() ? $existing_query->posts[0]->ID : 0;
		wp_reset_postdata();

		if ( $existing_id ) {
			if ( ! $overwrite ) {
				return new WP_Error(
					'blueprint_duplicate',
					sprintf(
						/* translators: %s: assistant name */
						__( 'An assistant named "%s" already exists. Set overwrite=true to replace it.', 'mcp-ai-wpoos-pro' ),
						esc_html( $post_title )
					)
				);
			}

			// Overwrite: update existing post.
			$assistant_id = wp_update_post(
				array(
					'ID'           => $existing_id,
					'post_title'   => $post_title,
					'post_content' => $post_content,
					'post_status'  => $post_status,
				),
				true
			);

			if ( is_wp_error( $assistant_id ) ) {
				return $assistant_id;
			}

			// Clear existing blueprint meta before repopulating.
			delete_post_meta( $existing_id, '_blueprint_source' );
		} else {
			// Create new assistant post.
			$assistant_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_assistant',
					'post_title'   => $post_title,
					'post_status'  => $post_status,
					'post_content' => $post_content,
				),
				true
			);

			if ( is_wp_error( $assistant_id ) ) {
				return $assistant_id;
			}
		}

		// ── Write post meta ──
		if ( ! empty( $meta_input ) ) {
			foreach ( $meta_input as $key => $value ) {
				update_post_meta( $assistant_id, sanitize_key( $key ), $value );
			}
		}

		// Always store the blueprint source slug.
		update_post_meta( $assistant_id, '_blueprint_source', sanitize_key( $blueprint_slug ) );

		/**
		 * Fires after a blueprint has been installed.
		 *
		 * @since 2.3.0
		 *
		 * @param int    $assistant_id   The assistant post ID.
		 * @param string $blueprint_slug The blueprint slug that was installed.
		 * @param array  $data           The parsed blueprint JSON data.
		 */
		do_action( 'wp_mcp_ai_blueprint_installed', $assistant_id, $blueprint_slug, $data );

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: 1: blueprint name, 2: assistant ID */
				__( 'Blueprint "%1$s" imported as assistant #%2$d.', 'mcp-ai-wpoos-pro' ),
				$post_title,
				$assistant_id
			),
			'blueprint'    => $blueprint_slug,
			'assistant_id' => $assistant_id,
		);
	}

	/**
	 * List available blueprint slugs in a directory.
	 *
	 * @since  2.3.0
	 *
	 * @param  string $blueprint_dir Absolute path to the examples directory.
	 * @return string[]              Sorted list of blueprint slugs (without .json).
	 */
	public static function list_blueprints( $blueprint_dir ) {
		$dir = trailingslashit( $blueprint_dir );
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '*.json' );
		$slugs = array();

		foreach ( $files as $file ) {
			$basename = basename( $file, '.json' );
			// Skip files that are not blueprints (e.g. schema files).
			if ( 'schema' === $basename ) {
				continue;
			}
			$slugs[] = $basename;
		}

		sort( $slugs );
		return $slugs;
	}

	/**
	 * Get a human-readable label for a blueprint slug.
	 *
	 * @since  2.3.0
	 *
	 * @param  string $slug Blueprint slug.
	 * @return string       Human-readable label.
	 */
	public static function slug_to_label( $slug ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
