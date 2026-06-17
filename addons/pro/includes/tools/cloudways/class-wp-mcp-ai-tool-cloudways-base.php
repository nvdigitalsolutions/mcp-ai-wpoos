<?php
/**
 * Cloudways Tool Base Class
 *
 * Abstract base for all Cloudways Pro Toolkit tools.
 * Provides shared client access, availability checks, capability flags,
 * canonical-envelope response formatting, and shared parameter schemas.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Cloudways_Toolkit
 * @since      1.1.15
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Base' ) ) {

	/**
	 * Abstract base for Cloudways toolkit tools.
	 *
	 * Implements WP_MCP_AI_Tool_Interface and WP_MCP_AI_Tool_Capability_Flags_Interface.
	 * Uses the canonical envelope trait for consistent response formatting.
	 */
	abstract class WP_MCP_AI_Tool_Cloudways_Base implements
		WP_MCP_AI_Tool_Interface,
		WP_MCP_AI_Tool_Capability_Flags_Interface {

		use WP_MCP_AI_Tool_Envelope;

		/**
		 * Get the required WordPress capability.
		 *
		 * All Cloudways tools require manage_options — infrastructure
		 * management is an admin-only concern.
		 *
		 * @since 1.1.15
		 *
		 * @return string
		 */
		public function get_required_capability() {
			return 'manage_options';
		}

		/**
		 * Check if this tool is available.
		 *
		 * Gating: toolkit enabled + not base version + credentials configured.
		 *
		 * @since 1.1.15
		 *
		 * @return bool
		 */
		public static function is_available() {
			if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
				return false;
			}

			if ( ! function_exists( 'wp_mcp_ai_is_cloudways_toolkit_enabled' ) || ! wp_mcp_ai_is_cloudways_toolkit_enabled() ) {
				return false;
			}

			if ( ! function_exists( 'wp_mcp_ai_cloudways_has_credentials' ) || ! wp_mcp_ai_cloudways_has_credentials() ) {
				return false;
			}

			return true;
		}

		/**
		 * Get the reason why this tool is unavailable.
		 *
		 * @since 1.1.15
		 *
		 * @return string
		 */
		public static function get_unavailable_reason() {
			if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
				return __( 'Cloudways toolkit is only available in the Pro addon.', 'mcp-ai-wpoos-pro' );
			}

			if ( ! function_exists( 'wp_mcp_ai_is_cloudways_toolkit_enabled' ) || ! wp_mcp_ai_is_cloudways_toolkit_enabled() ) {
				return __( 'Cloudways toolkit is not enabled. Enable it in Plugins → NV oOS → Tools → Pro Features.', 'mcp-ai-wpoos-pro' );
			}

			if ( ! function_exists( 'wp_mcp_ai_cloudways_has_credentials' ) || ! wp_mcp_ai_cloudways_has_credentials() ) {
				return __( 'Cloudways API credentials are not configured. Enter your email and API key in the Cloudways Toolkit settings.', 'mcp-ai-wpoos-pro' );
			}

			return __( 'Cloudways tool is not available.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * Get capability flags for this tool.
		 *
		 * Subclasses MUST override this to add their specific flags
		 * (read-only, write, state-changing, etc.) while including
		 * the base flags via parent::get_capability_flags().
		 *
		 * @since 1.1.15
		 *
		 * @return array<string>
		 */
		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array(
				'pro',
				'requires-credentials',
				'external-api',
				'network-dependent',
			);
		}

		/**
		 * Get the Cloudways client instance.
		 *
		 * Convenience accessor for tool subclasses.
		 *
		 * @since 1.1.15
		 *
		 * @return WP_MCP_AI_Cloudways_Client
		 */
		protected function client() {
			return WP_MCP_AI_Cloudways_Client::instance();
		}

		/**
		 * Build the canonical success response.
		 *
		 * Thin wrapper around the envelope trait for tool consistency.
		 *
		 * @since 1.1.15
		 *
		 * @param string $message Human-readable success message.
		 * @param mixed  $data    Serializable data payload.
		 * @return array Canonical success envelope.
		 */
		protected function success( $message, $data = null ) {
			$response = $this->format_success_response( $message, $data );

			// Ensure data is wrapped in a 'data' key for tool consistency.
			if ( null !== $data && ! isset( $response['data'] ) ) {
				$response['data'] = $data;
			}

			return $response;
		}

		/**
		 * Sanitize a server ID from arguments.
		 *
		 * @since 1.1.15
		 *
		 * @param array $arguments Tool arguments.
		 * @return int Server ID.
		 */
		protected function sanitize_server_id( $arguments ) {
			return isset( $arguments['server_id'] ) ? absint( $arguments['server_id'] ) : 0;
		}

		/**
		 * Sanitize an app ID from arguments.
		 *
		 * @since 1.1.15
		 *
		 * @param array $arguments Tool arguments.
		 * @return int App ID.
		 */
		protected function sanitize_app_id( $arguments ) {
			return isset( $arguments['app_id'] ) ? absint( $arguments['app_id'] ) : 0;
		}

		/**
		 * Sanitize a project ID from arguments.
		 *
		 * @since 1.1.15
		 *
		 * @param array $arguments Tool arguments.
		 * @return int Project ID.
		 */
		protected function sanitize_project_id( $arguments ) {
			return isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		}

		/**
		 * Sanitize an operation ID from arguments.
		 *
		 * @since 1.1.15
		 *
		 * @param array $arguments Tool arguments.
		 * @return string Operation ID.
		 */
		protected function sanitize_operation_id( $arguments ) {
			return isset( $arguments['operation_id'] ) ? sanitize_text_field( $arguments['operation_id'] ) : '';
		}

		/**
		 * Sanitize a label/name string from arguments.
		 *
		 * @since 1.1.15
		 *
		 * @param array  $arguments Tool arguments.
		 * @param string $key       Argument key.
		 * @return string
		 */
		protected function sanitize_label( $arguments, $key = 'label' ) {
			return isset( $arguments[ $key ] ) ? sanitize_text_field( $arguments[ $key ] ) : '';
		}

		/**
		 * Sanitize a boolean confirm flag from arguments.
		 *
		 * @since 1.1.15
		 *
		 * @param array $arguments Tool arguments.
		 * @return bool
		 */
		protected function sanitize_confirm( $arguments ) {
			return isset( $arguments['confirm'] ) && true === $arguments['confirm'];
		}
	}
}
