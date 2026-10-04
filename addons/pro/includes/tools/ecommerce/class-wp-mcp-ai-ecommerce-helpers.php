<?php
/**
 * E-commerce Toolkit Helpers
 *
 * Shared utility functions for the E-commerce Pro Toolkit.
 * Kept side-effect free so callers (including the test suite) can load
 * the enablement check without booting the full toolkit.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Ecommerce_Toolkit
 * @since      2.1.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the E-commerce Toolkit is enabled.
 *
 * The toolkit must be explicitly enabled in plugin settings (Pro features).
 *
 * @since 2.1.0
 *
 * @return bool True if enabled, false otherwise.
 */
function wp_mcp_ai_is_ecommerce_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_ecommerce_toolkit'] );
}

/**
 * Run a bundled Node.js document-generation script.
 *
 * Gates the invocation behind the shell-tools constant (F-EXEC-01 /
 * R-S-02) and Node availability, and executes through the Process
 * Service so hosts with disabled process functions degrade to a WP_Error
 * instead of a fatal Error.
 *
 * @since 2.1.0
 *
 * @param string $script_path Absolute path to the Node script.
 * @param string $input_file  Path to a JSON input file for the script.
 * @param string $output_file Path where the script writes its output.
 * @param int    $timeout     Timeout in seconds.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function wp_mcp_ai_ecommerce_run_node_script( $script_path, $input_file, $output_file, $timeout = 120 ) {
	if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
		return new WP_Error(
			'shell_tools_disabled',
			__( "Shell tools are disabled. Set define( 'WP_MCP_AI_ALLOW_SHELL_TOOLS', true ) in wp-config.php to enable them.", 'mcp-ai-wpoos-pro' )
		);
	}

	if ( ! file_exists( $script_path ) ) {
		return new WP_Error(
			'node_script_not_found',
			sprintf(
				/* translators: %s: script path */
				__( 'Node.js document generation script not found: %s', 'mcp-ai-wpoos-pro' ),
				$script_path
			)
		);
	}

	if ( ! class_exists( '\WP_MCP_AI\Services\WP_MCP_AI_Process_Service' ) ) {
		return new WP_Error(
			'process_service_missing',
			__( 'The process service is not available for document generation.', 'mcp-ai-wpoos-pro' )
		);
	}

	$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

	if ( ! $process_service->is_command_available( 'node' ) ) {
		return new WP_Error(
			'nodejs_not_available',
			__( 'Node.js is not available on this server.', 'mcp-ai-wpoos-pro' )
		);
	}

	$result = $process_service->run_silent(
		array( 'node', $script_path, $input_file, $output_file ),
		array( 'timeout' => $timeout )
	);

	if ( ! empty( $result['disabled'] ) ) {
		return new WP_Error(
			'process_functions_disabled',
			__( 'Process control functions are disabled on this server, so Node.js document generation is unavailable.', 'mcp-ai-wpoos-pro' )
		);
	}

	if ( ! empty( $result['timeout'] ) ) {
		return new WP_Error(
			'node_timeout',
			sprintf(
				/* translators: %d: timeout in seconds */
				__( 'Node.js document generation timed out after %d seconds.', 'mcp-ai-wpoos-pro' ),
				$timeout
			)
		);
	}

	if ( empty( $result['success'] ) ) {
		$output_text = ( isset( $result['output'] ) ? $result['output'] : '' ) . ( isset( $result['error'] ) ? $result['error'] : '' );
		return new WP_Error(
			'node_execution_failed',
			sprintf(
				/* translators: %s: error output */
				__( 'Node.js document generation failed: %s', 'mcp-ai-wpoos-pro' ),
				trim( $output_text )
			)
		);
	}

	return true;
}
