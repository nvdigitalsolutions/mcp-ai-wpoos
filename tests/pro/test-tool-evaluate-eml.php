<?php
/**
 * Tests for Evaluate EML Pro Tool.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Evaluate EML.
 */
class Test_Tool_Evaluate_Eml extends WP_UnitTestCase {

	/**
	 * SetUp.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Tool_Evaluate_Eml' ) ) {
			$tool_file = dirname( dirname( __DIR__ ) ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-evaluate-eml.php';
			if ( file_exists( $tool_file ) ) {
				require_once $tool_file;
			}
		}
	}

	/**
	 * Maybe skip.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Evaluate_Eml' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Evaluate_Eml not available' );
		}
	}

	/**
	 * Get tool.
	 */
	private function get_tool() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return new WP_MCP_AI_Tool_Evaluate_Eml();
	}

	/**
	 * Test: metadata.
	 */
	public function test_metadata() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$this->assertSame( 'evaluate_eml', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_description() );
		$flags = $tool->get_capability_flags();
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'read-only', $flags );
	}

	/**
	 * Test: evaluate eml zero one is one.
	 */
	public function test_evaluate_eml_zero_one_is_one() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		// eml(0, 1) = e^0 - ln 1 = 1 - 0 = 1.
		$r = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => 'eml(0, 1)',
			)
		);
		$this->assertIsArray( $r );
		$this->assertEqualsWithDelta( 1.0, $r['value'], 1e-12 );
	}

	/**
	 * Test: evaluate eml one e is e minus one.
	 */
	public function test_evaluate_eml_one_e_is_e_minus_one() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		// eml(1, e) = e^1 - ln(e) = e - 1.
		$r = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => 'eml(1, x)',
				'variables'  => array( 'x' => M_E ),
			)
		);
		$this->assertEqualsWithDelta( M_E - 1.0, $r['value'], 1e-12 );
	}

	/**
	 * Test: domain rejection.
	 */
	public function test_domain_rejection() {
		$this->maybe_skip();
		$tool = $this->get_tool();

		$r = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => 'eml(0, 0)',
			)
		);
		$this->assertWPError( $r );
		$this->assertSame( 'wp_mcp_ai_ln_domain', $r->get_error_code() );

		$r = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => 'eml(0, -1)',
			)
		);
		$this->assertWPError( $r );
	}

	/**
	 * Test: overflow rejection.
	 */
	public function test_overflow_rejection() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => 'eml(x, 1)',
				'variables'  => array( 'x' => 1000.0 ),
			)
		);
		$this->assertWPError( $r );
		$this->assertSame( 'wp_mcp_ai_exp_overflow', $r->get_error_code() );
	}

	/**
	 * Test: decompose exp.
	 */
	public function test_decompose_exp() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'exp',
				'arity_args' => array( 'x' ),
			)
		);
		$this->assertSame( 'eml(x, 1)', $r['canonical'] );

		// Round-trip: evaluate exp(x) at x=1 should equal e.
		$round = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => $r['canonical'],
				'variables'  => array( 'x' => 1.0 ),
			)
		);
		$this->assertEqualsWithDelta( M_E, $round['value'], 1e-12 );
	}

	/**
	 * Test: decompose ln round trip.
	 */
	public function test_decompose_ln_round_trip() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'ln',
				'arity_args' => array( 'x' ),
			)
		);
		$this->assertSame( 'eml(1, eml(eml(1, x), 1))', $r['canonical'] );

		// Round-trip: evaluating the decomposition at x=e gives 1.
		$at_e = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => $r['canonical'],
				'variables'  => array( 'x' => M_E ),
			)
		);
		$this->assertEqualsWithDelta( 1.0, $at_e['value'], 1e-12 );

		// And at x=10 gives ln(10).
		$at_10 = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => $r['canonical'],
				'variables'  => array( 'x' => 10.0 ),
			)
		);
		$this->assertEqualsWithDelta( log( 10.0 ), $at_10['value'], 1e-10 );
	}

	/**
	 * Test: decompose unsupported returns wp error.
	 */
	public function test_decompose_unsupported_returns_wp_error() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'sin',
				'arity_args' => array( 'x' ),
			)
		);
		$this->assertWPError( $r );
	}

	/**
	 * Test: depth cap.
	 */
	public function test_depth_cap() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		// Build an expression deeper than MAX_TREE_DEPTH (12).
		$expr = 'x';
		for ( $i = 0; $i < 20; $i++ ) {
			$expr = 'eml(' . $expr . ', 1)';
		}
		$r = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => $expr,
				'variables'  => array( 'x' => 0.0 ),
			)
		);
		$this->assertWPError( $r );
	}

	/**
	 * Test: invalid expression returns error.
	 */
	public function test_invalid_expression_returns_error() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => 'eml(1)',
			)
		);
		$this->assertWPError( $r );

		$r = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => 'eml(',
			)
		);
		$this->assertWPError( $r );
	}

	/**
	 * Test: unbound variable error.
	 */
	public function test_unbound_variable_error() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => 'eml(1, y)',
			)
		);
		$this->assertWPError( $r );
		$this->assertSame( 'wp_mcp_ai_unbound_variable', $r->get_error_code() );
	}
}
