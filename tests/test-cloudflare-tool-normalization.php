<?php
/**
 * Tests for Cloudflare tool normalization
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudflare tool normalization functionality.
 */
class Test_Cloudflare_Tool_Normalization extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance for testing.
	 *
	 * @var WP_MCP_AI_Cloudflare_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the Cloudflare client class.
		if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
		}

		$this->client = new WP_MCP_AI_Cloudflare_Client();
	}

	/**
	 * Test that tools with proper OpenAI function format are normalized correctly.
	 */
	public function test_openai_function_format_normalized() {
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get the current weather',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'location' => array(
								'type'        => 'string',
								'description' => 'City name',
							),
						),
					),
				),
			),
		);

		$normalized = $this->invoke_normalise_tools( $tools );

		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'get_weather', $normalized[0]['name'] );
		$this->assertEquals( 'function', $normalized[0]['type'] );
	}

	/**
	 * Test that tools with slug are normalized to name.
	 */
	public function test_slug_converted_to_name() {
		$tools = array(
			array(
				'slug'        => 'get_weather',
				'description' => 'Get the current weather',
			),
		);

		$normalized = $this->invoke_normalise_tools( $tools );

		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'get_weather', $normalized[0]['name'] );
	}

	/**
	 * Test that tools with id are normalized to name.
	 */
	public function test_id_converted_to_name() {
		$tools = array(
			array(
				'id'          => 'get_weather',
				'description' => 'Get the current weather',
			),
		);

		$normalized = $this->invoke_normalise_tools( $tools );

		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'get_weather', $normalized[0]['name'] );
	}

	/**
	 * Test that tools without name/slug/id are filtered out.
	 */
	public function test_tools_without_identifier_filtered() {
		$tools = array(
			array(
				'description' => 'Tool without identifier',
			),
			array(
				'name'        => 'valid_tool',
				'description' => 'Valid tool',
			),
		);

		$normalized = $this->invoke_normalise_tools( $tools );

		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'valid_tool', $normalized[0]['name'] );
	}

	/**
	 * Test that empty tools array returns empty array.
	 */
	public function test_empty_tools_returns_empty() {
		$tools = array();

		$normalized = $this->invoke_normalise_tools( $tools );

		$this->assertEmpty( $normalized );
	}

	/**
	 * Test that multiple tools are normalized correctly.
	 */
	public function test_multiple_tools_normalized() {
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get weather',
				),
			),
			array(
				'slug'        => 'search_web',
				'description' => 'Search the web',
			),
			array(
				'id'          => 'send_email',
				'description' => 'Send an email',
			),
		);

		$normalized = $this->invoke_normalise_tools( $tools );

		$this->assertCount( 3, $normalized );
		$this->assertEquals( 'get_weather', $normalized[0]['name'] );
		$this->assertEquals( 'search_web', $normalized[1]['name'] );
		$this->assertEquals( 'send_email', $normalized[2]['name'] );
	}

	/**
	 * Helper method to invoke the protected normalise_tools_for_payload method.
	 *
	 * @param array $tools Tools to normalize.
	 * @return array Normalized tools.
	 */
	private function invoke_normalise_tools( array $tools ) {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tools_for_payload' );
		$method->setAccessible( true );

		return $method->invoke( $this->client, $tools );
	}
}
