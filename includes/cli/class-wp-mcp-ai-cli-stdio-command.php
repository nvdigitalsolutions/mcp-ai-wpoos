<?php
/**
 * WP-CLI `mcp-ai stdio` command for NV oOS.
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

if ( ! class_exists( 'WP_MCP_AI_CLI_STDIO_Command' ) ) {
	class WP_MCP_AI_CLI_STDIO_Command extends WP_MCP_AI_CLI_Base_Command {

		/**
		 * Start the MCP server with STDIO transport.
		 *
		 * Runs an MCP server that reads JSON-RPC 2.0 requests from stdin
		 * and writes responses to stdout. This enables local MCP clients
		 * (like Claude Desktop) to communicate with WordPress.
		 *
		 * ## OPTIONS
		 *
		 * [--assistant-id=<id>]
		 * : Scope the server to a specific assistant ID.
		 *
		 * ## EXAMPLES
		 *
		 *     # Start STDIO transport server
		 *     wp mcp-ai stdio
		 *
		 *     # Start STDIO transport scoped to assistant ID 123
		 *     wp mcp-ai stdio --assistant-id=123
		 *
		 *     # Use with Claude Desktop (in claude_desktop_config.json):
		 *     # {
		 *     #   "mcpServers": {
		 *     #     "WordPress": {
		 *     #       "command": "wp",
		 *     #       "args": ["mcp-ai", "stdio", "--path=/path/to/wordpress"]
		 *     #     }
		 *     #   }
		 *     # }
		 *
		 * @since 1.0.0
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function __invoke( $args, $assoc_args ) {
			$assistant_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'assistant-id', 0 );
			$assistant_id = absint( $assistant_id );

			$this->require_capability( 'manage_options' );

			// Validate assistant exists if specified.
			if ( $assistant_id > 0 ) {
				$assistant = get_post( $assistant_id );

				if ( ! $assistant || 'mcp_ai_assistant' !== $assistant->post_type ) {
					WP_CLI::error(
						sprintf(
							/* translators: %d: assistant ID */
							__( 'Assistant not found: %d', 'mcp-ai-wpoos' ),
							$assistant_id
						)
					);
					return;
				}

				if ( 'publish' !== $assistant->post_status ) {
					WP_CLI::error(
						sprintf(
							/* translators: %d: assistant ID */
							__( 'Assistant %d is not published.', 'mcp-ai-wpoos' ),
							$assistant_id
						)
					);
					return;
				}
			}

			// Ensure the STDIO transport class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_STDIO_Transport' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-stdio-transport.php';
			}

			// Write startup message to stderr (not stdout, which is for JSON-RPC).
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Writing to STDERR stream; HTML escaping does not apply to CLI/STDIO output.
			fwrite( STDERR, "[NV oOS] STDIO transport starting...\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

			if ( $assistant_id > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Writing to STDERR stream; HTML escaping does not apply to CLI/STDIO output. Integer assistant_id is safe.
				fwrite( STDERR, "[NV oOS] Scoped to assistant ID: {$assistant_id}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
			}

			// Create and run the transport.
			$transport = new WP_MCP_AI_STDIO_Transport( $assistant_id );

			// Handle SIGTERM and SIGINT for graceful shutdown.
			if ( function_exists( 'pcntl_signal' ) ) {
				$shutdown_handler = function () use ( $transport ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Writing to STDERR stream; HTML escaping does not apply to CLI/STDIO output.
					fwrite( STDERR, "\n[NV oOS] Shutting down...\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
					$transport->stop();
				};

				pcntl_signal( SIGTERM, $shutdown_handler );
				pcntl_signal( SIGINT, $shutdown_handler );
			}

			$transport->run();

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Writing to STDERR stream; HTML escaping does not apply to CLI/STDIO output.
			fwrite( STDERR, "[NV oOS] STDIO transport stopped.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
		}
	}

}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai stdio', 'WP_MCP_AI_CLI_STDIO_Command' );
}
