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
 * Check if Node.js is available
 *
 * @return bool True if Node.js is available.
 */
function wp_mcp_ai_is_nodejs_available() {
	static $available = null;

	if ( null === $available ) {
		// Check if shell_exec is available (may be disabled in shared hosting).
		if ( ! function_exists( 'shell_exec' ) ) {
			$available = false;
			return $available;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$node_check = shell_exec( 'which node 2>/dev/null' );
		$available  = ! empty( $node_check );
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
 * @return array|WP_Error Result or error.
 */
function wp_mcp_ai_exec_node_service( $service_file, $action, $params, $timeout = 30 ) {
	// Check if exec function is available (may be disabled in shared hosting).
	if ( ! function_exists( 'exec' ) ) {
		return new WP_Error(
			'shell_functions_disabled',
			__( 'Shell execution functions are disabled on this server. Please contact your hosting provider to enable the exec() function.', 'mcp-ai-wpoos-pro' )
		);
	}

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

	// Build command.
	$cmd = sprintf(
		'timeout %d node %s %s %s 2>&1',
		absint( $timeout ),
		escapeshellarg( $service_file ),
		escapeshellarg( $action ),
		escapeshellarg( wp_json_encode( $params ) )
	);

	// Execute command.
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	exec( $cmd, $output, $return_code );

	// Handle timeout (return code 124).
	if ( 124 === $return_code ) {
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
	if ( 0 !== $return_code ) {
		$output_text = implode( "\n", $output );
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

	return implode( "\n", $output );
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
 * Register all NPM package filter handlers
 *
 * Call this function to enable all NPM package integrations at once.
 */
function wp_mcp_ai_register_all_npm_filters() {
	wp_mcp_ai_register_prettier_filters();
	wp_mcp_ai_register_mjml_filters();
	wp_mcp_ai_register_ffmpeg_filters();
}

/**
 * ============================================================================
 * AUTO-REGISTRATION
 * ============================================================================
 */

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

/**
 * ============================================================================
 * ADMIN NOTICES
 * ============================================================================
 */

/**
 * Show admin notice if Node.js is not available but tools require it
 */
function wp_mcp_ai_npm_integration_admin_notice() {
	// Only show in admin.
	if ( ! is_admin() ) {
		return;
	}

	// Check if any Pro NPM tools are enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
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

	// Check if Node.js is available.
	if ( ! wp_mcp_ai_is_nodejs_available() ) {
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'NV oOS Pro: Node.js Integration Required', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %1$s: link to Node.js download, %2$s: link to documentation */
						__( 'Some Pro features require Node.js to be installed on your server. Please install <a href="%1$s" target="_blank">Node.js</a> and run <code>npm install</code> in the Pro addon directory. See <a href="%2$s" target="_blank">integration guide</a> for details.', 'mcp-ai-wpoos-pro' ),
						'https://nodejs.org/',
						admin_url( 'admin.php?page=wp-mcp-ai-settings#npm-integration' )
					)
				);
				?>
			</p>
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
