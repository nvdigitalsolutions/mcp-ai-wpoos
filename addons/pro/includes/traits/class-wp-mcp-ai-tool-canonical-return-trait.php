<?php
/**
 * Canonical Return Envelope Adapter Trait
 *
 * Provides a helper that normalises tool return values so downstream
 * consumers (chat service, REST controllers, AJAX handlers) can check
 * both WP_Error and legacy array('success' => false) patterns without
 * duplication.
 *
 * Tools should return WP_Error on failures; this adapter bridges the
 * gap during migration from legacy array envelopes.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for tools needing canonical-envelope bridging.
 */
trait WP_MCP_AI_Tool_Canonical_Return_Trait {

	/**
	 * Wrap a WP_Error in the legacy array format for backward compatibility.
	 *
	 * @param \WP_Error|mixed $result Tool execution result.
	 * @return array Normalised result array. Success results pass through
	 *               unchanged; WP_Error is converted to array('success' => false, 'error' => ...).
	 */
	protected function normalise_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			);
		}

		return $result;
	}

	/**
	 * Create a WP_Error with a standardised code prefix for this tool.
	 *
	 * @param string $code    Short error code (e.g., 'missing_args').
	 * @param string $message Human-readable error message.
	 * @return \WP_Error
	 */
	protected function tool_error( $code, $message ) {
		return new \WP_Error(
			$this->get_slug() . '_' . $code,
			$message
		);
	}
}
