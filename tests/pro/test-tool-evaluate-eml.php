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
			$tool_file = dirname( dirname( __DIR__ ) ) . '/addons/pro/includes/tools/developer/class-wp-mcp-ai-tool-evaluate-eml.php';
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
	 * Test: decompose zero (Eq. (5) at z=1, Figure 2, K=7).
	 *
	 * Also exercises the nullary-argument path: omitting `arity_args`
	 * entirely must succeed because `zero` takes no inputs.
	 */
	public function test_decompose_zero() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'     => 'decompose',
				'function' => 'zero',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $r );
		$this->assertSame( 'eml(1, eml(eml(1, 1), 1))', $r['canonical'] );
		$this->assertSame( 7, $r['size'] );
		$round = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => $r['canonical'],
			)
		);
		$this->assertEqualsWithDelta( 0.0, $round['value'], 1e-12 );

		// Explicitly empty arity_args must also succeed.
		$r2 = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'zero',
				'arity_args' => array(),
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $r2 );
		$this->assertSame( $r['canonical'], $r2['canonical'] );
	}

	/**
	 * Test: decompose sub (Table 4, K=11). Domain x > 0.
	 */
	public function test_decompose_sub_round_trip() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'sub',
				'arity_args' => array( 'x', 'y' ),
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $r );
		$this->assertSame( 11, $r['size'] );

		foreach ( array( array( 5.0, 2.0 ), array( 0.5, 1.7 ), array( 10.0, 3.0 ) ) as $pair ) {
			list( $x, $y ) = $pair;
			$round         = $tool->execute(
				array(
					'mode'       => 'evaluate',
					'expression' => $r['canonical'],
					'variables'  => array(
						'x' => $x,
						'y' => $y,
					),
				)
			);
			$this->assertEqualsWithDelta( $x - $y, $round['value'], 1e-10 );
		}
	}

	/**
	 * Test: decompose neg (Table 4). Domain 0 < x < e.
	 */
	public function test_decompose_neg_round_trip() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'neg',
				'arity_args' => array( 'x' ),
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $r );

		foreach ( array( 0.5, 1.0, 2.0, 2.5 ) as $x ) {
			$round = $tool->execute(
				array(
					'mode'       => 'evaluate',
					'expression' => $r['canonical'],
					'variables'  => array( 'x' => $x ),
				)
			);
			$this->assertEqualsWithDelta( -$x, $round['value'], 1e-10 );
		}
	}

	/**
	 * Test: neg out-of-domain (x >= e) yields a domain error.
	 */
	public function test_decompose_neg_out_of_domain() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'neg',
				'arity_args' => array( 'x' ),
			)
		);
		// x = 3 > e ⇒ inner ln of (e − x) is undefined ⇒ domain error.
		$round = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => $r['canonical'],
				'variables'  => array( 'x' => 3.0 ),
			)
		);
		$this->assertWPError( $round );
	}

	/**
	 * Test: decompose inv (Table 4). Domain 0 < x < e^e.
	 */
	public function test_decompose_inv_round_trip() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'inv',
				'arity_args' => array( 'x' ),
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $r );

		foreach ( array( 0.25, 0.5, 1.0, 2.0, 5.0, 10.0 ) as $x ) {
			$round = $tool->execute(
				array(
					'mode'       => 'evaluate',
					'expression' => $r['canonical'],
					'variables'  => array( 'x' => $x ),
				)
			);
			$this->assertEqualsWithDelta( 1.0 / $x, $round['value'], 1e-10 );
		}
	}

	/**
	 * Test: inv out-of-domain (x >= e^e ≈ 15.15) yields a domain error.
	 */
	public function test_decompose_inv_out_of_domain() {
		$this->maybe_skip();
		$tool  = $this->get_tool();
		$r     = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'inv',
				'arity_args' => array( 'x' ),
			)
		);
		$round = $tool->execute(
			array(
				'mode'       => 'evaluate',
				'expression' => $r['canonical'],
				'variables'  => array( 'x' => 20.0 ),
			)
		);
		$this->assertWPError( $round );
	}

	/**
	 * Test: decompose mul (Table 4). Domain x, y > 0 and x < e^e.
	 */
	public function test_decompose_mul_round_trip() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'mode'       => 'decompose',
				'function'   => 'mul',
				'arity_args' => array( 'x', 'y' ),
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $r );

		foreach ( array(
			array( 2.0, 3.0 ),
			array( 0.5, 4.0 ),
			array( 1.5, 1.5 ),
			array( 7.0, 0.25 ),
		) as $pair ) {
			list( $x, $y ) = $pair;
			$round         = $tool->execute(
				array(
					'mode'       => 'evaluate',
					'expression' => $r['canonical'],
					'variables'  => array(
						'x' => $x,
						'y' => $y,
					),
				)
			);
			$this->assertEqualsWithDelta( $x * $y, $round['value'], 1e-9 );
		}
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
