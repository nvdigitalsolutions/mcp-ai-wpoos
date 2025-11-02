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
