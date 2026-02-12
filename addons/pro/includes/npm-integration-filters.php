<?php
/**
 * NPM Package Integration Filter Handlers
 *
 * This file provides WordPress filter implementations that connect PHP services
 * to Node.js microservices for NPM package functionality.
 *
 * Usage:
 * Include this file in your theme's functions.php or a custom plugin:
 * require_once WP_MCP_AI_PRO_PATH . 'includes/npm-integration-filters.php';
 *
 * Or selectively enable specific integrations using the individual functions.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if an NPM package is available
 *
 * Checks for package availability in CDN (via CDN Loader), vendor directory,
 * bundle files, or node_modules.
 *
 * @since 1.1.1
 * @param string $package_name Package name (e.g., 'katex', 'chart.js', 'pdfkit').
 * @return bool True if package is available.
 */
function wp_mcp_ai_is_npm_package_available( $package_name ) {
	// Check if CDN Loader is available and handles this package.
	if ( class_exists( 'WP_MCP_AI_Pro_CDN_Loader' ) ) {
		if ( WP_MCP_AI_Pro_CDN_Loader::is_package_available( $package_name ) ) {
			return true;
		}
	}

	// Check vendor directory (pre-packaged distribution).
	$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/' . $package_name;
	if ( is_dir( $vendor_path ) || file_exists( $vendor_path . '/package.json' ) ) {
		return true;
	}

	// Check node_modules (development environment).
	$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package_name;
	if ( is_dir( $node_modules_path ) || file_exists( $node_modules_path . '/package.json' ) ) {
		return true;
	}

	return false;
}

/**
 * Get NPM package status information
 *
 * Returns detailed status about how a package is available (CDN, vendor, node_modules, etc.).
 *
 * @since 1.1.1
 * @param string $package_name Package name.
 * @return array Status array with 'available', 'source', and 'message' keys.
 */
function wp_mcp_ai_get_npm_package_status( $package_name ) {
	// Check if CDN Loader is available and handles this package.
	if ( class_exists( 'WP_MCP_AI_Pro_CDN_Loader' ) ) {
		$status = WP_MCP_AI_Pro_CDN_Loader::get_package_status( $package_name );
		if ( $status['available'] ) {
			return $status;
		}
	}

	// Check vendor directory (pre-packaged distribution).
	$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/' . $package_name;
	if ( is_dir( $vendor_path ) || file_exists( $vendor_path . '/package.json' ) ) {
		return array(
			'available' => true,
			'source'    => 'vendor',
			'message'   => sprintf(
				/* translators: %s: package name */
				__( '%s (Bundled)', 'mcp-ai-wpoos-pro' ),
				$package_name
			),
		);
	}

	// Check node_modules (development environment).
	$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package_name;
	if ( is_dir( $node_modules_path ) || file_exists( $node_modules_path . '/package.json' ) ) {
		return array(
			'available' => true,
			'source'    => 'node_modules',
			'message'   => sprintf(
				/* translators: %s: package name */
				__( '%s (Development)', 'mcp-ai-wpoos-pro' ),
				$package_name
			),
		);
	}

	// Not available.
	return array(
		'available' => false,
		'source'    => 'none',
		'message'   => sprintf(
			/* translators: %s: package name */
			__( '%s (Not installed)', 'mcp-ai-wpoos-pro' ),
			$package_name
		),
	);
}

/**
 * Check if Node.js is available
 *
 * @return bool True if Node.js is available.
 */
function wp_mcp_ai_is_nodejs_available() {
	static $available = null;

	if ( null === $available ) {
		// Use Process Service to check for Node.js availability.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$available       = $process_service->is_command_available( 'node' );
	}

	return $available;
}

/**
 * Execute Node.js service script
 *
 * @param string $service_file Service file path.
 * @param string $action       Action to perform.
 * @param array  $params       Parameters to pass.
 * @param int    $timeout      Timeout in seconds (default: 30).
 * @return string|WP_Error Result or error.
 */
function wp_mcp_ai_exec_node_service( $service_file, $action, $params, $timeout = 30 ) {
	if ( ! wp_mcp_ai_is_nodejs_available() ) {
		return new WP_Error(
			'nodejs_not_available',
			__( 'Node.js is not available on this server.', 'mcp-ai-wpoos-pro' )
		);
	}

	if ( ! file_exists( $service_file ) ) {
		return new WP_Error(
			'service_not_found',
			sprintf(
				/* translators: %s: service file path */
				__( 'Node.js service not found: %s', 'mcp-ai-wpoos-pro' ),
				$service_file
			)
		);
	}

	// Get Process Service.
	$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

	// Build command as array for better security.
	$command = array(
		'node',
		$service_file,
		$action,
		wp_json_encode( $params ),
	);

	// Execute command using Process Service.
	$result = $process_service->run_silent( $command, array( 'timeout' => $timeout ) );

	// Check for timeout.
	if ( isset( $result['timeout'] ) && $result['timeout'] ) {
		return new WP_Error(
			'node_timeout',
			sprintf(
				/* translators: %d: timeout in seconds */
				__( 'Node.js service timed out after %d seconds.', 'mcp-ai-wpoos-pro' ),
				$timeout
			)
		);
	}

	// Handle errors.
	if ( ! $result['success'] ) {
		$output_text = $result['output'] . $result['error'];
		$error_data  = json_decode( $output_text, true );

		if ( isset( $error_data['error'] ) ) {
			return new WP_Error(
				'node_execution_failed',
				$error_data['error']
			);
		}

		return new WP_Error(
			'node_execution_failed',
			sprintf(
				/* translators: %s: error output */
				__( 'Node.js service failed: %s', 'mcp-ai-wpoos-pro' ),
				$output_text
			)
		);
	}

	return $result['output'];
}

/**
 * ============================================================================
 * PRETTIER FILTER HANDLERS
 * ============================================================================
 */

/**
 * Format code using Prettier Node.js service
 *
 * @param string|false $result Result from previous filters.
 * @param array        $params Formatting parameters.
 * @return string|WP_Error Formatted code or error.
 */
function wp_mcp_ai_prettier_format_code_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/prettier-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'format', $params, 30 );

	return $output;
}

/**
 * Check code syntax using Prettier Node.js service
 *
 * @param bool|WP_Error|false $result Result from previous filters.
 * @param array               $params Check parameters.
 * @return bool|WP_Error Check result or error.
 */
function wp_mcp_ai_prettier_check_syntax_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/prettier-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'check', $params, 10 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );
	return isset( $result_data['valid'] ) ? $result_data['valid'] : true;
}

/**
 * ============================================================================
 * MJML FILTER HANDLERS
 * ============================================================================
 */

/**
 * Compile MJML to HTML using Node.js service
 *
 * @param string|false $result Result from previous filters.
 * @param array        $params Compilation parameters.
 * @return string|WP_Error HTML output or error.
 */
function wp_mcp_ai_mjml_compile_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/mjml-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'compile', $params, 30 );

	return $output;
}

/**
 * Validate MJML markup using Node.js service
 *
 * @param bool|WP_Error|false $result Result from previous filters.
 * @param array               $params Validation parameters.
 * @return bool|WP_Error Validation result or error.
 */
function wp_mcp_ai_mjml_validate_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/mjml-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'validate', $params, 10 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );

	if ( isset( $result_data['valid'] ) && ! $result_data['valid'] ) {
		$errors = array();
		if ( isset( $result_data['errors'] ) ) {
			foreach ( $result_data['errors'] as $error ) {
				$errors[] = isset( $error['message'] ) ? $error['message'] : 'Unknown error';
			}
		}
		return new WP_Error( 'mjml_validation_failed', implode( '; ', $errors ) );
	}

	return true;
}

/**
 * ============================================================================
 * FLUENT-FFMPEG FILTER HANDLERS
 * ============================================================================
 */

/**
 * Get video metadata using fluent-ffmpeg Node.js service
 *
 * @param array|false $result Result from previous filters.
 * @param array       $params Metadata parameters.
 * @return array|WP_Error Video metadata or error.
 */
function wp_mcp_ai_fluent_ffmpeg_get_metadata_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/ffmpeg-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'metadata', $params, 60 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$metadata = json_decode( $output, true );

	if ( null === $metadata ) {
		return new WP_Error(
			'invalid_metadata',
			__( 'Failed to parse video metadata.', 'mcp-ai-wpoos-pro' )
		);
	}

	return $metadata;
}

/**
 * Transcode video using fluent-ffmpeg Node.js service
 *
 * @param string|false $result Result from previous filters.
 * @param array        $params Transcoding parameters.
 * @return string|WP_Error Output path or error.
 */
function wp_mcp_ai_fluent_ffmpeg_transcode_video_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/ffmpeg-service.js';

	// Transcoding can take a long time, set appropriate timeout based on file size.
	$timeout = 300; // 5 minutes default.

	/**
	 * Filter the timeout for video transcoding.
	 *
	 * @param int   $timeout Timeout in seconds.
	 * @param array $params  Transcoding parameters.
	 */
	$timeout = apply_filters( 'wp_mcp_ai_ffmpeg_transcode_timeout', $timeout, $params );

	$output = wp_mcp_ai_exec_node_service( $service_file, 'transcode', $params, $timeout );

	return $output;
}

/**
 * ============================================================================
 * YFINANCE FILTER HANDLERS
 * ============================================================================
 */

/**
 * Get ticker information from yfinance service
 *
 * @param array|false $result Result from previous filters.
 * @param array       $params Request parameters.
 * @return array|WP_Error Ticker information or error.
 */
function wp_mcp_ai_yfinance_ticker_info_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/yfinance-client.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'ticker_info', $params, 15 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );

	if ( ! $result_data || ! isset( $result_data['success'] ) || ! $result_data['success'] ) {
		return new WP_Error(
			'yfinance_ticker_info_failed',
			$result_data['error'] ?? __( 'Failed to fetch ticker information.', 'mcp-ai-wpoos-pro' )
		);
	}

	return $result_data['data'] ?? array();
}

/**
 * Get current price from yfinance service
 *
 * @param array|false $result Result from previous filters.
 * @param array       $params Request parameters.
 * @return array|WP_Error Price data or error.
 */
function wp_mcp_ai_yfinance_current_price_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/yfinance-client.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'current_price', $params, 15 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );

	if ( ! $result_data || ! isset( $result_data['success'] ) || ! $result_data['success'] ) {
		return new WP_Error(
			'yfinance_price_failed',
			$result_data['error'] ?? __( 'Failed to fetch current price.', 'mcp-ai-wpoos-pro' )
		);
	}

	return $result_data['data'] ?? array();
}

/**
 * Get batch prices from yfinance service
 *
 * @param array|false $result Result from previous filters.
 * @param array       $params Request parameters.
 * @return array|WP_Error Batch price data or error.
 */
function wp_mcp_ai_yfinance_batch_prices_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/yfinance-client.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'batch_prices', $params, 20 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );

	if ( ! $result_data || ! isset( $result_data['success'] ) || ! $result_data['success'] ) {
		return new WP_Error(
			'yfinance_batch_prices_failed',
			$result_data['error'] ?? __( 'Failed to fetch batch prices.', 'mcp-ai-wpoos-pro' )
		);
	}

	return $result_data['data'] ?? array();
}

/**
 * Get price history from yfinance service
 *
 * @param array|false $result Result from previous filters.
 * @param array       $params Request parameters.
 * @return array|WP_Error Historical price data or error.
 */
function wp_mcp_ai_yfinance_price_history_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/yfinance-client.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'price_history', $params, 20 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );

	if ( ! $result_data || ! isset( $result_data['success'] ) || ! $result_data['success'] ) {
		return new WP_Error(
			'yfinance_history_failed',
			$result_data['error'] ?? __( 'Failed to fetch price history.', 'mcp-ai-wpoos-pro' )
		);
	}

	return $result_data;
}

/**
 * Search ticker symbols using yfinance service
 *
 * @param array|false $result Result from previous filters.
 * @param array       $params Request parameters.
 * @return array|WP_Error Search results or error.
 */
function wp_mcp_ai_yfinance_search_ticker_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/yfinance-client.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'search', $params, 10 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );

	if ( ! $result_data || ! isset( $result_data['success'] ) || ! $result_data['success'] ) {
		return new WP_Error(
			'yfinance_search_failed',
			$result_data['error'] ?? __( 'Failed to search ticker symbols.', 'mcp-ai-wpoos-pro' )
		);
	}

	return $result_data['results'] ?? array();
}

/**
 * Check health of yfinance service
 *
 * @param array|false $result Result from previous filters.
 * @param array       $params Request parameters.
 * @return array Health status.
 */
function wp_mcp_ai_yfinance_health_check_handler( $result, $params ) {
	// If already handled by another filter, return it.
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/yfinance-client.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'health', $params, 5 );

	if ( is_wp_error( $output ) ) {
		return array(
			'success' => false,
			'error'   => $output->get_error_message(),
		);
	}

	$result_data = json_decode( $output, true );

	return $result_data ?? array( 'success' => false );
}

/**
 * ============================================================================
 * REGISTRATION FUNCTIONS
 * ============================================================================
 */

/**
 * Register Prettier filter handlers
 */
function wp_mcp_ai_register_prettier_filters() {
	add_filter( 'wp_mcp_ai_prettier_format_code', 'wp_mcp_ai_prettier_format_code_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_prettier_check_syntax', 'wp_mcp_ai_prettier_check_syntax_handler', 10, 2 );
}

/**
 * Register MJML filter handlers
 */
function wp_mcp_ai_register_mjml_filters() {
	add_filter( 'wp_mcp_ai_mjml_compile', 'wp_mcp_ai_mjml_compile_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_mjml_validate', 'wp_mcp_ai_mjml_validate_handler', 10, 2 );
}

/**
 * Register fluent-ffmpeg filter handlers
 */
function wp_mcp_ai_register_ffmpeg_filters() {
	add_filter( 'wp_mcp_ai_fluent_ffmpeg_get_metadata', 'wp_mcp_ai_fluent_ffmpeg_get_metadata_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_fluent_ffmpeg_transcode_video', 'wp_mcp_ai_fluent_ffmpeg_transcode_video_handler', 10, 2 );
}

/**
 * Register yfinance filter handlers
 */
function wp_mcp_ai_register_yfinance_filters() {
	add_filter( 'wp_mcp_ai_yfinance_ticker_info', 'wp_mcp_ai_yfinance_ticker_info_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_yfinance_current_price', 'wp_mcp_ai_yfinance_current_price_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_yfinance_batch_prices', 'wp_mcp_ai_yfinance_batch_prices_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_yfinance_price_history', 'wp_mcp_ai_yfinance_price_history_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_yfinance_search_ticker', 'wp_mcp_ai_yfinance_search_ticker_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_yfinance_health_check', 'wp_mcp_ai_yfinance_health_check_handler', 10, 2 );
}

/**
 * Register all NPM package filter handlers
 *
 * Call this function to enable all NPM package integrations at once.
 */
function wp_mcp_ai_register_all_npm_filters() {
	wp_mcp_ai_register_prettier_filters();
	wp_mcp_ai_register_mjml_filters();
	wp_mcp_ai_register_ffmpeg_filters();
	wp_mcp_ai_register_yfinance_filters();
}

/**
 * ============================================================================
 * AUTO-REGISTRATION
 * ============================================================================
 */

/**
 * Auto-register NPM filters on init hook
 *
 * Deferred to init hook to avoid instantiating Process Service during plugin activation.
 * This prevents fatal errors when proc_open is not available on the server.
 */
function wp_mcp_ai_auto_register_npm_filters() {
	/**
	 * Check if auto-registration is enabled
	 *
	 * Auto-registration can be controlled via constant or filter.
	 * Default is enabled if Node.js is available.
	 */
	$auto_register = defined( 'WP_MCP_AI_AUTO_REGISTER_NPM_FILTERS' )
		? WP_MCP_AI_AUTO_REGISTER_NPM_FILTERS
		: true;

	/**
	 * Filter to control auto-registration of NPM filters.
	 *
	 * @param bool $auto_register Whether to auto-register filters.
	 */
	$auto_register = apply_filters( 'wp_mcp_ai_auto_register_npm_filters', $auto_register );

	// Auto-register if enabled and Node.js is available.
	if ( $auto_register && wp_mcp_ai_is_nodejs_available() ) {
		wp_mcp_ai_register_all_npm_filters();
	}
}
add_action( 'init', 'wp_mcp_ai_auto_register_npm_filters', 20 );

/**
 * ============================================================================
 * ADMIN NOTICES
 * ============================================================================
 */

/**
 * Check if vendor packages are available
 *
 * @return array Array with 'available' boolean and 'missing' array of package names.
 */
function wp_mcp_ai_check_vendor_packages() {
	// CDN-loaded packages that don't need to be in vendor directory.
	// These are loaded from jsDelivr with automatic fallback.
	$cdn_packages = array( 'prettier', 'katex', 'chart.js', 'd3', 'axios', 'mathjs' );

	$packages = array(
		'prettier'      => 'assets/vendor/prettier/standalone.js',
		'mjml'          => 'assets/vendor/mjml/lib/index.js',
		'fluent-ffmpeg' => 'assets/vendor/fluent-ffmpeg/index.js',
		'sharp'         => 'assets/vendor/sharp/lib/index.js',
		'katex'         => 'assets/vendor/katex/dist/katex.min.js',
		'ics'           => 'assets/vendor/ics/index.js',
		'turf'          => 'assets/vendor/turf/dist/cjs/index.cjs',
	);

	$missing = array();
	foreach ( $packages as $name => $path ) {
		// Skip CDN packages - they're loaded from CDN, not vendor directory.
		if ( in_array( $name, $cdn_packages, true ) ) {
			continue;
		}

		$full_path = WP_MCP_AI_PRO_PATH . $path;
		// Also check for alternate paths that might exist.
		$alt_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $name;

		if ( ! file_exists( $full_path ) && ! is_dir( $alt_path ) ) {
			$missing[] = $name;
		}
	}

	return array(
		'available' => empty( $missing ),
		'missing'   => $missing,
	);
}

/**
 * Show admin notice if Node.js is not available but tools require it
 */
function wp_mcp_ai_npm_integration_admin_notice() {
	// Only show in admin.
	if ( ! is_admin() ) {
		return;
	}

	// Check if any Pro NPM tools are enabled.
	$settings             = get_option( 'wp_mcp_ai_settings', array() );
	$npm_features_enabled = false;

	// List of settings that require NPM packages.
	$npm_dependent_settings = array(
		'enable_media_toolkit',     // Uses sharp (already has service).
		'enable_quiz_system',       // Uses katex (already has service).
		'enable_project_management', // Uses ics (already has service).
		'enable_health_wellness_management', // Uses chart.js (already has service).
		'enable_places_management',  // Uses turf (already has service).
	);

	foreach ( $npm_dependent_settings as $setting ) {
		if ( ! empty( $settings[ $setting ] ) ) {
			$npm_features_enabled = true;
			break;
		}
	}

	// If no NPM features are enabled, don't show notice.
	if ( ! $npm_features_enabled ) {
		return;
	}

	// Check if vendor packages are available.
	$package_check = wp_mcp_ai_check_vendor_packages();

	// Check if Node.js is available.
	$nodejs_available = wp_mcp_ai_is_nodejs_available();

	// Show notice if either packages are missing or Node.js is unavailable.
	if ( ! $package_check['available'] || ! $nodejs_available ) {
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'NV oOS Pro: Node.js Integration Required', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<?php if ( ! $package_check['available'] ) : ?>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: link to documentation */
						__( 'Some required NPM packages are missing from the vendor directory. This may indicate an incomplete installation. Please download the complete Pro addon package or contact support. See <a href="%s" target="_blank">integration guide</a> for details.', 'mcp-ai-wpoos-pro' ),
						admin_url( 'admin.php?page=wp-mcp-ai-settings#npm-integration' )
					)
				);
				?>
			</p>
				<?php if ( ! empty( $package_check['missing'] ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Missing packages:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php echo esc_html( implode( ', ', $package_check['missing'] ) ); ?>
			</p>
			<?php endif; ?>
			<?php endif; ?>
			
			<?php if ( ! $nodejs_available ) : ?>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: link to Node.js download */
						__( 'Node.js is not installed on your server. Some Pro features require Node.js to execute. Please install <a href="%s" target="_blank">Node.js</a> on your server.', 'mcp-ai-wpoos-pro' ),
						'https://nodejs.org/'
					)
				);
				?>
			</p>
			<?php endif; ?>
			
			<p>
				<strong><?php esc_html_e( 'Features requiring Node.js:', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<ul style="list-style-type: disc; margin-left: 2em;">
				<li><?php esc_html_e( 'Code formatting (format_code_prettier tool)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Email template generation (generate_email_template tool)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Video transcoding (transcode_video tool)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Advanced image processing (optimize_image_sharp tool)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Math equation rendering (render_math_equation tool)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Calendar export (export_calendar_ics tool)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Health charts (generate_health_chart tool)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Geospatial analysis (analyze_geospatial tool)', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
add_action( 'admin_notices', 'wp_mcp_ai_npm_integration_admin_notice' );

/**
 * Generate QR code for TOTP authentication
 *
 * Uses qrcode NPM package to generate QR codes for authenticator apps.
 *
 * @since 1.3.0
 *
 * @param string $data    Data to encode (typically otpauth:// URI).
 * @param string $format  Output format: 'base64' (default), 'svg', or 'data-url'.
 * @param array  $options QR code options (size, margin, error correction level).
 * @return string|WP_Error QR code string or WP_Error on failure.
 */
function wp_mcp_ai_generate_qr_code( $data, $format = 'data-url', $options = array() ) {
	// Check if Node.js is available.
	if ( ! wp_mcp_ai_is_nodejs_available() ) {
		return new WP_Error(
			'nodejs_not_available',
			__( 'Node.js is required for QR code generation. Please install Node.js on your server.', 'mcp-ai-wpoos-pro' )
		);
	}

	// Default options.
	$defaults = array(
		'width'                => 200,
		'margin'               => 2,
		'errorCorrectionLevel' => 'M', // L, M, Q, H.
		'color'                => array(
			'dark'  => '#000000',
			'light' => '#ffffff',
		),
	);

	$options = wp_parse_args( $options, $defaults );

	// Build service path.
	$service_file = WP_MCP_AI_PRO_PATH . 'includes/npm-services/qrcode-service.js';

	// If service doesn't exist yet, create a simple inline implementation.
	if ( ! file_exists( $service_file ) ) {
		// Create the service file.
		$service_dir = dirname( $service_file );
		if ( ! file_exists( $service_dir ) ) {
			wp_mkdir_p( $service_dir );
		}

		$service_code = <<<'JAVASCRIPT'
#!/usr/bin/env node
/**
 * QR Code Generation Service
 * 
 * Uses qrcode NPM package to generate QR codes.
 * Compatible with TOTP authenticator apps.
 */

const QRCode = require('qrcode');

// Get command line arguments.
const action = process.argv[2];
const params = JSON.parse(process.argv[3] || '{}');

async function generateQRCode() {
    try {
        const { data, format, options } = params;
        
        if (!data) {
            throw new Error('Data is required for QR code generation');
        }

        const qrOptions = {
            width: options.width || 200,
            margin: options.margin || 2,
            errorCorrectionLevel: options.errorCorrectionLevel || 'M',
            color: options.color || {
                dark: '#000000',
                light: '#ffffff'
            }
        };

        let result;
        
        switch (format) {
            case 'base64':
                result = await QRCode.toDataURL(data, qrOptions);
                // Extract base64 part only (remove data:image/png;base64, prefix).
                result = result.split(',')[1];
                break;
                
            case 'svg':
                result = await QRCode.toString(data, { ...qrOptions, type: 'svg' });
                break;
                
            case 'data-url':
            default:
                result = await QRCode.toDataURL(data, qrOptions);
                break;
        }

        console.log(JSON.stringify({
            success: true,
            result: result
        }));
        
    } catch (error) {
        console.error(JSON.stringify({
            success: false,
            error: error.message
        }));
        process.exit(1);
    }
}

// Execute action.
if (action === 'generate') {
    generateQRCode();
} else {
    console.error(JSON.stringify({
        success: false,
        error: 'Unknown action: ' + action
    }));
    process.exit(1);
}
JAVASCRIPT;

		file_put_contents( $service_file, $service_code );
		chmod( $service_file, 0755 );
	}

	// Execute Node.js service.
	$params = array(
		'data'    => $data,
		'format'  => $format,
		'options' => $options,
	);

	$result = wp_mcp_ai_exec_node_service( $service_file, 'generate', $params, 10 );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Parse JSON result.
	$result_data = json_decode( $result, true );

	if ( ! $result_data || ! isset( $result_data['success'] ) || ! $result_data['success'] ) {
		return new WP_Error(
			'qr_generation_failed',
			$result_data['error'] ?? __( 'Failed to generate QR code.', 'mcp-ai-wpoos-pro' )
		);
	}

	return $result_data['result'];
}

/**
 * Add QR code generation filter for vault TOTP
 *
 * @since 1.3.0
 */
add_filter( 'wp_mcp_ai_generate_qr_code', 'wp_mcp_ai_generate_qr_code', 10, 3 );
