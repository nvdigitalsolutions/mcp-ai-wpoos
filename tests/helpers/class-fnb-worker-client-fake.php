<?php
/**
 * F&B worker-offload test stub.
 *
 * Fake worker client injected through the wp_mcp_ai_fnb_worker_client filter
 * so the loader seam tests never touch the network.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Fake worker client exposing should_offload() + parse_file().
 */
class Fnb_Worker_Client_Fake {

	/**
	 * Payload returned by parse_file().
	 *
	 * @var mixed
	 */
	public $payload;

	/**
	 * Whether should_offload() reports true.
	 *
	 * @var bool
	 */
	public $should_offload_result = true;

	/**
	 * Last path passed to should_offload().
	 *
	 * @var string
	 */
	public $last_path = '';

	/**
	 * Number of parse_file() calls.
	 *
	 * @var int
	 */
	public $parse_calls = 0;

	/**
	 * Stub offload decision.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	public function should_offload( $path ) {
		$this->last_path = $path;

		return $this->should_offload_result;
	}

	/**
	 * Stub parse call.
	 *
	 * @param string $path    Absolute file path.
	 * @param array  $options Parse options.
	 * @return mixed
	 */
	public function parse_file( $path, array $options = array() ) {
		unset( $path, $options );
		$this->parse_calls++;

		return $this->payload;
	}
}
