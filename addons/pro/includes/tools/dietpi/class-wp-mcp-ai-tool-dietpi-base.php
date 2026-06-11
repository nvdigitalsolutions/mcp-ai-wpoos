<?php
/**
 * DietPi Tool Base Class
 *
 * Abstract base for all DietPi Pro Toolkit tools.
 * Provides shared client access, availability checks, capability flags,
 * canonical-envelope response formatting, and shared parameter schemas.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Base' ) ) {

	/**
	 * Abstract base for DietPi toolkit tools.
	 *
	 * Implements WP_MCP_AI_Tool_Interface and WP_MCP_AI_Tool_Capability_Flags_Interface.
	 * Uses the canonical envelope trait for consistent response formatting.
	 *
	 * @since 1.3.0
	 */
	abstract class WP_MCP_AI_Tool_DietPi_Base implements
		WP_MCP_AI_Tool_Interface,
		WP_MCP_AI_Tool_Capability_Flags_Interface {

		use WP_MCP_AI_Tool_Envelope;

		/**
		 * Get the required WordPress capability.
		 *
		 * Read-only tools should override to return 'edit_posts'.
		 * State-changing tools keep the default 'manage_options'.
		 *
		 * @since 1.3.0
		 *
		 * @return string
		 */
		public function get_required_capability() {
			return 'manage_options';
		}

		/**
		 * Check if this tool is available.
		 *
		 * Gating: toolkit enabled + not base version + SSH credentials configured.
		 *
		 * @since 1.3.0
		 *
		 * @return bool
		 */
		public static function is_available() {
			if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
				return false;
			}

			if ( ! function_exists( 'wp_mcp_ai_is_dietpi_toolkit_enabled' ) || ! wp_mcp_ai_is_dietpi_toolkit_enabled() ) {
				return false;
			}

			if ( ! function_exists( 'wp_mcp_ai_dietpi_has_ssh_credentials' ) || ! wp_mcp_ai_dietpi_has_ssh_credentials() ) {
				return false;
			}

			return true;
		}

		/**
		 * Get the reason why this tool is unavailable.
		 *
		 * @since 1.3.0
		 *
		 * @return string
		 */
		public static function get_unavailable_reason() {
			if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
				return __( 'DietPi toolkit is only available in the Pro addon.', 'mcp-ai-wpoos-pro' );
			}

			if ( ! function_exists( 'wp_mcp_ai_is_dietpi_toolkit_enabled' ) || ! wp_mcp_ai_is_dietpi_toolkit_enabled() ) {
				return __( 'DietPi toolkit is not enabled. Enable it in Settings → NV oOS → Tools → Pro Features.', 'mcp-ai-wpoos-pro' );
			}

			if ( ! function_exists( 'wp_mcp_ai_dietpi_has_ssh_credentials' ) || ! wp_mcp_ai_dietpi_has_ssh_credentials() ) {
				return __( 'DietPi SSH credentials are not configured. Enter your Pi hostname, SSH user, and key or password in the DietPi Toolkit settings.', 'mcp-ai-wpoos-pro' );
			}

			return __( 'DietPi tool is not available.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * Get capability flags for this tool.
		 *
		 * Subclasses MUST override this to add their specific flags
		 * while including the base flags via parent::get_capability_flags().
		 *
		 * @since 1.3.0
		 *
		 * @return array<string>
		 */
		public function get_capability_flags() {
			return array(
				'pro',
				'requires-credentials',
				'external-api',
				'network-dependent',
			);
		}

		/**
		 * Get the SSH client instance.
		 *
		 * @since 1.3.0
		 *
		 * @return WP_MCP_AI_DietPi_SSH_Client
		 */
		protected function ssh() {
			return WP_MCP_AI_DietPi_SSH_Client::instance();
		}

		/**
		 * Get the app API client instance.
		 *
		 * @since 1.3.0
		 *
		 * @return WP_MCP_AI_DietPi_App_Client
		 */
		protected function app_client() {
			return WP_MCP_AI_DietPi_App_Client::instance();
		}

		/**
		 * Build the canonical success response.
		 *
		 * @since 1.3.0
		 *
		 * @param string $message Human-readable success message.
		 * @param mixed  $data    Serializable data payload.
		 * @return array Canonical success envelope.
		 */
		protected function success( $message, $data = null ) {
			$response = $this->format_success_response( $message, $data );

			if ( null !== $data && ! isset( $response['data'] ) ) {
				$response['data'] = $data;
			}

			return $response;
		}

		/**
		 * Sanitize a service name from arguments.
		 *
		 * @since 1.3.0
		 *
		 * @param array $arguments Tool arguments.
		 * @return string Sanitized service name.
		 */
		protected function sanitize_service_name( $arguments ) {
			return isset( $arguments['service_name'] ) ? sanitize_key( $arguments['service_name'] ) : '';
		}

		/**
		 * Sanitize a service action from arguments.
		 *
		 * @since 1.3.0
		 *
		 * @param array $arguments Tool arguments.
		 * @return string Sanitized action (start, stop, restart, status).
		 */
		protected function sanitize_service_action( $arguments ) {
			$valid = array( 'start', 'stop', 'restart', 'status' );
			$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
			return in_array( $action, $valid, true ) ? $action : '';
		}

		/**
		 * Sanitize a boolean confirm flag from arguments.
		 *
		 * @since 1.3.0
		 *
		 * @param array $arguments Tool arguments.
		 * @return bool
		 */
		protected function sanitize_confirm( $arguments ) {
			return isset( $arguments['confirm'] ) && true === $arguments['confirm'];
		}

		/**
		 * Sanitize a string parameter from arguments.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $arguments Tool arguments.
		 * @param string $key       Argument key.
		 * @param string $default   Default value.
		 * @return string
		 */
		protected function sanitize_string( $arguments, $key, $default = '' ) {
			return isset( $arguments[ $key ] ) ? sanitize_text_field( $arguments[ $key ] ) : $default;
		}

		/**
		 * Sanitize an integer parameter from arguments.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $arguments Tool arguments.
		 * @param string $key       Argument key.
		 * @param int    $default   Default value.
		 * @return int
		 */
		protected function sanitize_int( $arguments, $key, $default = 0 ) {
			return isset( $arguments[ $key ] ) ? absint( $arguments[ $key ] ) : $default;
		}

		/**
		 * Sanitize a boolean parameter from arguments.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $arguments Tool arguments.
		 * @param string $key       Argument key.
		 * @param bool   $default   Default value.
		 * @return bool
		 */
		protected function sanitize_bool( $arguments, $key, $default = false ) {
			return isset( $arguments[ $key ] ) ? (bool) $arguments[ $key ] : $default;
		}

		/**
		 * Resolve the URL for a managed app from settings.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @return string|WP_Error
		 */
		protected function resolve_app_url( $app_slug ) {
			return $this->app_client()->resolve_app_url( $app_slug );
		}
	}
}
