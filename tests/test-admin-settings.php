<?php
/**
 * Tests for the admin settings class.
 */
class WP_MCP_AI_Admin_Settings_Test extends WP_UnitTestCase {

	/**
	 * Ensure defaults include the uninstall cleanup flag.
	 */
	public function test_default_settings_include_cleanup_flag() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'delete_on_uninstall', $defaults );
		$this->assertFalse( $defaults['delete_on_uninstall'] );
	}

	/**
	 * Ensure defaults include the default model setting.
	 */
	public function test_default_settings_define_default_model() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'default_model', $defaults );
		$this->assertSame( 'gpt-4o-mini', $defaults['default_model'] );
		$this->assertArrayHasKey( 'default_gemini_model', $defaults );
		$this->assertSame( 'gemini-1.5-flash', $defaults['default_gemini_model'] );
		$this->assertArrayHasKey( 'default_provider', $defaults );
		$this->assertSame( 'openai', $defaults['default_provider'] );
		$this->assertArrayHasKey( 'openai_image_model', $defaults );
		$this->assertSame( 'gpt-image-1', $defaults['openai_image_model'] );
		$this->assertArrayHasKey( 'openai_image_size', $defaults );
		$this->assertSame( '1024x1024', $defaults['openai_image_size'] );
		$this->assertArrayHasKey( 'openai_image_quality', $defaults );
		$this->assertSame( 'standard', $defaults['openai_image_quality'] );
		$this->assertArrayHasKey( 'openai_image_response_format', $defaults );
		$this->assertSame( 'b64_json', $defaults['openai_image_response_format'] );
	}

	/**
	 * Ensure the default model field suggests popular OpenAI chat models.
	 */
	public function test_render_default_model_field_outputs_datalist() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$output = $this->capture_field_output( array( $admin_settings, 'render_default_model_field' ) );

		$this->assertStringContainsString( 'datalist id="wp-mcp-ai-default-openai-models"', $output );
		$this->assertStringContainsString( 'value="gpt-5"', $output );
		$this->assertStringContainsString( 'label="GPT-5"', $output );
		$this->assertStringContainsString( 'value="gpt-4o"', $output );
		$this->assertStringContainsString( 'value="gpt-4.1-mini"', $output );
		$this->assertStringContainsString( 'label="GPT-4o mini"', $output );
		$this->assertStringContainsString( '>GPT-4o mini<', $output );
	}

	/**
	 * Ensure defaults include the Crawl4AI configuration keys.
	 */
	public function test_default_settings_include_crawl4ai_configuration() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'crawl4ai_base_url', $defaults );
		$this->assertSame( '', $defaults['crawl4ai_base_url'] );
		$this->assertArrayHasKey( 'crawl4ai_api_key', $defaults );
		$this->assertSame( '', $defaults['crawl4ai_api_key'] );
	}

	/**
	 * Ensure defaults include configuration for the group email tool.
	 */
	public function test_default_settings_include_group_email_configuration() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'group_email_capability', $defaults );
		$this->assertSame( 'publish_posts', $defaults['group_email_capability'] );
		$this->assertArrayHasKey( 'group_email_max_recipients', $defaults );
		$this->assertSame( 100, $defaults['group_email_max_recipients'] );
	}

	/**
	 * Ensure sanitize_settings casts the cleanup flag to a boolean value.
	 */
	public function test_sanitize_settings_casts_cleanup_flag() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'delete_on_uninstall' => '1',
			)
		);

		$this->assertTrue( $sanitized['delete_on_uninstall'] );
	}

	/**
	 * Ensure sanitize_settings enforces a minimum request timeout of five seconds.
	 */
	public function test_sanitize_settings_clamps_request_timeout_floor() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'request_timeout' => '3',
			)
		);

		$this->assertSame( 5, $sanitized['request_timeout'] );
	}

	/**
	 * Ensure sanitize_settings leaves higher request timeout values untouched.
	 */
	public function test_sanitize_settings_preserves_request_timeout_above_floor() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'request_timeout' => '42',
			)
		);

		$this->assertSame( 42, $sanitized['request_timeout'] );
	}

	/**
	 * Ensure sanitize_settings strips unsafe characters from the default model.
	 */
	public function test_sanitize_settings_sanitizes_default_model() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$dirty_value = '  <b>gpt-4o-custom</b>  ';

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'default_model' => $dirty_value,
			)
		);

		$this->assertSame( sanitize_text_field( $dirty_value ), $sanitized['default_model'] );
	}

	/**
	 * Ensure sanitize_settings accepts valid default provider values.
	 */
	public function test_sanitize_settings_accepts_valid_default_provider() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'default_provider' => 'gemini',
			)
		);

		$this->assertSame( 'gemini', $sanitized['default_provider'] );
	}

	/**
	 * Ensure sanitize_settings rejects unsupported default provider values.
	 */
	public function test_sanitize_settings_rejects_invalid_default_provider() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'default_provider' => 'invalid-provider',
			)
		);

		$this->assertSame( 'openai', $sanitized['default_provider'] );
	}

	/**
	 * Ensure sanitize_settings cleans Crawl4AI settings.
	 */
	public function test_sanitize_settings_sanitizes_crawl4ai_configuration() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'crawl4ai_base_url' => ' https://example.com/crawl/ ',
				'crawl4ai_api_key'  => '  secret token  ',
			)
		);

		$this->assertSame( 'https://example.com/crawl/', $sanitized['crawl4ai_base_url'] );
		$this->assertSame( 'secret token', $sanitized['crawl4ai_api_key'] );
	}

	/**
	 * Ensure sanitize_settings cleans group email configuration values.
	 */
	public function test_sanitize_settings_sanitizes_group_email_configuration() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'group_email_capability'     => ' Manage_Options ',
				'group_email_max_recipients' => ' 250 ',
			)
		);

		$this->assertSame( 'manage_options', $sanitized['group_email_capability'] );
		$this->assertSame( 250, $sanitized['group_email_max_recipients'] );
	}

	/**
	 * Ensure sanitize_settings accepts valid OpenAI image configuration values.
	 */
	public function test_sanitize_settings_accepts_openai_image_configuration() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'openai_image_model'           => 'gpt-image-1',
				'openai_image_size'            => '1792x1024',
				'openai_image_quality'         => 'hd',
				'openai_image_response_format' => 'url',
			)
		);

		$this->assertSame( 'gpt-image-1', $sanitized['openai_image_model'] );
		$this->assertSame( '1792x1024', $sanitized['openai_image_size'] );
		$this->assertSame( 'hd', $sanitized['openai_image_quality'] );
		$this->assertSame( 'url', $sanitized['openai_image_response_format'] );
	}

	/**
	 * Ensure sanitize_settings rejects invalid OpenAI image configuration values.
	 */
	public function test_sanitize_settings_rejects_invalid_openai_image_configuration() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();
		$defaults       = WP_MCP_AI_Admin_Settings::get_default_settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'openai_image_model'           => 'unknown-model',
				'openai_image_size'            => '200x200',
				'openai_image_quality'         => 'ultra',
				'openai_image_response_format' => 'xml',
			)
		);

		$this->assertSame( $defaults['openai_image_model'], $sanitized['openai_image_model'] );
		$this->assertSame( $defaults['openai_image_size'], $sanitized['openai_image_size'] );
		$this->assertSame( $defaults['openai_image_quality'], $sanitized['openai_image_quality'] );
		$this->assertSame( $defaults['openai_image_response_format'], $sanitized['openai_image_response_format'] );
	}

	/**
	 * Ensure the Gmail section exposes a connect button when credentials exist.
	 */
	public function test_gmail_section_renders_connect_button_with_credentials() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings = array_merge(
			$defaults,
			array(
				'gmail_client_id'     => 'client-id',
				'gmail_client_secret' => 'client-secret',
			)
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$admin_settings = new WP_MCP_AI_Admin_Settings();
		$output         = $this->capture_field_output( array( $admin_settings, 'render_gmail_section_description' ) );

		$this->assertStringContainsString( 'Connect Gmail Account', $output );
		$this->assertStringContainsString( 'action=wp_mcp_ai_gmail_oauth_start', $output );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Ensure the Gmail section shows guidance when credentials are missing.
	 */
	public function test_gmail_section_prompts_for_client_configuration_when_missing() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$admin_settings = new WP_MCP_AI_Admin_Settings();
		$output         = $this->capture_field_output( array( $admin_settings, 'render_gmail_section_description' ) );

		$this->assertStringNotContainsString( 'Connect Gmail Account', $output );
		$this->assertStringContainsString( 'Save your Gmail client ID and secret', $output );
	}

	/**
	 * Ensure the Google OAuth authorize host is whitelisted for safe redirects.
	 */
	public function test_allow_gmail_oauth_redirect_host_whitelists_google_accounts_domain() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$hosts = $admin_settings->allow_gmail_oauth_redirect_host( array( 'example.com' ) );

		$this->assertContains( 'accounts.google.com', $hosts );
	}

	/**
	 * Ensure defaults include LM Studio configuration.
	 */
	public function test_default_settings_include_lm_studio_configuration() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'lm_studio_endpoint_url', $defaults );
		$this->assertSame( '', $defaults['lm_studio_endpoint_url'] );
		$this->assertArrayHasKey( 'lm_studio_model', $defaults );
		$this->assertSame( '', $defaults['lm_studio_model'] );
	}

	/**
	 * Ensure the default provider field includes LM Studio as an option.
	 */
	public function test_default_provider_field_includes_lm_studio() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$output = $this->capture_field_output( array( $admin_settings, 'render_default_provider_field' ) );

		$this->assertStringContainsString( 'value="openai"', $output );
		$this->assertStringContainsString( 'value="gemini"', $output );
		$this->assertStringContainsString( 'value="ollama"', $output );
		$this->assertStringContainsString( 'value="lm_studio"', $output );
		$this->assertStringContainsString( 'LM Studio (Local AI)', $output );
	}

	/**
	 * Ensure defaults include the Cloudways configuration keys.
	 */
	public function test_default_settings_include_cloudways_configuration() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'cloudways_email', $defaults );
		$this->assertSame( '', $defaults['cloudways_email'] );
		$this->assertArrayHasKey( 'cloudways_api_key', $defaults );
		$this->assertSame( '', $defaults['cloudways_api_key'] );
		$this->assertArrayHasKey( 'cloudways_server_id', $defaults );
		$this->assertSame( '', $defaults['cloudways_server_id'] );
		$this->assertArrayHasKey( 'cloudways_app_id', $defaults );
		$this->assertSame( '', $defaults['cloudways_app_id'] );
	}

	/**
	 * Ensure sanitize_settings properly sanitizes Cloudways email.
	 */
	public function test_sanitize_settings_sanitizes_cloudways_email() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'cloudways_email' => '  test@example.com  ',
			)
		);

		$this->assertSame( 'test@example.com', $sanitized['cloudways_email'] );
	}

	/**
	 * Ensure sanitize_settings properly sanitizes Cloudways API key.
	 */
	public function test_sanitize_settings_sanitizes_cloudways_api_key() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'cloudways_api_key' => '  test-api-key-123  ',
			)
		);

		$this->assertSame( 'test-api-key-123', $sanitized['cloudways_api_key'] );
	}

	/**
	 * Ensure defaults include the OpenAI embedding model setting.
	 */
	public function test_default_settings_include_embedding_model() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'openai_embedding_model', $defaults );
		$this->assertSame( 'text-embedding-3-small', $defaults['openai_embedding_model'] );
	}

	/**
	 * Ensure defaults include the max history messages setting.
	 */
	public function test_default_settings_include_max_history_messages() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'max_history_messages', $defaults );
		$this->assertSame( 8, $defaults['max_history_messages'] );
	}

	/**
	 * Ensure sanitize_settings properly sanitizes the embedding model.
	 */
	public function test_sanitize_settings_sanitizes_embedding_model() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'openai_embedding_model' => '  text-embedding-3-large  ',
			)
		);

		$this->assertSame( 'text-embedding-3-large', $sanitized['openai_embedding_model'] );
	}

	/**
	 * Ensure sanitize_settings enforces bounds on max history messages.
	 */
	public function test_sanitize_settings_clamps_max_history_messages() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		// Test minimum value.
		$sanitized_min = $admin_settings->sanitize_settings(
			array(
				'max_history_messages' => '0',
			)
		);
		$this->assertSame( 1, $sanitized_min['max_history_messages'] );

		// Test maximum value.
		$sanitized_max = $admin_settings->sanitize_settings(
			array(
				'max_history_messages' => '100',
			)
		);
		$this->assertSame( 50, $sanitized_max['max_history_messages'] );

		// Test valid value within range.
		$sanitized_valid = $admin_settings->sanitize_settings(
			array(
				'max_history_messages' => '8',
			)
		);
		$this->assertSame( 8, $sanitized_valid['max_history_messages'] );
	}

	/**
	 * Ensure get_default_model returns the configured default model.
	 */
	public function test_get_default_model_returns_setting() {
		$settings = array_merge(
			WP_MCP_AI_Admin_Settings::get_default_settings(),
			array( 'default_model' => 'gpt-4o' )
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$model = WP_MCP_AI_Admin_Settings::get_default_model();

		$this->assertSame( 'gpt-4o', $model );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Ensure get_embedding_model returns the configured embedding model.
	 */
	public function test_get_embedding_model_returns_setting() {
		$settings = array_merge(
			WP_MCP_AI_Admin_Settings::get_default_settings(),
			array( 'openai_embedding_model' => 'text-embedding-3-large' )
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$model = WP_MCP_AI_Admin_Settings::get_embedding_model();

		$this->assertSame( 'text-embedding-3-large', $model );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that accordion section headers have onclick handlers for expansion.
	 */
	public function test_accordion_section_headers_have_onclick_handlers() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		// Capture the output of the settings page.
		ob_start();
		$admin_settings->render_settings_page();
		$output = ob_get_clean();

		// Verify onclick handler is present.
		$this->assertStringContainsString( 'onclick="wpMcpAiToggleSection(this)"', $output, 'Section headers should have onclick handlers' );

		// Verify aria-expanded attribute is present.
		$this->assertStringContainsString( 'aria-expanded="false"', $output, 'Section headers should have aria-expanded attribute' );

		// Verify aria-controls attribute is present.
		$this->assertStringContainsString( 'aria-controls=', $output, 'Section headers should have aria-controls attribute' );

		// Verify the section has proper role.
		$this->assertStringContainsString( 'role="button"', $output, 'Section headers should have role="button"' );
	}

	/**
	 * Capture buffered output generated by a settings field renderer.
	 *
	 * @param callable $callback Renderer callback.
	 *
	 * @return string
	 */
	protected function capture_field_output( $callback ) {
		ob_start();
		call_user_func( $callback );

		return ob_get_clean();
	}
}
