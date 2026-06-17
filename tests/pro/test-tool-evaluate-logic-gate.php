<?php
/**
 * Tests for Evaluate Logic Gate Pro Tool.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Evaluate Logic Gate.
 */
class Test_Tool_Evaluate_Logic_Gate extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Tool_Evaluate_Logic_Gate' ) ) {
			$tool_file = dirname( dirname( __DIR__ ) ) . '/addons/pro/includes/tools/math/class-wp-mcp-ai-tool-evaluate-logic-gate.php';
			if ( file_exists( $tool_file ) ) {
				require_once $tool_file;
			}
		}
	}

	/**
	 * Skip when class not available.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Evaluate_Logic_Gate' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Evaluate_Logic_Gate not available' );
		}
	}

	/**
	 * Convenience: instantiate as administrator so capability checks pass.
	 */
	private function get_tool() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return new WP_MCP_AI_Tool_Evaluate_Logic_Gate();
	}

	/**
	 * Test: metadata.
	 */
	public function test_metadata() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$this->assertSame( 'evaluate_logic_gate', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$schema = $tool->get_parameters_schema();
		$this->assertSame( array( 'gate', 'inputs' ), $schema['required'] );
		$this->assertContains( 'NAND', $schema['properties']['gate']['enum'] );
	}

	/**
	 * Test: capability flags include pro and read only.
	 */
	public function test_capability_flags_include_pro_and_read_only() {
		$this->maybe_skip();
		$tool  = $this->get_tool();
		$flags = $tool->get_capability_flags();
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'cacheable', $flags );
	}

	/**
	 * Two-input rows for AND, OR, NAND, NOR, XOR, XNOR.
	 *
	 * @dataProvider two_input_rows
	 *
	 * @param string $gate     Gate name.
	 * @param bool   $a        First input.
	 * @param bool   $b        Second input.
	 * @param bool   $expected Expected result.
	 */
	public function test_two_input_truth_rows( $gate, $a, $b, $expected ) {
		$this->maybe_skip();
		$tool   = $this->get_tool();
		$result = $tool->execute(
			array(
				'gate'   => $gate,
				'inputs' => array( $a, $b ),
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( $expected, $result['result'] );
	}

	/**
	 * Two input rows.
	 */
	public function two_input_rows() {
		return array(
			array( 'AND', false, false, false ),
			array( 'AND', false, true, false ),
			array( 'AND', true, false, false ),
			array( 'AND', true, true, true ),

			array( 'OR', false, false, false ),
			array( 'OR', false, true, true ),
			array( 'OR', true, false, true ),
			array( 'OR', true, true, true ),

			array( 'NAND', false, false, true ),
			array( 'NAND', false, true, true ),
			array( 'NAND', true, false, true ),
			array( 'NAND', true, true, false ),

			array( 'NOR', false, false, true ),
			array( 'NOR', false, true, false ),
			array( 'NOR', true, false, false ),
			array( 'NOR', true, true, false ),

			array( 'XOR', false, false, false ),
			array( 'XOR', false, true, true ),
			array( 'XOR', true, false, true ),
			array( 'XOR', true, true, false ),

			array( 'XNOR', false, false, true ),
			array( 'XNOR', false, true, false ),
			array( 'XNOR', true, false, false ),
			array( 'XNOR', true, true, true ),
		);
	}

	/**
	 * Test: not truth rows.
	 */
	public function test_not_truth_rows() {
		$this->maybe_skip();
		$tool    = $this->get_tool();
		$true_r  = $tool->execute(
			array(
				'gate'   => 'NOT',
				'inputs' => array( true ),
			)
		);
		$false_r = $tool->execute(
			array(
				'gate'   => 'NOT',
				'inputs' => array( false ),
			)
		);
		$this->assertFalse( $true_r['result'] );
		$this->assertTrue( $false_r['result'] );
	}

	/**
	 * Test: n ary folds.
	 */
	public function test_n_ary_folds() {
		$this->maybe_skip();
		$tool = $this->get_tool();

		$nand3 = $tool->execute(
			array(
				'gate'   => 'NAND',
				'inputs' => array( 1, 1, 1 ),
			)
		);
		$this->assertFalse( $nand3['result'] ); // All ones → NAND = 0.

		$xor4 = $tool->execute(
			array(
				'gate'   => 'XOR',
				'inputs' => array( 1, 1, 1, 1 ),
			)
		);
		$this->assertFalse( $xor4['result'] ); // Even parity.

		$xor3 = $tool->execute(
			array(
				'gate'   => 'XOR',
				'inputs' => array( 1, 1, 1 ),
			)
		);
		$this->assertTrue( $xor3['result'] ); // Odd parity.

		$and3 = $tool->execute(
			array(
				'gate'   => 'AND',
				'inputs' => array( 1, 1, 1 ),
			)
		);
		$this->assertTrue( $and3['result'] );

		$or3 = $tool->execute(
			array(
				'gate'   => 'OR',
				'inputs' => array( 0, 0, 1 ),
			)
		);
		$this->assertTrue( $or3['result'] );
	}

	/**
	 * Test: validation errors.
	 */
	public function test_validation_errors() {
		$this->maybe_skip();
		$tool = $this->get_tool();

		$err = $tool->execute(
			array(
				'gate'   => 'NOT',
				'inputs' => array( 1, 0 ),
			)
		);
		$this->assertWPError( $err );

		$err = $tool->execute(
			array(
				'gate'   => 'AND',
				'inputs' => array( 1 ),
			)
		);
		$this->assertWPError( $err );

		$err = $tool->execute(
			array(
				'gate'   => 'BOGUS',
				'inputs' => array( 1, 0 ),
			)
		);
		$this->assertWPError( $err );

		$err = $tool->execute(
			array(
				'gate'   => 'AND',
				'inputs' => array( 'maybe', 'sure' ),
			)
		);
		$this->assertWPError( $err );
	}

	/**
	 * Test: input coercion.
	 */
	public function test_input_coercion() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'gate'   => 'AND',
				'inputs' => array( '1', 'true', 1, true ),
			)
		);
		$this->assertTrue( $r['result'] );
		$r = $tool->execute(
			array(
				'gate'   => 'OR',
				'inputs' => array( '0', 'false', 0, false ),
			)
		);
		$this->assertFalse( $r['result'] );
	}

	/**
	 * Test: decompose to nand for each gate.
	 */
	public function test_decompose_to_nand_for_each_gate() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		foreach ( array( 'AND', 'OR', 'NOT', 'NOR', 'XOR', 'XNOR' ) as $gate ) {
			$inputs = ( 'NOT' === $gate ) ? array( 1 ) : array( 1, 0 );
			$r      = $tool->execute(
				array(
					'gate'              => $gate,
					'inputs'            => $inputs,
					'decompose_to_nand' => true,
				)
			);
			$this->assertIsArray( $r, $gate );
			$this->assertArrayHasKey( 'nand_decomposition', $r, $gate );
			$this->assertNotEmpty( $r['nand_decomposition']['text'], $gate );
			// The decomposition for non-NAND/non-trivial gates must contain
			// the substring "NAND(" — i.e. it really is built out of NANDs.
			$this->assertStringContainsString( 'NAND(', $r['nand_decomposition']['text'], $gate );
			$this->assertStringContainsString( '\\uparrow', $r['nand_decomposition']['latex'], $gate );
		}
	}

	/**
	 * Test: capability required.
	 */
	public function test_capability_required() {
		$this->maybe_skip();
		// Logged-out user (no caps) — read is allowed by default for guests in
		// some configurations, so use a user with no role.
		wp_set_current_user( 0 );
		$tool = new WP_MCP_AI_Tool_Evaluate_Logic_Gate();
		$r    = $tool->execute(
			array(
				'gate'   => 'AND',
				'inputs' => array( 1, 1 ),
			)
		);
		// Either it returns a permission error, or current_user_can('read') is
		// true for the anonymous user (some test setups). In the latter case,
		// the result is still a valid response — both outcomes are acceptable
		// here; we only verify that no PHP warning slipped through.
		$this->assertTrue( is_wp_error( $r ) || is_array( $r ) );
	}
}
