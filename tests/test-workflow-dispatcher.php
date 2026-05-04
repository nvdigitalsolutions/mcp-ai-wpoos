<?php
/**
 * Test the Workflow Dispatcher pluggable executor entry point.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for WP_MCP_AI_Workflow_Dispatcher.
 */
class Test_Workflow_Dispatcher extends WP_UnitTestCase {

	/**
	 * Reset the executor filter between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_workflow_executor' );
		remove_all_filters( 'wp_mcp_ai_workflow_v2_enabled' );
		parent::tear_down();
	}

	/**
	 * A registered executor must take ownership of the dispatch.
	 */
	public function test_filter_registered_executor_handles_dispatch() {
		add_filter(
			'wp_mcp_ai_workflow_executor',
			static function ( $result, $workflow_id, $input, $context ) {
				return array(
					'success' => true,
					'run_id'  => 'custom_' . $workflow_id,
					'message' => 'Handled by custom executor.',
				);
			},
			10,
			4
		);

		$result = WP_MCP_AI_Workflow_Dispatcher::dispatch( 'wf_abc', array( 'k' => 'v' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'custom_wf_abc', $result['run_id'] );
	}

	/**
	 * When no executor handles a numeric workflow id and Engine V2 is
	 * disabled, the dispatcher must return a `no_workflow_executor` error.
	 */
	public function test_no_executor_returns_wp_error() {
		// Make absolutely sure Engine V2 reports as disabled.
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_false' );

		$result = WP_MCP_AI_Workflow_Dispatcher::dispatch( 999999, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'no_workflow_executor', $result->get_error_code() );
	}

	/**
	 * A filter that returns null must defer to the default executor path.
	 */
	public function test_filter_returning_null_defers_to_default() {
		add_filter( 'wp_mcp_ai_workflow_executor', '__return_null', 10, 4 );
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_false' );

		$result = WP_MCP_AI_Workflow_Dispatcher::dispatch( 0, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'no_workflow_executor', $result->get_error_code() );
	}

	/**
	 * A WP_Error returned by an executor must propagate out of dispatch().
	 */
	public function test_filter_returning_wp_error_propagates() {
		add_filter(
			'wp_mcp_ai_workflow_executor',
			static function () {
				return new WP_Error( 'boom', 'Executor failed' );
			},
			10,
			4
		);

		$result = WP_MCP_AI_Workflow_Dispatcher::dispatch( 'wf_xyz' );

		$this->assertWPError( $result );
		$this->assertSame( 'boom', $result->get_error_code() );
	}
}
