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
		// Hook into plugin activation to download components.
		add_action( 'wp_mcp_ai_after_activation', array( __CLASS__, 'download_on_activation' ) );

		// Add admin notice for download status.
		add_action( 'admin_notices', array( __CLASS__, 'show_download_notice' ) );

		// AJAX handler for manual downloads.
		add_action( 'wp_ajax_wp_mcp_ai_download_component', array( __CLASS__, 'ajax_download_component' ) );

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
	 * Download optional components on plugin activation.
	 *
	 * Schedules a background task to download components without blocking activation.
	 *
	 * @return void
	 */
	public static function download_on_activation() {
		$status = self::get_status();

		// Only schedule if not already downloaded and not currently downloading.
		if ( empty( $status['vectorizer']['downloaded'] ) || empty( $status['knowledge_base']['downloaded'] ) ) {
			if ( ! wp_next_scheduled( 'wp_mcp_ai_download_optional_components' ) ) {
				// Schedule immediate download in background.
				wp_schedule_single_event( time() + 10, 'wp_mcp_ai_download_optional_components' );
			}

			// Set flag that download is scheduled.
			set_transient( self::DOWNLOAD_IN_PROGRESS, true, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Perform background download of optional components.
	 *
	 * @return void
	 */
	public static function background_download() {
		// Download vectorizer if not present.
		if ( ! self::is_vectorizer_installed() ) {
			self::download_vectorizer();
		}

		// Download knowledge base if not present.
		if ( ! self::is_knowledge_base_complete() ) {
			self::download_knowledge_base();
		}

		// Clear the in-progress flag.
		delete_transient( self::DOWNLOAD_IN_PROGRESS );
	}

	/**
	 * Check if vectorizer is installed.
	 *
	 * @return bool True if vectorizer is installed.
	 */
	public static function is_vectorizer_installed() {
		$vectorizer_path = WP_MCP_AI_PATH . 'assets/js/vendor/neplex-vectorizer';
		return file_exists( $vectorizer_path ) && is_dir( $vectorizer_path );
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
	 * Download vectorizer library from GitHub.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function download_vectorizer() {
		$base_url     = self::get_download_base_url();
		$download_url = $base_url . '/neplex-vectorizer.zip';

		// Use uploads directory instead of plugin directory.
		$upload_dir = wp_upload_dir();

		// Check for upload directory errors.
		if ( ! empty( $upload_dir['error'] ) ) {
			$error_msg = $upload_dir['error'];
			self::update_status( 'vectorizer', 'error', $error_msg );
			return new WP_Error( 'upload_dir_error', $error_msg );
		}

		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-wpoos/vendor/';

		// Create directory if it doesn't exist.
		if ( ! wp_mkdir_p( $target_dir ) ) {
			$error_msg = __( 'Failed to create vendor directory in uploads folder.', 'mcp-ai-wpoos' );
			self::update_status( 'vectorizer', 'error', $error_msg );
			return new WP_Error( 'mkdir_failed', $error_msg );
		}

		$temp_file = download_url( $download_url );

		if ( is_wp_error( $temp_file ) ) {
			self::update_status( 'vectorizer', 'error', $temp_file->get_error_message() );
			return $temp_file;
		}

		// Extract ZIP file.
		$result = unzip_file( $temp_file, $target_dir );
		unlink( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

		if ( is_wp_error( $result ) ) {
			self::update_status( 'vectorizer', 'error', $result->get_error_message() );
			return $result;
		}

		self::update_status( 'vectorizer', 'downloaded', '' );
		return true;
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
			'vectorizer'     => array(
				'downloaded' => false,
				'status'     => 'not_downloaded',
				'error'      => '',
			),
			'knowledge_base' => array(
				'downloaded' => false,
				'status'     => 'not_downloaded',
				'error'      => '',
			),
		);

		$status = get_option( self::OPTION_NAME, $default );

		// Check actual file existence.
		$status['vectorizer']['downloaded']     = self::is_vectorizer_installed();
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
		// Only show on plugin pages.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'mcp' ) ) {
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
					<?php esc_html_e( 'Downloading optional components in the background (vectorizer library and complete knowledge base). This may take a few minutes.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// Show error notices if any.
		$has_error = false;

		if ( ! empty( $status['vectorizer']['error'] ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Open Operator System:', 'mcp-ai-wpoos' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Error message */
							__( 'Could not download vectorizer library: %s. The vectorize_image tool will not be available.', 'mcp-ai-wpoos' ),
							$status['vectorizer']['error']
						)
					);
					?>
				</p>
			</div>
			<?php
			$has_error = true;
		}

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
			if ( ! $shown && ( $status['vectorizer']['downloaded'] || $status['knowledge_base']['downloaded'] ) ) {
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

		$component = isset( $_POST['component'] ) ? sanitize_key( $_POST['component'] ) : '';

		if ( ! in_array( $component, array( 'vectorizer', 'knowledge_base' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid component' ) );
		}

		$result = false;
		if ( 'vectorizer' === $component ) {
			$result = self::download_vectorizer();
		} elseif ( 'knowledge_base' === $component ) {
			$result = self::download_knowledge_base();
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Component downloaded successfully' ) );
	}
}

// Initialize the optional components manager.
WP_MCP_AI_Optional_Components::init();
