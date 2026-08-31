<?php
/**
 * /diagnose slash command.
 *
 * Compiles a diagnostic bundle — plugin version, PHP, recent errors,
 * async health, and tool count — formatted as a Markdown code block for
 * easy copy-paste into issue reports.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlashCommandDiagnose
 *
 * @since 2.1.0
 */
class SlashCommandDiagnose {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute the /diagnose command.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map.
	 * @param array $context Execution context.
	 * @return array|WP_Error Command response or error.
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new \WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'nvoos-content-graph-ai-platform' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability gate — manage_options required.
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'manage_options capability is required to run /diagnose.', 'nvoos-content-graph-ai-platform' ) );
		}

		$as_json = isset( $flags['json'] );

		$bundle = $this->build_bundle();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $bundle,
			);
		}

		return array(
			'success' => true,
			'message' => $this->render_report( $bundle ),
			'data'    => $bundle,
		);
	}

	/**
	 * Compile the full diagnostic bundle.
	 *
	 * @return array
	 */
	private function build_bundle() {
		$bundle = array();

		// 1. Plugin version.
		if ( defined( 'WP_MCP_AI_VERSION' ) ) {
			$bundle['plugin_version'] = WP_MCP_AI_VERSION;
		} else {
			$bundle['plugin_version'] = get_option( 'wp_mcp_ai_version', 'unknown' );
		}

		// 2. WordPress version.
		$bundle['wp_version'] = get_bloginfo( 'version' );

		// 3. PHP version.
		$bundle['php_version'] = phpversion();

		// 4. Recent errors (last 5).
		$raw_errors              = get_option( 'wp_mcp_ai_recent_errors', '[]' );
		$errors                  = json_decode( $raw_errors, true );
		$bundle['recent_errors'] = is_array( $errors ) ? array_slice( $errors, -5 ) : array();

		// 5. Recent activity (last 5).
		$raw_activity              = get_option( 'wp_mcp_ai_recent_activity', '[]' );
		$activity                  = json_decode( $raw_activity, true );
		$bundle['recent_activity'] = is_array( $activity ) ? array_slice( $activity, -5 ) : array();

		// 6. Async health.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Async_Health_Monitor' ) ) {
			$health                 = \WP_MCP_AI_Async_Health_Monitor::check_async_health();
			$bundle['async_health'] = is_array( $health ) ? $health : array( 'raw' => $health );
		} else {
			$bundle['async_health'] = array( 'available' => false );
		}

		// 7. Tool count.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry             = \WP_MCP_AI_Tool_Registry::get_instance();
			$tools                = $registry->get_tools();
			$bundle['tool_count'] = is_array( $tools ) ? count( $tools ) : 0;
		} else {
			$bundle['tool_count'] = 0;
		}

		return $bundle;
	}

	/**
	 * Render the bundle as a Markdown diagnostic block.
	 *
	 * @param array $bundle Diagnostic bundle.
	 * @return string
	 */
	private function render_report( array $bundle ) {
		$plugin_version  = isset( $bundle['plugin_version'] ) ? esc_html( (string) $bundle['plugin_version'] ) : 'unknown';
		$wp_version      = isset( $bundle['wp_version'] ) ? esc_html( (string) $bundle['wp_version'] ) : 'unknown';
		$php_version     = isset( $bundle['php_version'] ) ? esc_html( (string) $bundle['php_version'] ) : 'unknown';
		$tool_count      = isset( $bundle['tool_count'] ) ? (int) $bundle['tool_count'] : 0;
		$recent_errors   = isset( $bundle['recent_errors'] ) && is_array( $bundle['recent_errors'] ) ? $bundle['recent_errors'] : array();
		$recent_activity = isset( $bundle['recent_activity'] ) && is_array( $bundle['recent_activity'] ) ? $bundle['recent_activity'] : array();
		$async_health    = isset( $bundle['async_health'] ) && is_array( $bundle['async_health'] ) ? $bundle['async_health'] : array();

		$out  = "```diagnostic\n";
		$out .= "NV oOS Diagnostic Report\n";
		$out .= "========================\n";
		$out .= "Plugin version : {$plugin_version}\n";
		$out .= "WordPress      : {$wp_version}\n";
		$out .= "PHP            : {$php_version}\n";
		$out .= "Tools loaded   : {$tool_count}\n";

		// Async health summary.
		$health_status = isset( $async_health['status'] ) ? (string) $async_health['status'] : 'n/a';
		$out          .= "Async health   : {$health_status}\n";

		// Recent errors.
		$out .= "\nRecent Errors (last 5)\n";
		$out .= "----------------------\n";
		if ( empty( $recent_errors ) ) {
			$out .= "  (none)\n";
		} else {
			foreach ( $recent_errors as $err ) {
				if ( is_array( $err ) ) {
					$err_msg  = isset( $err['message'] ) ? (string) $err['message'] : wp_json_encode( $err );
					$err_time = isset( $err['time'] ) ? (string) $err['time'] : '';
					$out     .= sprintf( "  [%s] %s\n", $err_time, $err_msg );
				} else {
					$out .= '  ' . (string) $err . "\n";
				}
			}
		}

		// Recent activity.
		$out .= "\nRecent Activity (last 5)\n";
		$out .= "------------------------\n";
		if ( empty( $recent_activity ) ) {
			$out .= "  (none)\n";
		} else {
			foreach ( $recent_activity as $act ) {
				if ( is_array( $act ) ) {
					$act_msg  = isset( $act['message'] ) ? (string) $act['message'] : wp_json_encode( $act );
					$act_time = isset( $act['time'] ) ? (string) $act['time'] : '';
					$out     .= sprintf( "  [%s] %s\n", $act_time, $act_msg );
				} else {
					$out .= '  ' . (string) $act . "\n";
				}
			}
		}

		$out .= "```\n";

		return $out;
	}
}
