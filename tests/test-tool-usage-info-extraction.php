<?php
/**
 * Tests for extracting usage information from tool results.
 *
 * Phase 7: Enhanced Token Tracking - Tool-level usage data.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Tool_Usage_Info_Extraction
 *
 * Tests the extract_usage_info_from_tool_result method functionality.
 * Uses reflection to access the protected method without extending the class.
 */
class Test_Tool_Usage_Info_Extraction extends WP_UnitTestCase {

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST_Controller
	 */
	protected $controller;

	/**
	 * Reflection method for testing.
	 *
	 * @var ReflectionMethod
	 */
	protected $extract_method;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the REST controller class and its dependencies.
		if ( ! class_exists( 'WP_MCP_AI_REST_Controller' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		}

		// Create mock dependencies for the controller.
		$mock_registry = $this->getMockBuilder( 'WP_MCP_AI_Tool_Registry' )
			->disableOriginalConstructor()
			->getMock();

		$mock_router = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->getMock();

		// Create controller with mock dependencies.
		$this->controller = new WP_MCP_AI_REST_Controller( $mock_registry, $mock_router );

		// Use reflection to access the protected method.
		$this->extract_method = new ReflectionMethod( $this->controller, 'extract_usage_info_from_tool_result' );
		$this->extract_method->setAccessible( true );
	}

	/**
	 * Helper to invoke the protected method.
	 *
	 * @param mixed $tool_result Tool result to extract usage from.
	 * @return array|null Usage info or null.
	 */
	protected function extract_usage_info( $tool_result ) {
		return $this->extract_method->invoke( $this->controller, $tool_result );
	}

	/**
	 * Test that WP_Error returns null.
	 */
	public function test_returns_null_for_wp_error() {
		$error  = new WP_Error( 'test_error', 'Test error message' );
		$result = $this->extract_usage_info( $error );

		$this->assertNull( $result );
	}

	/**
	 * Test that string results return null.
	 */
	public function test_returns_null_for_string_result() {
		$result = $this->extract_usage_info( 'Simple string result' );

		$this->assertNull( $result );
	}

	/**
	 * Test that array without usage key returns null.
	 */
	public function test_returns_null_for_array_without_usage() {
		$tool_result = array(
			'success' => true,
			'data'    => 'Some data',
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertNull( $result );
	}

	/**
	 * Test that empty usage array returns null.
	 */
	public function test_returns_null_for_empty_usage() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertNull( $result );
	}

	/**
	 * Test that usage with zero tokens returns null.
	 */
	public function test_returns_null_for_zero_tokens() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertNull( $result );
	}

	/**
	 * Test basic usage extraction.
	 */
	public function test_extracts_basic_usage_info() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 100, $result['prompt_tokens'] );
		$this->assertEquals( 50, $result['completion_tokens'] );
		$this->assertEquals( 150, $result['total_tokens'] );
	}

	/**
	 * Test that total_tokens is calculated if not provided.
	 */
	public function test_calculates_total_tokens_if_missing() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 150, $result['total_tokens'] );
	}

	/**
	 * Test is_estimated flag extraction.
	 */
	public function test_extracts_is_estimated_flag() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
				'is_estimated'      => true,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['is_estimated'] );
	}

	/**
	 * Test model extraction from tool result.
	 */
	public function test_extracts_model_from_tool_result() {
		$tool_result = array(
			'success' => true,
			'model'   => 'gpt-4o',
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 'gpt-4o', $result['model'] );
	}

	/**
	 * Test provider extraction from tool result.
	 */
	public function test_extracts_provider_from_tool_result() {
		$tool_result = array(
			'success'  => true,
			'provider' => 'openai',
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 'openai', $result['provider'] );
	}

	/**
	 * Test cost data extraction.
	 */
	public function test_extracts_cost_data() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
			'cost'    => array(
				'cost_usd'     => 0.0025,
				'is_estimated' => true,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 0.0025, $result['cost_usd'] );
		$this->assertTrue( $result['cost_is_estimated'] );
	}

	/**
	 * Test model and provider fallback from usage data.
	 */
	public function test_extracts_model_and_provider_from_usage() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
				'model'             => 'dall-e-3',
				'provider'          => 'openai',
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 'dall-e-3', $result['model'] );
		$this->assertEquals( 'openai', $result['provider'] );
	}

	/**
	 * Test tool result model takes precedence over usage model.
	 */
	public function test_tool_result_model_takes_precedence() {
		$tool_result = array(
			'success' => true,
			'model'   => 'dall-e-3',
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
				'model'             => 'gpt-4o',
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 'dall-e-3', $result['model'] );
	}

	/**
	 * Test complete usage info with all fields.
	 */
	public function test_complete_usage_info_extraction() {
		$tool_result = array(
			'success'  => true,
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
			'usage'    => array(
				'prompt_tokens'     => 500,
				'completion_tokens' => 200,
				'total_tokens'      => 700,
				'is_estimated'      => false,
			),
			'cost'     => array(
				'cost_usd'     => 0.0035,
				'is_estimated' => false,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 500, $result['prompt_tokens'] );
		$this->assertEquals( 200, $result['completion_tokens'] );
		$this->assertEquals( 700, $result['total_tokens'] );
		$this->assertFalse( $result['is_estimated'] );
		$this->assertEquals( 'gpt-4o-mini', $result['model'] );
		$this->assertEquals( 'openai', $result['provider'] );
		$this->assertEquals( 0.0035, $result['cost_usd'] );
		$this->assertFalse( $result['cost_is_estimated'] );
	}

	/**
	 * Test that negative cost values are not included.
	 */
	public function test_rejects_negative_cost() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
			'cost'    => array(
				'cost_usd'     => -0.0025,
				'is_estimated' => false,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'cost_usd', $result );
		$this->assertArrayNotHasKey( 'cost_is_estimated', $result );
	}

	/**
	 * Test that zero cost is accepted.
	 */
	public function test_accepts_zero_cost() {
		$tool_result = array(
			'success' => true,
			'usage'   => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
			'cost'    => array(
				'cost_usd'     => 0.0,
				'is_estimated' => true,
			),
		);
		$result      = $this->extract_usage_info( $tool_result );

		$this->assertIsArray( $result );
		$this->assertEquals( 0.0, $result['cost_usd'] );
		$this->assertTrue( $result['cost_is_estimated'] );
	}
}
