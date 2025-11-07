<?php
/**
 * Security Monitor Admin Interface
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Security_Monitor_Admin' ) ) {
	/**
	 * Handles admin interface for security monitoring.
	 */
	class WP_MCP_AI_Security_Monitor_Admin {
		/**
		 * Initialize the admin interface.
		 */
		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_post_wp_mcp_ai_clear_shutdown', array( __CLASS__, 'handle_clear_shutdown' ) );
			add_action( 'admin_post_wp_mcp_ai_clear_violations', array( __CLASS__, 'handle_clear_violations' ) );
			add_filter( 'wp_mcp_ai_admin_settings_sanitize', array( __CLASS__, 'sanitize_monitor_settings' ), 10, 2 );
		}

		/**
		 * Register settings fields.
		 */
		public static function register_settings() {
			// Settings are registered via the main settings class hooks.
		}

		/**
		 * Sanitize monitor settings.
		 *
		 * @param array $settings Sanitized settings.
		 * @param array $input    Raw input settings.
		 * @return array
		 */
		public static function sanitize_monitor_settings( $settings, $input ) {
			$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();

			// Update monitor settings from form input.
			$monitor_settings = array(
				'enabled'                 => ! empty( $input['wp_mcp_ai_security_monitor_enabled'] ),
				'auto_shutdown_enabled'   => ! empty( $input['wp_mcp_ai_security_monitor_auto_shutdown'] ),
				'max_requests_per_minute' => isset( $input['wp_mcp_ai_security_monitor_max_requests_per_minute'] ) ?
					absint( $input['wp_mcp_ai_security_monitor_max_requests_per_minute'] ) :
					WP_MCP_AI_Nefarious_Usage_Monitor::DEFAULT_MAX_REQUESTS_PER_MINUTE,
				'max_tools_per_hour'      => isset( $input['wp_mcp_ai_security_monitor_max_tools_per_hour'] ) ?
					absint( $input['wp_mcp_ai_security_monitor_max_tools_per_hour'] ) :
					WP_MCP_AI_Nefarious_Usage_Monitor::DEFAULT_MAX_TOOLS_PER_HOUR,
				'violation_threshold'     => isset( $input['wp_mcp_ai_security_monitor_violation_threshold'] ) ?
					absint( $input['wp_mcp_ai_security_monitor_violation_threshold'] ) :
					5,
			);

			$monitor->update_settings( $monitor_settings );

			return $settings;
		}

		/**
		 * Handle clear emergency shutdown request.
		 */
		public static function handle_clear_shutdown() {
			check_admin_referer( 'wp_mcp_ai_clear_shutdown', 'wp_mcp_ai_clear_shutdown_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-mcp-ai' ) );
			}

			$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();
			$monitor->clear_emergency_shutdown();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'wp-mcp-ai-settings',
						'cleared' => 'shutdown',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		/**
		 * Handle clear violations request.
		 */
		public static function handle_clear_violations() {
			check_admin_referer( 'wp_mcp_ai_clear_violations', 'wp_mcp_ai_clear_violations_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-mcp-ai' ) );
			}

			$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();
			$monitor->clear_violations();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'wp-mcp-ai-settings',
						'cleared' => 'violations',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}
	}
}
