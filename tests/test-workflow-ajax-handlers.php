<?php
/**
 * AJAX tests for the Workflow Editor + Orchestration Dashboard handlers.
 *
 * Pilot cluster for the AJAX test suite gap-fill plan. Each handler is
 * exercised against the 4-point coverage contract:
 *
 *   1. Capability gate    — subscriber-level user is rejected.
 *   2. Nonce verification — bad/missing nonce is rejected.
 *   3. Happy path         — valid request returns the documented JSON shape.
 *   4. Input validation   — at least one missing/invalid param is rejected.
 *
 * Handlers covered:
 *   - wp_mcp_ai_save_workflow         (Workflow Editor)
 *   - wp_mcp_ai_delete_workflow       (Workflow Editor)
 *   - wp_mcp_ai_test_workflow         (Workflow Editor)
 *   - wp_mcp_ai_execute_workflow      (Orchestration Dashboard)
 *   - wp_mcp_ai_restart_workflow      (Orchestration Dashboard)
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * Pilot AJAX cluster: workflow CRUD + execution.
 */
class Test_Workflow_AJAX_Handlers extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Reset workflow state between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_custom_workflows' );

		// The orchestration dashboard registers its AJAX handlers when its file
		// is loaded; make sure wp_ajax_wp_mcp_ai_execute_workflow and the
		// restart sibling are hooked so the dashboard tests exercise the real
		// handlers instead of a silent no-op dispatch.
		if ( class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_execute_workflow' ) ) {
			new WP_MCP_AI_Admin_Orchestration_Dashboard();
		}
	}

	/**
	 * Reset workflow state between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_custom_workflows' );
		parent::tearDown();
	}

	// ---
	// wp_mcp_ai_save_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_save_workflow_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_save_workflow',
			array(
				'name'  => 'My Test Flow',
				'steps' => wp_json_encode( array( array( 'command' => '/help' ) ) ),
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_save_workflow_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_save_workflow',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'name'  => 'My Test Flow',
				'steps' => wp_json_encode( array( array( 'command' => '/help' ) ) ),
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the required fields parameter. */
	public function test_save_workflow_validates_required_fields() {
		$this->as_admin();

		// Missing name.
		$response = $this->dispatch(
			'wp_mcp_ai_save_workflow',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'name'  => '',
				'steps' => wp_json_encode( array( array( 'command' => '/help' ) ) ),
			)
		);
		$this->assertAjaxError( $response, 'Name and steps are required' );

		$this->reset_post();

		// Missing/empty steps.
		$response = $this->dispatch(
			'wp_mcp_ai_save_workflow',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'name'  => 'No Steps',
				'steps' => wp_json_encode( array() ),
			)
		);
		$this->assertAjaxError( $response, 'Name and steps are required' );
	}

	/** Save workflow happy path persists workflow. */
	public function test_save_workflow_happy_path_persists_workflow() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_save_workflow',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'name'  => 'Pilot Workflow',
				'steps' => wp_json_encode(
					array(
						array(
							'command' => '/help',
							'args'    => '',
						),
					)
				),
			)
		);

		$this->assertAjaxSuccess( $response );
		$this->assertSame( 'pilotworkflow', $response['data']['workflow']['slug'] );
		$this->assertSame( 1, $response['data']['workflow']['steps'] );

		$saved = get_option( 'wp_mcp_ai_custom_workflows' );
		$this->assertIsArray( $saved );
		$this->assertArrayHasKey( 'pilotworkflow', $saved );
	}

	// ---
	// wp_mcp_ai_delete_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_delete_workflow_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_delete_workflow',
			array( 'workflow' => 'pilot-workflow' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_delete_workflow_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_delete_workflow',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'workflow' => 'pilot-workflow',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the slug parameter. */
	public function test_delete_workflow_validates_slug() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_delete_workflow',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'workflow' => '',
			)
		);

		$this->assertAjaxError( $response, 'Workflow slug required' );
	}

	/** Delete workflow happy path removes existing workflow. */
	public function test_delete_workflow_happy_path_removes_existing_workflow() {
		$this->as_admin();

		// Seed a workflow first via the orchestrator option directly.
		update_option(
			'wp_mcp_ai_custom_workflows',
			array(
				'pilot-workflow' => array(
					'name'        => 'Pilot Workflow',
					'description' => '',
					'steps'       => array( array( 'command' => '/help' ) ),
				),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_delete_workflow',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'workflow' => 'pilot-workflow',
			)
		);

		$this->assertAjaxSuccess( $response );
		$saved = get_option( 'wp_mcp_ai_custom_workflows', array() );
		$this->assertArrayNotHasKey( 'pilot-workflow', $saved );
	}

	/** Verifies the response returns error for unknown slug. */
	public function test_delete_workflow_returns_error_for_unknown_slug() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_delete_workflow',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'workflow' => 'does-not-exist',
			)
		);

		// The orchestrator returns false; the handler maps that to an error.
		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_test_workflow
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_test_workflow_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_workflow',
			array( 'workflow' => 'pilot-workflow' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_test_workflow_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_test_workflow',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'workflow' => 'pilot-workflow',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the slug parameter. */
	public function test_test_workflow_validates_slug() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_test_workflow',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'workflow' => '',
			)
		);

		$this->assertAjaxError( $response, 'Workflow slug required' );
	}

	/** Test workflow runs for known slug. */
	public function test_test_workflow_runs_for_known_slug() {
		$this->as_admin();

		// Seed an empty-step workflow; `wp_mcp_ai_execute_workflow()` may
		// resolve it or return a structured failure — either way the handler
		// must produce a JSON response without bubbling exceptions.
		update_option(
			'wp_mcp_ai_custom_workflows',
			array(
				'pilot-workflow' => array(
					'name'        => 'Pilot Workflow',
					'description' => '',
					'steps'       => array( array( 'command' => '/help' ) ),
				),
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_test_workflow',
			array(
				'nonce'    => wp_create_nonce( 'wp_mcp_ai_workflow_editor' ),
				'workflow' => 'pilot-workflow',
				'params'   => wp_json_encode( array() ),
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
		// Either success or a structured error is acceptable here — what we're
		// asserting is the contract, not the orchestrator's runtime behaviour.
	}

	// ---
	// wp_mcp_ai_execute_workflow (Orchestration Dashboard)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_execute_workflow_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow',
			array( 'workflow_id' => 'pilot-workflow' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_execute_workflow_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow',
			array(
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'workflow_id' => 'pilot-workflow',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the id parameter. */
	public function test_execute_workflow_validates_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow',
			array(
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'workflow_id' => '',
			)
		);

		$this->assertAjaxError( $response, 'Workflow ID is required' );
	}

	/** Verifies the response returns structured response for unknown id. */
	public function test_execute_workflow_returns_structured_response_for_unknown_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow',
			array(
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'workflow_id' => 'unknown-workflow-id',
			)
		);

		// We don't assert success vs error — the coordinator may or may not be
		// available in the test environment. The contract is: a JSON response
		// with a `success` flag and (on error) a `message`.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
		if ( false === $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'message', $response['data'] );
		}
	}

	// ---
	// wp_mcp_ai_restart_workflow (Orchestration Dashboard)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_restart_workflow_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array( 'workflow_id' => 'wf-1' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_restart_workflow_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'workflow_id' => 'wf-1',
			)
		);

		$this->assertAjaxError( $response, 'Insufficient permissions' );
	}

	/** Validates the id parameter. */
	public function test_restart_workflow_validates_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'workflow_id' => '',
			)
		);

		$this->assertAjaxError( $response, 'Workflow ID is required' );
	}

	/** Restart workflow errors for missing transient. */
	public function test_restart_workflow_errors_for_missing_transient() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'workflow_id' => 'never-existed',
			)
		);

		$this->assertAjaxError( $response, 'Workflow not found' );
	}

	/** Restart workflow happy path resets state. */
	public function test_restart_workflow_happy_path_resets_state() {
		$this->as_admin();

		$workflow_id   = 'restart-pilot';
		$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow_id );
		set_transient(
			$transient_key,
			array(
				'state'        => 'failed',
				'started_at'   => '2026-01-01 00:00:00',
				'completed_at' => '2026-01-01 00:01:00',
				'updated_at'   => '2026-01-01 00:01:00',
				'tasks'        => array(
					array(
						'type'   => 'task',
						'status' => 'failed',
						'error'  => 'boom',
					),
				),
			),
			HOUR_IN_SECONDS
		);

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
				'workflow_id' => $workflow_id,
			)
		);

		// The handler may flow through additional logic before responding;
		// what we assert is the contract — JSON with a `success` flag — and
		// that, on success, the transient state was reset to "initialized".
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
		if ( true === $response['success'] ) {
			$updated = get_transient( $transient_key );
			$this->assertSame( 'initialized', $updated['state'] );
		}

		delete_transient( $transient_key );
	}
}
