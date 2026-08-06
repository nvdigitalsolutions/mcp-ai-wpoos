<?php
/**
 * Optional Components Manager
 *
 * Handles downloading and managing optional components like knowledge base
 * that are excluded from the base plugin ZIP to reduce size.
 *
 * Note: As of current version, neplex-vectorizer is now bundled with the base plugin.
 * Only the knowledge base (profession playbooks) remains as an optional download.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages optional plugin components that can be downloaded on-demand.
 */
class WP_MCP_AI_Optional_Components {

	/**
	 * Option name for tracking download status.
	 */
	const OPTION_NAME = 'wp_mcp_ai_optional_components_status';

	/**
	 * GitHub release URL for downloading components.
	 */
	const GITHUB_RELEASE_BASE = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download';

	/**
	 * Dev-working URL for downloading components during development.
	 * This URL is used when WP_MCP_AI_DEV_COMPONENTS is defined.
	 */
	const DEV_WORKING_BASE = 'https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/dev-working/build/optional-components';

	/**
	 * Transient key for download in progress.
	 */
	const DOWNLOAD_IN_PROGRESS = 'wp_mcp_ai_downloading_components';

	/**
	 * Initialize the optional components manager.
	 */
	public static function init() {
		// Hook into plugin activation to show opt-in notice for optional component downloads.
		add_action( 'wp_mcp_ai_after_activation', array( __CLASS__, 'download_on_activation' ) );

		// Add admin notice for download status and opt-in consent.
		add_action( 'admin_notices', array( __CLASS__, 'show_download_notice' ) );

		// AJAX handler for individual manual downloads.
		add_action( 'wp_ajax_wp_mcp_ai_download_component', array( __CLASS__, 'ajax_download_component' ) );

		// AJAX handler for opt-in download of all components.
		add_action( 'wp_ajax_wp_mcp_ai_download_all_components', array( __CLASS__, 'ajax_download_all_components' ) );

		// AJAX handler for dismissing the opt-in notice.
		add_action( 'wp_ajax_wp_mcp_ai_dismiss_optional_components', array( __CLASS__, 'ajax_dismiss_optional_components' ) );

		// Background download via action scheduler or cron.
		add_action( 'wp_mcp_ai_download_optional_components', array( __CLASS__, 'background_download' ) );
	}

	/**
	 * Get the base URL for downloading components.
	 *
	 * Returns dev-working URL if WP_MCP_AI_DEV_COMPONENTS is defined,
	 * otherwise returns the GitHub release URL.
	 *
	 * @return string Base URL for component downloads.
	 */
	private static function get_download_base_url() {
		// Use dev-working location if explicitly enabled.
		if ( defined( 'WP_MCP_AI_DEV_COMPONENTS' ) && true === WP_MCP_AI_DEV_COMPONENTS ) {
			return self::DEV_WORKING_BASE;
		}

		// Default to GitHub releases.
		return self::GITHUB_RELEASE_BASE . '/v' . WP_MCP_AI_VERSION;
	}

	/**
	 * Handle plugin activation — show opt-in notice instead of auto-downloading.
	 *
	 * Per WordPress.org Guidelines 7 & 9, plugins must not auto-download optional
	 * components without explicit opt-in consent. This method flags that optional
	 * components are available but does NOT schedule any background downloads.
	 *
	 * @return void
	 */
	public static function download_on_activation() {
		// Flag that optional components are available for download (opt-in notice).
		// Do NOT auto-schedule downloads — the admin must explicitly consent first
		// via the admin notice or the AJAX manual download handler.
		if ( ! self::is_knowledge_base_complete() ) {
			update_option( 'wp_mcp_ai_optional_components_available', true );
		}
	}

	/**
	 * Perform background download of optional components.
	 *
	 * @return void
	 */
	public static function background_download() {
		// Download knowledge base if not present.
		if ( ! self::is_knowledge_base_complete() ) {
			self::download_knowledge_base();
		}

		// Clear the in-progress flag.
		delete_transient( self::DOWNLOAD_IN_PROGRESS );
	}

	/**
	 * Check if knowledge base is complete.
	 *
	 * @return bool True if all profession playbooks are present.
	 */
	public static function is_knowledge_base_complete() {
		$playbooks_dir = WP_MCP_AI_PATH . 'includes/knowledge-base/profession-playbooks/professions/';
		if ( ! file_exists( $playbooks_dir ) ) {
			return false;
		}

		$files = glob( $playbooks_dir . '*.txt' );
		// Expected 218 profession playbooks.
		return count( $files ) >= 200;
	}

	/**
	 * Download knowledge base from GitHub.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function download_knowledge_base() {
		$base_url     = self::get_download_base_url();
		$download_url = $base_url . '/knowledge-base.zip';

		// Use uploads directory instead of plugin directory.
		$upload_dir = wp_upload_dir();

		// Check for upload directory errors.
		if ( ! empty( $upload_dir['error'] ) ) {
			$error_msg = $upload_dir['error'];
			self::update_status( 'knowledge_base', 'error', $error_msg );
			return new WP_Error( 'upload_dir_error', $error_msg );
		}

		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-wpoos/knowledge-base/';

		// Create directory if it doesn't exist.
		if ( ! wp_mkdir_p( $target_dir ) ) {
			$error_msg = __( 'Failed to create knowledge base directory in uploads folder.', 'mcp-ai-wpoos' );
			self::update_status( 'knowledge_base', 'error', $error_msg );
			return new WP_Error( 'mkdir_failed', $error_msg );
		}

		$temp_file = download_url( $download_url );

		if ( is_wp_error( $temp_file ) ) {
			self::update_status( 'knowledge_base', 'error', $temp_file->get_error_message() );
			return $temp_file;
		}

		// Extract ZIP file.
		$result = unzip_file( $temp_file, $target_dir );
		unlink( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

		if ( is_wp_error( $result ) ) {
				self::update_status( 'knowledge_base', 'error', $result->get_error_message() );
				return $result;
		}

			// Validate the extracted archive to prevent path traversal and ensure
			// only expected file types are present.
			$validation = self::validate_knowledge_base_archive( $target_dir );
		if ( is_wp_error( $validation ) ) {
			// Clean up the extracted files that failed validation.
			self::delete_extracted_dir( $target_dir );
			self::update_status( 'knowledge_base', 'error', $validation->get_error_message() );
			return $validation;
		}

			self::update_status( 'knowledge_base', 'downloaded', '' );
		return true;
	}

	/**
	 * Get download status of optional components.
	 *
	 * @return array Status array with component info.
	 */
	public static function get_status() {
		$default = array(
			'knowledge_base' => array(
				'downloaded' => false,
				'status'     => 'not_downloaded',
				'error'      => '',
			),
		);

		$status = get_option( self::OPTION_NAME, $default );

		// Check actual file existence.
		$status['knowledge_base']['downloaded'] = self::is_knowledge_base_complete();

		return $status;
	}

	/**
	 * Update status of a component.
	 *
	 * @param string $component Component name (vectorizer or knowledge_base).
	 * @param string $status    Status (downloaded, error, not_downloaded).
	 * @param string $error     Error message if any.
	 * @return void
	 */
	public static function update_status( $component, $status, $error = '' ) {
		$current = self::get_status();

		$current[ $component ] = array(
			'downloaded' => ( 'downloaded' === $status ),
			'status'     => $status,
			'error'      => $error,
			'updated_at' => time(),
		);

		update_option( self::OPTION_NAME, $current );
	}

	/**
	 * Show admin notice about optional components download status.
	 *
	 * @return void
	 */
	public static function show_download_notice() {
		// Only show on NV oOS plugin pages.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'mcp-ai' ) ) {
			return;
		}

		$status      = self::get_status();
		$downloading = get_transient( self::DOWNLOAD_IN_PROGRESS );

		// Show downloading notice.
		if ( $downloading ) {
			?>
			<div class="notice notice-info is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Open Operator System:', 'mcp-ai-wpoos' ); ?></strong>
								<?php esc_html_e( 'Downloading optional components in the background (complete knowledge base). This may take a few minutes.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// Show opt-in notice for optional component downloads (WordPress.org Guidelines 7 & 9).
		$components_available = get_option( 'wp_mcp_ai_optional_components_available', false );
		$needs_knowledge_base = ! $status['knowledge_base']['downloaded'];

		if ( $components_available && $needs_knowledge_base ) {
			$nonce = wp_create_nonce( 'wp_mcp_ai_download_component' );
			?>
			<div class="notice notice-info is-dismissible" id="wp-mcp-ai-optional-components-notice">
				<p>
					<strong><?php esc_html_e( 'Open Operator System:', 'mcp-ai-wpoos' ); ?></strong>
				<?php esc_html_e( 'Optional components are available for download to enhance your experience (complete knowledge base with 218 profession playbooks). These are downloaded from the plugin\'s GitHub releases.', 'mcp-ai-wpoos' ); ?>
				</p>
				<p style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
					<button type="button" class="button button-primary" id="wp-mcp-ai-download-components-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Download Optional Components', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="button" class="button" id="wp-mcp-ai-dismiss-components-btn">
					<?php esc_html_e( 'No Thanks', 'mcp-ai-wpoos' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=data_management' ) ); ?>" class="button">
					<?php esc_html_e( 'Go to Data Management', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<?php
			ob_start();
			?>
					(function() {
						var downloadBtn = document.getElementById('wp-mcp-ai-download-components-btn');
						var dismissBtn = document.getElementById('wp-mcp-ai-dismiss-components-btn');
						if (downloadBtn) {
							downloadBtn.addEventListener('click', function() {
								downloadBtn.disabled = true;
								downloadBtn.textContent = <?php echo wp_json_encode( __( 'Downloading...', 'mcp-ai-wpoos' ) ); ?>;
								var data = new FormData();
								data.append('action', 'wp_mcp_ai_download_all_components');
								data.append('nonce', downloadBtn.getAttribute('data-nonce'));
								fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
									.then(function(response) {
										if (!response.ok) { throw new Error('HTTP ' + response.status); }
										downloadBtn.textContent = <?php echo wp_json_encode( __( 'Download started!', 'mcp-ai-wpoos' ) ); ?>;
										var notice = document.getElementById('wp-mcp-ai-optional-components-notice');
										if (notice) {
											var statusP = notice.querySelector('p:last-child');
											statusP.textContent = '';
											var em = document.createElement('em');
											em.textContent = <?php echo wp_json_encode( __( 'Components are downloading in the background. Please refresh in a few minutes.', 'mcp-ai-wpoos' ) ); ?>;
											statusP.appendChild(em);
										}
									})
									.catch(function() {
										downloadBtn.disabled = false;
										downloadBtn.textContent = <?php echo wp_json_encode( __( 'Download Optional Components', 'mcp-ai-wpoos' ) ); ?>;
										var notice = document.getElementById('wp-mcp-ai-optional-components-notice');
										if (notice) {
											var errorP = document.createElement('p');
											errorP.style.color = '#d63638';
											errorP.innerHTML = <?php echo wp_json_encode( sprintf( /* translators: %s: URL to the Data Management admin page */ __( 'Download failed. Please try again or manage your data on the <a href="%s">Data Management</a> page.', 'mcp-ai-wpoos' ), esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=data_management' ) ) ) ); ?>;
											notice.appendChild(errorP);
										}
									});
							});
						}
						if (dismissBtn) {
							dismissBtn.addEventListener('click', function() {
								var data = new FormData();
								data.append('action', 'wp_mcp_ai_dismiss_optional_components');
								data.append('_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_dismiss_components' ) ); ?>);
								fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' });
								var notice = document.getElementById('wp-mcp-ai-optional-components-notice');
								if (notice) { notice.style.display = 'none'; }
							});
						}
					})();
				<?php
				$js = ob_get_clean();
				wp_print_inline_script_tag( $js );
				?>
			<?php
			return;
		}

		// Show error notices if any.
		$has_error = false;

		if ( ! empty( $status['knowledge_base']['error'] ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Open Operator System:', 'mcp-ai-wpoos' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Error message */
							__( 'Could not download complete knowledge base: %s. Only 20 common professions are available.', 'mcp-ai-wpoos' ),
							$status['knowledge_base']['error']
						)
					);
					?>
				</p>
			</div>
			<?php
			$has_error = true;
		}

		// Show success notice after download completes (once).
		if ( ! $downloading && ! $has_error ) {
			$shown = get_transient( 'wp_mcp_ai_download_success_notice_shown' );
			if ( ! $shown && $status['knowledge_base']['downloaded'] ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Open Operator System:', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'Optional components downloaded successfully! All features are now available.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>
				<?php
				set_transient( 'wp_mcp_ai_download_success_notice_shown', true, DAY_IN_SECONDS );
			}
		}
	}

	/**
	 * AJAX handler for manual component downloads.
	 *
	 * @return void
	 */
	public static function ajax_download_component() {
		check_ajax_referer( 'wp_mcp_ai_download_component', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$component = isset( $_POST['component'] ) ? sanitize_key( wp_unslash( $_POST['component'] ) ) : '';

		if ( ! in_array( $component, array( 'knowledge_base' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid component' ) );
		}

		$result = false;
		if ( 'knowledge_base' === $component ) {
			$result = self::download_knowledge_base();
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Component downloaded successfully' ) );
	}

	/**
	 * AJAX handler for downloading all optional components after explicit consent.
	 *
	 * This is triggered when the admin clicks "Download Optional Components" in the
	 * opt-in admin notice. Downloads are scheduled in the background so the admin
	 * does not have to wait for completion.
	 *
	 * @return void
	 */
	public static function ajax_download_all_components() {
		check_ajax_referer( 'wp_mcp_ai_download_component', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// Schedule background download now that admin has opted in.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_download_optional_components' ) ) {
			wp_schedule_single_event( time() + 10, 'wp_mcp_ai_download_optional_components' );
		}

		set_transient( self::DOWNLOAD_IN_PROGRESS, true, HOUR_IN_SECONDS );

		// Clear the available flag since admin has consented.
		delete_option( 'wp_mcp_ai_optional_components_available' );

		wp_send_json_success( array( 'message' => 'Download scheduled. Components will be ready in a few minutes.' ) );
	}

	/**
	 * AJAX handler for dismissing the optional components notice.
	 *
	 * @return void
	 */
	public static function ajax_dismiss_optional_components() {
			check_ajax_referer( 'wp_mcp_ai_dismiss_components' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

			delete_option( 'wp_mcp_ai_optional_components_available' );
			wp_send_json_success();
	}

		/**
		 * Validate extracted knowledge base archive for security.
		 *
		 * Performs three checks:
		 * 1. Path traversal — every file's realpath must be within the target dir.
		 * 2. Extension allowlist — only .txt files are permitted.
		 * 3. Minimum playbook count — at least 200 .txt files must exist.
		 *
		 * @since 1.1.41
		 *
		 * @param string $target_dir Absolute path to extracted directory.
		 * @return true|WP_Error True if valid, WP_Error with details on failure.
		 */
	private static function validate_knowledge_base_archive( $target_dir ) {
		$target_dir = trailingslashit( $target_dir );

		if ( ! is_dir( $target_dir ) ) {
			return new WP_Error(
				'kb_validation_failed',
				__( 'Knowledge base directory does not exist after extraction.', 'mcp-ai-wpoos' )
			);
		}

		$real_target = realpath( $target_dir );
		if ( false === $real_target ) {
			return new WP_Error(
				'kb_validation_failed',
				__( 'Cannot resolve knowledge base directory path.', 'mcp-ai-wpoos' )
			);
		}

		$real_target = trailingslashit( $real_target );
		$count       = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $target_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$file_path = $file->getPathname();
				$real_file = realpath( $file_path );

				// Check 1: Path traversal — file must be within target dir.
				if ( false === $real_file || 0 !== strpos( $real_file, $real_target ) ) {
					return new WP_Error(
						'kb_path_traversal',
						sprintf(
							/* translators: %s: filename */
							__( 'Knowledge base archive contains a path traversal entry: %s', 'mcp-ai-wpoos' ),
							basename( $file_path )
						)
					);
				}

				// Check 2: Extension allowlist.
				$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
				if ( 'txt' !== $ext ) {
					return new WP_Error(
						'kb_invalid_extension',
						sprintf(
							/* translators: 1: filename, 2: extension */
							__( 'Knowledge base archive contains a disallowed file type (%2$s): %1$s', 'mcp-ai-wpoos' ),
							basename( $file_path ),
							$ext
						)
					);
				}

				++$count;
			}
		} catch ( Exception $e ) {
			return new WP_Error(
				'kb_validation_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Knowledge base validation error: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}

		// Check 3: Minimum playbook count.
		if ( $count < 200 ) {
			return new WP_Error(
				'kb_insufficient_playbooks',
				sprintf(
					/* translators: %d: number of playbook files found */
					__( 'Knowledge base archive contains only %d playbook files. At least 200 are expected.', 'mcp-ai-wpoos' ),
					$count
				)
			);
		}

		return true;
	}

		/**
		 * Recursively delete a directory that failed validation.
		 *
		 * Used to clean up extracted archives that did not pass the security
		 * validation check. Only performs deletion if the directory is within
		 * the expected uploads base path.
		 *
		 * @since 1.1.41
		 *
		 * @param string $dir Absolute path to directory.
		 * @return void
		 */
	private static function delete_extracted_dir( $dir ) {
		if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		// Verify containment within uploads directory.
		$upload_dir = wp_upload_dir();
		$real_dir   = realpath( $dir );
		$real_base  = realpath( $upload_dir['basedir'] );

		if ( ! $real_dir || ! $real_base || 0 !== strpos( $real_dir, $real_base ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::delete_extracted_dir( $path );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $path );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $dir );
	}
}

// Initialize the optional components manager.
WP_MCP_AI_Optional_Components::init();

// Initialize the plugin updater (GitHub releases for full builds).
WP_MCP_AI_Plugin_Updater::init();
