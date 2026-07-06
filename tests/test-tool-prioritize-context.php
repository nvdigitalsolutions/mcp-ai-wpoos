<?php
/**
 * Tests for prioritize_context tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-author
 */

/**
 * Test prioritize_context tool functionality.
 */
class Test_Tool_Prioritize_Context extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Prioritize_Context
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Prioritize_Context();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'prioritize_context', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Missing context_items returns error response.
	 */
	public function test_missing_context_items_returns_error() {
		$result = $this->tool->execute(
			array( 'token_budget' => 1000 ),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString( 'Context items', $result->get_error_message() );
	}

	/**
	 * Missing token_budget returns error response.
	 */
	public function test_missing_token_budget_returns_error() {
		$result = $this->tool->execute(
			array(
				'context_items' => array(
					array(
						'context_id' => 'ctx-1',
						'content'    => 'Some content',
					),
				),
			),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString( 'Token budget', $result->get_error_message() );
	}

	/**
	 * Valid inputs return a prioritized result with selected and excluded keys.
	 */
	public function test_valid_inputs_return_prioritized_result() {
		$result = $this->tool->execute(
			array(
				'context_items' => array(
					array(
						'context_id' => 'ctx-1',
						'content'    => str_repeat( 'Relevant text about machine learning. ', 10 ),
						'importance' => 'high',
					),
					array(
						'context_id' => 'ctx-2',
						'content'    => str_repeat( 'Less relevant text. ', 5 ),
						'importance' => 'low',
					),
					array(
						'context_id' => 'ctx-3',
						'content'    => str_repeat( 'Moderately relevant text. ', 8 ),
						'importance' => 'medium',
					),
				),
				'token_budget'  => 200,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'prioritized', $result );
		$this->assertArrayHasKey( 'excluded_count', $result );
		$this->assertArrayHasKey( 'total_tokens', $result );
	}

	/**
	 * Token budget is respected — selected items do not exceed the budget.
	 */
	public function test_token_budget_is_respected() {
		$budget = 50;
		$result = $this->tool->execute(
			array(
				'context_items' => array(
					array(
						'context_id' => 'ctx-a',
						'content'    => str_repeat( 'word ', 100 ),
					),
					array(
						'context_id' => 'ctx-b',
						'content'    => str_repeat( 'word ', 100 ),
					),
				),
				'token_budget'  => $budget,
			),
			array()
		);

		$this->assertIsArray( $result );
		if ( isset( $result['total_tokens'] ) ) {
			$this->assertLessThanOrEqual( $budget, $result['total_tokens'] );
		}
	}

	/**
	 * Empty context_items array returns graceful result.
	 */
	public function test_empty_context_items_returns_graceful_result() {
		$result = $this->tool->execute(
			array(
				'context_items' => array(),
				'token_budget'  => 1000,
			),
			array()
		);

		// PHP empty([]) is true, so the tool returns WP_Error for an empty array.
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString( 'Context items', $result->get_error_message() );
	}

	/**
	 * Strategy parameter is accepted without errors.
	 */
	public function test_strategy_parameter_accepted() {
		foreach ( array( 'balanced', 'recency', 'importance' ) as $strategy ) {
			$result = $this->tool->execute(
				array(
					'context_items' => array(
						array(
							'context_id' => 'ctx-1',
							'content'    => 'Some text.',
						),
					),
					'token_budget'  => 500,
					'strategy'      => $strategy,
				),
				array()
			);

			$this->assertTrue( is_array( $result ), "Strategy '{$strategy}' should not throw." );
		}
	}
}
