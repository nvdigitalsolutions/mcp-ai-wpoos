<?php
/**
 * E-commerce Toolkit Helpers (ecosystem port — Wave F2, e-commerce data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-ecommerce-helpers.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; path/URL/version constants resolve from
 * `NVOOS_CONTENT_GRAPH_PRO_*`. The init's four admin-page requires are
 * file-gated — the pages land with the F2 admin slice (wave-proof).
 *
 * @package NvoosContentGraphPro
 * @since 2.1.0
 */

declare(strict_types=1);

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
			__( "Shell tools are disabled. Set define( 'WP_MCP_AI_ALLOW_SHELL_TOOLS', true ) in wp-config.php to enable them.", 'nvoos-content-graph-pro' )
		);
	}

	if ( ! file_exists( $script_path ) ) {
		return new WP_Error(
			'node_script_not_found',
			sprintf(
				/* translators: %s: script path */
				__( 'Node.js document generation script not found: %s', 'nvoos-content-graph-pro' ),
				$script_path
			)
		);
	}

	if ( ! class_exists( '\WP_MCP_AI\Services\WP_MCP_AI_Process_Service' ) ) {
		return new WP_Error(
			'process_service_missing',
			__( 'The process service is not available for document generation.', 'nvoos-content-graph-pro' )
		);
	}

	$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

	if ( ! $process_service->is_command_available( 'node' ) ) {
		return new WP_Error(
			'nodejs_not_available',
			__( 'Node.js is not available on this server.', 'nvoos-content-graph-pro' )
		);
	}

	$result = $process_service->run_silent(
		array( 'node', $script_path, $input_file, $output_file ),
		array( 'timeout' => $timeout )
	);

	if ( ! empty( $result['disabled'] ) ) {
		return new WP_Error(
			'process_functions_disabled',
			__( 'Process control functions are disabled on this server, so Node.js document generation is unavailable.', 'nvoos-content-graph-pro' )
		);
	}

	if ( ! empty( $result['timeout'] ) ) {
		return new WP_Error(
			'node_timeout',
			sprintf(
				/* translators: %d: timeout in seconds */
				__( 'Node.js document generation timed out after %d seconds.', 'nvoos-content-graph-pro' ),
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
				__( 'Node.js document generation failed: %s', 'nvoos-content-graph-pro' ),
				trim( $output_text )
			)
		);
	}

	return true;
}
