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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the map of document generation packages to their pre-packed bundle files.
 *
 * @return array Map of package name => relative path to bundle within WP_MCP_AI_PRO_PATH.
 */
function wp_mcp_ai_doc_gen_bundle_map() {
	return array(
		'pdfkit'  => 'bin/generate-pdf.bundle.js',
		'docx'    => 'bin/generate-word.bundle.js',
		'exceljs' => 'bin/generate-excel.bundle.js',
	);
}

/**
 * Get the list of Cornerstone3D package names.
 *
 * These are loaded at runtime by imaging-viewer.js — from local vendor bundles
 * when available (built by bin/vendor-cornerstone.js), otherwise from the
 * esm.sh CDN.
 *
 * @return array List of @cornerstonejs/* package names.
 */
function wp_mcp_ai_cornerstone_package_names() {
	return array(
		'@cornerstonejs/core',
		'@cornerstonejs/tools',
		'@cornerstonejs/dicom-image-loader',
	);
}

/**
 * Check whether vendored Cornerstone3D ESM bundles are available on disk.
 *
 * These are built by `bin/vendor-cornerstone.js` and placed in
 * `assets/vendor/cornerstone/`.  When present, the imaging viewer loads
 * Cornerstone3D entirely from local files with no CDN dependency.
 *
 * Also checks the standalone nvoos-cornerstone3d addon which provides
 * the same bundles as a separate WordPress plugin.
 *
 * Delegates to the admin page class when loaded; otherwise performs a
 * standalone filesystem check.
 *
 * @since 2.1.0
 * @return bool
 */
function wp_mcp_ai_has_vendored_cornerstone() {
	// Check standalone addon first (installed as a separate plugin).
	if ( function_exists( 'nvoos_cornerstone3d_is_available' ) && nvoos_cornerstone3d_is_available() ) {
		return true;
	}

	if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
		return false;
	}
	// Delegate to the admin class when it is loaded (single source of truth).
	if ( class_exists( 'WP_MCP_AI_Imaging_Admin_Page' ) && method_exists( 'WP_MCP_AI_Imaging_Admin_Page', 'has_vendored_cornerstone' ) ) {
		return WP_MCP_AI_Imaging_Admin_Page::has_vendored_cornerstone();
	}
	// Standalone fallback — the admin class may not be loaded in REST/CLI contexts.
	$base = WP_MCP_AI_PRO_PATH . 'assets/vendor/cornerstone/';
	return file_exists( $base . 'cornerstone-core.esm.js' )
		&& file_exists( $base . 'cornerstone-tools.esm.js' )
		&& file_exists( $base . 'cornerstone-dicom-loader.esm.js' )
		&& file_exists( $base . 'dicom-parser.esm.js' )
		&& file_exists( $base . 'xmlbuilder2.esm.js' );
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

	// Check for document generation packages bundled into Node.js scripts.
	$doc_gen_bundles = wp_mcp_ai_doc_gen_bundle_map();
	if ( isset( $doc_gen_bundles[ $package_name ] ) ) {
		if ( file_exists( WP_MCP_AI_PRO_PATH . $doc_gen_bundles[ $package_name ] ) ) {
			return true;
		}
	}

	// Check for Cornerstone3D packages — prefer vendored ESM bundles, fall back to CDN.
	if ( in_array( $package_name, wp_mcp_ai_cornerstone_package_names(), true ) ) {
		if ( wp_mcp_ai_has_vendored_cornerstone() ) {
			return true;
		}
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'assets/js/imaging-viewer.js' ) ) {
			return true;
		}
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

	// Check for document generation packages bundled into Node.js scripts.
	$doc_gen_bundles = wp_mcp_ai_doc_gen_bundle_map();
	if ( isset( $doc_gen_bundles[ $package_name ] ) ) {
		if ( file_exists( WP_MCP_AI_PRO_PATH . $doc_gen_bundles[ $package_name ] ) ) {
			return array(
				'available' => true,
				'source'    => 'bundled',
				'message'   => sprintf(
					/* translators: %s: package name */
					__( '%s (Pre-packed)', 'mcp-ai-wpoos-pro' ),
					$package_name
				),
			);
		}
	}

	// Check for Cornerstone3D packages — prefer vendored ESM bundles, fall back to CDN.
	if ( in_array( $package_name, wp_mcp_ai_cornerstone_package_names(), true ) ) {
		if ( wp_mcp_ai_has_vendored_cornerstone() ) {
			return array(
				'available' => true,
				'source'    => 'vendor',
				'message'   => sprintf(
					/* translators: %s: package name */
					__( '%s (Vendored)', 'mcp-ai-wpoos-pro' ),
					$package_name
				),
			);
		}
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'assets/js/imaging-viewer.js' ) ) {
			return array(
				'available' => true,
				'source'    => 'cdn',
				'message'   => sprintf(
					/* translators: %s: package name */
					__( '%s (CDN)', 'mcp-ai-wpoos-pro' ),
					$package_name
				),
			);
		}
	}

	// Check for canvas: prefer the NV oOS Canvas Addon plugin (pre-compiled
	// binaries), then fall back to canvas-service.js + node_modules.
	if ( 'canvas' === $package_name ) {
		// Priority 1: NV oOS Canvas Addon plugin provides pre-compiled binaries.
		if ( function_exists( 'nvoos_canvas_is_available' ) && nvoos_canvas_is_available() ) {
			return array(
				'available' => true,
				'source'    => 'canvas-addon',
				'message'   => __( 'canvas (NV oOS Canvas Addon)', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Priority 2: canvas-service.js present AND canvas in node_modules.
		$canvas_service_path = WP_MCP_AI_PRO_PATH . 'node-services/canvas-service.js';
		$canvas_npm_path     = WP_MCP_AI_PRO_PATH . 'node_modules/canvas';
		if ( file_exists( $canvas_service_path ) && is_dir( $canvas_npm_path ) ) {
			return array(
				'available' => true,
				'source'    => 'node_modules',
				'message'   => __( 'canvas (Installed)', 'mcp-ai-wpoos-pro' ),
			);
		}
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
 * NOTE: this deliberately conflates two things so the admin notice can show
 * a single status: it returns true when a LOCAL `node` command exists, or
 * the string 'sidecar' when only the Media Worker sidecar is reachable.
 * Code that actually executes the local binary must use
 * wp_mcp_ai_has_local_nodejs() instead — the sidecar serves HTTP, not a
 * local process.
 *
 * @return bool|string True if local Node.js exists, 'sidecar' if only the
 *                     Media Worker sidecar is reachable, false otherwise.
 */
function wp_mcp_ai_is_nodejs_available() {
	static $available = null;

	if ( null === $available ) {
		// Check for local Node.js first.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		if ( $process_service->is_command_available( 'node' ) ) {
			$available = true;
			return $available;
		}

		// Check for Media Worker sidecar.
		$sidecar_url = defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL
			? WP_MEDIA_WORKER_URL
			: get_option( 'wp_mcp_ai_media_worker_url', '' );
		if ( ! empty( $sidecar_url ) ) {
			$response = wp_remote_get( rtrim( $sidecar_url, '/' ) . '/api/health', array( 'timeout' => 3 ) );
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$available = 'sidecar';
				return $available;
			}
		}

		$available = false;
	}

	return $available;
}

/**
 * Check whether Node.js is available as a LOCAL command on this server.
 *
 * Unlike wp_mcp_ai_is_nodejs_available(), this never reports the Media
 * Worker sidecar: the sidecar serves HTTP, not a local `node` binary, so
 * legacy local-execution paths (wp_mcp_ai_exec_node_service and the filter
 * handlers below) gate on this check to avoid spawning `node` on
 * sidecar-only hosts.
 *
 * @since 1.1.55
 * @return bool True when the local `node` command is available.
 */
function wp_mcp_ai_has_local_nodejs() {
	static $available = null;

	if ( null === $available ) {
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
	if ( ! wp_mcp_ai_has_local_nodejs() ) {
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

	// No local Node.js — pass through (return false) so the service cascade
	// can try the Media Worker sidecar or surface its own accurate error.
	if ( ! wp_mcp_ai_has_local_nodejs() ) {
		return false;
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

	// No local Node.js — pass through so the service cascade can try the
	// Media Worker sidecar.
	if ( ! wp_mcp_ai_has_local_nodejs() ) {
		return false;
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

	// No local Node.js — pass through so the service cascade can try the
	// Media Worker sidecar.
	if ( ! wp_mcp_ai_has_local_nodejs() ) {
		return false;
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

	// No local Node.js — pass through so the service cascade can try the
	// Media Worker sidecar.
	if ( ! wp_mcp_ai_has_local_nodejs() ) {
		return false;
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

	// No local Node.js — pass through so the service cascade can try the
	// Media Worker sidecar.
	if ( ! wp_mcp_ai_has_local_nodejs() ) {
		return false;
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

	// No local Node.js — pass through so the service cascade can try the
	// Media Worker sidecar.
	if ( ! wp_mcp_ai_has_local_nodejs() ) {
		return false;
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
 * CANVAS FILTER HANDLERS
 * ============================================================================
 */

/**
 * Generate an image using the canvas npm package (server-side).
 *
 * Handles the `wp_mcp_ai_canvas_generate_image` filter. Requires the
 * NV oOS Canvas Addon plugin, or canvas installed via npm install canvas@2.
 *
 * @param array|false $result Result from a previous filter handler (pass-through).
 * @param array       $params {
 *     Image generation parameters.
 *
 *     @type string $output     Absolute path for the output PNG file (required).
 *     @type int    $width      Canvas width in pixels (default 800).
 *     @type int    $height     Canvas height in pixels (default 600).
 *     @type string $background CSS background colour (default #ffffff).
 *     @type array  $commands   Drawing commands array (optional).
 * }
 * @return array|WP_Error Result array with 'output_path', 'width', 'height' on success.
 */
function wp_mcp_ai_canvas_generate_image_handler( $result, $params ) {
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/canvas-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'generate', $params, 30 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );

	if ( ! $result_data || empty( $result_data['success'] ) ) {
		return new WP_Error(
			'canvas_generate_failed',
			isset( $result_data['error'] ) ? $result_data['error'] : __( 'Canvas image generation failed.', 'mcp-ai-wpoos-pro' )
		);
	}

	return array(
		'output_path' => $result_data['output_path'],
		'width'       => $result_data['width'],
		'height'      => $result_data['height'],
	);
}

/**
 * Render a Chart.js configuration to a PNG image using canvas.
 *
 * Handles the `wp_mcp_ai_chartjs_generate_image` filter. Both the canvas
 * and chart.js npm packages must be available.
 *
 * @param array|false $result Result from a previous filter handler (pass-through).
 * @param array       $config Chart.js configuration array with 'type', 'data', and 'options' keys.
 * @return array|WP_Error Result array with 'url', 'path', 'width', 'height' on success.
 */
function wp_mcp_ai_chartjs_generate_image_handler( $result, $config ) {
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/canvas-service.js';

	// Canvas service file must exist.
	if ( ! file_exists( $service_file ) ) {
		return false;
	}

	// Canvas npm package must be installed.
	$canvas_npm_path = WP_MCP_AI_PRO_PATH . 'node_modules/canvas';
	if ( ! is_dir( $canvas_npm_path ) ) {
		return false;
	}

	// Build a temporary output path inside the WordPress uploads directory.
	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		return new WP_Error( 'canvas_upload_dir', $upload_dir['error'] );
	}
	$output_dir = trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-wpoos/charts/';
	if ( ! wp_mkdir_p( $output_dir ) ) {
		return new WP_Error( 'canvas_dir_failed', __( 'Failed to create chart output directory.', 'mcp-ai-wpoos-pro' ) );
	}

	$filename    = 'chart-' . wp_generate_uuid4() . '.png';
	$output_path = $output_dir . $filename;
	$output_url  = trailingslashit( $upload_dir['baseurl'] ) . 'mcp-ai-wpoos/charts/' . $filename;

	$params = array(
		'output' => $output_path,
		'width'  => 800,
		'height' => 400,
		'config' => $config,
	);

	$output = wp_mcp_ai_exec_node_service( $service_file, 'render_chart', $params, 30 );

	if ( is_wp_error( $output ) ) {
		return $output;
	}

	$result_data = json_decode( $output, true );

	if ( ! $result_data || empty( $result_data['success'] ) ) {
		return new WP_Error(
			'chartjs_render_failed',
			isset( $result_data['error'] ) ? $result_data['error'] : __( 'Chart image rendering failed.', 'mcp-ai-wpoos-pro' )
		);
	}

	return array(
		'url'    => $output_url,
		'path'   => $output_path,
		'width'  => $result_data['width'],
		'height' => $result_data['height'],
	);
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
 * Register canvas filter handlers
 */
function wp_mcp_ai_register_canvas_filters() {
	add_filter( 'wp_mcp_ai_canvas_generate_image', 'wp_mcp_ai_canvas_generate_image_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_chartjs_generate_image', 'wp_mcp_ai_chartjs_generate_image_handler', 10, 2 );
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
	wp_mcp_ai_register_canvas_filters();
	wp_mcp_ai_register_language_filters();
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
	// Get CDN-loaded packages from the CDN Loader class (single source of truth).
	$cdn_packages = array();
	if ( class_exists( 'WP_MCP_AI_Pro_CDN_Loader' ) ) {
		$cdn_packages = WP_MCP_AI_Pro_CDN_Loader::get_cdn_packages();
	}

	$packages = array(
		'prettier'      => 'assets/vendor/prettier/standalone.js',
		'mjml'          => 'assets/vendor/mjml/lib/index.js',
		'fluent-ffmpeg' => 'assets/vendor/fluent-ffmpeg/index.js',
		'sharp'         => 'assets/vendor/sharp/lib/index.js',
		'katex'         => 'assets/vendor/katex/dist/katex.min.js',
		'ics'           => 'assets/vendor/ics/index.js',
		'turf'          => 'assets/vendor/turf/dist/cjs/index.cjs',
		'qrcode'        => 'assets/vendor/qrcode/lib/index.js',
	);

	/**
	 * Filter the vendor package path map before availability checks.
	 *
	 * Allows tests and integrations to remap or inject package locations.
	 *
	 * @param array $packages Package name => relative path pairs.
	 */
	$packages = apply_filters( 'wp_mcp_ai_vendor_package_paths', $packages );

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
				<?php esc_html_e( 'Node.js is not installed locally.', 'mcp-ai-wpoos-pro' ); ?>
				<?php esc_html_e( 'The Media Worker sidecar can handle all Node.js operations remotely — configure it in Settings → Media Worker, or install Node.js locally.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<?php elseif ( 'sidecar' === $nodejs_available ) : ?>
			<p style="color:#46b450;">
				<strong><?php esc_html_e( 'Media Worker Sidecar Active', 'mcp-ai-wpoos-pro' ); ?></strong>
				&mdash; <?php esc_html_e( 'All Node.js operations are routed to the sidecar.', 'mcp-ai-wpoos-pro' ); ?>
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
 * Generate QR code via external API service.
 *
 * Falls back to api.qrserver.com when Node.js is unavailable.
 *
 * @since 1.3.1
 *
 * @param string $data    Data to encode.
 * @param string $format  Output format: 'base64', 'svg', or 'data-url'.
 * @param array  $options QR code options (width).
 * @return string|WP_Error QR code string or WP_Error on failure.
 */
function wp_mcp_ai_generate_qr_code_via_api( $data, $format, $options ) {
	$width = isset( $options['width'] ) ? absint( $options['width'] ) : 200;
	$size  = $width . 'x' . $width;

	$query_args = array(
		'size' => $size,
		'data' => $data,
	);

	if ( 'svg' === $format ) {
		$query_args['format'] = 'svg';
	}

	$url = add_query_arg( $query_args, 'https://api.qrserver.com/v1/create-qr-code/' );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 10,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== (int) $code ) {
		return new WP_Error(
			'qr_api_error',
			sprintf(
				/* translators: %d: HTTP response code */
				__( 'External QR code API returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
				$code
			)
		);
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return new WP_Error(
			'qr_api_empty',
			__( 'External QR code API returned an empty response.', 'mcp-ai-wpoos-pro' )
		);
	}

	switch ( $format ) {
		case 'base64':
			return base64_encode( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		case 'svg':
			return $body;
		case 'data-url':
		default:
			return 'data:image/png;base64,' . base64_encode( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}

/**
 * Send a JSON POST to the Media Worker sidecar.
 *
 * Procedural helper for filter handlers and utility functions that cannot
 * use the WP_MCP_AI_Media_Worker_Client trait. Fails fast when no sidecar
 * URL is configured.
 *
 * @param string $endpoint API path (e.g. '/api/data/translate').
 * @param array  $body     Request payload.
 * @param int    $timeout  Timeout in seconds (default 30).
 * @return array|WP_Error Decoded response body or error.
 */
function wp_mcp_ai_sidecar_json_post( $endpoint, $body = array(), $timeout = 30 ) {
	$url = defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL
		? WP_MEDIA_WORKER_URL
		: get_option( 'wp_mcp_ai_media_worker_url', '' );
	if ( empty( $url ) ) {
		return new WP_Error(
			'wp_mcp_ai_sidecar_not_configured',
			__( 'Media Worker sidecar URL is not configured.', 'mcp-ai-wpoos-pro' )
		);
	}

	$token = defined( 'WP_MEDIA_WORKER_TOKEN' ) && WP_MEDIA_WORKER_TOKEN
		? WP_MEDIA_WORKER_TOKEN
		: get_option( 'wp_mcp_ai_media_worker_token', '' );
	if ( empty( $token ) ) {
		$token = wp_hash( home_url() );
	}

	$response = wp_remote_post(
		rtrim( $url, '/' ) . '/' . ltrim( $endpoint, '/' ),
		array(
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-Site-Token' => $token,
				'X-Site-Url'   => home_url(),
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status  = wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $status && 202 !== $status ) {
		$error_msg = isset( $decoded['error'] )
			? $decoded['error']
			: sprintf( 'HTTP %d: %s', $status, substr( wp_remote_retrieve_body( $response ), 0, 200 ) );

		return new WP_Error(
			'wp_mcp_ai_sidecar_error',
			$error_msg,
			array(
				'status'   => $status,
				'response' => $decoded,
			)
		);
	}

	if ( null === $decoded ) {
		return new WP_Error(
			'wp_mcp_ai_sidecar_invalid_json',
			__( 'Media Worker returned invalid JSON.', 'mcp-ai-wpoos-pro' )
		);
	}

	return $decoded;
}

/**
 * Generate QR code for TOTP authentication
 *
 * Uses qrcode NPM package to generate QR codes for authenticator apps.
 * Falls back to an external API service when Node.js is not available.
 *
 * @since 1.3.0
 *
 * @param string $data    Data to encode (typically otpauth:// URI).
 * @param string $format  Output format: 'base64' (default), 'svg', or 'data-url'.
 * @param array  $options QR code options (size, margin, error correction level).
 * @return string|WP_Error QR code string or WP_Error on failure.
 */
function wp_mcp_ai_generate_qr_code( $data, $format = 'data-url', $options = array() ) {
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

	// Try the Media Worker sidecar first (sidecar-first routing — the
	// connected worker generates the QR code instead of the plugin's
	// bundled JS). Fails fast when no sidecar URL is configured.
	$sidecar = wp_mcp_ai_sidecar_json_post(
		'/api/data/qrcode',
		array(
			'text'    => $data,
			'options' => array(
				'format'          => 'svg' === $format ? 'svg' : 'png',
				'width'           => isset( $options['width'] ) ? absint( $options['width'] ) : 200,
				'margin'          => isset( $options['margin'] ) ? absint( $options['margin'] ) : 2,
				'colorDark'       => isset( $options['color']['dark'] ) ? $options['color']['dark'] : '#000000',
				'colorLight'      => isset( $options['color']['light'] ) ? $options['color']['light'] : '#ffffff',
				'errorCorrection' => isset( $options['errorCorrectionLevel'] ) ? $options['errorCorrectionLevel'] : 'M',
			),
		),
		15
	);
	if ( ! is_wp_error( $sidecar ) && ! empty( $sidecar['data_url'] ) ) {
		// 'svg' callers expect the raw SVG markup (same as the local service
		// and external API fallback) — unwrap the worker's data URL.
		if ( 'svg' === $format && 0 === strpos( $sidecar['data_url'], 'data:image/svg+xml;base64,' ) ) {
			$svg = base64_decode( substr( $sidecar['data_url'], strlen( 'data:image/svg+xml;base64,' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unwrapping the worker's SVG data URL is the transport contract.
			if ( false !== $svg ) {
				return $svg;
			}
		}
		// 'base64' callers expect bare base64 without the data URL prefix.
		if ( 'base64' === $format && 0 === strpos( $sidecar['data_url'], 'data:image/png;base64,' ) ) {
			return substr( $sidecar['data_url'], strlen( 'data:image/png;base64,' ) );
		}
		return $sidecar['data_url'];
	}

	// Fallback: local Node.js service (qrcode and its deps are vendored under
	// assets/vendor/qrcode/ so no npm install is required on the server).
	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/qrcode-service.js';

	if ( wp_mcp_ai_has_local_nodejs() && file_exists( $service_file ) ) {
		$params = array(
			'data'    => $data,
			'format'  => $format,
			'options' => $options,
		);

		$result = wp_mcp_ai_exec_node_service( $service_file, 'generate', $params, 10 );

		if ( ! is_wp_error( $result ) ) {
			$result_data = json_decode( $result, true );

			if ( $result_data && ! empty( $result_data['success'] ) && isset( $result_data['result'] ) ) {
				return $result_data['result'];
			}
		}
	}

	// Fallback: generate via external API (works even without Node.js).
	return wp_mcp_ai_generate_qr_code_via_api( $data, $format, $options );
}

/**
 * Add QR code generation filter for vault TOTP
 *
 * @since 1.3.0
 */
add_filter( 'wp_mcp_ai_generate_qr_code', 'wp_mcp_ai_generate_qr_code', 10, 3 );

// =============================================================================
// LANGUAGE DETECTION FILTER HANDLERS (franc + iso-639-1)
// =============================================================================

/**
 * Detect language of text using the franc Node.js service.
 *
 * @since 1.4.0
 *
 * @param false|array $result Existing result (false = not yet handled).
 * @param array       $params Parameters including 'text'.
 * @return false|array Detection result or false on failure.
 */
function wp_mcp_ai_lang_detect_handler( $result, $params ) {
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/lang-detect-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'detect', $params, 10 );

	if ( is_wp_error( $output ) ) {
		return false;
	}

	$data = json_decode( $output, true );
	if ( $data && ! empty( $data['success'] ) && isset( $data['result'] ) ) {
		return $data['result'];
	}

	return false;
}

/**
 * Look up ISO 639-1 language code info using the lang-detect Node.js service.
 *
 * @since 1.4.0
 *
 * @param false|array $result Existing result.
 * @param array       $params Parameters including 'code'.
 * @return false|array Language info or false on failure.
 */
function wp_mcp_ai_lang_code_info_handler( $result, $params ) {
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/lang-detect-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'validate_code', $params, 10 );

	if ( is_wp_error( $output ) ) {
		return false;
	}

	$data = json_decode( $output, true );
	if ( $data && ! empty( $data['success'] ) && isset( $data['result'] ) ) {
		return $data['result'];
	}

	return false;
}

/**
 * Format / validate a phone number using the phone-format Node.js service.
 *
 * @since 1.4.0
 *
 * @param false|array $result Existing result.
 * @param array       $params Parameters including 'phone' and 'country_code'.
 * @return false|array Formatted phone data or false on failure.
 */
function wp_mcp_ai_phone_format_handler( $result, $params ) {
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/phone-format-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'format', $params, 10 );

	if ( is_wp_error( $output ) ) {
		return false;
	}

	$data = json_decode( $output, true );
	if ( $data && ! empty( $data['success'] ) && isset( $data['result'] ) ) {
		return $data['result'];
	}

	return false;
}

/**
 * Validate a phone number using libphonenumber-js via Node.js service.
 *
 * @since 1.4.0
 *
 * @param false|bool|WP_Error $result Existing result.
 * @param array               $params Parameters including 'phone' and 'country'.
 * @return false|bool|WP_Error Validation result or false if Node.js unavailable.
 */
function wp_mcp_ai_validator_phone_handler( $result, $params ) {
	if ( false !== $result ) {
		return $result;
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/phone-format-service.js';
	$output       = wp_mcp_ai_exec_node_service(
		$service_file,
		'validate',
		array(
			'phone'        => isset( $params['phone'] ) ? $params['phone'] : '',
			'country_code' => isset( $params['country'] ) ? $params['country'] : 'US',
		),
		10
	);

	if ( is_wp_error( $output ) ) {
		return false;
	}

	$data = json_decode( $output, true );
	if ( $data && ! empty( $data['success'] ) && isset( $data['result']['valid'] ) ) {
		if ( ! $data['result']['valid'] ) {
			return new WP_Error(
				'invalid_phone',
				__( 'Invalid phone number.', 'mcp-ai-wpoos-pro' )
			);
		}
		return true;
	}

	return false;
}

/**
 * Translate text using the google-translate-api-x Node.js service.
 *
 * @since 1.4.0
 *
 * @param false|array $result Existing result.
 * @param array       $params Parameters including 'text', 'target_language', 'source_language'.
 * @return false|array Translation result or false on failure.
 */
function wp_mcp_ai_translate_text_handler( $result, $params ) {
	if ( false !== $result ) {
		return $result;
	}

	// Try the Media Worker sidecar first (procedural handler — no trait).
	$sidecar = wp_mcp_ai_sidecar_json_post(
		'/api/data/translate',
		array(
			'text' => isset( $params['text'] ) ? $params['text'] : '',
			'to'   => isset( $params['target_language'] ) ? $params['target_language'] : '',
			'from' => isset( $params['source_language'] ) ? $params['source_language'] : '',
		),
		15
	);
	if ( ! is_wp_error( $sidecar ) && ! empty( $sidecar['translated'] ) ) {
		return $sidecar['translated'];
	}

	$service_file = WP_MCP_AI_PRO_PATH . 'node-services/translate-service.js';
	$output       = wp_mcp_ai_exec_node_service( $service_file, 'translate', $params, 30 );

	if ( is_wp_error( $output ) ) {
		return false;
	}

	$data = json_decode( $output, true );
	if ( $data && ! empty( $data['success'] ) && isset( $data['result'] ) ) {
		return $data['result'];
	}

	return false;
}

/**
 * Register language detection filter handlers.
 *
 * @since 1.4.0
 */
function wp_mcp_ai_register_language_filters() {
	add_filter( 'wp_mcp_ai_lang_detect', 'wp_mcp_ai_lang_detect_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_lang_code_info', 'wp_mcp_ai_lang_code_info_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_phone_format', 'wp_mcp_ai_phone_format_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_validator_phone', 'wp_mcp_ai_validator_phone_handler', 10, 2 );
	add_filter( 'wp_mcp_ai_translate_text', 'wp_mcp_ai_translate_text_handler', 10, 2 );
}
