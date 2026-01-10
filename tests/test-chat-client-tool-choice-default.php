<?php
/**
 * Tests for chat-client default tool_choice behavior.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for chat-client tool_choice defaults.
 */
class Test_Chat_Client_Tool_Choice_Default extends WP_UnitTestCase {

	/**
	 * Chat controller instance.
	 *
	 * @var WP_MCP_AI_REST_Chat_Controller
	 */
	private $controller;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->controller = new WP_MCP_AI_REST_Chat_Controller();
	}

	/**
	 * Test that Cloudflare chat-client defaults to tool_choice="auto".
	 */
	public function test_cloudflare_chat_client_defaults_to_tool_choice_auto() {
		$options = array(
			'tools' => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'web_search',
						'description' => 'Search the web',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'query' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			// No tool_choice explicitly set.
		);

		$assistant_config = array(
			'ID'       => 123,
			'provider' => 'cloudflare',
		);

		$request_params = array();

		$result = $this->controller->set_chat_client_tool_choice_default( $options, $assistant_config, $request_params );

		// Should default to "auto" for Cloudflare.
		$this->assertArrayHasKey( 'tool_choice', $result, 'tool_choice should be set' );
		$this->assertSame( 'auto', $result['tool_choice'], 'Default tool_choice should be "auto" for Cloudflare' );
	}

	/**
	 * Test that explicit tool_choice is not overridden.
	 */
	public function test_explicit_tool_choice_not_overridden() {
		$options = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'web_search',
						'description' => 'Search the web',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'query' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'tool_choice' => 'auto', // Explicitly set by user.
		);

		$assistant_config = array(
			'ID'       => 123,
			'provider' => 'cloudflare',
		);

		$request_params = array();

		$result = $this->controller->set_chat_client_tool_choice_default( $options, $assistant_config, $request_params );

		// Should NOT override explicit tool_choice.
		$this->assertSame( 'auto', $result['tool_choice'], 'Explicit tool_choice should not be overridden' );
	}

	/**
	 * Test that non-Cloudflare providers are not affected.
	 */
	public function test_non_cloudflare_providers_not_affected() {
		$options = array(
			'tools' => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'web_search',
						'description' => 'Search the web',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'query' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			// No tool_choice explicitly set.
		);

		$assistant_config = array(
			'ID'       => 123,
			'provider' => 'openai', // Not Cloudflare.
		);

		$request_params = array();

		$result = $this->controller->set_chat_client_tool_choice_default( $options, $assistant_config, $request_params );

		// Should NOT set default for non-Cloudflare providers.
		$this->assertArrayNotHasKey( 'tool_choice', $result, 'tool_choice should not be set for non-Cloudflare providers' );
	}

	/**
	 * Test that default is not set when no tools present.
	 */
	public function test_no_default_when_no_tools() {
		$options = array(
			'tools' => array(), // No tools.
		);

		$assistant_config = array(
			'ID'       => 123,
			'provider' => 'cloudflare',
		);

		$request_params = array();

		$result = $this->controller->set_chat_client_tool_choice_default( $options, $assistant_config, $request_params );

		// Should NOT set default when no tools.
		$this->assertArrayNotHasKey( 'tool_choice', $result, 'tool_choice should not be set when no tools present' );
	}

	/**
	 * Test that user can override with tool_choice="required".
	 */
	public function test_user_can_override_with_required() {
		$options = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'web_search',
						'description' => 'Search the web',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'query' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'tool_choice' => 'required', // User wants to force tool use.
		);

		$assistant_config = array(
			'ID'       => 123,
			'provider' => 'cloudflare',
		);

		$request_params = array();

		$result = $this->controller->set_chat_client_tool_choice_default( $options, $assistant_config, $request_params );

		// Should respect user's choice.
		$this->assertSame( 'required', $result['tool_choice'], 'User override to "required" should be respected' );
	}

	/**
	 * Test that specific tool choice is not overridden.
	 */
	public function test_specific_tool_choice_not_overridden() {
		$specific_tool = array(
			'type'     => 'function',
			'function' => array( 'name' => 'web_search' ),
		);

		$options = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'web_search',
						'description' => 'Search the web',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'query' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'tool_choice' => $specific_tool, // User wants specific tool.
		);

		$assistant_config = array(
			'ID'       => 123,
			'provider' => 'cloudflare',
		);

		$request_params = array();

		$result = $this->controller->set_chat_client_tool_choice_default( $options, $assistant_config, $request_params );

		// Should respect user's specific tool choice.
		$this->assertSame( $specific_tool, $result['tool_choice'], 'Specific tool choice should not be overridden' );
	}

	/**
	 * Test with missing provider in config.
	 */
	public function test_missing_provider_no_default() {
		$options = array(
			'tools' => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'web_search',
						'description' => 'Search the web',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'query' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
		);

		$assistant_config = array(
			'ID' => 123,
			// No provider specified.
		);

		$request_params = array();

		$result = $this->controller->set_chat_client_tool_choice_default( $options, $assistant_config, $request_params );

		// Should NOT set default when provider is missing.
		$this->assertArrayNotHasKey( 'tool_choice', $result, 'tool_choice should not be set when provider is missing' );
	}
}
