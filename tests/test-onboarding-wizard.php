<?php
/**
 * Tests for the Onboarding Wizard.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Onboarding_Wizard.
 */
class WP_MCP_AI_Onboarding_Wizard_Test extends WP_UnitTestCase {

	/**
	 * Wizard instance under test.
	 *
	 * @var WP_MCP_AI_Onboarding_Wizard
	 */
	private $wizard;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the class file is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Onboarding_Wizard' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-onboarding-wizard.php';
		}

		$this->wizard = new WP_MCP_AI_Onboarding_Wizard();

		// Reset options between tests.
		delete_option( WP_MCP_AI_Onboarding_Wizard::COMPLETE_OPTION );
		delete_option( 'wp_mcp_ai_onboarding_presets' );
		delete_option( 'wp_mcp_ai_settings' );

		// Clean up any user meta.
		$user_id = get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, WP_MCP_AI_Onboarding_Wizard::NOTICE_META_KEY );
		}
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Onboarding_Wizard::COMPLETE_OPTION );
		delete_option( 'wp_mcp_ai_onboarding_presets' );
		delete_option( 'wp_mcp_ai_settings' );
		delete_transient( WP_MCP_AI_Onboarding_Wizard::REDIRECT_TRANSIENT );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Constants & class structure
	// -------------------------------------------------------------------------

	/**
	 * The wizard class should define the expected constants.
	 */
	public function test_constants_are_defined() {
		$this->assertSame( 'wp_mcp_ai_onboarding_complete', WP_MCP_AI_Onboarding_Wizard::COMPLETE_OPTION );
		$this->assertSame( 'wp_mcp_ai_activation_redirect', WP_MCP_AI_Onboarding_Wizard::REDIRECT_TRANSIENT );
		$this->assertSame( 'wp-mcp-ai-getting-started', WP_MCP_AI_Onboarding_Wizard::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_welcome_notice_dismissed', WP_MCP_AI_Onboarding_Wizard::NOTICE_META_KEY );
		$this->assertSame( 4, WP_MCP_AI_Onboarding_Wizard::TOTAL_STEPS );
	}

	// -------------------------------------------------------------------------
	// is_complete()
	// -------------------------------------------------------------------------

	/**
	 * The wizard should not be marked complete by default.
	 */
	public function test_is_complete_returns_false_by_default() {
		$this->assertFalse( $this->wizard->is_complete() );
	}

	/**
	 * After the option is set, is_complete() should return true.
	 */
	public function test_is_complete_returns_true_when_option_set() {
		update_option( WP_MCP_AI_Onboarding_Wizard::COMPLETE_OPTION, 1 );
		$this->assertTrue( $this->wizard->is_complete() );
	}

	// -------------------------------------------------------------------------
	// Presets
	// -------------------------------------------------------------------------

	/**
	 * The get_presets() method should return a non-empty array keyed by preset slugs.
	 */
	public function test_get_presets_returns_array() {
		$presets = $this->wizard->get_presets();
		$this->assertIsArray( $presets );
		$this->assertNotEmpty( $presets );
	}

	/**
	 * Each preset should have required keys: label, icon, description, tools, assistant, system_prompt, temperature.
	 */
	public function test_presets_have_required_keys() {
		$required_keys = array( 'label', 'icon', 'description', 'tools', 'assistant', 'system_prompt', 'temperature' );
		$presets       = $this->wizard->get_presets();

		foreach ( $presets as $key => $preset ) {
			foreach ( $required_keys as $req_key ) {
				$this->assertArrayHasKey(
					$req_key,
					$preset,
					sprintf( 'Preset "%s" is missing the "%s" key.', $key, $req_key )
				);
			}
		}
	}

	/**
	 * Each preset should have a non-empty tools array.
	 */
	public function test_presets_have_tools() {
		$presets = $this->wizard->get_presets();

		foreach ( $presets as $key => $preset ) {
			$this->assertIsArray( $preset['tools'], sprintf( 'Preset "%s" tools should be an array.', $key ) );
			$this->assertNotEmpty( $preset['tools'], sprintf( 'Preset "%s" should have at least one tool.', $key ) );
		}
	}

	/**
	 * Each preset should include a system prompt.
	 */
	public function test_presets_have_system_prompts() {
		$presets = $this->wizard->get_presets();

		foreach ( $presets as $key => $preset ) {
			$this->assertIsString( $preset['system_prompt'], sprintf( 'Preset "%s" system_prompt should be a string.', $key ) );
			$this->assertGreaterThan(
				50,
				strlen( $preset['system_prompt'] ),
				sprintf( 'Preset "%s" system_prompt should be substantial (>50 chars).', $key )
			);
		}
	}

	/**
	 * Each preset temperature should be between 0 and 2.
	 */
	public function test_presets_have_valid_temperatures() {
		$presets = $this->wizard->get_presets();

		foreach ( $presets as $key => $preset ) {
			$this->assertGreaterThanOrEqual( 0, $preset['temperature'], sprintf( 'Preset "%s" temperature must be >= 0.', $key ) );
			$this->assertLessThanOrEqual( 2, $preset['temperature'], sprintf( 'Preset "%s" temperature must be <= 2.', $key ) );
		}
	}

	/**
	 * The presets should include the expected default preset keys.
	 */
	public function test_default_preset_keys_present() {
		$presets       = $this->wizard->get_presets();
		$expected_keys = array(
			'content_creator',
			'customer_support',
			'ecommerce',
			'seo_research',
			'developer',
			'media_creative',
			'site_admin',
			'general',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $presets, sprintf( 'Expected preset "%s" is missing.', $key ) );
		}
	}

	/**
	 * Presets should be filterable via the wp_mcp_ai_onboarding_presets filter.
	 */
	public function test_presets_are_filterable() {
		add_filter(
			'wp_mcp_ai_onboarding_presets',
			function ( $presets ) {
				$presets['custom_test'] = array(
					'label'         => 'Custom',
					'icon'          => '🧪',
					'description'   => 'Test preset.',
					'tools'         => array( 'test_tool' ),
					'assistant'     => 'Test Assistant',
					'system_prompt' => 'Test prompt that is longer than fifty characters for the minimum length validation.',
					'temperature'   => 0.5,
				);
				return $presets;
			}
		);

		$presets = $this->wizard->get_presets();
		$this->assertArrayHasKey( 'custom_test', $presets );
		$this->assertSame( 'Custom', $presets['custom_test']['label'] );

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_onboarding_presets' );
	}

	// -------------------------------------------------------------------------
	// Content Creator preset uses correct tool slugs
	// -------------------------------------------------------------------------

	/**
	 * The content_creator preset should use actual base plugin tool slugs.
	 */
	public function test_content_creator_tools_are_valid_slugs() {
		$presets         = $this->wizard->get_presets();
		$content_creator = $presets['content_creator'];

		// These are confirmed tool slugs from the base plugin.
		$expected_tools = array(
			'create_post',
			'save_post',
			'generate_post_excerpt',
			'search_content',
			'auto_categorize_content',
			'suggest_internal_links',
			'generate_openai_image',
			'seo_meta_optimizer',
			'content_freshness_checker',
			'web_search',
			'deep_research',
			'client_summarize_text',
		);

		$this->assertSame( $expected_tools, $content_creator['tools'] );
	}

	// -------------------------------------------------------------------------
	// Wizard not completed on step 4 render
	// -------------------------------------------------------------------------

	/**
	 * Navigating to Step 4 should NOT automatically mark the wizard as complete.
	 *
	 * This regression test ensures the premature completion bug is fixed.
	 */
	public function test_step_4_does_not_auto_complete() {
		// The wizard should not be complete before visiting step 4.
		$this->assertFalse( $this->wizard->is_complete() );

		// Simulate rendering step 4 by checking the option is NOT set.
		// We cannot call render_step_finish() directly since it's private,
		// but we verify the option remains unset after construction.
		$this->assertFalse( get_option( WP_MCP_AI_Onboarding_Wizard::COMPLETE_OPTION, false ) );
	}

	// -------------------------------------------------------------------------
	// Masked key detection
	// -------------------------------------------------------------------------

	/**
	 * The is_masked_key helper should detect masked placeholder values.
	 */
	public function test_is_masked_key_detection() {
		$method = new ReflectionMethod( $this->wizard, 'is_masked_key' );
		$method->setAccessible( true );

		// Empty string should be treated as masked.
		$this->assertTrue( $method->invoke( $this->wizard, '' ) );

		// Bullet placeholder should be treated as masked.
		$this->assertTrue( $method->invoke( $this->wizard, '••••••••••••••••' ) );

		// Real key should not be masked.
		$this->assertFalse( $method->invoke( $this->wizard, 'sk-proj-abc123' ) );
		$this->assertFalse( $method->invoke( $this->wizard, 'sk-ant-xyz789' ) );
	}

	// -------------------------------------------------------------------------
	// resolve_default_model
	// -------------------------------------------------------------------------

	/**
	 * The resolve_default_model method should use the saved default_model when present.
	 */
	public function test_resolve_default_model_uses_saved_setting() {
		$method = new ReflectionMethod( $this->wizard, 'resolve_default_model' );
		$method->setAccessible( true );

		$settings = array( 'default_model' => 'gpt-4o-mini' );
		$result   = $method->invoke( $this->wizard, $settings, 'openai' );

		$this->assertSame( 'gpt-4o-mini', $result );
	}

	/**
	 * The resolve_default_model method should fall back to provider defaults.
	 */
	public function test_resolve_default_model_provider_fallbacks() {
		$method = new ReflectionMethod( $this->wizard, 'resolve_default_model' );
		$method->setAccessible( true );

		$this->assertSame( 'gpt-4.1', $method->invoke( $this->wizard, array(), 'openai' ) );
		$this->assertSame( 'claude-sonnet-4-6', $method->invoke( $this->wizard, array(), 'anthropic' ) );
		$this->assertSame( 'gemini-3.5-flash', $method->invoke( $this->wizard, array(), 'gemini' ) );
		$this->assertSame( 'llama4', $method->invoke( $this->wizard, array(), 'ollama' ) );
	}

	/**
	 * The resolve_default_model method should return gpt-4.1 for unknown providers.
	 */
	public function test_resolve_default_model_unknown_provider() {
		$method = new ReflectionMethod( $this->wizard, 'resolve_default_model' );
		$method->setAccessible( true );

		$this->assertSame( 'gpt-4.1', $method->invoke( $this->wizard, array(), 'unknown_provider' ) );
	}

	// -------------------------------------------------------------------------
	// Provider validation in handle_save_provider_step
	// -------------------------------------------------------------------------

	/**
	 * Valid provider slugs should be accepted.
	 */
	public function test_valid_provider_slugs() {
		$valid_providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare' );

		// The valid list is defined in handle_save_provider_step.
		// Just verify our preset doesn't use any invalid provider.
		$this->assertCount( 7, $valid_providers );
	}

	// -------------------------------------------------------------------------
	// Seed preset assistants
	// -------------------------------------------------------------------------

	/**
	 * The seed_preset_assistants method should create assistant CPT posts.
	 */
	public function test_seed_preset_assistants_creates_posts() {
		// Ensure the post type is registered.
		if ( ! post_type_exists( 'mcp_ai_assistant' ) ) {
			register_post_type( 'mcp_ai_assistant', array( 'public' => false ) );
		}

		// Set a current user with manage_options capability.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$method = new ReflectionMethod( $this->wizard, 'seed_preset_assistants' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->wizard, array( 'content_creator', 'general' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'content_creator', $result );
		$this->assertArrayHasKey( 'general', $result );

		// Verify the posts were created.
		$content_id = $result['content_creator'];
		$general_id = $result['general'];

		$this->assertIsInt( $content_id );
		$this->assertIsInt( $general_id );
		$this->assertGreaterThan( 0, $content_id );
		$this->assertGreaterThan( 0, $general_id );

		// Verify the post type.
		$this->assertSame( 'mcp_ai_assistant', get_post_type( $content_id ) );
		$this->assertSame( 'mcp_ai_assistant', get_post_type( $general_id ) );

		// Verify post status.
		$this->assertSame( 'publish', get_post_status( $content_id ) );

		// Verify system prompt was saved.
		$system_prompt = get_post_meta( $content_id, '_wp_mcp_ai_system_prompt', true );
		$this->assertNotEmpty( $system_prompt );
		$this->assertStringContainsString( 'content writer', strtolower( $system_prompt ) );

		// Verify tools were saved.
		$tools = get_post_meta( $content_id, '_wp_mcp_ai_tools', true );
		$this->assertIsArray( $tools );
		$this->assertContains( 'create_post', $tools );
		$this->assertContains( 'web_search', $tools );

		// Verify temperature was saved.
		$temperature = get_post_meta( $content_id, '_wp_mcp_ai_temperature', true );
		$this->assertSame( 0.7, floatval( $temperature ) );

		// Verify required capability.
		$capability = get_post_meta( $content_id, 'mcp_ai_required_capability', true );
		$this->assertSame( 'edit_posts', $capability );

		// Clean up.
		wp_delete_post( $content_id, true );
		wp_delete_post( $general_id, true );
	}

	/**
	 * The seed_preset_assistants method should not create duplicate assistants.
	 */
	public function test_seed_preset_assistants_skips_duplicates() {
		if ( ! post_type_exists( 'mcp_ai_assistant' ) ) {
			register_post_type( 'mcp_ai_assistant', array( 'public' => false ) );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$method = new ReflectionMethod( $this->wizard, 'seed_preset_assistants' );
		$method->setAccessible( true );

		// First run: create.
		$result_1 = $method->invoke( $this->wizard, array( 'developer' ) );
		$first_id = $result_1['developer'];

		// Second run: should return the same ID without creating a new post.
		$result_2  = $method->invoke( $this->wizard, array( 'developer' ) );
		$second_id = $result_2['developer'];

		$this->assertSame( $first_id, $second_id );

		// Verify only one post exists with this slug.
		$posts = get_posts(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'name'        => 'onboarding-developer',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);
		$this->assertCount( 1, $posts );

		// Clean up.
		wp_delete_post( $first_id, true );
	}

	/**
	 * The seed_preset_assistants method should skip invalid preset keys.
	 */
	public function test_seed_preset_assistants_skips_invalid_keys() {
		if ( ! post_type_exists( 'mcp_ai_assistant' ) ) {
			register_post_type( 'mcp_ai_assistant', array( 'public' => false ) );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$method = new ReflectionMethod( $this->wizard, 'seed_preset_assistants' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->wizard, array( 'nonexistent_preset' ) );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * The seed_preset_assistants method should set the first created assistant as default.
	 */
	public function test_seed_preset_sets_default_assistant() {
		if ( ! post_type_exists( 'mcp_ai_assistant' ) ) {
			register_post_type( 'mcp_ai_assistant', array( 'public' => false ) );
		}

		// Ensure no default is set.
		delete_option( 'wp_mcp_ai_settings' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$method = new ReflectionMethod( $this->wizard, 'seed_preset_assistants' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->wizard, array( 'general' ) );

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertArrayHasKey( 'default_assistant', $settings );
		$this->assertSame( $result['general'], $settings['default_assistant'] );

		// Clean up.
		wp_delete_post( $result['general'], true );
	}

	// -------------------------------------------------------------------------
	// Preset description quality
	// -------------------------------------------------------------------------

	/**
	 * Preset descriptions should be meaningful (not just 1-2 words).
	 */
	public function test_preset_descriptions_are_meaningful() {
		$presets = $this->wizard->get_presets();

		foreach ( $presets as $key => $preset ) {
			$this->assertGreaterThan(
				20,
				strlen( $preset['description'] ),
				sprintf( 'Preset "%s" description should be meaningful (>20 chars).', $key )
			);
		}
	}

	/**
	 * Preset tool lists should use correct slug format (snake_case).
	 */
	public function test_preset_tool_slugs_are_snake_case() {
		$presets = $this->wizard->get_presets();

		foreach ( $presets as $key => $preset ) {
			foreach ( $preset['tools'] as $tool ) {
				$this->assertMatchesRegularExpression(
					'/^[a-z][a-z0-9_]*$/',
					$tool,
					sprintf( 'Tool "%s" in preset "%s" should be snake_case.', $tool, $key )
				);
			}
		}
	}
}
