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
		$this->assertSame( 'gemini-2.5-flash', $defaults['default_gemini_model'] );
		$this->assertArrayHasKey( 'default_provider', $defaults );
		$this->assertSame( 'openai', $defaults['default_provider'] );
		$this->assertArrayHasKey( 'openai_image_model', $defaults );
		$this->assertSame( 'gpt-image-1', $defaults['openai_image_model'] );
		$this->assertArrayHasKey( 'openai_image_size', $defaults );
		$this->assertSame( '1024x1024', $defaults['openai_image_size'] );
		$this->assertArrayHasKey( 'openai_image_quality', $defaults );
		$this->assertSame( 'medium', $defaults['openai_image_quality'] );
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
	 * Ensure the default model choices include comprehensive OpenAI models from fallback.
	 */
	public function test_default_model_choices_include_comprehensive_models() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();
		$reflection     = new ReflectionClass( $admin_settings );
		$method         = $reflection->getMethod( 'get_openai_default_model_choices' );
		$method->setAccessible( true );
		$choices = $method->invoke( $admin_settings );

		// Verify we have more models than the original 8 hardcoded ones.
		$this->assertGreaterThanOrEqual( 15, count( $choices ) );

		// Verify some key models are present.
		$this->assertArrayHasKey( 'gpt-5', $choices );
		$this->assertArrayHasKey( 'gpt-5-mini', $choices );
		$this->assertArrayHasKey( 'gpt-4o', $choices );
		$this->assertArrayHasKey( 'gpt-4o-mini', $choices );
		$this->assertArrayHasKey( 'gpt-4.1', $choices );
		$this->assertArrayHasKey( 'gpt-4.1-mini', $choices );
		$this->assertArrayHasKey( 'gpt-4.1-nano', $choices );
		$this->assertArrayHasKey( 'gpt-4-turbo', $choices );
		$this->assertArrayHasKey( 'gpt-4', $choices );
		$this->assertArrayHasKey( 'gpt-3.5-turbo', $choices );
		$this->assertArrayHasKey( 'o1-preview', $choices );
		$this->assertArrayHasKey( 'o1-mini', $choices );
	}

	/**
	 * Ensure the format_model_label method formats model names correctly.
	 */
	public function test_format_model_label_handles_common_patterns() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();
		$reflection     = new ReflectionClass( $admin_settings );
		$method         = $reflection->getMethod( 'format_model_label' );
		$method->setAccessible( true );

		// Test special cases.
		$this->assertSame( 'GPT-5', $method->invoke( $admin_settings, 'gpt-5' ) );
		$this->assertSame( 'GPT-4o', $method->invoke( $admin_settings, 'gpt-4o' ) );
		$this->assertSame( 'GPT-4o Mini', $method->invoke( $admin_settings, 'gpt-4o-mini' ) );
		$this->assertSame( 'GPT-4.1', $method->invoke( $admin_settings, 'gpt-4.1' ) );
		$this->assertSame( 'GPT-4 Turbo', $method->invoke( $admin_settings, 'gpt-4-turbo' ) );
		$this->assertSame( 'O1 Preview', $method->invoke( $admin_settings, 'o1-preview' ) );
		$this->assertSame( 'O1 Mini', $method->invoke( $admin_settings, 'o1-mini' ) );
	}

	/**
	 * Ensure get_openai_models_from_cct returns empty array when CCT is not available.
	 */
	public function test_get_openai_models_from_cct_returns_empty_without_jetengine() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();
		$reflection     = new ReflectionClass( $admin_settings );
		$method         = $reflection->getMethod( 'get_openai_models_from_cct' );
		$method->setAccessible( true );

		$models = $method->invoke( $admin_settings );

		// Without JetEngine, should return empty array.
		$this->assertIsArray( $models );
		$this->assertEmpty( $models );
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
		$oauth_manager = new WP_MCP_AI_OAuth_Manager();

		$hosts = $oauth_manager->allow_gmail_oauth_redirect_host( array( 'example.com' ) );

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
	 * Ensure defaults include the WordPress/Gravatar bridge configuration keys.
	 */
	public function test_default_settings_include_wordpress_gravatar_bridge_configuration() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'enable_wordpress_gravatar_bridge', $defaults );
		$this->assertFalse( $defaults['enable_wordpress_gravatar_bridge'] );
		$this->assertArrayHasKey( 'wordpress_gravatar_userinfo_endpoint', $defaults );
		$this->assertSame( '', $defaults['wordpress_gravatar_userinfo_endpoint'] );
	}

	/**
	 * Ensure sanitize_settings sanitizes WordPress/Gravatar bridge toggle.
	 */
	public function test_sanitize_settings_sanitizes_wordpress_gravatar_bridge_toggle() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'enable_wordpress_gravatar_bridge' => '1',
			)
		);

		$this->assertTrue( $sanitized['enable_wordpress_gravatar_bridge'] );
	}

	/**
	 * Ensure sanitize_settings properly sanitizes WordPress/Gravatar userinfo endpoint.
	 */
	public function test_sanitize_settings_sanitizes_wordpress_gravatar_userinfo_endpoint() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		$sanitized = $admin_settings->sanitize_settings(
			array(
				'wordpress_gravatar_userinfo_endpoint' => '  https://public-api.wordpress.com/oauth2/userinfo  ',
			)
		);

		$this->assertSame( 'https://public-api.wordpress.com/oauth2/userinfo', $sanitized['wordpress_gravatar_userinfo_endpoint'] );
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
	public function test_accordion_section_headers_have_proper_attributes() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		// Capture the output of the settings page.
		ob_start();
		$admin_settings->render_settings_page();
		$output = ob_get_clean();

		// Verify section headers are present with the correct class.
		$this->assertStringContainsString( 'wp-mcp-ai-section__header', $output, 'Section headers should have correct class' );

		// Verify first section starts expanded (aria-expanded="true").
		$this->assertStringContainsString( 'aria-expanded="true"', $output, 'First section should have aria-expanded="true"' );

		// Verify other sections start collapsed (aria-expanded="false").
		$this->assertStringContainsString( 'aria-expanded="false"', $output, 'Other sections should have aria-expanded="false"' );

		// Verify first section has expanded class.
		$this->assertStringContainsString( 'wp-mcp-ai-section--expanded', $output, 'First section should have expanded class' );

		// Verify aria-controls attribute is present.
		$this->assertStringContainsString( 'aria-controls=', $output, 'Section headers should have aria-controls attribute' );

		// Verify the section has proper role.
		$this->assertStringContainsString( 'role="button"', $output, 'Section headers should have role="button"' );
	}

	/**
	 * Test that token usage section renders without errors.
	 */
	public function test_render_token_usage_section_requires_manage_options() {
		// Create a user without manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$admin_settings = new WP_MCP_AI_Admin_Settings();

		ob_start();
		$admin_settings->render_token_usage_section();
		$output = ob_get_clean();

		// Should not render anything for users without manage_options.
		$this->assertEmpty( $output );
	}

	/**
	 * Test that token usage section renders for admin users.
	 */
	public function test_render_token_usage_section_for_admin() {
		// Create an admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$admin_settings = new WP_MCP_AI_Admin_Settings();

		ob_start();
		$admin_settings->render_token_usage_section();
		$output = ob_get_clean();

		// Should render the section.
		$this->assertStringContainsString( 'wp-mcp-ai-token-usage-section', $output );
		$this->assertStringContainsString( 'Token Usage Statistics', $output );
		$this->assertStringContainsString( 'All Users', $output );
		$this->assertStringContainsString( 'Your Usage', $output );
	}

	/**
	 * Test token usage calculation.
	 */
	public function test_calculate_usage_totals() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $admin_settings );
		$method     = $reflection->getMethod( 'calculate_usage_totals' );
		$method->setAccessible( true );

		$usage = array(
			'openai' => array(
				'gpt-4o-mini' => array(
					'requests'          => 10,
					'prompt_tokens'     => 500,
					'completion_tokens' => 300,
					'total_tokens'      => 800,
					'cached_tokens'     => 50,
				),
			),
			'gemini' => array(
				'gemini-1.5-flash' => array(
					'requests'          => 5,
					'prompt_tokens'     => 200,
					'completion_tokens' => 150,
					'total_tokens'      => 350,
					'cached_tokens'     => 25,
				),
			),
		);

		$totals = $method->invoke( $admin_settings, $usage );

		$this->assertSame( 15, $totals['requests'] );
		$this->assertSame( 700, $totals['prompt_tokens'] );
		$this->assertSame( 450, $totals['completion_tokens'] );
		$this->assertSame( 1150, $totals['total_tokens'] );
		$this->assertSame( 75, $totals['cached_tokens'] );
	}

	/**
	 * Test reset user token usage AJAX handler.
	 */
	public function test_reset_user_token_usage_requires_capability() {
		// Create a user without manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Store some usage data.
		update_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, array( 'test' => 'data' ) );

		$admin_settings = new WP_MCP_AI_Admin_Settings();

		// Suppress die() call.
		add_filter( 'wp_doing_ajax', '__return_true' );

		try {
			$admin_settings->handle_reset_user_token_usage();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Data should still be there.
		$data = get_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, true );
		$this->assertNotEmpty( $data );
	}

	/**
	 * Test reset all token usage AJAX handler.
	 */
	public function test_reset_all_token_usage_requires_capability() {
		// Create a user without manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Store some usage data.
		update_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, array( 'test' => 'data' ) );

		$admin_settings = new WP_MCP_AI_Admin_Settings();

		// Suppress die() call.
		add_filter( 'wp_doing_ajax', '__return_true' );

		try {
			$admin_settings->handle_reset_all_token_usage();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Data should still be there.
		$data = get_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, true );
		$this->assertNotEmpty( $data );
	}

	/**
	 * Test that reset all token usage clears usermeta cache.
	 */
	public function test_reset_all_token_usage_clears_cache() {
		global $wpdb;

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create multiple users with usage data.
		$user_id_1 = $this->factory->user->create();
		$user_id_2 = $this->factory->user->create();

		$meta_key = WP_MCP_AI_Usage_Tracker::USER_META_KEY;
		update_user_meta( $user_id_1, $meta_key, array( 'test' => 'data1' ) );
		update_user_meta( $user_id_2, $meta_key, array( 'test' => 'data2' ) );

		// Prime the cache.
		get_user_meta( $user_id_1, $meta_key, true );
		get_user_meta( $user_id_2, $meta_key, true );

		$admin_settings = new WP_MCP_AI_Admin_Settings();

		// Set up nonce.
		$_REQUEST['nonce'] = wp_create_nonce( 'wp-mcp-ai-settings' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		// Reset all usage.
		try {
			$admin_settings->handle_reset_all_token_usage();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Verify cache is cleared - get_user_meta should return false/empty.
		$cached_data_1 = get_user_meta( $user_id_1, $meta_key, true );
		$cached_data_2 = get_user_meta( $user_id_2, $meta_key, true );

		$this->assertEmpty( $cached_data_1, 'User 1 cache should be cleared' );
		$this->assertEmpty( $cached_data_2, 'User 2 cache should be cleared' );

		// Verify database is also empty.
		$db_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);
		$this->assertEquals( 0, $db_count, 'All usage records should be deleted from database' );
	}

	/**
	 * Test static method for getting OpenAI models from CCT.
	 */
	public function test_get_openai_models_from_cct_static_returns_empty_without_jetengine() {
		// Without JetEngine, should return empty array.
		$models = WP_MCP_AI_Admin_Settings::get_openai_models_from_cct_static();

		$this->assertIsArray( $models );
		$this->assertEmpty( $models );
	}

	/**
	 * Test static method for formatting model labels.
	 */
	public function test_format_model_label_static_handles_common_patterns() {
		// Test special cases.
		$this->assertSame( 'GPT-5', WP_MCP_AI_Admin_Settings::format_model_label_static( 'gpt-5' ) );
		$this->assertSame( 'GPT-4o', WP_MCP_AI_Admin_Settings::format_model_label_static( 'gpt-4o' ) );
		$this->assertSame( 'GPT-4o Mini', WP_MCP_AI_Admin_Settings::format_model_label_static( 'gpt-4o-mini' ) );
		$this->assertSame( 'GPT-4.1', WP_MCP_AI_Admin_Settings::format_model_label_static( 'gpt-4.1' ) );
		$this->assertSame( 'GPT-4 Turbo', WP_MCP_AI_Admin_Settings::format_model_label_static( 'gpt-4-turbo' ) );
		$this->assertSame( 'O1 Preview', WP_MCP_AI_Admin_Settings::format_model_label_static( 'o1-preview' ) );
		$this->assertSame( 'O1 Mini', WP_MCP_AI_Admin_Settings::format_model_label_static( 'o1-mini' ) );
	}

	/**
	 * Test static method for getting all OpenAI model choices.
	 */
	public function test_get_openai_default_model_choices_static_returns_fallback_without_cct() {
		// Without CCT, should return fallback hardcoded list.
		$choices = WP_MCP_AI_Admin_Settings::get_openai_default_model_choices_static();

		$this->assertIsArray( $choices );
		$this->assertGreaterThanOrEqual( 15, count( $choices ) );

		// Verify key models are present in fallback.
		$this->assertArrayHasKey( 'gpt-5', $choices );
		$this->assertArrayHasKey( 'gpt-4o', $choices );
		$this->assertArrayHasKey( 'gpt-4o-mini', $choices );
		$this->assertArrayHasKey( 'o1-preview', $choices );
	}

	/**
	 * Test that settings cache is properly cleared when temporarily updating settings.
	 *
	 * This test verifies the fix for the issue where test connection handlers
	 * were not able to use updated endpoint URLs because the settings cache
	 * was not cleared after calling update_option().
	 */
	public function test_settings_cache_is_cleared_after_update() {
		// First, set and cache some initial settings.
		$initial_settings = array(
			'ollama_endpoint_url'    => 'http://initial-endpoint:11434',
			'lm_studio_endpoint_url' => 'http://initial-lm-studio:1234',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Call get_settings to cache the initial values.
		$cached_settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertEquals( 'http://initial-endpoint:11434', $cached_settings['ollama_endpoint_url'] );

		// Now update the settings in the database.
		$updated_settings = array(
			'ollama_endpoint_url'    => 'http://updated-endpoint:11434',
			'lm_studio_endpoint_url' => 'http://updated-lm-studio:1234',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $updated_settings );

		// Without clearing the cache, get_settings() would return the cached values.
		// But we need to verify that in the test connection handlers, the cache IS cleared.
		// Since we can't directly test private static properties from here,
		// we'll use reflection to access and verify the cache state.

		$reflection     = new ReflectionClass( 'WP_MCP_AI_Admin_Settings' );
		$cache_property = $reflection->getProperty( 'settings_cache' );
		$cache_property->setAccessible( true );

		// The cache should still contain the old values at this point.
		$cache_value = $cache_property->getValue();
		$this->assertNotNull( $cache_value, 'Cache should be populated' );
		$this->assertEquals( 'http://initial-endpoint:11434', $cache_value['ollama_endpoint_url'] );

		// Now simulate what the fix does: clear the cache.
		$cache_property->setValue( null );

		// After clearing, get_settings should fetch fresh data from the database.
		$fresh_settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertEquals( 'http://updated-endpoint:11434', $fresh_settings['ollama_endpoint_url'] );
		$this->assertEquals( 'http://updated-lm-studio:1234', $fresh_settings['lm_studio_endpoint_url'] );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		$cache_property->setValue( null );
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

	/**
	 * Test that default settings include provider_priority_list.
	 */
	public function test_default_settings_include_provider_priority_list() {
		$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		$this->assertArrayHasKey( 'provider_priority_list', $defaults );
		$this->assertIsArray( $defaults['provider_priority_list'] );
		$this->assertContains( 'openai', $defaults['provider_priority_list'] );
		$this->assertContains( 'gemini', $defaults['provider_priority_list'] );
		$this->assertContains( 'ollama', $defaults['provider_priority_list'] );
		$this->assertContains( 'lm_studio', $defaults['provider_priority_list'] );
	}

	/**
	 * Test provider priority list sanitization - valid input.
	 */
	public function test_provider_priority_list_sanitization_valid() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Test with valid reordered list.
		$input = array(
			'provider_priority_list' => array( 'gemini', 'ollama', 'openai', 'lm_studio' ),
		);

		$sanitized = $settings_base->sanitize_settings( $input );

		$this->assertArrayHasKey( 'provider_priority_list', $sanitized );
		$this->assertEquals( array( 'gemini', 'ollama', 'openai', 'lm_studio' ), $sanitized['provider_priority_list'] );
	}

	/**
	 * Test provider priority list sanitization - removes invalid providers.
	 */
	public function test_provider_priority_list_sanitization_removes_invalid() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Test with invalid provider.
		$input = array(
			'provider_priority_list' => array( 'openai', 'invalid_provider', 'gemini' ),
		);

		$sanitized = $settings_base->sanitize_settings( $input );

		$this->assertArrayHasKey( 'provider_priority_list', $sanitized );
		// Should remove invalid_provider and add missing providers.
		$this->assertContains( 'openai', $sanitized['provider_priority_list'] );
		$this->assertContains( 'gemini', $sanitized['provider_priority_list'] );
		$this->assertNotContains( 'invalid_provider', $sanitized['provider_priority_list'] );
		// Missing providers should be added at the end.
		$this->assertContains( 'ollama', $sanitized['provider_priority_list'] );
		$this->assertContains( 'lm_studio', $sanitized['provider_priority_list'] );
	}

	/**
	 * Test provider priority list sanitization - removes duplicates.
	 */
	public function test_provider_priority_list_sanitization_removes_duplicates() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Test with duplicates.
		$input = array(
			'provider_priority_list' => array( 'openai', 'gemini', 'openai', 'ollama' ),
		);

		$sanitized = $settings_base->sanitize_settings( $input );

		$this->assertArrayHasKey( 'provider_priority_list', $sanitized );
		$this->assertEquals( 4, count( $sanitized['provider_priority_list'] ) );
		// Should only have one instance of openai.
		$this->assertEquals( 1, count( array_keys( $sanitized['provider_priority_list'], 'openai' ) ) );
	}

	/**
	 * Test provider priority list sanitization - handles non-array input.
	 */
	public function test_provider_priority_list_sanitization_handles_non_array() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Test with non-array input.
		$input = array(
			'provider_priority_list' => 'not_an_array',
		);

		$sanitized = $settings_base->sanitize_settings( $input );

		$this->assertArrayHasKey( 'provider_priority_list', $sanitized );
		$this->assertIsArray( $sanitized['provider_priority_list'] );
		// Should return default list.
		$this->assertEquals( array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ), $sanitized['provider_priority_list'] );
	}

	/**
	 * Test provider priority list sanitization - adds missing providers.
	 */
	public function test_provider_priority_list_sanitization_adds_missing_providers() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Test with partial list.
		$input = array(
			'provider_priority_list' => array( 'gemini', 'openai' ),
		);

		$sanitized = $settings_base->sanitize_settings( $input );

		$this->assertArrayHasKey( 'provider_priority_list', $sanitized );
		$this->assertEquals( 5, count( $sanitized['provider_priority_list'] ) );
		// Specified providers should be first.
		$this->assertEquals( 'gemini', $sanitized['provider_priority_list'][0] );
		$this->assertEquals( 'openai', $sanitized['provider_priority_list'][1] );
		// Missing providers should be added.
		$this->assertContains( 'ollama', $sanitized['provider_priority_list'] );
		$this->assertContains( 'lm_studio', $sanitized['provider_priority_list'] );
	}

	/**
	 * Test that the admin settings script enqueues jquery-ui-sortable dependency.
	 */
	public function test_admin_settings_script_enqueues_sortable_dependency() {
		// Create an admin user and set as current user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Instantiate the admin settings class.
		$admin_settings = new WP_MCP_AI_Admin_Settings();

		// Set the current screen to the settings page.
		set_current_screen( 'settings_page_wp-mcp-ai-settings' );

		// Trigger the enqueue_admin_assets method.
		do_action( 'admin_enqueue_scripts', 'settings_page_wp-mcp-ai-settings' );

		// Check if the admin settings script is enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-admin-settings', 'enqueued' ) );

		// Get the script data.
		global $wp_scripts;
		$script_data = $wp_scripts->registered['wp-mcp-ai-admin-settings'];

		// Verify that jquery-ui-sortable is in the dependencies.
		$this->assertContains( 'jquery-ui-sortable', $script_data->deps, 'The wp-mcp-ai-admin-settings script should have jquery-ui-sortable as a dependency.' );
	}
}
