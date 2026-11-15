<?php
/**
 * Test support: an MCP App client that records what it would send.
 *
 * Overrides `send_request()` so the envelope builders can be exercised without
 * a transport, and exposes the protected `build_request_meta()` so its output
 * can be compared with the shared core builder.
 *
 * Required lazily from `test-mcp-request-envelope-parity.php`; excluded from
 * PHPUnit discovery in phpunit.xml.dist.
 *
 * @package WP_MCP_AI_Pro
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

declare(strict_types=1);

/**
 * Records the JSON-RPC method and params of each call, returning a canned result.
 */
class WP_MCP_AI_Test_Capture_MCP_Client extends WP_MCP_AI_MCP_App_Client {

	/**
	 * Recorded calls, each `array( 'method' => string, 'params' => mixed )`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $captured = array();

	/**
	 * Result the next `send_request()` returns.
	 *
	 * @var array<string, mixed>
	 */
	public $next = array();

	/**
	 * Skip the parent constructor: no transport, no credentials.
	 */
	public function __construct() {}

	/**
	 * The protected `_meta` envelope, for comparison with the core builder.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return $this->build_request_meta();
	}

	/**
	 * Record the request instead of sending it.
	 *
	 * @param string $method JSON-RPC method.
	 * @param mixed  $params Request params.
	 * @return array<string, mixed>
	 */
	protected function send_request( $method, $params ) {
		$this->captured[] = array(
			'method' => $method,
			'params' => $params,
		);

		return $this->next;
	}
}
