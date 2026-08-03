<?php
/**
 * Destructive Confirmation Required Exception
 *
 * Thrown by WP_MCP_AI_Destructive_Ops_Gate when a destructive tool is invoked
 * without the required `confirm_destructive=true` argument. Replaces the
 * previous wp_die() short-circuit so the rejection flows through the normal
 * REST error pipeline (canonical WP_Error envelope, rest_post_dispatch
 * filters, JSON responses for MCP clients).
 *
 * @package WP_MCP_AI
 * @since   1.1.44
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Destructive_Confirmation_Required' ) ) {
	/**
	 * Exception raised when a destructive operation lacks confirmation.
	 */
	class WP_MCP_AI_Destructive_Confirmation_Required extends Exception {

		/**
		 * Tool slug that was rejected.
		 *
		 * @var string
		 */
		private $tool_slug;

		/**
		 * Preview payload (flags, arguments, confirmation instructions).
		 *
		 * @var array
		 */
		private $payload;

		/**
		 * Constructor.
		 *
		 * @param string $tool_slug Tool identifier that was rejected.
		 * @param array  $payload   Preview/confirmation payload for the error data.
		 * @param string $message   Human-readable rejection message.
		 */
		public function __construct( $tool_slug, array $payload, $message = '' ) {
			$this->tool_slug = sanitize_key( $tool_slug );
			$this->payload   = $payload;

			parent::__construct( $message );
		}

		/**
		 * Get the rejected tool slug.
		 *
		 * @return string
		 */
		public function get_tool_slug() {
			return $this->tool_slug;
		}

		/**
		 * Get the preview/confirmation payload.
		 *
		 * @return array
		 */
		public function get_payload() {
			return $this->payload;
		}

		/**
		 * Convert to a WP_Error with HTTP 428 (Precondition Required).
		 *
		 * @return WP_Error
		 */
		public function to_wp_error() {
			return new WP_Error(
				'wp_mcp_ai_destructive_confirmation_required',
				$this->getMessage(),
				array_merge( array( 'status' => 428 ), $this->payload )
			);
		}
	}
}
