<?php
/**
 * Tests for building tool cost envelopes from extracted usage info.
 *
 * The chat client aggregates a top-level `cost` object on each tool_result
 * message into the final assistant response label. This file covers the
 * server-side builder that produces that object.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class Test_Tool_Cost_Envelope_Building
 *
 * Tests the build_tool_cost_envelope method. Uses reflection to access the
 * protected method without extending the class.
 */
class Test_Tool_Cost_Envelope_Building extends WP_UnitTestCase {

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $controller;

	/**
	 * Reflection method for testing.
	 *
	 * @var ReflectionMethod
	 */
	protected $envelope_method;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		}

		$mock_registry = $this->getMockBuilder( 'WP_MCP_AI_Tool_Registry' )
			->disableOriginalConstructor()
			->getMock();

		$mock_router = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->getMock();

		$this->controller = new WP_MCP_AI_REST( $mock_registry, $mock_router );

		$this->envelope_method = new ReflectionMethod( $this->controller, 'build_tool_cost_envelope' );
		$this->envelope_method->setAccessible( true );
	}

	/**
	 * Helper to invoke the protected method.
	 *
	 * @param array $tool_usage_info Usage info from extract_usage_info_from_tool_result().
	 * @return array|null Cost envelope or null.
	 */
	protected function build_cost_envelope( $tool_usage_info ) {
		return $this->envelope_method->invoke( $this->controller, $tool_usage_info );
	}

	/**
	 * Test that empty usage info returns null.
	 */
	public function test_returns_null_for_empty_usage_info() {
		$this->assertNull( $this->build_cost_envelope( array() ) );
	}

	/**
	 * Test that usage info without a cost figure returns null.
	 */
	public function test_returns_null_when_cost_usd_missing() {
		$usage_info = array(
			'prompt_tokens'     => 100,
			'completion_tokens' => 50,
			'total_tokens'      => 150,
			'model'             => 'gpt-4o-mini',
			'provider'          => 'openai',
		);

		$this->assertNull( $this->build_cost_envelope( $usage_info ) );
	}

	/**
	 * Test that a full cost envelope is built from usage info.
	 */
	public function test_builds_cost_envelope_from_usage_info() {
		$usage_info = array(
			'prompt_tokens'     => 100,
			'completion_tokens' => 50,
			'total_tokens'      => 150,
			'model'             => 'veo-2.0',
			'provider'          => 'gemini',
			'cost_usd'          => 0.0025,
			'cost_is_estimated' => true,
		);

		$envelope = $this->build_cost_envelope( $usage_info );

		$this->assertIsArray( $envelope );
		$this->assertEquals( 0.0025, $envelope['cost_usd'] );
		$this->assertTrue( $envelope['is_estimated'] );
		$this->assertEquals( 'gemini', $envelope['provider'] );
		$this->assertEquals( 'veo-2.0', $envelope['model'] );
	}

	/**
	 * Test that computed (fallback) costs are marked as estimated.
	 */
	public function test_marks_calculated_cost_as_estimated() {
		$usage_info = array(
			'prompt_tokens'      => 100,
			'completion_tokens'  => 50,
			'total_tokens'       => 150,
			'provider'           => 'openai',
			'model'              => 'gpt-4o-mini',
			'cost_usd'           => 0.0001,
			'cost_is_calculated' => true,
		);

		$envelope = $this->build_cost_envelope( $usage_info );

		$this->assertIsArray( $envelope );
		$this->assertTrue( $envelope['is_estimated'] );
	}

	/**
	 * Test that provider and model are omitted when unavailable.
	 */
	public function test_omits_missing_provider_and_model() {
		$usage_info = array(
			'prompt_tokens'     => 10,
			'completion_tokens' => 5,
			'total_tokens'      => 15,
			'cost_usd'          => 0.0001,
			'is_estimated'      => false,
		);

		$envelope = $this->build_cost_envelope( $usage_info );

		$this->assertIsArray( $envelope );
		$this->assertArrayNotHasKey( 'provider', $envelope );
		$this->assertArrayNotHasKey( 'model', $envelope );
		$this->assertFalse( $envelope['is_estimated'] );
	}

	/**
	 * Test that zero cost still produces an envelope (tools may legitimately
	 * report a free API call and the client should still show the badge).
	 */
	public function test_builds_envelope_for_zero_cost() {
		$usage_info = array(
			'prompt_tokens'     => 10,
			'completion_tokens' => 5,
			'total_tokens'      => 15,
			'cost_usd'          => 0.0,
			'is_estimated'      => false,
		);

		$envelope = $this->build_cost_envelope( $usage_info );

		$this->assertIsArray( $envelope );
		$this->assertSame( 0.0, $envelope['cost_usd'] );
	}
}
