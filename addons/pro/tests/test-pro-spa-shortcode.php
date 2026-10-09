<?php
/**
 * Test the Pro SPA v2 shortcode ([nvoos_pro_spa]).
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test class for WP_MCP_AI_Pro_SPA_Shortcode.
 *
 * @since 2.1.0
 */
class Test_WP_MCP_AI_Pro_SPA_Shortcode extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_SPA_Shortcode' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-spa-shortcode.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_SPA_Config' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-spa-config.php';
		}

		WP_MCP_AI_Pro_SPA_Shortcode::register();
	}

	/**
	 * Ensure the assistant CPT is registered (some environments skip init).
	 */
	private function ensure_assistant_cpt() {
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$cpt_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
			if ( file_exists( $cpt_file ) ) {
				require_once $cpt_file;
			}
		}
		if ( ! post_type_exists( 'mcp_ai_assistant' ) ) {
			register_post_type( 'mcp_ai_assistant', array( 'public' => false ) );
		}
	}

	/**
	 * Seed the default-assistant setting and clean up afterwards.
	 *
	 * @param int $assistant_id Assistant post ID.
	 */
	private function set_default_assistant( $assistant_id ) {
		delete_option( 'wp_mcp_ai_default_assistant' );
		update_option( 'wp_mcp_ai_settings', array( 'default_assistant' => absint( $assistant_id ) ) );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_default_assistant' );
		delete_option( 'wp_mcp_ai_settings' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test the shortcode is registered.
	 */
	public function test_shortcode_is_registered() {
		$this->assertTrue( shortcode_exists( 'nvoos_pro_spa' ) );
	}

	/**
	 * Test the render returns the root container with data-config.
	 */
	public function test_render_returns_root_container() {
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array() );

		$this->assertIsString( $out );
		$this->assertStringContainsString( 'nvoos-pro-spa-root', $out );
		$this->assertStringContainsString( 'nvoos-pro-spa-embedded', $out );
		$this->assertStringContainsString( 'data-config', $out );
	}

	/**
	 * Test the assistant_id attribute is sanitized with absint.
	 */
	public function test_render_sanitizes_assistant_id() {
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'assistant_id' => '42abc' ) );

		// absint() strips non-digits; 42 should appear in the JSON config.
		$this->assertStringContainsString( '&quot;assistantId&quot;:42', $out );
	}

	/**
	 * Test an unknown theme is clamped to auto.
	 */
	public function test_render_clamps_unknown_theme_to_auto() {
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'theme' => 'rainbow' ) );

		$this->assertStringContainsString( '&quot;theme&quot;:&quot;auto&quot;', $out );
	}

	/**
	 * Test the can_render filter short-circuits the render.
	 */
	public function test_render_respects_can_render_filter() {
		add_filter( 'nvoos_pro_spa_can_render', '__return_false' );
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array() );
		remove_filter( 'nvoos_pro_spa_can_render', '__return_false' );

		$this->assertSame( '', $out );
	}

	/**
	 * Test guest mode returns empty when no token can be minted.
	 *
	 * Logged-out visitor + guest=1 + no valid assistant => no renderable
	 * surface (and nothing sensitive leaked).
	 */
	public function test_render_guest_without_token_returns_empty() {
		wp_set_current_user( 0 );

		$out = WP_MCP_AI_Pro_SPA_Shortcode::render(
			array(
				'guest'        => '1',
				'assistant_id' => '0',
			)
		);

		$this->assertSame( '', $out );
	}

	/**
	 * Test admin mode is downgraded to embedded for non-admins.
	 */
	public function test_render_admin_mode_downgraded_for_non_admin() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'mode' => 'admin' ) );

		$this->assertStringContainsString( '&quot;mode&quot;:&quot;embedded&quot;', $out );
	}

	/**
	 * Test cron monitoring defaults to enabled in the per-instance config.
	 */
	public function test_render_defaults_cron_monitor_to_enabled() {
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array() );

		$this->assertStringContainsString( '&quot;cronMonitor&quot;:true', $out );
	}

	/**
	 * Test cron_monitor="0" disables the job stream in the per-instance config.
	 */
	public function test_render_cron_monitor_zero_disables_job_stream() {
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'cron_monitor' => '0' ) );

		$this->assertStringContainsString( '&quot;cronMonitor&quot;:false', $out );
	}

	/**
	 * Test guest mode is ignored for logged-in users.
	 */
	public function test_render_guest_ignored_when_logged_in() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'guest' => '1' ) );

		// The authenticated surface has no guestToken in its config.
		$this->assertStringNotContainsString( 'guestToken', $out );
	}

	/**
	 * Test build() resolves the site-wide default assistant when none is requested.
	 */
	public function test_build_resolves_site_default_assistant() {
		$this->ensure_assistant_cpt();

		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Site Default Assistant',
			)
		);
		$this->set_default_assistant( $assistant_id );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build( array( 'mode' => 'embedded' ) );

		$this->assertSame( $assistant_id, $runtime['config']['assistantId'] );
	}

	/**
	 * Test build() pre-loads only assistants the visitor is allowed to use.
	 *
	 * Subscribers see 'public' and 'read' assistants; assistants without meta
	 * follow the global chat capability (edit_posts) and admin-only assistants
	 * stay hidden.
	 */
	public function test_build_filters_preloaded_assistants_by_capability() {
		$this->ensure_assistant_cpt();

		$public_assistant = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Public Assistant',
			)
		);
		update_post_meta( $public_assistant, 'mcp_ai_required_capability', 'public' );

		$read_assistant = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Read Assistant',
			)
		);
		update_post_meta( $read_assistant, 'mcp_ai_required_capability', 'read' );

		$edit_assistant = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Edit Assistant',
			)
		);
		update_post_meta( $edit_assistant, 'mcp_ai_required_capability', 'edit_posts' );

		$admin_assistant = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Admin Assistant',
			)
		);
		update_post_meta( $admin_assistant, 'mcp_ai_required_capability', 'manage_options' );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build( array( 'mode' => 'embedded' ) );

		$ids = wp_list_pluck( $runtime['assistants'], 'id' );
		$this->assertContains( $public_assistant, $ids );
		$this->assertContains( $read_assistant, $ids );
		$this->assertNotContains( $edit_assistant, $ids );
		$this->assertNotContains( $admin_assistant, $ids );
	}

	/**
	 * Test the assistant switcher defaults on for the admin surface, even
	 * when the mode is clamped to embedded for non-admins.
	 */
	public function test_build_assistant_selector_defaults_on_for_admin_surface() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build( array( 'mode' => 'admin' ) );

		$this->assertSame( 'embedded', $runtime['config']['mode'] );
		$this->assertTrue( $runtime['config']['assistantSelector'] );
	}

	/**
	 * Test shortcode instances get the switcher only when opted in.
	 */
	public function test_build_assistant_selector_off_for_shortcode_by_default() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build( array( 'mode' => 'embedded' ) );

		$this->assertFalse( $runtime['config']['assistantSelector'] );
	}

	/**
	 * Test assistant_selector=1 enables the switcher on shortcode instances.
	 */
	public function test_build_assistant_selector_opt_in() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build(
			array(
				'mode'               => 'embedded',
				'assistant_selector' => true,
			)
		);

		$this->assertTrue( $runtime['config']['assistantSelector'] );
	}

	/**
	 * Test logged-out visitors never get the assistant switcher.
	 */
	public function test_build_assistant_selector_never_for_guests() {
		wp_set_current_user( 0 );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build(
			array(
				'mode'               => 'embedded',
				'assistant_selector' => true,
			)
		);

		$this->assertFalse( $runtime['config']['assistantSelector'] );
	}

	/**
	 * Test the shortcode assistant_selector attribute reaches the config.
	 */
	public function test_render_assistant_selector_attribute() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'assistant_selector' => '1' ) );

		$this->assertStringContainsString( '&quot;assistantSelector&quot;:true', $out );
	}
}
