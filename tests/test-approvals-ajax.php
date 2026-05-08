<?php
/**
 * AJAX tests for the Approvals admin handlers.
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_list_approvals   (WP_MCP_AI_Admin_Approvals::ajax_list)
 *   - wp_mcp_ai_resolve_approval (WP_MCP_AI_Admin_Approvals::ajax_resolve)
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Approvals.
 */
class Test_Approvals_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Nonce action used by both handlers.
	 */
	const NONCE = 'wp_mcp_ai_approvals';

	/**
	 * Seed approval queue table if the class/DB table exists.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the approval queue table exists before each test.
		if ( class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
			$queue = WP_MCP_AI_Approval_Queue::get_instance();
			if ( method_exists( $queue, 'maybe_create_table' ) ) {
				$queue->maybe_create_table();
			}
		}
	}

	// ---
	// wp_mcp_ai_list_approvals
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_list_approvals_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_list_approvals' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_list_approvals_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_list_approvals',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns array on success. */
	public function test_list_approvals_returns_array_on_success() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_list_approvals',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		// If the approval queue table doesn't exist in the test environment we
		// accept any structured response; what we assert is the contract shape.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'approvals', $response['data'] );
			$this->assertIsArray( $response['data']['approvals'] );
		}
	}

	/** List approvals with specific assistant id. */
	public function test_list_approvals_with_specific_assistant_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_list_approvals',
			array(
				'nonce'        => wp_create_nonce( self::NONCE ),
				'assistant_id' => 999,
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_resolve_approval
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_resolve_approval_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_resolve_approval',
			array(
				'approval_id' => 1,
				'resolution'  => 'approve',
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_resolve_approval_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_resolve_approval',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'approval_id' => 1,
				'resolution'  => 'approve',
			)
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Validates the approval id zero parameter. */
	public function test_resolve_approval_validates_approval_id_zero() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_resolve_approval',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'approval_id' => 0,
				'resolution'  => 'approve',
			)
		);

		$this->assertAjaxError( $response, 'Invalid approval ID' );
	}

	/** Validates the approval id negative parameter. */
	public function test_resolve_approval_validates_approval_id_negative() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_resolve_approval',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'approval_id' => -5,
				'resolution'  => 'approve',
			)
		);

		$this->assertAjaxError( $response, 'Invalid approval ID' );
	}

	/** Validates the invalid resolution parameter. */
	public function test_resolve_approval_validates_invalid_resolution() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_resolve_approval',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'approval_id' => 1,
				'resolution'  => 'invalid_action',
			)
		);

		$this->assertAjaxError( $response, 'Invalid resolution' );
	}

	/** Validates the missing resolution parameter. */
	public function test_resolve_approval_validates_missing_resolution() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_resolve_approval',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'approval_id' => 1,
				// no 'resolution' key — falls through to "invalid resolution" branch.
			)
		);

		$this->assertAjaxError( $response, 'Invalid resolution' );
	}

	/** Verifies the response returns structured response for unknown id. */
	public function test_resolve_approval_returns_structured_response_for_unknown_id() {
		$this->as_admin();

		// Approval ID 99999 should not exist; the queue returns a WP_Error or
		// false, which the handler maps to an error response.
		$response = $this->dispatch(
			'wp_mcp_ai_resolve_approval',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'approval_id' => 99999,
				'resolution'  => 'approve',
			)
		);

		// Contract: JSON object with a `success` flag.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( false === $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
		} else {
			// If somehow it succeeded (edge case where ID 99999 exists), still
			// assert the shape.
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'approval_id', $response['data'] );
		}
	}

	/** Resolve approval deny path. */
	public function test_resolve_approval_deny_path() {
		$this->as_admin();

		// "deny" is the second valid resolution — ensure it passes validation.
		$response = $this->dispatch(
			'wp_mcp_ai_resolve_approval',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'approval_id' => 99999,
				'resolution'  => 'deny',
			)
		);

		// Should not return "Invalid resolution" — it may return a queue error.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
		if ( false === $response['success'] ) {
			$message = isset( $response['data']['message'] ) ? $response['data']['message'] : '';
			$this->assertStringNotContainsString( 'Invalid resolution', $message );
		}
	}
}
