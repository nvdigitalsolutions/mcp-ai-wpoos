<?php
/**
 * Tests for Tool Registry provider compatibility checking.
 *
 * @package WP_MCP_AI
 */

/**
 * @group tool-registry
 * @group tool-filtering
 */
class WP_MCP_AI_Tool_Provider_Compatibility_Tests extends WP_UnitTestCase {

	/**
	 * Test that tools with provider restrictions are correctly identified.
	 */
	public function test_provider_restricted_tool_compatibility() {
		$registry = new WP_MCP_AI_Tool_Registry();

		// Create a tool that only supports OpenAI.
		$openai_tool = $this->create_mock_tool( 'openai_only_tool', array( 'openai' ) );
		$registry->register( $openai_tool );

		// Should be compatible with OpenAI.
		$this->assertTrue(
			$registry->is_tool_compatible_with_provider( 'openai_only_tool', 'openai' ),
			'Tool should be compatible with openai provider'
		);

		// Should not be compatible with LM Studio.
		$this->assertFalse(
			$registry->is_tool_compatible_with_provider( 'openai_only_tool', 'lm_studio' ),
			'Tool should not be compatible with lm_studio provider'
		);

		// Should not be compatible with Gemini.
		$this->assertFalse(
			$registry->is_tool_compatible_with_provider( 'openai_only_tool', 'gemini' ),
			'Tool should not be compatible with gemini provider'
		);
	}

	/**
	 * Test that tools supporting multiple providers work correctly.
	 */
	public function test_multi_provider_tool_compatibility() {
		$registry = new WP_MCP_AI_Tool_Registry();

		// Tool that supports both OpenAI and LM Studio.
		$multi_tool = $this->create_mock_tool( 'multi_provider_tool', array( 'openai', 'lm_studio' ) );
		$registry->register( $multi_tool );

		// Should be compatible with both.
		$this->assertTrue(
			$registry->is_tool_compatible_with_provider( 'multi_provider_tool', 'openai' ),
			'Tool should be compatible with openai'
		);

		$this->assertTrue(
			$registry->is_tool_compatible_with_provider( 'multi_provider_tool', 'lm_studio' ),
			'Tool should be compatible with lm_studio'
		);

		// Should not be compatible with others.
		$this->assertFalse(
			$registry->is_tool_compatible_with_provider( 'multi_provider_tool', 'gemini' ),
			'Tool should not be compatible with gemini'
		);
	}

	/**
	 * Test that unrestricted tools work with any provider.
	 */
	public function test_unrestricted_tool_compatibility() {
		$registry = new WP_MCP_AI_Tool_Registry();

		// Tool with no provider restrictions.
		$unrestricted_tool = $this->create_mock_tool( 'unrestricted_tool', array() );
		$registry->register( $unrestricted_tool );

		// Should work with all providers.
		$this->assertTrue(
			$registry->is_tool_compatible_with_provider( 'unrestricted_tool', 'openai' ),
			'Unrestricted tool should work with openai'
		);

		$this->assertTrue(
			$registry->is_tool_compatible_with_provider( 'unrestricted_tool', 'lm_studio' ),
			'Unrestricted tool should work with lm_studio'
		);

		$this->assertTrue(
			$registry->is_tool_compatible_with_provider( 'unrestricted_tool', 'gemini' ),
			'Unrestricted tool should work with gemini'
		);

		$this->assertTrue(
			$registry->is_tool_compatible_with_provider( 'unrestricted_tool', 'anthropic' ),
			'Unrestricted tool should work with anthropic'
		);
	}

	/**
	 * Test that non-existent tools return false.
	 */
	public function test_nonexistent_tool_compatibility() {
		$registry = new WP_MCP_AI_Tool_Registry();

		$this->assertFalse(
			$registry->is_tool_compatible_with_provider( 'nonexistent_tool', 'openai' ),
			'Non-existent tool should return false'
		);
	}

	/**
	 * Create a mock tool with provider restrictions.
	 *
	 * @param string $slug      Tool slug.
	 * @param array  $providers Allowed providers (empty = no restrictions).
	 * @return object Mock tool object.
	 */
	private function create_mock_tool( $slug, $providers ) {
		return new class( $slug, $providers ) implements WP_MCP_AI_Tool_Interface {
			private $slug;
			private $providers;

			public function __construct( $slug, $providers ) {
				$this->slug      = $slug;
				$this->providers = $providers;
			}

			public function get_slug() {
				return $this->slug;
			}

			public function get_description() {
				return 'Test tool: ' . $this->slug;
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( $arguments, $context = array() ) {
				return 'executed';
			}

			public function get_tool_rules() {
				if ( empty( $this->providers ) ) {
					return array();
				}

				return array(
					'model_requirements' => array(
						'providers' => $this->providers,
					),
				);
			}
		};
	}
}
