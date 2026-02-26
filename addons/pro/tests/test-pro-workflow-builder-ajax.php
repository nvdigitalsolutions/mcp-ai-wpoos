<?php
/**
 * Test Pro Workflow Builder AJAX Handlers
 *
 * Verifies the new AJAX endpoints added to the pro workflow builder:
 * - wp_mcp_ai_execute_workflow_node
 * - wp_mcp_ai_save_workflow_execution
 * - wp_mcp_ai_list_pro_workflows
 * - wp_mcp_ai_export_pro_workflow
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Pro Workflow Builder AJAX handlers
 */
class Test_Pro_Workflow_Builder_Ajax extends WP_Ajax_UnitTestCase {

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Workflow builder page instance
	 *
	 * @var WP_MCP_AI_Pro_Workflow_Builder_Page
	 */
	private $builder;

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Make sure the class is available.
		$file = WP_MCP_AI_PATH . 'addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php';
		if ( file_exists( $file ) ) {
			// Define the pro version constant so the class can instantiate.
			if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				define( 'WP_MCP_AI_PRO_VERSION', '2.0.0-test' );
			}
		}

		if ( class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ) ) {
			$this->builder = new WP_MCP_AI_Pro_Workflow_Builder_Page();
		}
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		// Remove test workflow data.
		delete_option( 'wp_mcp_ai_pro_workflows' );
		delete_option( 'wp_mcp_ai_workflow_executions_test-workflow' );
		parent::tearDown();
	}

	/**
	 * Test that the class can be instantiated.
	 */
	public function test_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ),
			'WP_MCP_AI_Pro_Workflow_Builder_Page class should exist'
		);
	}

	/**
	 * Test save workflow and list workflows.
	 */
	public function test_save_and_list_workflows() {
		if ( ! $this->builder ) {
			$this->markTestSkipped( 'Builder class not available' );
		}

		// Save a workflow.
		$workflow = array(
			'name'        => 'Test Workflow',
			'description' => 'A test workflow',
			'nodes'       => array(
				array( 'id' => 'trigger-1', 'type' => 'trigger', 'position' => array( 'x' => 100, 'y' => 100 ), 'data' => array( 'label' => 'Start', 'config' => array() ) ),
				array( 'id' => 'action-1', 'type' => 'action', 'position' => array( 'x' => 100, 'y' => 250 ), 'data' => array( 'label' => 'Do Something', 'config' => array( 'command' => '/test' ) ) ),
			),
			'edges'       => array(
				array( 'id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1' ),
			),
		);

		$_POST['nonce']    = wp_create_nonce( 'mcp_ai_pro_workflow_builder' );
		$_POST['workflow'] = wp_json_encode( $workflow );

		try {
			$this->_handleAjax( 'wp_mcp_ai_save_pro_workflow' );
		} catch ( WPAjaxDieContinuedException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], 'Workflow save should succeed' );
		$this->assertNotEmpty( $response['data']['workflow']['id'], 'Saved workflow should have an ID' );

		// Now list workflows.
		$this->_last_response = '';
		$_POST['nonce']       = wp_create_nonce( 'mcp_ai_pro_workflow_builder' );

		try {
			$this->_handleAjax( 'wp_mcp_ai_list_pro_workflows' );
		} catch ( WPAjaxDieContinuedException $e ) {
			// Expected.
		}

		$list_response = json_decode( $this->_last_response, true );
		$this->assertTrue( $list_response['success'], 'List workflows should succeed' );
		$this->assertNotEmpty( $list_response['data']['workflows'], 'Should return at least one workflow' );
	}

	/**
	 * Test export workflow.
	 */
	public function test_export_workflow() {
		if ( ! $this->builder ) {
			$this->markTestSkipped( 'Builder class not available' );
		}

		// Pre-populate a workflow.
		$workflows = array(
			'my-workflow' => array(
				'id'          => 'my-workflow',
				'name'        => 'My Workflow',
				'description' => 'Export test',
				'nodes'       => array(),
				'edges'       => array(),
				'created_at'  => time(),
				'updated_at'  => time(),
			),
		);
		update_option( 'wp_mcp_ai_pro_workflows', $workflows );

		$_POST['nonce']       = wp_create_nonce( 'mcp_ai_pro_workflow_builder' );
		$_POST['workflow_id'] = 'my-workflow';

		try {
			$this->_handleAjax( 'wp_mcp_ai_export_pro_workflow' );
		} catch ( WPAjaxDieContinuedException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], 'Export should succeed' );
		$this->assertEquals( 'My Workflow', $response['data']['workflow']['name'], 'Exported workflow name should match' );
	}

	/**
	 * Test save workflow execution.
	 */
	public function test_save_workflow_execution() {
		if ( ! $this->builder ) {
			$this->markTestSkipped( 'Builder class not available' );
		}

		$execution = array(
			'id'             => 'exec-123',
			'workflowId'     => 'test-workflow',
			'timestamp'      => time(),
			'duration'       => 5000,
			'status'         => 'completed',
			'nodeCount'      => 3,
			'completedNodes' => 3,
			'failedNodes'    => 0,
		);

		$_POST['nonce']     = wp_create_nonce( 'mcp_ai_pro_workflow_builder' );
		$_POST['execution'] = wp_json_encode( $execution );

		try {
			$this->_handleAjax( 'wp_mcp_ai_save_workflow_execution' );
		} catch ( WPAjaxDieContinuedException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], 'Execution save should succeed' );

		// Verify it was persisted.
		$log = get_option( 'wp_mcp_ai_workflow_executions_test-workflow', array() );
		$this->assertNotEmpty( $log, 'Execution log should be stored' );
		$this->assertEquals( 'completed', $log[0]['status'], 'Stored execution status should match' );
	}

	/**
	 * Test that execute_workflow_node requires authentication.
	 */
	public function test_execute_node_requires_auth() {
		if ( ! $this->builder ) {
			$this->markTestSkipped( 'Builder class not available' );
		}

		// Switch to a non-admin user.
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['nonce']     = wp_create_nonce( 'mcp_ai_pro_workflow_builder' );
		$_POST['node_type'] = 'action';
		$_POST['command']   = '/test';

		try {
			$this->_handleAjax( 'wp_mcp_ai_execute_workflow_node' );
		} catch ( WPAjaxDieContinuedException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Non-admin should be rejected' );
	}

	/**
	 * Test context variable substitution in apply_context_to_string (via execute node).
	 */
	public function test_execute_action_node_with_missing_command() {
		if ( ! $this->builder ) {
			$this->markTestSkipped( 'Builder class not available' );
		}

		$_POST['nonce']     = wp_create_nonce( 'mcp_ai_pro_workflow_builder' );
		$_POST['node_type'] = 'action';
		$_POST['command']   = '';
		$_POST['context']   = '{}';

		try {
			$this->_handleAjax( 'wp_mcp_ai_execute_workflow_node' );
		} catch ( WPAjaxDieContinuedException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Action node without command should fail' );
	}

	/**
	 * Test execute_workflow_node with unsupported type.
	 */
	public function test_execute_node_unsupported_type() {
		if ( ! $this->builder ) {
			$this->markTestSkipped( 'Builder class not available' );
		}

		$_POST['nonce']     = wp_create_nonce( 'mcp_ai_pro_workflow_builder' );
		$_POST['node_type'] = 'unsupported_node_type_xyz';
		$_POST['context']   = '{}';

		try {
			$this->_handleAjax( 'wp_mcp_ai_execute_workflow_node' );
		} catch ( WPAjaxDieContinuedException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Unsupported node type should return error' );
	}
}
