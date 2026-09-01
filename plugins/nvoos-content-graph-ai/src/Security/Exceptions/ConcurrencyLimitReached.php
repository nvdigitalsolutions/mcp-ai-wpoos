<?php
/**
 * Concurrency Limit Reached Exception for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/exceptions/class-wp-mcp-ai-concurrency-limit-reached.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Thrown by the ConcurrencyGuard subscriber when a tool
 * execution would exceed the per-operation-type concurrent execution
 * limit; caught by REST handlers and converted to a WP_Error envelope
 * (HTTP 429).
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
 * Exception for concurrency limit violations.
 *
 * @since 1.1.0
 */
class ConcurrencyLimitReached extends \Exception {

	/**
	 * Operation type that reached its limit.
	 *
	 * @var string
	 */
	private $operation_type;

	/**
	 * Constructor.
	 *
	 * @param string $operation_type Concurrency operation type (e.g. 'image_generation').
	 * @param string $message        Human-readable error message.
	 */
	public function __construct( $operation_type, $message ) {
		parent::__construct( $message, 429 );
		$this->operation_type = $operation_type;
	}

	/**
	 * Get the operation type that triggered the limit.
	 *
	 * @return string
	 */
	public function get_operation_type() {
		return $this->operation_type;
	}

	/**
	 * Convert to a WP_Error suitable for REST response envelopes.
	 *
	 * @return WP_Error
	 */
	public function to_wp_error() {
		return new \WP_Error(
			'concurrency_limit',
			$this->getMessage(),
			array(
				'status'         => 429,
				'operation_type' => $this->operation_type,
				'retry_after'    => 30,
			)
		);
	}
}
