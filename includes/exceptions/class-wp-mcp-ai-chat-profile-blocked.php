<?php
/**
 * Chat Profile Blocked Exception
 *
 * Thrown by WP_MCP_AI_Read_Only_Profile_Gate when a tool carrying gated
 * capability flags is invoked under a restrictive chat profile (e.g.
 * `read-only`). Mirrors WP_MCP_AI_Destructive_Confirmation_Required so the
 * rejection flows through the normal REST error pipeline: the executor
 * catches it and converts it to a canonical WP_Error envelope (HTTP 403)
 * that the model receives as the tool result and can react to without
 * retry-looping.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Chat_Profile_Blocked' ) ) {
	/**
	 * Exception raised when a chat profile blocks a tool execution.
	 */
	class WP_MCP_AI_Chat_Profile_Blocked extends Exception {

		/**
		 * Tool slug that was rejected.
		 *
		 * @var string
		 */
		private $tool_slug;

		/**
		 * Rejection payload (flags, profile, guidance).
		 *
		 * @var array
		 */
		private $payload;

		/**
		 * Constructor.
		 *
		 * @param string $tool_slug Tool identifier that was rejected.
		 * @param array  $payload   Rejection payload for the error data.
		 * @param string $message   Human-readable rejection message.
		 */
		public function __construct( $tool_slug, array $payload, $message = '' ) {
			$this->tool_slug = (string) $tool_slug;
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
		 * Get the rejection payload.
		 *
		 * @return array
		 */
		public function get_payload() {
			return $this->payload;
		}

		/**
		 * Convert to a WP_Error with HTTP 403 (Forbidden).
		 *
		 * @return WP_Error
		 */
		public function to_wp_error() {
			return new WP_Error(
				'wp_mcp_ai_chat_profile_blocked',
				$this->getMessage(),
				array_merge( array( 'status' => 403 ), $this->payload )
			);
		}
	}
}
