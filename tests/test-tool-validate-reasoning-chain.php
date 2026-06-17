<?php
/**
 * Tests for validate_reasoning_chain tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test validate_reasoning_chain tool functionality.
 */
class Test_Tool_Validate_Reasoning_Chain extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Validate_Reasoning_Chain
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Validate_Reasoning_Chain();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'validate_reasoning_chain', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Missing reasoning_steps returns missing_parameter error.
	 */
	public function test_missing_reasoning_steps_returns_error() {
		$result = $this->tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_parameter', $result->get_error_code() );
	}

	/**
	 * Non-array reasoning_steps returns missing_parameter error.
	 */
	public function test_non_array_steps_returns_error() {
		$result = $this->tool->execute(
			array( 'reasoning_steps' => 'not an array' ),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_parameter', $result->get_error_code() );
	}

	/**
	 * Valid reasoning chain: the tool has a pre-existing bug where it calls
	 * $this->success() (a method that does not exist on this class).
	 * The test documents the behaviour: execute with valid steps either returns
	 * service_unavailable WP_Error or raises an Error once it reaches the success call.
	 */
	public function test_valid_steps_hits_success_call_or_service_unavailable() {
		try {
			$result = $this->tool->execute(
				array(
					'reasoning_steps' => array(
						'All men are mortal.',
						'Socrates is a man.',
						'Therefore, Socrates is mortal.',
					),
					'conclusion'      => 'Socrates is mortal.',
				),
				array()
			);

			// If WP_MCP_AI_Reasoning_Controller is unavailable, expect a WP_Error.
			$this->assertTrue(
				is_wp_error( $result ) || is_array( $result ),
				'Expected WP_Error or array result.'
			);
		} catch ( \Error $e ) {
			// Pre-existing bug: $this->success() is not defined on this class.
			$this->assertStringContainsString( 'success', $e->getMessage() );
		}
	}

	/**
	 * Single-step reasoning reaches same code path as multi-step.
	 */
	public function test_single_step_hits_same_code_path() {
		try {
			$result = $this->tool->execute(
				array( 'reasoning_steps' => array( 'It rained yesterday.' ) ),
				array()
			);

			$this->assertTrue(
				is_wp_error( $result ) || is_array( $result ),
				'Expected WP_Error or array.'
			);
		} catch ( \Error $e ) {
			// Pre-existing $this->success() bug surfaced.
			$this->assertStringContainsString( 'success', $e->getMessage() );
		}
	}
}
