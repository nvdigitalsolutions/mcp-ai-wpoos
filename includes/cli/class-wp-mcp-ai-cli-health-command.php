<?php
/**
 * WP-CLI `mcp-ai health` command for NV oOS.
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

if ( ! class_exists( 'WP_MCP_AI_CLI_Health_Command' ) ) {
	class WP_MCP_AI_CLI_Health_Command extends WP_MCP_AI_CLI_Base_Command {

		/**
		 * Run diagnostic health checks.
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
		 *     $ wp mcp-ai health
		 *     $ wp mcp-ai health --format=json
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function __invoke( $args, $assoc_args ) {
			global $wp_version;

			$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

			// Plugin version info.
			$nv_version = defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : __( 'unknown', 'mcp-ai-wpoos' );
			$is_base    = defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION;
			$edition    = $is_base ? __( 'Base', 'mcp-ai-wpoos' ) : __( 'Pro', 'mcp-ai-wpoos' );

			// Tool registry health.
			$registry         = WP_MCP_AI_Tool_Registry::get_instance();
			$all_tools        = $registry->get_tools();
			$registered_count = count( $all_tools );

			// Count enabled tools (those not marked as disabled).
			$enabled_count = 0;
			foreach ( $all_tools as $tool ) {
				if ( method_exists( $tool, 'is_enabled' ) && $tool->is_enabled() ) {
					++$enabled_count;
				} elseif ( ! method_exists( $tool, 'is_enabled' ) ) {
					// Tools without is_enabled method are considered enabled by default.
					++$enabled_count;
				}
			}

			// DLQ pending count.
			$dlq_pending = 0;
			if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
				$dlq_stats   = WP_MCP_AI_Dead_Letter_Queue::get_stats();
				$dlq_pending = isset( $dlq_stats['total'] ) ? absint( $dlq_stats['total'] ) : 0;
			}

			// Async queue stats.
			$queue_stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();

			// Provider connectivity.
			$settings        = get_option( 'wp_mcp_ai_settings', array() );
			$openai_key      = isset( $settings['openai_api_key'] ) ? trim( $settings['openai_api_key'] ) : '';
			$gemini_key      = isset( $settings['gemini_api_key'] ) ? trim( $settings['gemini_api_key'] ) : '';
			$ollama_base_url = isset( $settings['ollama_base_url'] ) ? trim( $settings['ollama_base_url'] ) : '';

			$openai_status = '' !== $openai_key ? __( 'configured', 'mcp-ai-wpoos' ) : __( 'not configured', 'mcp-ai-wpoos' );
			$gemini_status = '' !== $gemini_key ? __( 'configured', 'mcp-ai-wpoos' ) : __( 'not configured', 'mcp-ai-wpoos' );
			$ollama_status = '' !== $ollama_base_url ? __( 'configured', 'mcp-ai-wpoos' ) : __( 'not configured', 'mcp-ai-wpoos' );

			// Settings integrity.
			$settings_count  = is_array( $settings ) ? count( $settings ) : -1;
			$settings_status = $settings_count >= 0 ? __( 'ok', 'mcp-ai-wpoos' ) : __( 'corrupt', 'mcp-ai-wpoos' );

			$items = array(
				array(
					'metric' => __( 'WordPress Version', 'mcp-ai-wpoos' ),
					'value'  => $wp_version,
				),
				array(
					'metric' => __( 'PHP Version', 'mcp-ai-wpoos' ),
					'value'  => PHP_VERSION,
				),
				array(
					'metric' => __( 'NV oOS Version', 'mcp-ai-wpoos' ),
					'value'  => sprintf( '%s (%s)', $nv_version, $edition ),
				),
				array(
					'metric' => __( 'Tool Registry — Total Tools', 'mcp-ai-wpoos' ),
					'value'  => $registered_count,
				),
				array(
					'metric' => __( 'Tool Registry — Enabled Tools', 'mcp-ai-wpoos' ),
					'value'  => $enabled_count,
				),
				array(
					'metric' => __( 'DLQ Pending Items', 'mcp-ai-wpoos' ),
					'value'  => $dlq_pending,
				),
				array(
					'metric' => __( 'Async Queue — Total', 'mcp-ai-wpoos' ),
					'value'  => isset( $queue_stats['total'] ) ? absint( $queue_stats['total'] ) : 0,
				),
				array(
					'metric' => __( 'Async Queue — Active', 'mcp-ai-wpoos' ),
					'value'  => isset( $queue_stats['active'] ) ? absint( $queue_stats['active'] ) : 0,
				),
				array(
					'metric' => __( 'Async Queue — Pending', 'mcp-ai-wpoos' ),
					'value'  => isset( $queue_stats['pending'] ) ? absint( $queue_stats['pending'] ) : 0,
				),
				array(
					'metric' => __( 'Async Queue — Failed', 'mcp-ai-wpoos' ),
					'value'  => isset( $queue_stats['failed'] ) ? absint( $queue_stats['failed'] ) : 0,
				),
				array(
					'metric' => __( 'OpenAI API Key', 'mcp-ai-wpoos' ),
					'value'  => $openai_status,
				),
				array(
					'metric' => __( 'Gemini API Key', 'mcp-ai-wpoos' ),
					'value'  => $gemini_status,
				),
				array(
					'metric' => __( 'Ollama Base URL', 'mcp-ai-wpoos' ),
					'value'  => $ollama_status,
				),
				array(
					'metric' => __( 'Settings Integrity', 'mcp-ai-wpoos' ),
					'value'  => sprintf(
						/* translators: %s: status indicator (ok/corrupt) */
						__( '%1$s (%2$d keys)', 'mcp-ai-wpoos' ),
						$settings_status,
						$settings_count
					),
				),
			);

			\WP_CLI\Utils\format_items( $format, $items, array( 'metric', 'value' ) );
		}
	}

}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai health', 'WP_MCP_AI_CLI_Health_Command' );
}
