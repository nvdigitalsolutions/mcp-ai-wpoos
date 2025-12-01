<?php
/**
 * Test to verify all provider subtabs save consistently.
 *
 * @package WP_MCP_AI
 */

/**
 * Verify all provider subtabs work identically.
 */
class WP_MCP_AI_Provider_Subtabs_Consistency_Test extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		unset( $_POST['subtab'] );
		unset( $_GET['subtab'] );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that Priority Order subtab saves correctly.
	 */
	public function test_priority_order_subtab_saves_correctly() {
		$section = new WP_MCP_AI_Section_Providers();

		$_POST['subtab'] = 'priority';
		$input           = array(
			'provider_priority_list' => array( 'gemini', 'openai', 'ollama', 'anthropic', 'lm_studio' ),
		);

		$sanitized = $section->sanitize( $input );

		$this->assertArrayHasKey( 'provider_priority_list', $sanitized );
		$this->assertCount( 5, $sanitized['provider_priority_list'] );
		$this->assertEquals( 'gemini', $sanitized['provider_priority_list'][0] );
	}

	/**
	 * Test that OpenAI subtab saves correctly.
	 */
	public function test_openai_subtab_saves_correctly() {
		$section = new WP_MCP_AI_Section_Providers();

		$_POST['subtab'] = 'openai';
		$input           = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-test-123',
			'default_model'  => 'gpt-4o',
		);

		$sanitized = $section->sanitize( $input );

		$this->assertArrayHasKey( 'enable_openai', $sanitized );
		$this->assertTrue( $sanitized['enable_openai'] );
		$this->assertArrayHasKey( 'openai_api_key', $sanitized );
		$this->assertEquals( 'sk-test-123', $sanitized['openai_api_key'] );
	}

	/**
	 * Test that Anthropic subtab saves correctly.
	 */
	public function test_anthropic_subtab_saves_correctly() {
		$section = new WP_MCP_AI_Section_Providers();

		$_POST['subtab'] = 'anthropic';
		$input           = array(
			'enable_anthropic'  => '1',
			'anthropic_api_key' => 'sk-ant-test-456',
			'anthropic_model'   => 'claude-3-5-sonnet-20241022',
		);

		$sanitized = $section->sanitize( $input );

		$this->assertArrayHasKey( 'enable_anthropic', $sanitized );
		$this->assertTrue( $sanitized['enable_anthropic'] );
		$this->assertArrayHasKey( 'anthropic_api_key', $sanitized );
		$this->assertEquals( 'sk-ant-test-456', $sanitized['anthropic_api_key'] );
	}

	/**
	 * Test that Google Gemini subtab saves correctly.
	 */
	public function test_gemini_subtab_saves_correctly() {
		$section = new WP_MCP_AI_Section_Providers();

		$_POST['subtab'] = 'gemini';
		$input           = array(
			'enable_gemini'        => '1',
			'gemini_api_key'       => 'AIza-test-789',
			'default_gemini_model' => 'gemini-1.5-pro',
		);

		$sanitized = $section->sanitize( $input );

		$this->assertArrayHasKey( 'enable_gemini', $sanitized );
		$this->assertTrue( $sanitized['enable_gemini'] );
		$this->assertArrayHasKey( 'gemini_api_key', $sanitized );
		$this->assertEquals( 'AIza-test-789', $sanitized['gemini_api_key'] );
	}

	/**
	 * Test that Ollama subtab saves correctly.
	 */
	public function test_ollama_subtab_saves_correctly() {
		$section = new WP_MCP_AI_Section_Providers();

		$_POST['subtab'] = 'ollama';
		$input           = array(
			'enable_ollama'       => '1',
			'ollama_endpoint_url' => 'http://localhost:11434',
			'ollama_model'        => 'llama3',
		);

		$sanitized = $section->sanitize( $input );

		$this->assertArrayHasKey( 'enable_ollama', $sanitized );
		$this->assertTrue( $sanitized['enable_ollama'] );
		$this->assertArrayHasKey( 'ollama_endpoint_url', $sanitized );
		$this->assertEquals( 'http://localhost:11434', $sanitized['ollama_endpoint_url'] );
	}

	/**
	 * Test that all provider subtabs handle checkboxes consistently.
	 */
	public function test_all_providers_handle_unchecked_checkboxes_consistently() {
		$section = new WP_MCP_AI_Section_Providers();

		$providers = array(
			'openai'    => 'enable_openai',
			'anthropic' => 'enable_anthropic',
			'gemini'    => 'enable_gemini',
			'ollama'    => 'enable_ollama',
			'lm_studio' => 'enable_lm_studio',
		);

		foreach ( $providers as $subtab => $enable_field ) {
			$_POST['subtab'] = $subtab;

			// Submit with checkbox UNCHECKED (not in input).
			$input = array();

			$sanitized = $section->sanitize( $input );

			$this->assertArrayHasKey( $enable_field, $sanitized, "$enable_field should be in sanitized output for $subtab" );
			$this->assertFalse( $sanitized[ $enable_field ], "$enable_field should be false when unchecked for $subtab" );
		}
	}

	/**
	 * Test that all provider subtabs preserve other subtabs' settings.
	 */
	public function test_all_providers_preserve_other_settings() {
		// Set up initial state with all providers enabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'    => true,
				'enable_anthropic' => true,
				'enable_gemini'    => true,
				'enable_ollama'    => true,
				'enable_lm_studio' => true,
			)
		);

		$section = new WP_MCP_AI_Section_Providers();

		// Save each provider subtab individually.
		$providers = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );

		foreach ( $providers as $subtab ) {
			$_POST['subtab'] = $subtab;
			$enable_field    = "enable_$subtab";

			// Keep checkbox enabled.
			$input = array( $enable_field => '1' );

			$sanitized = $section->sanitize( $input );

			// Should only contain fields from this subtab.
			$this->assertArrayHasKey( $enable_field, $sanitized );

			// Should NOT contain fields from other subtabs.
			foreach ( $providers as $other_provider ) {
				if ( $other_provider !== $subtab ) {
					$other_enable = "enable_$other_provider";
					$this->assertArrayNotHasKey(
						$other_enable,
						$sanitized,
						"Saving $subtab should not include $other_enable"
					);
				}
			}

			// Merge with existing (simulating actual save).
			$existing = get_option( 'wp_mcp_ai_settings', array() );
			$merged   = array_merge( $existing, $sanitized );

			// All providers should still be enabled.
			foreach ( $providers as $provider ) {
				$enable_field_check = "enable_$provider";
				$this->assertTrue(
					$merged[ $enable_field_check ],
					"$enable_field_check should still be true after saving $subtab"
				);
			}
		}
	}

	/**
	 * Verify all subtabs use the same sanitization method.
	 */
	public function test_all_subtabs_use_same_sanitization_method() {
		$section    = new WP_MCP_AI_Section_Providers();
		$reflection = new ReflectionClass( $section );

		// Verify the section uses sanitize_with_subtabs from parent class.
		$sanitize_method = $reflection->getMethod( 'sanitize' );
		$declaring_class = $sanitize_method->getDeclaringClass()->getName();

		// Should be inherited from parent, not overridden.
		$this->assertEquals(
			'WP_MCP_AI_Settings_Section',
			$declaring_class,
			'Providers section should inherit sanitize from parent class'
		);
	}
}
