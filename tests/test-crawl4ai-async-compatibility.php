<?php
/**
 * Test Crawl4AI async result sanitization and compatibility.
 *
 * Verifies that crawl4ai async results follow the same pattern as veo
 * (async, status, job_id fields) to ensure consistent JavaScript detection.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php';

/**
 * Test Crawl4AI async compatibility.
 */
class Test_Crawl4AI_Async_Compatibility extends WP_UnitTestCase {

	/**
	 * The tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Run_Crawl4AI_Job
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
	}

	/**
	 * Test that async fields are preserved during sanitization.
	 *
	 * Crawl4AI uses the same async pattern as veo for consistency.
	 * The JavaScript chat client (chat.js:8942) checks for:
	 * result.async === true && result.status === 'pending' && result.job_id
	 */
	public function test_sanitize_preserves_async_fields() {
		// Mock pending crawl4ai result
		$pending_result = array(
			'async'    => true,
			'status'   => 'pending',
			'task_id'  => 'crawl_abc123',
			'job_id'   => 'crawl_abc123',
			'message'  => 'Crawl job queued for background processing. Results will appear when ready.',
			'results'  => array(),
			'metadata' => array(
				'poll_interval' => 3,
				'wait_timeout'  => 120,
			),
		);

		$sanitized = $this->tool->sanitize_for_llm( $pending_result );

		// Critical async fields must be preserved
		$this->assertArrayHasKey( 'async', $sanitized, 'async field must be preserved for UI detection' );
		$this->assertArrayHasKey( 'status', $sanitized, 'status field must be preserved for UI detection' );
		$this->assertArrayHasKey( 'task_id', $sanitized, 'task_id field must be preserved for polling' );
		$this->assertArrayHasKey( 'job_id', $sanitized, 'job_id field must be preserved for consistency with veo' );
		$this->assertArrayHasKey( 'message', $sanitized, 'message field should be preserved for user feedback' );

		// Verify values are unchanged
		$this->assertTrue( $sanitized['async'], 'async flag must be true' );
		$this->assertSame( 'pending', $sanitized['status'], 'status must be pending' );
		$this->assertSame( 'crawl_abc123', $sanitized['task_id'], 'task_id must be preserved' );
		$this->assertSame( 'crawl_abc123', $sanitized['job_id'], 'job_id must match task_id' );
	}

	/**
	 * Test JavaScript compatibility for async detection.
	 *
	 * Verifies that the sanitized result will pass the JavaScript check
	 * at chat.js:8942 that detects async tool execution.
	 */
	public function test_javascript_async_detection_compatibility() {
		// Mock pending result
		$result = array(
			'async'   => true,
			'status'  => 'pending',
			'task_id' => 'crawl_test',
			'job_id'  => 'crawl_test',
			'message' => 'Crawling in progress...',
			'results' => array(),
		);

		$sanitized = $this->tool->sanitize_for_llm( $result );

		// Simulate JavaScript check: result.async === true && result.status === 'pending' && result.job_id
		$js_check = (
			isset( $sanitized['async'] ) &&
			$sanitized['async'] === true &&
			isset( $sanitized['status'] ) &&
			$sanitized['status'] === 'pending' &&
			isset( $sanitized['job_id'] ) &&
			! empty( $sanitized['job_id'] )
		);

		$this->assertTrue( $js_check, 'Sanitized result must pass JavaScript async detection check' );
	}

	/**
	 * Test that completed results preserve essential metadata.
	 *
	 * When crawl completes, results array is populated and status changes.
	 */
	public function test_sanitize_preserves_completed_metadata() {
		// Mock completed crawl result
		$completed = array(
			'status'   => 'completed',
			'task_id'  => 'crawl_done',
			'results'  => array(
				array(
					'url'         => 'https://example.com',
					'status_code' => 200,
					'markdown'    => '# Example\n\nContent here',
					'text'        => 'Example Content here',
					'html'        => '<h1>Example</h1><p>Content here</p>', // Should be stripped
				),
			),
			'metadata' => array(
				'total_urls' => 1,
				'headers'    => array( 'User-Agent' => 'Test' ), // Should be stripped
			),
		);

		$sanitized = $this->tool->sanitize_for_llm( $completed );

		// Essential fields should be preserved
		$this->assertArrayHasKey( 'status', $sanitized );
		$this->assertArrayHasKey( 'task_id', $sanitized );
		$this->assertArrayHasKey( 'results', $sanitized );
		$this->assertSame( 'completed', $sanitized['status'] );

		// Results should be preserved but HTML stripped
		$this->assertIsArray( $sanitized['results'] );
		$this->assertCount( 1, $sanitized['results'] );
		$this->assertArrayHasKey( 'markdown', $sanitized['results'][0] );
		$this->assertArrayNotHasKey( 'html', $sanitized['results'][0], 'HTML should be stripped' );

		// Verbose metadata should be stripped
		if ( isset( $sanitized['metadata'] ) ) {
			$this->assertArrayNotHasKey( 'headers', $sanitized['metadata'], 'Headers should be stripped from metadata' );
		}
	}

	/**
	 * Test that raw field is stripped to save context.
	 *
	 * The 'raw' field duplicates results and wastes LLM context.
	 */
	public function test_sanitize_strips_raw_field() {
		$result = array(
			'status'  => 'completed',
			'task_id' => 'crawl_raw_test',
			'results' => array(
				array(
					'url'      => 'https://example.com',
					'markdown' => 'Content',
				),
			),
			'raw'     => array(
				'results' => array(
					array(
						'url'      => 'https://example.com',
						'markdown' => 'Content',
					),
				),
			),
		);

		$sanitized = $this->tool->sanitize_for_llm( $result );

		$this->assertArrayNotHasKey( 'raw', $sanitized, 'Raw field should be stripped to save context' );
		$this->assertArrayHasKey( 'results', $sanitized, 'Results should be preserved' );
	}

	/**
	 * Test backward compatibility with task_id only (no job_id).
	 *
	 * Older results may not have job_id field yet.
	 */
	public function test_backward_compatibility_task_id_only() {
		$result = array(
			'status'  => 'pending',
			'task_id' => 'crawl_old_format',
			'results' => array(),
		);

		$sanitized = $this->tool->sanitize_for_llm( $result );

		// task_id should still be preserved
		$this->assertArrayHasKey( 'task_id', $sanitized );
		$this->assertSame( 'crawl_old_format', $sanitized['task_id'] );
	}

	/**
	 * Test that non-array results pass through.
	 */
	public function test_non_array_results_pass_through() {
		$string_result = 'Crawl error: Connection failed';
		$sanitized     = $this->tool->sanitize_for_llm( $string_result );
		$this->assertSame( $string_result, $sanitized );

		$null_result = null;
		$sanitized   = $this->tool->sanitize_for_llm( $null_result );
		$this->assertNull( $sanitized );
	}

	/**
	 * Test that both async and status fields work together.
	 *
	 * This ensures crawl4ai follows the same pattern as veo and other
	 * async tools for a consistent user experience.
	 */
	public function test_async_pattern_consistency_with_veo() {
		// Crawl4AI pending result
		$crawl_result = array(
			'async'   => true,
			'status'  => 'pending',
			'task_id' => 'crawl_123',
			'job_id'  => 'crawl_123',
			'message' => 'Crawling...',
		);

		// Veo pending result (for comparison)
		$veo_result = array(
			'async'   => true,
			'status'  => 'pending',
			'job_id'  => 'veo_456',
			'message' => 'Generating video...',
		);

		$crawl_sanitized = $this->tool->sanitize_for_llm( $crawl_result );

		// Both should have the same required async fields
		$required = array( 'async', 'status', 'job_id', 'message' );
		foreach ( $required as $field ) {
			$this->assertArrayHasKey( $field, $crawl_sanitized, "Crawl4AI must have $field like veo" );
		}

		// Verify JavaScript detection would work for both
		$crawl_js_check = (
			$crawl_sanitized['async'] === true &&
			$crawl_sanitized['status'] === 'pending' &&
			! empty( $crawl_sanitized['job_id'] )
		);

		$this->assertTrue( $crawl_js_check, 'Crawl4AI must pass same JavaScript check as veo' );
	}
}
