<?php
/**
 * WP-CLI `mcp-ai version` command for NV oOS.
 *
 * Extracted from includes/class-wp-mcp-ai-cli-command.php
 * (Proposal 050, WP-CLI parity & hardening).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

if ( ! class_exists( 'WP_MCP_AI_CLI_Version_Command' ) ) {
	class WP_MCP_AI_CLI_Version_Command extends WP_MCP_AI_CLI_Base_Command {

		/**
		 * Show plugin and environment version information.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp mcp-ai version
		 *     $ wp mcp-ai version --format=json
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function __invoke( $args, $assoc_args ) {
			global $wp_version;

			$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

			$nv_version = defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : __( 'unknown', 'mcp-ai-wpoos' );

			$pro_version = __( 'not active', 'mcp-ai-wpoos' );
			if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				$pro_version = WP_MCP_AI_PRO_VERSION;
			} elseif ( defined( 'WP_MCP_AI_BASE_VERSION' ) && ! WP_MCP_AI_BASE_VERSION ) {
				$pro_version = __( 'active (version unknown)', 'mcp-ai-wpoos' );
			}

			$items = array(
				array(
					'metric' => __( 'NV oOS Version', 'mcp-ai-wpoos' ),
					'value'  => $nv_version,
				),
				array(
					'metric' => __( 'Pro Version', 'mcp-ai-wpoos' ),
					'value'  => $pro_version,
				),
				array(
					'metric' => __( 'PHP Version', 'mcp-ai-wpoos' ),
					'value'  => PHP_VERSION,
				),
				array(
					'metric' => __( 'WordPress Version', 'mcp-ai-wpoos' ),
					'value'  => $wp_version,
				),
			);

			\WP_CLI\Utils\format_items( $format, $items, array( 'metric', 'value' ) );
		}
	}

}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai version', 'WP_MCP_AI_CLI_Version_Command' );
}
