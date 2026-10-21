<?php
/**
 * Tests the workflow-node execution REST bridge (2.4.0, Proposal 084
 * Phase 3b, W10.2d): POST /mcp-ai-pro/v1/workflow-builder/nodes/execute.
 *
 * Covers: manage_options gating, node-type validation, per-type missing
 * payload errors, and the unknown-tool error passthrough. Skipped when the
 * Pro addon is not loaded.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Workflow_Node_REST_Controller_Test extends WP_UnitTestCase {

	/**
	 * Skip when the Pro addon classes are absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Node_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
	}

	/**
	 * Build a request with the given params.
	 *
	 * @param array $params Body params.
	 * @return WP_REST_Request
	 */
	private function request( array $params ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/workflow-builder/nodes/execute' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Workflow_Node_REST_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Unknown node types are rejected as readable 400s.
	 */
	public function test_invalid_node_type_is_400() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Workflow_Node_REST_Controller() )->execute_node(
			$this->request( array( 'node_type' => 'mystery' ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Tool nodes without a tool_name surface the executor's error.
	 */
	public function test_tool_node_missing_name() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Workflow_Node_REST_Controller() )->execute_node(
			$this->request(
				array(
					'node_type' => 'tool',
					'node_id'   => 'n1',
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_tool', $result->get_error_code() );
	}

	/**
	 * Action nodes without a command surface the executor's error.
	 */
	public function test_action_node_missing_command() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Workflow_Node_REST_Controller() )->execute_node(
			$this->request(
				array(
					'node_type' => 'action',
					'node_id'   => 'n1',
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_command', $result->get_error_code() );
	}

	/**
	 * Unknown tools surface the executor's not-found error with a status.
	 */
	public function test_unknown_tool_is_400() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Workflow_Node_REST_Controller() )->execute_node(
			$this->request(
				array(
					'node_type'      => 'tool',
					'node_id'        => 'n1',
					'tool_name'      => 'no_such_tool_xyz',
					'tool_arguments' => array(),
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'tool_not_found', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}
