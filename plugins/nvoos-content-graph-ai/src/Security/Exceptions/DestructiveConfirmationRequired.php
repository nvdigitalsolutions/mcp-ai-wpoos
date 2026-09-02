<?php
/**
 * Destructive Confirmation Required Exception for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/exceptions/class-wp-mcp-ai-destructive-confirmation-required.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Thrown by the DestructiveOpsGate when a destructive
 * tool is invoked without the required `confirm_destructive=true`
 * argument so the rejection flows through the normal REST error pipeline.
 *
 * @package NvoosContentGraphAi\Security\Exceptions
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception raised when a destructive operation lacks confirmation.
 *
 * @since 1.1.0
 */
class DestructiveConfirmationRequired extends \Exception {

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
		return new \WP_Error(
			'wp_mcp_ai_destructive_confirmation_required',
			$this->getMessage(),
			array_merge( array( 'status' => 428 ), $this->payload )
		);
	}
}
