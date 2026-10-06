<?php
/**
 * WP-CLI `mcp-ai cache clear` command for NV oOS.
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

if ( ! class_exists( 'WP_MCP_AI_CLI_Cache_Command' ) ) {
	class WP_MCP_AI_CLI_Cache_Command extends WP_MCP_AI_CLI_Base_Command {

		/**
		 * Clear all NV oOS caches (default action).
		 *
		 * ## EXAMPLES
		 *
		 *     # Clear all caches.
		 *     $ wp mcp-ai cache clear
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function __invoke( $args, $assoc_args ) {
			$this->clear();
		}

		/**
		 * Clear all NV oOS caches.
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp mcp-ai cache clear
		 *
		 * @subcommand clear
		 * @when after_wp_load
		 */
		public function clear() {
			$this->require_capability( 'manage_options' );

			$cleared = array();

			// Clear settings cache.
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
				WP_MCP_AI_Admin_Settings::reset_settings_cache();
				$cleared[] = __( 'Settings cache', 'mcp-ai-wpoos' );
			}

			// Clear tool registry cache.
			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) && method_exists( 'WP_MCP_AI_Tool_Registry', 'clear_tools' ) ) {
				$registry = WP_MCP_AI_Tool_Registry::get_instance();
				$registry->clear_tools();
				$cleared[] = __( 'Tool registry cache', 'mcp-ai-wpoos' );
			}

			// Flush WordPress object cache.
			wp_cache_flush();
			$cleared[] = __( 'WordPress object cache', 'mcp-ai-wpoos' );

			if ( empty( $cleared ) ) {
				WP_CLI::log( __( 'No caches to clear.', 'mcp-ai-wpoos' ) );
			} else {
				WP_CLI::success(
					sprintf(
						/* translators: %s: comma-separated list of cleared caches */
						__( 'Cleared: %s.', 'mcp-ai-wpoos' ),
						implode( ', ', $cleared )
					)
				);
			}
		}
	}

}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai cache clear', 'WP_MCP_AI_CLI_Cache_Command' );
}
