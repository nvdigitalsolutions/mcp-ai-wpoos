<?php
/**
 * Tests for Generate Truth Table Pro Tool.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Generate Truth Table.
 */
class Test_Tool_Generate_Truth_Table extends WP_UnitTestCase {

	/**
	 * SetUp.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Truth_Table' ) ) {
			$tool_file = dirname( dirname( __DIR__ ) ) . '/addons/pro/includes/tools/math/class-wp-mcp-ai-tool-generate-truth-table.php';
			if ( file_exists( $tool_file ) ) {
				require_once $tool_file;
			}
		}
	}

	/**
	 * Maybe skip.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Truth_Table' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Generate_Truth_Table not available' );
		}
	}

	/**
	 * Get tool.
	 */
	private function get_tool() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return new WP_MCP_AI_Tool_Generate_Truth_Table();
	}

	/**
	 * Test: metadata.
	 */
	public function test_metadata() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$this->assertSame( 'generate_truth_table', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_description() );
		$schema = $tool->get_parameters_schema();
		$this->assertSame( array( 'expression' ), $schema['required'] );
	}

	/**
	 * Test: a nand b.
	 */
	public function test_a_nand_b() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute( array( 'expression' => 'A NAND B' ) );
		$this->assertIsArray( $r );
		$this->assertSame( array( 'A', 'B' ), $r['variables'] );
		$this->assertCount( 4, $r['rows'] );
		$expected = array(
			array(
				'A'      => 0,
				'B'      => 0,
				'result' => 1,
			),
			array(
				'A'      => 0,
				'B'      => 1,
				'result' => 1,
			),
			array(
				'A'      => 1,
				'B'      => 0,
				'result' => 1,
			),
			array(
				'A'      => 1,
				'B'      => 1,
				'result' => 0,
			),
		);
		$this->assertSame( $expected, $r['rows'] );
	}

	/**
	 * Test: three var expression.
	 */
	public function test_three_var_expression() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute( array( 'expression' => '(A AND B) OR NOT C' ) );
		$this->assertSame( array( 'A', 'B', 'C' ), $r['variables'] );
		$this->assertCount( 8, $r['rows'] );

		// Expected: result = (A AND B) OR (NOT C).
		foreach ( $r['rows'] as $row ) {
			$expected = ( $row['A'] && $row['B'] ) || ! $row['C'];
			$this->assertSame( $expected ? 1 : 0, $row['result'], 'A=' . $row['A'] . ' B=' . $row['B'] . ' C=' . $row['C'] );
		}
	}

	/**
	 * Test: symbolic sheffer stroke.
	 */
	public function test_symbolic_sheffer_stroke() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute( array( 'expression' => 'A↑B' ) );
		$this->assertSame( array( 'A', 'B' ), $r['variables'] );
		// Same as A NAND B.
		$expected = array(
			array(
				'A'      => 0,
				'B'      => 0,
				'result' => 1,
			),
			array(
				'A'      => 0,
				'B'      => 1,
				'result' => 1,
			),
			array(
				'A'      => 1,
				'B'      => 0,
				'result' => 1,
			),
			array(
				'A'      => 1,
				'B'      => 1,
				'result' => 0,
			),
		);
		$this->assertSame( $expected, $r['rows'] );
	}

	/**
	 * Test: too many variables.
	 */
	public function test_too_many_variables() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute( array( 'expression' => 'A AND B AND C AND D AND E AND F AND G AND H AND I' ) );
		$this->assertWPError( $r );
		$this->assertSame( 'wp_mcp_ai_too_many_variables', $r->get_error_code() );
	}

	/**
	 * Test: malformed input.
	 */
	public function test_malformed_input() {
		$this->maybe_skip();
		$tool = $this->get_tool();

		$r = $tool->execute( array( 'expression' => '@@@' ) );
		$this->assertWPError( $r );

		$r = $tool->execute( array( 'expression' => '(A AND B' ) );
		$this->assertWPError( $r );

		$r = $tool->execute( array( 'expression' => 'A AND' ) );
		$this->assertWPError( $r );
	}

	/**
	 * Test: constants supported.
	 */
	public function test_constants_supported() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute( array( 'expression' => 'A AND TRUE' ) );
		$this->assertIsArray( $r );
		$this->assertSame( array( 'A' ), $r['variables'] );
		$this->assertSame( 0, $r['rows'][0]['result'] );
		$this->assertSame( 1, $r['rows'][1]['result'] );
	}

	/**
	 * Test: markdown table format.
	 */
	public function test_markdown_table_format() {
		$this->maybe_skip();
		$tool = $this->get_tool();
		$r    = $tool->execute(
			array(
				'expression' => 'A NAND B',
				'format'     => 'markdown_table',
			)
		);
		$this->assertArrayHasKey( 'markdown_table', $r );
		$this->assertStringContainsString( '| A | B |', $r['markdown_table'] );
	}
}
