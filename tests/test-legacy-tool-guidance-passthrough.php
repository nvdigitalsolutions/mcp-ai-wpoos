<?php
/**
 * Tests for the legacy-tool usage-guidance passthrough on
 * WP_MCP_AI_Legacy_Tool_Wrapper.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Legacy-format stub tool that opts into guidance via a method.
 */
class Test_Legacy_Guidance_Method_Stub {

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'test_legacy_method_stub';
	}

	/**
	 * Legacy definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'        => 'Legacy Method Stub',
			'description' => 'Legacy stub with a guidance method.',
		);
	}

	/**
	 * Execute stub.
	 *
	 * @return array
	 */
	public function execute() {
		return array( 'success' => true );
	}

	/**
	 * Opt-in guidance method.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => 'The method path',
			'when_not_to_use' => 'The other path',
		);
	}
}

/**
 * Legacy-format stub tool that opts into guidance via a definition key.
 */
class Test_Legacy_Guidance_Key_Stub {

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'test_legacy_key_stub';
	}

	/**
	 * Legacy definition with a usage_guidance key.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'        => 'Legacy Key Stub',
			'description' => 'Legacy stub with a guidance definition key.',
			'usage_guidance' => array(
				'when_to_use'     => 'The definition-key path',
				'related_tools'   => array( 'test_legacy_method_stub' ),
			),
		);
	}

	/**
	 * Execute stub.
	 *
	 * @return array
	 */
	public function execute() {
		return array( 'success' => true );
	}
}

/**
 * Legacy-format stub tool without any guidance.
 */
class Test_Legacy_Guidance_None_Stub {

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'test_legacy_none_stub';
	}

	/**
	 * Legacy definition without guidance.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'        => 'Legacy None Stub',
			'description' => 'Legacy stub without guidance.',
		);
	}

	/**
	 * Execute stub.
	 *
	 * @return array
	 */
	public function execute() {
		return array( 'success' => true );
	}
}

/**
 * Legacy-format stub tool that declares guidance in BOTH entry points.
 */
class Test_Legacy_Guidance_Both_Stub extends Test_Legacy_Guidance_Key_Stub {

	/**
	 * Method entry point that should win over the definition key.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use' => 'The method path wins',
		);
	}
}

/**
 * Test class for the legacy-tool guidance passthrough.
 */
class Test_Legacy_Tool_Guidance_Passthrough extends WP_UnitTestCase {

	/**
	 * The wrapper forwards a get_usage_guidance() method from the inner tool.
	 */
	public function test_wrapper_forwards_guidance_method() {
		$wrapper = new WP_MCP_AI_Legacy_Tool_Wrapper( new Test_Legacy_Guidance_Method_Stub() );

		$guidance = $wrapper->get_usage_guidance();

		$this->assertSame( 'The method path', $guidance['when_to_use'] );
		$this->assertSame( 'The other path', $guidance['when_not_to_use'] );
	}

	/**
	 * The wrapper forwards a usage_guidance definition key from the inner tool.
	 */
	public function test_wrapper_forwards_definition_key() {
		$wrapper = new WP_MCP_AI_Legacy_Tool_Wrapper( new Test_Legacy_Guidance_Key_Stub() );

		$guidance = $wrapper->get_usage_guidance();

		$this->assertSame( 'The definition-key path', $guidance['when_to_use'] );
		$this->assertSame( array( 'test_legacy_method_stub' ), $guidance['related_tools'] );
	}

	/**
	 * The wrapper returns an empty array when the inner tool declares nothing.
	 */
	public function test_wrapper_returns_empty_for_plain_legacy_tool() {
		$wrapper = new WP_MCP_AI_Legacy_Tool_Wrapper( new Test_Legacy_Guidance_None_Stub() );

		$this->assertSame( array(), $wrapper->get_usage_guidance() );
	}

	/**
	 * The method path wins over the definition key when both are present.
	 */
	public function test_method_path_wins_over_definition_key() {
		$wrapper = new WP_MCP_AI_Legacy_Tool_Wrapper( new Test_Legacy_Guidance_Both_Stub() );

		$guidance = $wrapper->get_usage_guidance();

		$this->assertSame( 'The method path wins', $guidance['when_to_use'] );
		$this->assertArrayNotHasKey( 'related_tools', $guidance );
	}

	/**
	 * The registry assembles the model-facing suffix for a wrapped legacy tool.
	 */
	public function test_registry_assembles_suffix_for_wrapped_legacy_tool() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$wrapper  = new WP_MCP_AI_Legacy_Tool_Wrapper( new Test_Legacy_Guidance_Method_Stub() );

		$description = $registry->get_model_facing_description( $wrapper );

		$this->assertStringContainsString( 'Legacy stub with a guidance method.', $description );
		$this->assertStringContainsString( '[Usage:', $description );
		$this->assertStringContainsString( 'do NOT use when The other path', $description );
	}

	/**
	 * The registry leaves a guidance-less wrapped legacy tool untouched.
	 */
	public function test_registry_leaves_plain_legacy_tool_unchanged() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$wrapper  = new WP_MCP_AI_Legacy_Tool_Wrapper( new Test_Legacy_Guidance_None_Stub() );

		$this->assertSame( 'Legacy stub without guidance.', $registry->get_model_facing_description( $wrapper ) );
	}
}
