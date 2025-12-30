<?php
/**
 * Tests for Custom Filters Applicator
 *
 * Tests that the custom filter settings are properly applied to WordPress filters.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Custom_Filters_Applicator_Test extends WP_UnitTestCase {

	/**
	 * Settings option name constant.
	 */
	const OPTION_NAME = 'wp_mcp_ai_settings';

	/**
	 * Test instance.
	 *
	 * @var WP_MCP_AI_Custom_Filters_Applicator
	 */
	private $applicator;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any existing settings.
		delete_option( self::OPTION_NAME );

		// Create fresh instance.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up.
		delete_option( self::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that filter hooks are registered.
	 */
	public function test_filter_hooks_registered() {
		$this->assertTrue( has_filter( 'wp_mcp_ai_default_light_model' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_default_advanced_model' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_max_agentic_iterations' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_resource_max_tokens' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_resource_request_timeout' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_max_retries' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_max_retry_delay' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_max_attachment_bytes' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_default_ollama_endpoint_url' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_default_lm_studio_endpoint_url' ) );
		$this->assertTrue( has_filter( 'wp_mcp_ai_lm_studio_fallback_model' ) );
	}

	/**
	 * Test default light model filter with no custom setting.
	 */
	public function test_default_light_model_no_custom_setting() {
		$default = 'gpt-4o-mini';
		$result  = apply_filters( 'wp_mcp_ai_default_light_model', $default );
		$this->assertEquals( $default, $result );
	}

	/**
	 * Test default light model filter with custom setting.
	 */
	public function test_default_light_model_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_default_light_model' => 'gpt-3.5-turbo',
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 'gpt-4o-mini';
		$result  = apply_filters( 'wp_mcp_ai_default_light_model', $default );
		$this->assertEquals( 'gpt-3.5-turbo', $result );
	}

	/**
	 * Test default advanced model filter with custom setting.
	 */
	public function test_default_advanced_model_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_default_advanced_model' => 'gpt-4-turbo',
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 'gpt-4o';
		$result  = apply_filters( 'wp_mcp_ai_default_advanced_model', $default );
		$this->assertEquals( 'gpt-4-turbo', $result );
	}

	/**
	 * Test max agentic iterations filter with custom setting.
	 */
	public function test_max_agentic_iterations_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_max_agentic_iterations' => 10,
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 5;
		$result  = apply_filters( 'wp_mcp_ai_max_agentic_iterations', $default, array() );
		$this->assertEquals( 10, $result );
	}

	/**
	 * Test resource max tokens filter with custom setting.
	 */
	public function test_resource_max_tokens_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_resource_max_tokens' => 8000,
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 4096;
		$result  = apply_filters( 'wp_mcp_ai_resource_max_tokens', $default, 'medium' );
		$this->assertEquals( 8000, $result );
	}

	/**
	 * Test resource request timeout filter with custom setting.
	 */
	public function test_resource_request_timeout_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_resource_request_timeout' => 120,
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 60;
		$result  = apply_filters( 'wp_mcp_ai_resource_request_timeout', $default, 'medium', 300 );
		$this->assertEquals( 120, $result );
	}

	/**
	 * Test max retries filter with custom setting.
	 */
	public function test_max_retries_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_max_retries' => 5,
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 3;
		$result  = apply_filters( 'wp_mcp_ai_max_retries', $default, array() );
		$this->assertEquals( 5, $result );
	}

	/**
	 * Test max retry delay filter with custom setting.
	 */
	public function test_max_retry_delay_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_max_retry_delay' => 90,
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 60;
		$result  = apply_filters( 'wp_mcp_ai_max_retry_delay', $default, array() );
		$this->assertEquals( 90, $result );
	}

	/**
	 * Test max attachment bytes filter with custom setting.
	 */
	public function test_max_attachment_bytes_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_max_attachment_bytes' => 20971520, // 20MB.
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 10485760; // 10MB.
		$result  = apply_filters( 'wp_mcp_ai_max_attachment_bytes', $default, array() );
		$this->assertEquals( 20971520, $result );
	}

	/**
	 * Test Ollama endpoint URL filter with custom setting.
	 */
	public function test_ollama_endpoint_url_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_default_ollama_endpoint_url' => 'http://custom-ollama:11434',
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 'http://localhost:11434';
		$result  = apply_filters( 'wp_mcp_ai_default_ollama_endpoint_url', $default );
		$this->assertEquals( 'http://custom-ollama:11434', $result );
	}

	/**
	 * Test LM Studio endpoint URL filter with custom setting.
	 */
	public function test_lm_studio_endpoint_url_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_default_lm_studio_endpoint_url' => 'http://custom-lm-studio:1234',
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 'http://localhost:1234';
		$result  = apply_filters( 'wp_mcp_ai_default_lm_studio_endpoint_url', $default );
		$this->assertEquals( 'http://custom-lm-studio:1234', $result );
	}

	/**
	 * Test LM Studio fallback model with custom setting.
	 */
	public function test_lm_studio_fallback_model_with_custom_setting() {
		// Set custom value.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_lm_studio_fallback_model' => 'custom-local-model',
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 'gpt-4o';
		$result  = apply_filters( 'wp_mcp_ai_lm_studio_fallback_model', $default, array() );
		$this->assertEquals( 'custom-local-model', $result );
	}

	/**
	 * Test LM Studio fallback model uses default when not set.
	 */
	public function test_lm_studio_fallback_model_uses_default_when_not_set() {
		// No custom value set.
		update_option( self::OPTION_NAME, array() );

		// Recreate applicator.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 'gpt-4o';
		$result  = apply_filters( 'wp_mcp_ai_lm_studio_fallback_model', $default, array() );
		$this->assertEquals( 'gpt-4o', $result, 'Should use default when no custom setting' );
	}

	/**
	 * Test that empty string settings don't override defaults.
	 */
	public function test_empty_string_settings_use_defaults() {
		// Set empty string values.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_default_light_model'    => '',
				'filter_max_agentic_iterations' => '',
				'filter_resource_max_tokens'    => '',
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		// Verify defaults are used.
		$result = apply_filters( 'wp_mcp_ai_default_light_model', 'gpt-4o-mini' );
		$this->assertEquals( 'gpt-4o-mini', $result );

		$result = apply_filters( 'wp_mcp_ai_max_agentic_iterations', 5, array() );
		$this->assertEquals( 5, $result );

		$result = apply_filters( 'wp_mcp_ai_resource_max_tokens', 4096, 'medium' );
		$this->assertEquals( 4096, $result );
	}

	/**
	 * Test that filters are applied at priority 5.
	 */
	public function test_filter_priority() {
		// Add a higher priority filter (should not be overridden).
		add_filter(
			'wp_mcp_ai_default_light_model',
			function ( $model ) {
				return 'high-priority-model';
			},
			1
		);

		// Set custom value at priority 5.
		update_option(
			self::OPTION_NAME,
			array(
				'filter_default_light_model' => 'custom-model',
			)
		);

		// Recreate applicator to pick up new setting.
		$this->applicator = new WP_MCP_AI_Custom_Filters_Applicator();

		$default = 'gpt-4o-mini';
		$result  = apply_filters( 'wp_mcp_ai_default_light_model', $default );

		// Should use the priority 1 filter value.
		$this->assertEquals( 'high-priority-model', $result );

		remove_all_filters( 'wp_mcp_ai_default_light_model' );
	}
}
