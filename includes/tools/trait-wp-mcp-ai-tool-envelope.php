<?php
/**
 * Tool-agnostic canonical return-envelope helper.
 *
 * This trait exposes the canonical success-envelope helper described in the
 * Unix Theory Compliance Proposal (§2.2). Every NV oOS tool's `execute()`
 * method returns exactly one of two shapes:
 *
 *   - Success — array( 'success' => true, 'message' => ..., 'data' => ... )
 *   - Failure — new WP_Error( $code, $message, $extra ); never an array
 *               with 'success' => false.
 *
 * The helper was previously scoped to `WP_MCP_AI_Tool_Chat_Response`, which
 * also pulls in collection / pagination / message-generation behaviour. This
 * sibling trait gives tools a focused way to compose the canonical envelope
 * without inheriting the broader chat-response machinery.
 *
 * Backwards compatibility: `WP_MCP_AI_Tool_Chat_Response` composes this trait,
 * so the existing ~227 consumers of `format_success_response()` continue to
 * work unchanged.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @link    docs/project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Envelope
 *
 * Provides the canonical success-envelope helper. Failure paths use
 * `WP_Error` directly — no helper is needed and adding one would obscure the
 * call site.
 *
 * Usage:
 * ```php
 * class My_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Envelope;
 *
 *     public function execute( array $arguments = array(), array $context = array() ) {
 *         if ( ! current_user_can( 'edit_posts' ) ) {
 *             return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
 *         }
 *
 *         return $this->format_success_response(
 *             __( 'Done.', 'mcp-ai-wpoos' ),
 *             array( 'id' => 123 )
 *         );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Envelope {

	/**
	 * Format a canonical success response.
	 *
	 * Returns the shape `array( 'success' => true, 'message' => ..., 'data' => ... )`
	 * — the only success shape permitted by the Unix-theory proposal.
	 *
	 * The `$data` argument may be:
	 *   - `null` — omitted from the response;
	 *   - an associative array — merged into the response at the top level;
	 *   - any other value — placed under the `data` key.
	 *
	 * This mirrors the legacy `WP_MCP_AI_Tool_Chat_Response::format_success_response()`
	 * helper, which now delegates to this trait. The two methods are intentionally
	 * identical so that introducing this trait does not change observed behaviour
	 * for any of the ~227 existing consumers.
	 *
	 * @since 1.2.0
	 *
	 * @param string $message Translated, human-readable success message.
	 * @param mixed  $data    Optional. Payload data. Default null.
	 * @return array{success: bool, message: string} Canonical success envelope.
	 */
	protected function format_success_response( $message, $data = null ) {
		$response = array(
			'success' => true,
			'message' => $message,
		);

		if ( null !== $data ) {
			if ( is_array( $data ) ) {
				$response = array_merge( $response, $data );
			} else {
				$response['data'] = $data;
			}
		}

		return $response;
	}
}
