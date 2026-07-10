<?php
/**
 * Page Agent Unit Tests
 *
 * Tests for the Page Agent addon core functionality:
 * config building, enqueue logic, settings, tool registration.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Test_Page_Agent
 *
 * @since 0.1.0
 */
class Test_Page_Agent extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the addon classes.
		if ( ! class_exists( 'WP_MCP_AI_Page_Agent' ) ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Page_Agent_REST' ) ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent-rest.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Page_Agent_Execute' ) ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/tools/class-wp-mcp-ai-tool-page-agent-execute.php';
		}
	}

	/**
	 * Tear down test environment.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Clean up options.
		delete_option( WP_MCP_AI_Page_Agent::OPTION_KEY );
		parent::tearDown();
	}

	// ── Option / Settings Tests ────────────────────────────

	/**
	 * Test that get_settings() returns defaults when no options are set.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_get_settings_returns_defaults() {
		$settings = WP_MCP_AI_Page_Agent::get_settings();

		$this->assertIsArray( $settings );
		$this->assertTrue( $settings['enabled'] );
		$this->assertEquals( 'gpt-4o-mini', $settings['model'] );
		$this->assertEquals( 'en-US', $settings['language'] );
		$this->assertEquals( 50, $settings['max_steps'] );
	}

	/**
	 * Test that is_enabled() returns true by default.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_is_enabled_defaults_true() {
		$this->assertTrue( WP_MCP_AI_Page_Agent::is_enabled() );
	}

	/**
	 * Test that is_enabled() returns false when explicitly disabled.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_is_enabled_respects_setting() {
		update_option(
			WP_MCP_AI_Page_Agent::OPTION_KEY,
			array(
				'enabled'   => false,
				'model'     => 'gpt-4o',
				'language'  => 'fr-FR',
				'max_steps' => 10,
			)
		);

		$this->assertFalse( WP_MCP_AI_Page_Agent::is_enabled() );

		$settings = WP_MCP_AI_Page_Agent::get_settings();
		$this->assertEquals( 'gpt-4o', $settings['model'] );
		$this->assertEquals( 'fr-FR', $settings['language'] );
		$this->assertEquals( 10, $settings['max_steps'] );
	}

	/**
	 * Test that the sanitize_settings method clamps max_steps.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_settings_clamps_max_steps() {
		if ( ! class_exists( 'WP_MCP_AI_Page_Agent_Settings' ) ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/admin/class-wp-mcp-ai-page-agent-settings.php';
		}

		$settings_obj = new WP_MCP_AI_Page_Agent_Settings();

		// Test below minimum.
		$result = $settings_obj->sanitize( array( 'max_steps' => 0 ) );
		$this->assertEquals( 1, $result['max_steps'] );

		// Test above maximum.
		$result = $settings_obj->sanitize( array( 'max_steps' => 999 ) );
		$this->assertEquals( 200, $result['max_steps'] );

		// Test valid value.
		$result = $settings_obj->sanitize( array( 'max_steps' => 75 ) );
		$this->assertEquals( 75, $result['max_steps'] );
	}

	// ── Config Tests ────────────────────────────────────────

	/**
	 * Test that build_config returns expected keys.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_build_config_has_required_keys() {
		$instance = new WP_MCP_AI_Page_Agent();

		$reflection = new ReflectionMethod( $instance, 'build_config' );
		$reflection->setAccessible( true );
		$config = $reflection->invoke( $instance );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'model', $config );
		$this->assertArrayHasKey( 'baseURL', $config );
		$this->assertArrayHasKey( 'apiKey', $config );
		$this->assertArrayHasKey( 'language', $config );
		$this->assertArrayHasKey( 'maxSteps', $config );
		$this->assertArrayHasKey( 'restUrl', $config );
		$this->assertArrayHasKey( 'nonce', $config );
		$this->assertArrayHasKey( 'tools', $config );
		$this->assertArrayHasKey( 'enabled', $config );
	}

	/**
	 * Test that the nonce is valid.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_build_config_nonce_is_valid() {
		$instance = new WP_MCP_AI_Page_Agent();

		$reflection = new ReflectionMethod( $instance, 'build_config' );
		$reflection->setAccessible( true );
		$config = $reflection->invoke( $instance );

		$this->assertNotEmpty( $config['nonce'] );
		$this->assertIsString( $config['nonce'] );
	}

	// ── Provider Resolution Tests ────────────────────────────

	/**
	 * Test that resolve_provider_for_model correctly identifies OpenAI models.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_resolve_provider_openai() {
		$instance  = new WP_MCP_AI_Page_Agent();
		$reflection = new ReflectionMethod( $instance, 'resolve_provider_for_model' );
		$reflection->setAccessible( true );

		$this->assertEquals( 'openai', $reflection->invoke( $instance, 'gpt-4o' ) );
		$this->assertEquals( 'openai', $reflection->invoke( $instance, 'gpt-4o-mini' ) );
		$this->assertEquals( 'openai', $reflection->invoke( $instance, 'o1' ) );
		$this->assertEquals( 'openai', $reflection->invoke( $instance, 'o3-mini' ) );
	}

	/**
	 * Test that resolve_provider_for_model correctly identifies Gemini models.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_resolve_provider_gemini() {
		$instance  = new WP_MCP_AI_Page_Agent();
		$reflection = new ReflectionMethod( $instance, 'resolve_provider_for_model' );
		$reflection->setAccessible( true );

		$this->assertEquals( 'gemini', $reflection->invoke( $instance, 'gemini-2.0-flash' ) );
		$this->assertEquals( 'gemini', $reflection->invoke( $instance, 'gemini-pro' ) );
	}

	/**
	 * Test that resolve_provider_for_model correctly identifies Anthropic models.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_resolve_provider_anthropic() {
		$instance  = new WP_MCP_AI_Page_Agent();
		$reflection = new ReflectionMethod( $instance, 'resolve_provider_for_model' );
		$reflection->setAccessible( true );

		$this->assertEquals( 'anthropic', $reflection->invoke( $instance, 'claude-3.5-sonnet' ) );
		$this->assertEquals( 'anthropic', $reflection->invoke( $instance, 'claude-3-opus' ) );
	}

	/**
	 * Test that resolve_provider_for_model defaults to openai for unknown models.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_resolve_provider_default() {
		$instance  = new WP_MCP_AI_Page_Agent();
		$reflection = new ReflectionMethod( $instance, 'resolve_provider_for_model' );
		$reflection->setAccessible( true );

		$this->assertEquals( 'openai', $reflection->invoke( $instance, 'unknown-model' ) );
	}

	// ── Shortcode Tests ──────────────────────────────────────

	/**
	 * Test that the shortcode renders when enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_shortcode_renders_when_enabled() {
		if ( ! class_exists( 'WP_MCP_AI_Page_Agent_Widget' ) ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent-widget.php';
		}

		$widget = new WP_MCP_AI_Page_Agent_Widget();
		$output = $widget->render_shortcode();

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'nvoos-page-agent-container', $output );
	}

	/**
	 * Test that the shortcode accepts overrides.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_shortcode_accepts_overrides() {
		if ( ! class_exists( 'WP_MCP_AI_Page_Agent_Widget' ) ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent-widget.php';
		}

		$widget = new WP_MCP_AI_Page_Agent_Widget();
		$output = $widget->render_shortcode(
			array(
				'model'    => 'gpt-4o',
				'language' => 'ja-JP',
				'position' => 'bottom-left',
			)
		);

		$this->assertStringContainsString( 'data-model="gpt-4o"', $output );
		$this->assertStringContainsString( 'data-language="ja-JP"', $output );
		$this->assertStringContainsString( 'data-position="bottom-left"', $output );
	}

	// ── Constants Tests ──────────────────────────────────────

	/**
	 * Test that required constants are defined.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'NVOOS_PAGE_AGENT_VERSION' ) );
		$this->assertTrue( defined( 'NVOOS_PAGE_AGENT_FILE' ) );
		$this->assertTrue( defined( 'NVOOS_PAGE_AGENT_PATH' ) );
		$this->assertTrue( defined( 'NVOOS_PAGE_AGENT_URL' ) );

		$this->assertEquals( '0.1.0', NVOOS_PAGE_AGENT_VERSION );
	}
}
