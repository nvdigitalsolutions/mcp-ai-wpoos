<?php
/**
 * Global Hook Registrations
 *
 * Registers add_action / add_filter calls that live at the plugin-global scope:
 *   - Upload MIME type and size filters
 *   - Cache invalidation hooks for assistant changes
 *   - Admin notices (upload limits, plugin directory status)
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Upload MIME type and size filters
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_mcp_ai_extend_upload_mimes' ) ) {
	/**
	 * Ensure additional file-search formats can be uploaded when enabled.
	 *
	 * @param array|string $mimes Allowed mime types keyed by file extension.
	 * @return array
	 */
	function wp_mcp_ai_extend_upload_mimes( $mimes ) {
		if ( ! is_array( $mimes ) ) {
			$mimes = array();
		}

		if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			return $mimes;
		}

		$allowed_sets = WP_MCP_AI_Message_Attachments::get_allowed_mime_types();
		$file_mimes   = isset( $allowed_sets['file'] ) ? (array) $allowed_sets['file'] : array();

		$jsonl_candidates = array(
			'application/jsonl',
			'application/x-ndjson',
		);

		$selected_jsonl_mime = '';

		foreach ( $jsonl_candidates as $candidate ) {
			if ( in_array( $candidate, $file_mimes, true ) ) {
				$selected_jsonl_mime = $candidate;
				break;
			}
		}

		if ( '' !== $selected_jsonl_mime ) {
			$mimes['jsonl'] = $selected_jsonl_mime;
		}

		if ( in_array( 'application/x-ndjson', $file_mimes, true ) ) {
			$mimes['ndjson'] = 'application/x-ndjson';
		} elseif ( '' !== $selected_jsonl_mime ) {
			$mimes['ndjson'] = $selected_jsonl_mime;
		}

		if ( in_array( 'text/markdown', $file_mimes, true ) ) {
			$mimes['md']       = 'text/markdown';
			$mimes['markdown'] = 'text/markdown';
		}

		return $mimes;
	}
}

if ( ! has_filter( 'upload_mimes', 'wp_mcp_ai_extend_upload_mimes' ) ) {
	add_filter( 'upload_mimes', 'wp_mcp_ai_extend_upload_mimes' );
}

// Note: The global upload_size_limit filter was removed as part of the 2026-07-19
// compliance review. Increasing the upload limit globally for all plugins/users
// is a potential availability concern and should be managed at the server level
// (php.ini / .user.ini / .htaccess) rather than by a plugin filter.

// ---------------------------------------------------------------------------
// Cache invalidation hooks
// ---------------------------------------------------------------------------

/**
 * Setup cache invalidation hooks for assistant changes.
 *
 * Ensures caches are cleared when assistants are created, updated, or deleted.
 *
 * @since 1.0.0
 */
function wp_mcp_ai_setup_cache_invalidation_hooks() {
	if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
		return;
	}

	// Invalidate caches when assistant posts are saved or deleted.
	add_action( 'save_post_mcp_ai_assistant', 'wp_mcp_ai_invalidate_assistant_cache_on_save', 10, 1 );
	add_action( 'delete_post', 'wp_mcp_ai_invalidate_assistant_cache_on_delete', 10, 1 );
	add_action( 'wp_trash_post', 'wp_mcp_ai_invalidate_assistant_cache_on_delete', 10, 1 );
	add_action( 'untrash_post', 'wp_mcp_ai_invalidate_assistant_cache_on_save', 10, 1 );

	// Invalidate when assistant meta is updated.
	add_action( 'updated_post_meta', 'wp_mcp_ai_invalidate_assistant_cache_on_meta_update', 10, 4 );
	add_action( 'added_post_meta', 'wp_mcp_ai_invalidate_assistant_cache_on_meta_update', 10, 4 );
	add_action( 'deleted_post_meta', 'wp_mcp_ai_invalidate_assistant_cache_on_meta_update', 10, 4 );

	// REST API cache invalidation hooks.
	if ( class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
		add_action( 'save_post_mcp_ai_assistant', array( 'WP_MCP_AI_REST_Cache', 'invalidate_on_assistant_save' ), 10, 1 );
		add_action( 'delete_post', array( 'WP_MCP_AI_REST_Cache', 'invalidate_on_assistant_delete' ), 10, 1 );
		add_action( 'wp_trash_post', array( 'WP_MCP_AI_REST_Cache', 'invalidate_on_assistant_delete' ), 10, 1 );
		add_action( 'update_option_' . WP_MCP_AI_Admin_Settings::OPTION_NAME, array( 'WP_MCP_AI_REST_Cache', 'invalidate_on_settings_save' ) );
	}

	// Invalidate chat response cache when assistant config changes.
	add_action( 'save_post_mcp_ai_assistant', 'wp_mcp_ai_invalidate_chat_response_cache', 10, 1 );
}

/**
 * Invalidate cache when assistant is saved.
 *
 * @param int $post_id Post ID.
 */
function wp_mcp_ai_invalidate_assistant_cache_on_save( $post_id ) {
	if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
		return;
	}

	WP_MCP_AI_Cache_Helper::invalidate_assistant_cache( $post_id );
}

/**
 * Invalidate cache when assistant is deleted.
 *
 * @param int $post_id Post ID.
 */
function wp_mcp_ai_invalidate_assistant_cache_on_delete( $post_id ) {
	if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
		return;
	}

	$post = get_post( $post_id );
	if ( $post && 'mcp_ai_assistant' === $post->post_type ) {
		WP_MCP_AI_Cache_Helper::invalidate_assistant_caches();
	}
}

/**
 * Invalidate cache when assistant meta is updated.
 *
 * @param int    $meta_id    Meta ID.
 * @param int    $object_id  Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 */
function wp_mcp_ai_invalidate_assistant_cache_on_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook callback signature requires all parameters.
	if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
		return;
	}

	// Only invalidate for assistant meta keys.
	if ( 0 === strpos( $meta_key, 'mcp_ai_' ) ) {
		$post = get_post( $object_id );
		if ( $post && 'mcp_ai_assistant' === $post->post_type ) {
			WP_MCP_AI_Cache_Helper::invalidate_assistant_cache( $object_id );
		}
	}
}

/**
 * Invalidate chat response cache for the saved assistant.
 *
 * @param int $post_id Assistant post ID.
 */
function wp_mcp_ai_invalidate_chat_response_cache( $post_id ) {
	if ( class_exists( 'WP_MCP_AI_Chat_Response_Cache' ) ) {
		$cache = new WP_MCP_AI_Chat_Response_Cache();
		$cache->invalidate_for_assistant( $post_id );
	}
}

// Initialize cache invalidation hooks.
add_action( 'init', 'wp_mcp_ai_setup_cache_invalidation_hooks', 20 );

// Note: The "pending directory approval" admin notice was removed as part of
// the 2026-07-19 compliance review. The plugin is now live in the WordPress.org
// directory and this notice is no longer relevant.

// Note: The Pro plugin upload-limit notice was removed as part of the
// 2026-07-19 compliance review. Upload limits should be managed at the
// server level rather than via plugin admin notices.

// ---------------------------------------------------------------------------
// Model catalog migration & cron-driven discovery
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_mcp_ai_run_model_catalog_migration' ) ) {
	/**
	 * Run the one-time model catalog migration for the current JSON version.
	 *
	 * The migration rewrites stored model identifiers (in
	 * wp_mcp_ai_model_configs and assistant post meta) that reference ids
	 * removed during a catalog refresh, mapping them to documented
	 * successors. The catalog version (read from the bundled JSON file) is
	 * used as the bookkeeping key, so future refreshes re-run the routine.
	 *
	 * @return void
	 */
	function wp_mcp_ai_run_model_catalog_migration() {
		if ( ! class_exists( 'WP_MCP_AI_Model_Catalog_Migration' ) ) {
			return;
		}

		$catalog_version = '';
		$catalog_path    = WP_MCP_AI_PATH . 'includes/data/model-catalog.json';
		if ( file_exists( $catalog_path ) && is_readable( $catalog_path ) ) {
			$raw     = file_get_contents( $catalog_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading bundled JSON catalog.
			$decoded = json_decode( (string) $raw, true );
			if ( is_array( $decoded ) && isset( $decoded['version'] ) ) {
				$catalog_version = (string) $decoded['version'];
			} else {
				// JSON parse failed or missing version field. Use a sentinel keyed to the
				// raw bytes so the migration is recorded as run for this corrupt state
				// and does not repeat on every page load. A future valid JSON (with any
				// different `version`) re-triggers the migration automatically.
				$catalog_version = 'invalid-json-' . md5( (string) $raw );
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'model_catalog_json_invalid',
						'Bundled model catalog JSON is unreadable or missing a version field. Migration recorded with sentinel version.'
					);
				}
			}
		} else {
			$catalog_version = 'missing-json';
		}

		WP_MCP_AI_Model_Catalog_Migration::run_if_needed( $catalog_version );
	}
}

if ( ! has_action( 'init', 'wp_mcp_ai_run_model_catalog_migration' ) ) {
	add_action( 'init', 'wp_mcp_ai_run_model_catalog_migration', 20 );
}
