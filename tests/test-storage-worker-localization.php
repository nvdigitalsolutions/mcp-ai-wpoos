<?php
/**
 * Tests for the storage-worker localization augmentation (proposal 032).
 *
 * Verifies that WP_MCP_AI_Shortcode::get_storage_worker_inline_script()
 * emits the worker URL and threshold onto window.wpMcpAiChat, and that
 * the wp_mcp_ai_storage_worker_threshold filter acts as the kill switch.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Storage_Worker_Localization
 */
class Test_Storage_Worker_Localization extends WP_UnitTestCase {

	/**
	 * The inline script must carry the worker URL and the default threshold.
	 */
	public function test_inline_script_contains_worker_url_and_default_threshold() {
		$script = WP_MCP_AI_Shortcode::get_storage_worker_inline_script();

		$this->assertStringContainsString( 'storageWorkerUrl', $script );
		// Slashes are JSON-escaped (\/) by wp_json_encode(), so assert the
		// filename without a path prefix.
		$this->assertStringContainsString( 'storage-worker.js', $script );
		$this->assertStringContainsString( '"storageWorkerThreshold":10000', $script );
		$this->assertStringContainsString( 'Object.assign', $script );
	}

	/**
	 * A zero threshold (filter kill switch) must disable the offload.
	 */
	public function test_threshold_filter_zero_disables_offload() {
		add_filter( 'wp_mcp_ai_storage_worker_threshold', '__return_zero' );

		$script = WP_MCP_AI_Shortcode::get_storage_worker_inline_script();

		$this->assertStringContainsString( '"storageWorkerThreshold":0', $script );

		remove_filter( 'wp_mcp_ai_storage_worker_threshold', '__return_zero' );
	}

	/**
	 * Negative filter values must be clamped to zero, never emitted as-is.
	 */
	public function test_negative_threshold_is_clamped_to_zero() {
		add_filter(
			'wp_mcp_ai_storage_worker_threshold',
			static function () {
				return -50;
			}
		);

		$script = WP_MCP_AI_Shortcode::get_storage_worker_inline_script();

		$this->assertStringContainsString( '"storageWorkerThreshold":0', $script );
		$this->assertStringNotContainsString( ':-50', $script );

		remove_all_filters( 'wp_mcp_ai_storage_worker_threshold' );
	}
}
