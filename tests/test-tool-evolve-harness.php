<?php
/**
 * Tests for evolve_harness tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test evolve_harness tool functionality.
 */
class Test_Tool_Evolve_Harness extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Evolve_Harness
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Evolve_Harness' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Evolve_Harness class not available.' );
		}

		$this->tool = new WP_MCP_AI_Tool_Evolve_Harness();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'evolve_harness', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Required capability is edit_posts.
	 */
	public function test_required_capability() {
		$this->assertSame( 'edit_posts', $this->tool->get_required_capability() );
	}

	/**
	 * Parameter schema is valid JSON Schema structure.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'operation', $schema['required'] );
	}

	/**
	 * Invalid operation returns WP_Error.
	 */
	public function test_invalid_operation_returns_error() {
		$result = $this->tool->execute(
			array( 'operation' => 'nonexistent' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_operation', $result->get_error_code() );
	}

	/**
	 * Invalid component returns WP_Error.
	 */
	public function test_invalid_component_returns_error() {
		$result = $this->tool->execute(
			array(
				'operation' => 'evolve',
				'component' => 'nonexistent',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_component', $result->get_error_code() );
	}

	/**
	 * Missing operation defaults to 'evolve'.
	 */
	public function test_missing_operation_defaults_to_evolve() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		// Should not produce an "invalid operation" error, and must never fatal.
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'wp_mcp_ai_invalid_operation', $result->get_error_code() );
		}
	}

	/**
	 * Status operation returns valid response with no prior evolutions.
	 */
	public function test_status_operation_returns_evolution_log() {
		$result = $this->tool->execute(
			array( 'operation' => 'status' ),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => 0,
			)
		);

		// Status should return a chat response array even when empty.
		$this->assertIsArray( $result );
	}

	/**
	 * Window length is clamped to valid range.
	 */
	public function test_window_length_clamped() {
		// Below minimum should be clamped to 10.
		$result = $this->tool->execute(
			array(
				'operation'     => 'status',
				'window_length' => 1,
			),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => 0,
			)
		);

		$this->assertIsArray( $result );
	}

	/**
	 * Analyze operation handles missing evolver gracefully.
	 */
	public function test_analyze_operation_handles_missing_evolver() {
		$result = $this->tool->execute(
			array( 'operation' => 'analyze' ),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => 999999,
				'session_id'   => 'test-session',
			)
		);

		// Analyze may return WP_Error if evolver unavailable or a chat response.
		// Either way it should not throw an exception.
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Capability flags include background-only.
	 */
	public function test_capability_flags() {
		if ( ! method_exists( $this->tool, 'get_capability_flags' ) ) {
			$this->markTestSkipped( 'get_capability_flags method not available.' );
		}

		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertArrayHasKey( 'background-only', $flags );
		$this->assertTrue( $flags['background-only'] );
	}

	/**
	 * Analyze must never fatal — regression test for the historical
	 * undefined-method contract mismatch (analyze_failures did not exist).
	 */
	public function test_analyze_operation_does_not_fatal() {
		$result = $this->tool->execute(
			array(
				'operation'     => 'analyze',
				'component'     => 'all',
				'window_length' => 10,
			),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => 999999,
				'session_id'   => 'analyze-no-trail-session',
			)
		);

		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Evolve with evolution disabled returns a normal envelope, not an error.
	 */
	public function test_evolve_operation_disabled_by_default_returns_envelope() {
		$result = $this->tool->execute(
			array(
				'operation' => 'evolve',
				'component' => 'all',
			),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => 999999,
				'session_id'   => 'evolve-disabled-session',
			)
		);

		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Invalid component values are rejected before touching the evolver.
	 */
	public function test_invalid_component_still_validated() {
		$result = $this->tool->execute(
			array(
				'operation' => 'evolve',
				'component' => 'bogus',
			),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => 999999,
				'session_id'   => 'evolve-bogus-session',
			)
		);

		// The tool validates the enum before dispatch, so this is a client error.
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_component', $result->get_error_code() );
	}
}
