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

if ( ! function_exists( 'wp_mcp_ai_increase_upload_size_limit' ) ) {
	/**
	 * Increase upload size limit for plugin uploads.
	 *
	 * The pro plugin ZIP files are approximately 50MB in size, which exceeds
	 * the default WordPress upload limit (often 2-10MB). This filter increases
	 * the limit to accommodate large plugin uploads while respecting PHP's
	 * upload_max_filesize and post_max_size settings.
	 *
	 * Note: This filter cannot exceed PHP's upload_max_filesize and post_max_size.
	 * If you see "The link you followed has expired" when uploading large plugins,
	 * you need to increase these PHP settings on your server.
	 *
	 * @since 1.1.2
	 *
	 * @param int $size Upload size limit in bytes.
	 * @return int Modified upload size limit in bytes.
	 */
	function wp_mcp_ai_increase_upload_size_limit( $size ) {
		// Get PHP limits.
		$upload_max_filesize = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
		$post_max_size       = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );

		// Use the smaller of the two PHP limits.
		$php_limit = min( $upload_max_filesize, $post_max_size );

		// Set to 100MB or PHP limit, whichever is smaller.
		$new_limit = min( 100 * MB_IN_BYTES, $php_limit );

		// Only increase the limit, never decrease it.
		return max( $size, $new_limit );
	}
}

if ( ! has_filter( 'upload_size_limit', 'wp_mcp_ai_increase_upload_size_limit' ) ) {
	add_filter( 'upload_size_limit', 'wp_mcp_ai_increase_upload_size_limit' );
}

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

// ---------------------------------------------------------------------------
// Admin notices
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_mcp_ai_plugin_directory_pending_notice' ) ) {
	/**
	 * Display admin notice indicating plugin is pending WordPress Plugin Directory approval.
	 *
	 * This notice builds trust by transparently communicating the plugin's approval status.
	 * Users can dismiss the notice, and it will not be shown again.
	 */
	function wp_mcp_ai_plugin_directory_pending_notice() {
		// Check if user has dismissed the notice.
		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, 'wp_mcp_ai_dismissed_directory_notice', true ) ) {
			return;
		}

		// Only show to users who can manage options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="notice notice-info is-dismissible" data-dismissible="wp_mcp_ai_directory_notice">
			<p>
				<strong><?php esc_html_e( 'NV Digital Open Operator System (oOS):', 'mcp-ai-wpoos' ); ?></strong>
				<?php esc_html_e( 'This plugin is currently pending approval in the WordPress Plugin Directory. We are committed to maintaining high quality and security standards.', 'mcp-ai-wpoos' ); ?>
			</p>
		</div>
		<?php
		ob_start();
		?>
		jQuery(document).ready(function($) {
			$(document).on('click', '[data-dismissible="wp_mcp_ai_directory_notice"] .notice-dismiss', function() {
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wp_mcp_ai_dismiss_directory_notice',
						nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_dismiss_directory_notice' ) ); ?>
					}
				});
			});
		});
		<?php
		$js = ob_get_clean();
		wp_print_inline_script_tag( $js );
		?>
		<?php
	}
}

if ( ! function_exists( 'wp_mcp_ai_dismiss_directory_notice_ajax' ) ) {
	/**
	 * Handle AJAX request to dismiss the plugin directory pending notice.
	 */
	function wp_mcp_ai_dismiss_directory_notice_ajax() {
		check_ajax_referer( 'wp_mcp_ai_dismiss_directory_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
			return;
		}

		$user_id = get_current_user_id();
		update_user_meta( $user_id, 'wp_mcp_ai_dismissed_directory_notice', true );

		wp_send_json_success();
	}
}

add_action( 'admin_notices', 'wp_mcp_ai_plugin_directory_pending_notice' );
add_action( 'wp_ajax_wp_mcp_ai_dismiss_directory_notice', 'wp_mcp_ai_dismiss_directory_notice_ajax' );

if ( ! function_exists( 'wp_mcp_ai_check_upload_limits_notice' ) ) {
	/**
	 * Display admin notice if PHP upload limits are too low for pro plugin upload.
	 *
	 * The pro plugin ZIP files are approximately 50-53MB. If PHP's upload_max_filesize
	 * or post_max_size are below 64MB, users will encounter "The link you followed has
	 * expired" error when trying to upload the plugin.
	 *
	 * @since 1.1.2
	 */
	function wp_mcp_ai_check_upload_limits_notice() {
		// Only show on plugins page or plugin install page.
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugin-install' ), true ) ) {
			return;
		}

		// Only show to administrators.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get PHP limits.
		$upload_max_filesize = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
		$post_max_size       = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$php_limit           = min( $upload_max_filesize, $post_max_size );

		// Pro plugin size is approximately 50-53MB, recommend at least 64MB.
		$recommended_size = 64 * MB_IN_BYTES;

		if ( $php_limit < $recommended_size ) {
			$current_limit      = size_format( $php_limit );
			$recommended_format = size_format( $recommended_size );

			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'NV oOS Pro Plugin Upload Limit Warning', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: 1: current limit, 2: recommended limit */
							__( 'Your server\'s PHP upload limit is currently %1$s. To upload the NV oOS Pro plugin (approximately 50MB), you need at least %2$s.', 'mcp-ai-wpoos' ),
							array( 'strong' => array() )
						),
						'<strong>' . esc_html( $current_limit ) . '</strong>',
						'<strong>' . esc_html( $recommended_format ) . '</strong>'
					);
					?>
				</p>
				<p>
					<?php esc_html_e( 'If you see "The link you followed has expired" when uploading the pro plugin, increase these PHP settings:', 'mcp-ai-wpoos' ); ?>
				</p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><code>upload_max_filesize = 64M</code></li>
					<li><code>post_max_size = 64M</code></li>
					<li><code>memory_limit = 256M</code> <?php esc_html_e( '(recommended)', 'mcp-ai-wpoos' ); ?></li>
				</ul>
				<p>
					<strong><?php esc_html_e( 'How to increase these limits:', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<details style="margin-left: 20px;">
					<summary style="cursor: pointer; font-weight: bold;">
						<?php esc_html_e( 'Option 1: Edit php.ini (requires server access)', 'mcp-ai-wpoos' ); ?>
					</summary>
					<pre style="background: #f5f5f5; padding: 10px; margin: 10px 0;">upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 256M</pre>
				</details>
				<details style="margin-left: 20px;">
					<summary style="cursor: pointer; font-weight: bold;">
						<?php esc_html_e( 'Option 2: Create .user.ini in WordPress root (cPanel/shared hosting)', 'mcp-ai-wpoos' ); ?>
					</summary>
					<pre style="background: #f5f5f5; padding: 10px; margin: 10px 0;">upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 256M</pre>
					<p style="margin: 10px 0;">
						<?php esc_html_e( 'Note: Changes may take 5 minutes to take effect.', 'mcp-ai-wpoos' ); ?>
					</p>
				</details>
				<details style="margin-left: 20px;">
					<summary style="cursor: pointer; font-weight: bold;">
						<?php esc_html_e( 'Option 3: Add to .htaccess (Apache servers)', 'mcp-ai-wpoos' ); ?>
					</summary>
					<pre style="background: #f5f5f5; padding: 10px; margin: 10px 0;">php_value upload_max_filesize 64M
php_value post_max_size 64M
php_value memory_limit 256M</pre>
				</details>
				<details style="margin-left: 20px;">
					<summary style="cursor: pointer; font-weight: bold;">
						<?php esc_html_e( 'Option 4: Contact your hosting provider', 'mcp-ai-wpoos' ); ?>
					</summary>
					<p style="margin: 10px 0;">
						<?php esc_html_e( 'Ask them to increase upload_max_filesize and post_max_size to at least 64M.', 'mcp-ai-wpoos' ); ?>
					</p>
				</details>
				<p>
					<em>
						<?php esc_html_e( 'After making changes, refresh this page to verify the new limits are active.', 'mcp-ai-wpoos' ); ?>
					</em>
				</p>
			</div>
			<?php
		}
	}
}

add_action( 'admin_notices', 'wp_mcp_ai_check_upload_limits_notice' );

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
